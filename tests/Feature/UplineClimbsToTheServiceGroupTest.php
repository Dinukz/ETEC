<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\Consultant;
use App\Models\Course;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ⚠️ THE UPLINE 2,500 MUST CLIMB TO THE OWNER'S SERVICE GROUP — set 2026-08-26.
 *
 * Owner: "Dn consultant lagen yana upline 2500 dn add wen na eka fix kral denn
 * consultant yatathe inn aya student la add krahama 2500 udata calculate wenn
 * one auto."
 *
 * The money was never LOST — the 08-21 rule puts it on the specific account the
 * downline was sponsored through, and it did land there. But when that account
 * is a DEEP one (a leg inside a bought set) it stopped dead, and the owner's
 * Service Groups headline read 0.
 *
 * It only became visible on 08-25, when the rollup was narrowed to count only
 * accounts hanging DIRECTLY off the main (the fix for the duplicated 2,500).
 * Before that the main summed the whole subtree, so a deep row still showed up
 * — by accident, at the cost of counting shallower students many times over.
 *
 * So the credit now climbs the RECIPIENT's own tree the same way a registrant's
 * own placement does. Both halves are pinned here: the money reaches the
 * Service Group account, and the main still gets no row of its own.
 */
class UplineClimbsToTheServiceGroupTest extends TestCase
{
    use RefreshDatabase;

    private const SLOT = 2500;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        \App\Services\FinanceSchemaRepair::ensure();

