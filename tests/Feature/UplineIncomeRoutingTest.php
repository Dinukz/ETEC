<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\Consultant;
use App\Models\Course;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\Student;
use App\Models\User;
use App\Services\PaymentService;
use App\Services\ServiceGroupStaging;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * WHERE downline-sourced income lands, and the approval gates around it.
 *
 * Replaces UplineAutoApprovalTest: the auto-approval half of that file was
 * retired on 2026-08-14 when the owner put downline income back through the
 * flush (pinned by UplineIncomeIsFlushedTest). The ROUTING half — which account
 * and which service group the money lands on — is unchanged and lives on here.
 *
 * ⚠️ ROUTING RULE AS OF 2026-08-21 — THIRD SETTING. An ancestor is credited on
 * the SPECIFIC PLACEMENT ACCOUNT the downline was sponsored through, and the
 * seat follows onto that same account. The owner's main account is no longer
 * credited directly.
 *
 * History, because this has now been set both ways twice: specific account
 * (08-05) -> owner's MAIN account (08-12) -> specific account again (08-21).
 * Do not flip it a fourth time without an explicit worked example.
 *
 * The money still appears under the owner's correct Service Group — the
 * Service Groups rollup attributes each account to the leg it represents
 * (Consultant::serviceGroupLeg()), so it now shows on BOTH the placement
 * account's row and the owner's headline, which is what "dekem penwnn one"
 * asked for. Pinned by ServiceGroupRollupTest and UplineLandsOnSubAccountTest.
 */
class UplineIncomeRoutingTest extends TestCase
{
    use RefreshDatabase;

    private const SLOT = 2500;

    private const DIRECT = 4000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        \App\Models\CommissionSetting::where('is_active', true)->update([
            'commission_per_student' => self::SLOT,
            'direct_commission' => self::DIRECT,
        ]);
        \Illuminate\Support\Facades\Cache::forget('commission_settings.active');

