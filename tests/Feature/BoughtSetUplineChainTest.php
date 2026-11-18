<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\Consultant;
use App\Models\Course;
use App\Models\Payment;
use App\Models\User;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A student seated in a BOUGHT account fills every account above it.
 *
 * Owner, 2026-08-22: "buy krapu 4th account eka athule student kenk placement
 * kra, ethkot normal 2500i 4000i calculate una eth upline eken yana 2500
 * calculate un na. eka harinm 143 account ekatat saha 142 main account
 * ekatath calculate wen one."
 *
 * A bought set records its placement on the PAYMENT, never as a
 * parent_consultant_id, so creditUpline() resolved straight to the owning
 * person and jumped to their EXTERNAL sponsor — skipping every account of their
 * own in between. The accounts above could therefore never fill a leg from
 * anything placed below them, and never became pairable.
 */
class BoughtSetUplineChainTest extends TestCase
{
    use RefreshDatabase;

    private const SLOT = 2500;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        \App\Services\FinanceSchemaRepair::ensure();
    }

    private function owner(): Consultant
    {
        return User::where('email', 'consultant@etec.lk')->firstOrFail()->consultant;
    }

    private function course(): Course
    {
        return Course::where('status', 'published')->firstOrFail();
    }

    private function verify(User $buyer, Consultant $placement, int $tier): void
    {
        $payment = Payment::create([
            'payment_code' => 'PYM-'.Str::random(8),
            'user_id' => $buyer->id,
            'course_id' => $this->course()->id,
            'consultant_id' => $placement->id,
            'requested_tier' => $tier,
            'amount' => 10000,
            'currency' => 'LKR',
            'method' => 'bank_transfer',
            'payment_type' => 'full',
            'status' => 'pending',
        ]);

        app(PaymentService::class)->verify($payment, User::role('admin')->first());
    }

    private function slotOn(Consultant $account, ?int $tier = null): float
    {
        return (float) Commission::where('consultant_id', $account->id)
            ->where('type', 'bonus')
            ->when($tier !== null, fn ($q) => $q->where('tier', $tier))
            ->sum('amount');
    }

    /**
     * The owner's exact scenario, with their account numbers:
     *   SCN-00142 main · SCN-00143 = its leg 1 · a bought set under 143 leg 1.
     */
    public function test_a_student_in_a_bought_account_credits_every_account_above_it(): void
    {
        $main = $this->owner();                                    // "142"
        $legOne = $main->hiddenAccounts()->orderBy('id')->first();  // "143"

        // The consultant buys a course, placing it under their own 143 · SG1.
        $this->verify($main->user, $legOne, 1);

        $bought = $main->fresh()->hiddenAccounts()->orderBy('id')->get();
        $this->assertCount(5, $bought, 'Opening pair + a bought set of three.');
        $setMain = $bought[2];                                      // "145"

        $before = [
            'setMain' => $this->slotOn($setMain),
            'legOne' => $this->slotOn($legOne, 1),
            'main' => $this->slotOn($main, 1),
        ];

        // A real student placed inside the bought account, on its leg 1.
        $student = User::factory()->create();
        $this->verify($student, $setMain, 1);

        $this->assertSame(
            $before['setMain'] + self::SLOT,
            $this->slotOn($setMain),
            'The account the student sits in earns its own 2,500.',
        );
        $this->assertSame(
            (float) \App\Models\CommissionSetting::current()->direct_commission,
            (float) Commission::where('consultant_id', $setMain->id)->where('type', 'referral')->sum('amount'),
            '...and the direct-add bonus for bringing them in.',
        );

        // ⚠️ The part that was missing entirely.
        $this->assertSame(
            $before['legOne'] + self::SLOT,
            $this->slotOn($legOne, 1),
            'SCN-00143 — the account the set was placed under — fills on the leg it was placed on.',
        );
        // ⚠️ SCN-00142 GETS ITS OWN ROW — INVERTED 2026-09-02.
        //
        // "142 main account ekatath calculate wen one" used to be satisfied by
        // the ROLLUP rather than by a commission: the main's Service Group
        // figures were the SUM of the accounts beneath it, so the money showed
        // there without a second row, and giving it one would have doubled its
        // own headline.
        //
        // That rollup is what left the main unable to settle anything — it
        // displayed rupees it did not own, so it could never pair or appear in a
        // System Run. The owner's answer to this exact request is now literal:
        // the main is credited, the headline reads only its own rows, and
        // nothing is counted twice.
        $this->assertSame(
            $before['main'] + self::SLOT,
            $this->slotOn($main, 1),
            'The main is credited literally now, not via a rollup.',
        );

        $ownerSg1 = (float) Commission::whereIn('consultant_id', $main->fresh()->accountIds())
            ->where('type', 'bonus')
            ->sum('amount');

        $this->assertGreaterThanOrEqual(
            self::SLOT * 2,
            $ownerSg1,
            'But the money IS on the person: 2,500 in the account the student sits in, plus 2,500 on the account above it.',
        );
    }

    /** ⚠️ The 4,000 belongs to the registrant only; it must not climb. */
    public function test_the_direct_bonus_does_not_climb_the_account_tree(): void
    {
        $main = $this->owner();
        $legOne = $main->hiddenAccounts()->orderBy('id')->first();

        $this->verify($main->user, $legOne, 1);
        $setMain = $main->fresh()->hiddenAccounts()->orderBy('id')->get()[2];

        $mainDirectBefore = (float) Commission::where('consultant_id', $main->id)->where('type', 'referral')->sum('amount');

        $student = User::factory()->create();
        $this->verify($student, $setMain, 1);

        $this->assertSame(
            $mainDirectBefore,
            (float) Commission::where('consultant_id', $main->id)->where('type', 'referral')->sum('amount'),
        );
        $this->assertSame(
            0.0,
            (float) Commission::where('consultant_id', $legOne->id)->where('type', 'referral')->sum('amount'),
        );
    }

    /**
     * Buying the set is itself a purchase placed under 143 — so the 2,500 lands
     * there. "eke course ekak buy krahama thamanma consultant 2500 add wenn
     * one, eka add wela na kohe wath."
     */
    public function test_buying_the_set_credits_the_account_it_was_placed_under(): void
    {
        $main = $this->owner();
        $legOne = $main->hiddenAccounts()->orderBy('id')->first();

        $before = $this->slotOn($legOne, 1);

        $this->verify($main->user, $legOne, 1);

        $this->assertSame(
            $before + self::SLOT,
            $this->slotOn($legOne, 1),
            'The purchase fills the seat it was placed in.',
        );
        $this->assertSame(
            0.0,
            (float) Commission::where('consultant_id', $legOne->id)->where('type', 'referral')->sum('amount'),
            'But no direct-add bonus — nobody was brought in.',
        );
    }

    /**
     * ⚠️ UPLINE ROWS ARRIVE APPROVED — no human Approve step (2026-08-23).
     *
     * The internal account-tree walk left its rows pending while the external
     * sponsor chain auto-approved its own, so the same kind of income showed up
     * in two different states on one screen: some rows "Approved · Manual ·
     * Mark paid", others "Pending · Stage not balanced · Approve". The owner
     * wants the manual step gone — "upline eyana ewata approve kran nathuw
     * kelinm mark paid button eka withrk denn" — because one registration
     * credits EVERY ancestor and the clicking does not survive real volume.
     */
    public function test_upline_rows_are_auto_approved_so_only_mark_paid_remains(): void
    {
        $main = $this->owner();
        $legOne = $main->hiddenAccounts()->orderBy('id')->first();

        $this->verify($main->user, $legOne, 1);
        $setMain = $main->fresh()->hiddenAccounts()->orderBy('id')->get()[2];

        $student = User::factory()->create();
        $this->verify($student, $setMain, 1);

        // The climbing 2,500 that landed on the account above.
        $upline = Commission::where('consultant_id', $legOne->id)
            ->where('type', 'bonus')
            ->where('user_id', $student->id)
            ->first();

        $this->assertNotNull($upline, 'The upline row exists.');
        $this->assertNotSame('pending', $upline->status, 'It must not be waiting on a human click.');
        $this->assertContains($upline->status, ['approved', 'paid']);
    }

    /**
     * ⚠️ REVISED 2026-08-25 — the slot is auto-approved too.
     *
     * It used to wait for the run that released it. The owner then removed the
     * Approve step entirely: "Approve button eka ain kranm hari... 2500 and
     * 4000 dekama auto yana widiyta hadann payment eka verify krhama". So both
     * kinds are approved at verification and the only manual step left is Mark
     * paid.
     */
    public function test_the_registrants_own_slot_income_is_auto_approved_too(): void
    {
        $main = $this->owner();
        $legOne = $main->hiddenAccounts()->orderBy('id')->first();

        $this->verify($main->user, $legOne, 1);
        $setMain = $main->fresh()->hiddenAccounts()->orderBy('id')->get()[2];

        $student = User::factory()->create();
        $this->verify($student, $setMain, 1);

        $own = Commission::where('consultant_id', $setMain->id)
            ->where('type', 'bonus')
            ->where('user_id', $student->id)
            ->first();

        $this->assertNotNull($own);
        $this->assertSame('approved', $own->status, 'Approved when its payment was verified.');
    }

    /** Each account is credited once per student, never twice. */
    public function test_no_account_is_credited_twice_for_one_student(): void
    {
        $main = $this->owner();
        $legOne = $main->hiddenAccounts()->orderBy('id')->first();

        $this->verify($main->user, $legOne, 1);
        $setMain = $main->fresh()->hiddenAccounts()->orderBy('id')->get()[2];

        $student = User::factory()->create();
        $this->verify($student, $setMain, 1);

        foreach ([$main, $legOne, $setMain] as $account) {
            $rows = Commission::where('consultant_id', $account->id)
                ->where('type', 'bonus')
                ->where('user_id', $student->id)
                ->count();

            $this->assertLessThanOrEqual(1, $rows, "{$account->scn_code} holds at most one slot row for this student.");
        }
    }

    /**
     * ⚠️ EVERY ACCOUNT IN THE CLIMB EARNS — INCLUDING A SET-MAIN.
     *
     * Nothing can be PLACED into a set-main (the picker hides it), so the climb
     * is the only income it will ever have. Skipping it starved the account:
     * SCN-00106 read LKR 0 in every column while the two accounts beneath it
     * earned normally — "SCN 106 amount tika watila na, eka calculate wela na".
     *
     * It cannot inflate the main's headline, because the headline counts only
     * accounts hanging DIRECTLY off the main, and a set-main does not.
     */
    public function test_a_set_main_earns_from_the_climb_without_inflating_the_headline(): void
    {
        $main = $this->owner();
        $pair = $main->hiddenAccounts()->orderBy('id')->get();

        // A set bought under the first opening account.
        $this->verify($main->user, $pair[0], 2);

        $accounts = $main->fresh()->hiddenAccounts()->orderBy('id')->get();
        [$setMain, $leg2] = [$accounts[2], $accounts[4]];

        $this->assertNull($setMain->serviceGroupLeg(), 'Precondition: it is a set-main.');

        Commission::query()->delete();

        $student = User::factory()->create();
        $this->verify($student, $leg2, 2);

        $slotOn = fn (Consultant $a) => (float) Commission::where('consultant_id', $a->id)
            ->where('type', 'bonus')->sum('amount');

        $this->assertSame((float) self::SLOT, $slotOn($leg2), 'The account the student sits in.');
        $this->assertSame((float) self::SLOT, $slotOn($setMain), 'The set-main above it earns from the climb.');
        $this->assertSame((float) self::SLOT, $slotOn($pair[0]), 'And so does the account the set hangs off.');

        // ⚠️ The main still counts that student ONCE — the whole point of the
        // direct-children rule. Three credits exist, one headline 2,500.
        $response = $this->actingAs($main->user)->get(route('consultant.groups.index'));
        $response->assertOk();

        $tierTotals = $response->viewData('tierTotals');
        $headline = (float) ($tierTotals[1]->total ?? 0) + (float) ($tierTotals[2]->total ?? 0);

        $this->assertSame((float) self::SLOT, $headline, 'One student, one 2,500 on the main.');
    }
}