        \App\Models\CommissionSetting::where('is_active', true)->update([
            'commission_per_student' => self::SLOT,
            'direct_commission' => 4000,
        ]);
        \Illuminate\Support\Facades\Cache::forget('commission_settings.active');
    }

    /** Verify a full payment for $buyer, placed under $account on leg $leg. */
    private function verify(User $buyer, Consultant $account, int $leg): void
    {
        $payment = Payment::create([
            'payment_code' => 'PYM'.Str::random(8), 'user_id' => $buyer->id,
            'course_id' => Course::where('status', 'published')->firstOrFail()->id,
            'consultant_id' => $account->id, 'requested_tier' => $leg,
            'amount' => 39000, 'currency' => 'LKR', 'method' => 'bank_transfer',
            'payment_type' => 'full', 'status' => 'pending', 'paid_at' => now(),
        ]);

        app(PaymentService::class)->verify($payment, User::where('email', 'accountant@etec.lk')->firstOrFail());
    }

    private function addStudent(Consultant $account, int $leg): User
    {
        $user = User::factory()->create();
        Student::create([
            'user_id' => $user->id, 'student_code' => 'SRN'.Str::random(8),
            'status' => 'active', 'enrolled_at' => now(),
        ]);

        $this->verify($user, $account, $leg);

        return $user;
    }

    private function slotOn(Consultant $account): float
    {
        return (float) Commission::where('consultant_id', $account->id)
            ->where('type', 'bonus')->sum('amount');
    }

    /**
     * Sponsor with a bought set, so a DEEP account exists to sponsor through.
     *
     * @return array{0: Consultant, 1: \Illuminate\Support\Collection<int, Consultant>}
     */
    private function sponsorWithABoughtSet(): array
    {
        $main = User::where('email', 'consultant@etec.lk')->firstOrFail()->consultant;

        // A second course, placed on the first hidden account: that creates a
        // set of three hanging off it, two levels below the main.
        $this->verify($main->user, $main->hiddenAccounts()->orderBy('id')->first(), 2);

        return [$main->fresh(), $main->fresh()->hiddenAccounts()->orderBy('id')->get()];
    }

    private function downlineUnder(Consultant $sponsorAccount): Consultant
    {
        $user = $this->addStudent($sponsorAccount, 1);
        $user->syncRoles(['consultant']);

        $c = Consultant::create([
            'user_id' => $user->id,
            'parent_consultant_id' => $sponsorAccount->id,
            'consultant_code' => 'CON'.Str::random(8),
            'referral_code' => strtoupper(Str::random(10)),
            'status' => 'active',
        ]);

        app(\App\Services\HiddenAccountService::class)->ensureFor($c);

        return $c->fresh();
    }

    public function test_a_downlines_student_climbs_from_a_deep_account_to_the_service_group(): void
    {
        [$main, $accounts] = $this->sponsorWithABoughtSet();

        $directChild = $accounts[0];  // the owner's Service Group 1 leg
        $setMain = $accounts[2];      // head of the bought set, under $directChild
        $deep = $accounts[4];         // a leg inside that set — two levels down

        $downline = $this->downlineUnder($deep);

        // Only what the downline's OWN student earns the sponsor is in scope.
        Commission::query()->delete();

        $this->addStudent($downline->hiddenAccounts()->orderBy('id')->first(), 1);

        $this->assertSame(
            (float) self::SLOT,
            $this->slotOn($deep),
            'the account the downline was sponsored through earns its 2,500'
        );

        $this->assertSame(
            (float) self::SLOT,
            $this->slotOn($setMain),
            'and it climbs through the set-main above it'
        );

        $this->assertSame(
            (float) self::SLOT,
            $this->slotOn($directChild),
            'and reaches the account that IS the owner\'s Service Group — this is the bug'
        );

        // ⚠️ INVERTED 2026-09-02. "It rolls up, it does not earn" was true for
        // exactly as long as the main's headline summed its children — and that
        // rollup is what made the main unable to settle anything, reported eight
        // times as "main account eke amount eka yanne na". The main earns its
        // own row now and the headline reads only those rows, so nothing is
        // counted twice.
        //
        // The assertion above is untouched and is what this test is FOR: the
        // money must reach the account that IS the owner's Service Group.
        $this->assertSame(
            (float) self::SLOT,
            $this->slotOn($main),
            'and carries on to the main, which is the top of the same tree'
        );
    }

    public function test_the_owners_service_groups_page_shows_a_downlines_student(): void
    {
        [$main, $accounts] = $this->sponsorWithABoughtSet();

        $downline = $this->downlineUnder($accounts[4]);
        Commission::query()->delete();
        $this->addStudent($downline->hiddenAccounts()->orderBy('id')->first(), 1);

        $totals = $this->actingAs($main->user)
            ->get(route('consultant.groups.index'))
            ->viewData('tierTotals');

        // The bought set hangs off the Service Group 1 leg, so that is where the
        // money belongs — it must NOT cross into Service Group 2.
        $this->assertSame((float) self::SLOT, (float) ($totals[1]->total ?? 0));
        $this->assertSame(0.0, (float) ($totals[2]->total ?? 0));
    }

    /**
     * The shallow case must be untouched: a downline sponsored through a direct
     * child credits it once, and the climb stops immediately at the main.
     */
    public function test_a_shallow_sponsorship_still_earns_exactly_one_slot(): void
    {
        $main = User::where('email', 'consultant@etec.lk')->firstOrFail()->consultant;
        $directChild = $main->hiddenAccounts()->orderBy('id')->first();

        $downline = $this->downlineUnder($directChild);
        Commission::query()->delete();
        $this->addStudent($downline->hiddenAccounts()->orderBy('id')->first(), 1);

        $this->assertSame((float) self::SLOT, $this->slotOn($directChild));

        // ⚠️ 0 -> 2,500 on 2026-09-02; the climb no longer stops below the main.
        // "Exactly one slot" is still the rule and is still what this pins —
        // one 2,500 per account the climb passes through, never two on any of
        // them.
        $this->assertSame((float) self::SLOT, $this->slotOn($main));
    }

    /**
     * ⚠️ THE SCN-00109 SHAPE (2026-08-29): the DOWNLINE buys a course too.
     *
     * "SCN 100 yatathe thiyen SCN 109 course ekak buy kranwa, eke 2500 upline
     *  eken SCN 100 account ekat..."
     *
     * This is the deepest version of the climb and the one that has broken three
     * times: a student is seated on an account inside a set the DOWNLINE bought,
     * so the money has to walk the downline's own account tree, hop across to
     * the sponsor, walk the SPONSOR's tree, and stop below their main — where
     * the rollup can finally see it.
     *
     * Both ends are asserted, because a pass on either alone has been misleading
     * before: the sponsor's account must hold the row, AND the main's Service
     * Group headline must report it.
     */
    public function test_a_student_on_a_downlines_bought_account_still_reaches_the_sponsors_service_group(): void
    {
        $main = User::where('email', 'consultant@etec.lk')->firstOrFail()->consultant;
        $sponsorAccount = $main->hiddenAccounts()->orderBy('id')->first();

        $downline = $this->downlineUnder($sponsorAccount);

        // The downline buys a course of their own — that opens their own set of
        // three, exactly like SCN-00109 was opened.
        $this->verify($downline->user, $downline->hiddenAccounts()->orderBy('id')->first(), 1);

        $bought = $downline->fresh()->hiddenAccounts()->orderBy('id')->get()->last();

        Commission::query()->delete();

        $this->addStudent($bought, 1);

        $this->assertSame(
            (float) self::SLOT,
            $this->slotOn($sponsorAccount),
            'the 2,500 crosses from the downline network onto the sponsor account it hangs off',
        );

        // ⚠️ 0 -> 2,500 on 2026-09-02 (the rollup was removed; the main earns).
        // The assertion above is the one this test is named for and is
        // unchanged: the 2,500 must cross onto the sponsor ACCOUNT the downline
        // hangs off, not skip it.
        $this->assertSame((float) self::SLOT, $this->slotOn($main), 'and continues up to the main');

        // The half the owner actually looks at: the Service Groups headline.
        $flush = app(\App\Services\ServiceGroupFlush::class);
        $group = \App\Models\Group::where('consultant_id', $main->id)->orderBy('id')->firstOrFail();

        $this->assertSame(
            (float) self::SLOT,
            $flush->legBalance($main->fresh(), $group, 1) + $flush->legBalance($main->fresh(), $group, 2),
            'and SCN-00100 reports it on one of its two Service Groups',
        );
    }
}