        Setting::set('stage_first_pairs', '1', 'integer', 'commission');
        Setting::set('stage_second_pairs', '2', 'integer', 'commission');
        Setting::set('stage_repeat_pairs', '3', 'integer', 'commission');
        Setting::set('account_unlock_students', '12', 'integer', 'commission');
        ServiceGroupStaging::forget();
    }

    private function sponsor(): Consultant
    {
        return User::where('email', 'consultant@etec.lk')->firstOrFail()->consultant;
    }

    private function addStudent(Consultant $account, int $leg): User
    {
        $user = User::factory()->create();
        Student::create([
            'user_id' => $user->id, 'student_code' => 'SRN'.Str::random(6),
            'status' => 'active', 'enrolled_at' => now(),
        ]);

        $payment = Payment::create([
            'payment_code' => 'PYM'.Str::random(6), 'user_id' => $user->id, 'course_id' => Course::first()->id,
            'consultant_id' => $account->id, 'requested_tier' => $leg, 'amount' => 28000, 'currency' => 'LKR',
            'method' => 'bank_transfer', 'payment_type' => 'full', 'status' => 'pending', 'paid_at' => now(),
        ]);

        app(PaymentService::class)->verify($payment, User::where('email', 'accountant@etec.lk')->firstOrFail());

        return $user;
    }

    private function promoteToConsultant(User $user, Consultant $sponsorAccount): Consultant
    {
        $user->syncRoles(['consultant']);

        $consultant = Consultant::create([
            'user_id' => $user->id,
            'parent_consultant_id' => $sponsorAccount->id,
            'consultant_code' => 'CON'.Str::random(6),
            'referral_code' => strtoupper(Str::random(10)),
            'status' => 'active',
        ]);

        app(\App\Services\HiddenAccountService::class)->ensureFor($consultant);

        return $consultant->fresh();
    }

    /**
     * A sponsor with a downline consultant seated on one of their PLACEMENT
     * accounts — the shape the routing rule is about.
     *
     * @return array{0: Consultant, 1: Consultant, 2: Consultant} [sponsor main, sponsor placement, downline account]
     */
    private function networkWithDownline(): array
    {
        $sponsor = $this->sponsor();
        $placement = $sponsor->hiddenAccounts()->first();

        $downlineUser = $this->addStudent($placement, 1);
        $downline = $this->promoteToConsultant($downlineUser, $placement);

        return [$sponsor, $placement, $downline->hiddenAccounts()->first()];
    }

    // ----- Where the money lands -----------------------------------------------

    /**
     * ⚠️ INVERTED 2026-08-21 — upline income lands on the SPECIFIC PLACEMENT
     * ACCOUNT, not on the owner's main.
     *
     * Third setting of this rule: specific account (08-05) -> main (08-12) ->
     * specific account again. Owner: "pahala student add krahama upline eken
     * yana 2500 sub ekat add wen nathiw kelinm main account ekat yanne, ekath
     * waradi."
     *
     * The owner still sees it under their correct Service Group — the rollup
     * attributes the account to the leg it represents — so it now shows on
     * BOTH rows rather than only the main. See UplineLandsOnSubAccountTest.
     */
    public function test_downline_income_is_credited_to_the_placement_account(): void
    {
        [$sponsor, $placement, $downlineAccount] = $this->networkWithDownline();

        $mainBefore = (float) Commission::where('consultant_id', $sponsor->id)->where('type', 'bonus')->sum('amount');
        $placementBefore = (float) Commission::where('consultant_id', $placement->id)->where('type', 'bonus')->sum('amount');

        $this->addStudent($downlineAccount, 1);

        $this->assertSame(
            $placementBefore + (float) self::SLOT,
            (float) Commission::where('consultant_id', $placement->id)->where('type', 'bonus')->sum('amount'),
            'The 2,500 lands on the account the downline was sponsored through.',
        );
        // ⚠️ INVERTED 2026-09-02. "Not credited directly any more" was the
        // 2026-08-21 rule, and it existed because the main's headline was a
        // rollup of its children — a row of its own would have been the same
        // student twice on one screen. With the rollup removed, the main is an
        // ordinary account in the climb and must be credited, or it holds
        // nothing, never pairs, and never appears in a System Run.
        //
        // ⚠️ NOT "credited INSTEAD": the assertion above still requires the
        // money to land on the account the downline was sponsored through.
        $this->assertSame(
            $mainBefore + (float) self::SLOT,
            (float) Commission::where('consultant_id', $sponsor->id)->where('type', 'bonus')->sum('amount'),
            'and the climb carries on to the main above it.',
        );
    }

    /** Every ancestor up the chain earns, not just the nearest one. */
    public function test_every_ancestor_up_the_chain_earns(): void
    {
        [$top, $topPlacement, $middleAccount] = $this->networkWithDownline();

        $middle = Consultant::find($middleAccount->owner_consultant_id);
        $bottomUser = $this->addStudent($middleAccount, 1);
        $bottom = $this->promoteToConsultant($bottomUser, $middleAccount);

        // Each level is credited on the account the level below hangs off.
        $topBefore = (float) Commission::where('consultant_id', $topPlacement->id)->where('type', 'bonus')->sum('amount');
        $middleBefore = (float) Commission::where('consultant_id', $middleAccount->id)->where('type', 'bonus')->sum('amount');

        $this->addStudent($bottom->hiddenAccounts()->first(), 1);

        $this->assertSame(
            $middleBefore + (float) self::SLOT,
            (float) Commission::where('consultant_id', $middleAccount->id)->where('type', 'bonus')->sum('amount'),
            'The direct sponsor one level up is credited, on the account used.',
        );
        $this->assertSame(
            $topBefore + (float) self::SLOT,
            (float) Commission::where('consultant_id', $topPlacement->id)->where('type', 'bonus')->sum('amount'),
            'And so is the grandparent — the whole chain earns.',
        );
    }

    /** The seat follows the money onto the same placement account. */
    public function test_the_downlines_student_seats_on_the_placement_account(): void
    {
        [$sponsor, $placement, $downlineAccount] = $this->networkWithDownline();

        $seatsOn = fn (int $accountId) => GroupMember::whereIn(
            'group_id', Group::where('consultant_id', $accountId)->select('id'),
        )->count();

        $mainBefore = $seatsOn($sponsor->id);
        $placementBefore = $seatsOn($placement->id);

        $this->addStudent($downlineAccount, 1);

        $this->assertSame($placementBefore + 1, $seatsOn($placement->id), 'The seat follows the money onto the placement account.');
        // ⚠️ INVERTED 2026-09-02, following the money above it: a credit needs a
        // seat, and the main is credited now. The two seats pair independently —
        // the placement account against its own other leg, the main against its
        // own — which is what stops a main ever draining a child.
        $this->assertSame($mainBefore + 1, $seatsOn($sponsor->id), 'and the main holds its own seat for the same student.');
    }

    /**
     * ⚠️ THE ORDINAL-AT-CREDIT-TIME TESTS WERE REMOVED HERE ON 2026-08-21.
     *
     * Six tests used to live in this spot asserting that the placement
     * account's ORDINAL named which leg of the OWNER'S MAIN ACCOUNT an upline
     * 2,500 landed on (1st account -> leg 1, 2nd -> leg 2, later sets
     * repeating in threes).
     *
     * That question stopped existing when upline income moved back onto the
     * placement account itself: nothing credits the owner's main account any
     * more, so there is no "which leg of the main" to get right at credit time.
     * The leg a row takes is now simply the leg it sits on INSIDE its own
     * account.
     *
     * The requirement those tests protected — that the money appear under the
     * owner's CORRECT Service Group — is still pinned, in the two places that
     * now decide it:
     *
     *   ServiceGroupRollupTest        the account -> owner's leg attribution
     *   UplineLandsOnSubAccountTest   the money landing on the sub account,
     *                                 and still rolling up to the right side
     *
     * They were deleted rather than rewritten because rewriting them would
     * have duplicated those two files exactly.
     */
    public function test_the_leg_a_row_takes_is_the_one_inside_its_own_account(): void
    {
        [$sponsor, $placement, $downlineAccount] = $this->networkWithDownline();

        // ⚠️ The comment here used to say the downline sits on TIER 2. It does
        // not — networkWithDownline() seats them with addStudent($placement, 1),
        // so they are on leg 1. Corrected 2026-09-01 after the stale comment
        // nearly got the wrong leg pinned as correct.
        $this->addStudent($downlineAccount, 1);

        $row = Commission::where('consultant_id', $placement->id)
            ->where('type', 'bonus')
            ->latest('id')
            ->first();

        $this->assertNotNull($row, 'The placement account is the one credited.');

        // ⚠️⚠️ STRENGTHENED 2026-09-01. This asserted only that the tier was
        // 1 OR 2 — so a cross from leg 2 to leg 1 PASSED IT. That is not a
        // slack assertion, it is a hole: this is the only test named as though
        // it pins the leg, and the exact bug it is named for slipped through it
        // for eight rounds of reports.
        //
        // The downline occupies leg 1 of the placement account, so every credit
        // climbing through them belongs on leg 1 of it.
        $this->assertSame(
            1,
            (int) $row->tier,
            'The credit takes the leg the downline occupies INSIDE this account — leg 1, not "either leg".',
        );
    }

    public function test_an_unbalanced_own_placement_is_not_approvable(): void
    {
        $account = $this->sponsor()->hiddenAccounts()->first();

        for ($i = 0; $i < 3; $i++) {
            $this->addStudent($account, 1);
        }

        $this->assertSame(
            0,
            Commission::where('consultant_id', $account->id)
                ->where('type', 'bonus')
                ->whereIn('status', ['approved', 'paid'])
                ->count(),
            'Nothing is payable until a System Run flushes it.',
        );
    }

    /** The deliberate per-row override still works on an unflushed row. */
    public function test_an_accountant_can_force_approve_an_unflushed_row(): void
    {
        $admin = User::where('email', 'admin@etec.lk')->firstOrFail();
        $account = $this->sponsor()->hiddenAccounts()->first();

        $this->addStudent($account, 1); // leg 1 only — never balances

        $row = Commission::where('consultant_id', $account->id)->where('type', 'bonus')->firstOrFail();
        $this->assertSame('pending', $row->status);

        $this->actingAs($admin)->patch(route('commissions.approve', $row))->assertRedirect();

        $this->assertSame(
            'approved',
            $row->fresh()->status,
            'The single-row Approve button is always honoured — that is why the dead "Not yet balanced" label was removed.',
        );
    }

    /** Bulk approval must NOT gain the override. */
    public function test_approve_all_still_refuses_an_unflushed_row(): void
    {
        $admin = User::where('email', 'admin@etec.lk')->firstOrFail();
        $account = $this->sponsor()->hiddenAccounts()->first();

        $this->addStudent($account, 1);

        $row = Commission::where('consultant_id', $account->id)->where('type', 'bonus')->firstOrFail();

        $this->actingAs($admin)->patch(route('commissions.approve-all'));

        $this->assertSame(
            'pending',
            $row->fresh()->status,
            'Approve all must keep respecting the release rule, or money leaks out unflushed.',
        );
    }

    /** Nothing overrides the hold gate — not even the per-row button. */
    public function test_force_approval_cannot_release_held_income(): void
    {
        $admin = User::where('email', 'admin@etec.lk')->firstOrFail();
        $sponsor = $this->sponsor();
        $account = $sponsor->hiddenAccounts()->first();

        $sponsor->update(['finance_status' => 'suspended']);
        $this->addStudent($account, 1);

        $row = Commission::where('consultant_id', $account->id)->where('type', 'bonus')->firstOrFail();

        $this->actingAs($admin)->patch(route('commissions.approve', $row));

        $this->assertSame(
            'pending',
            $row->fresh()->status,
            'The hold / finance-status gate is not overridable by anything.',
        );
    }

    // ----- The direct bonus stays with the registrant ---------------------------

    public function test_the_direct_bonus_is_never_carried_up_the_chain(): void
    {
        [$sponsor, , $downlineAccount] = $this->networkWithDownline();

        $before = (float) Commission::where('consultant_id', $sponsor->id)->where('type', 'referral')->sum('amount');

        $this->addStudent($downlineAccount, 1);

        $this->assertSame(
            $before,
            (float) Commission::where('consultant_id', $sponsor->id)->where('type', 'referral')->sum('amount'),
            'The 4,000 belongs only to whoever personally registered the student.',
        );
    }
}
