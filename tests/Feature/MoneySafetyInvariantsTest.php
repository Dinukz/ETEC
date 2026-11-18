<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\Consultant;
use App\Models\Course;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Payment;
use App\Models\User;
use App\Services\PaymentService;
use App\Services\ServiceGroupFlush;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Invariants that must hold across the 2026-08-23/24 money changes.
 *
 * These are not feature tests — each one pins a property that, if it broke,
 * would move real money wrongly and would be very hard to notice from a screen:
 * paying the same row twice, stranding a row so it can never be settled, or
 * letting held income escape.
 *
 * The changes they guard interact, which is the point of testing them together:
 * a person's MAIN now flushes their whole Service Group while each placement
 * account still flushes its own legs, a half payment defers its slot to the
 * balance, and approval is automatic on several paths.
 */
class MoneySafetyInvariantsTest extends TestCase
{
    use RefreshDatabase;

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

    private function accountant(): User
    {
        return User::where('email', 'accountant@etec.lk')->firstOrFail();
    }

    /** Unflushed slot rows of 2,500 on $account's internal leg $tier. */
    private function seedRows(Consultant $account, int $tier, int $count): void
    {
        $group = app(\App\Services\HiddenAccountService::class)->ensureGroup($account);

        for ($i = 0; $i < $count; $i++) {
            $student = User::factory()->create();
            $student->assignRole('student');

            $seat = GroupMember::create([
                'group_id' => $group->id,
                'user_id' => $student->id,
                'tier' => $tier,
                'position' => ($tier * 10000) + random_int(1, 9000),
                'commission_amount' => 0,
                'joined_at' => now()->subDays(3),
            ]);

            Commission::create([
                'consultant_id' => $account->id,
                'user_id' => $student->id,
                'group_id' => $group->id,
                'group_member_id' => $seat->id,
                'type' => 'bonus',
                'tier' => $tier,
                'amount' => 2500,
                'status' => 'pending',
            ])->forceFill(['created_at' => now()->subDays(3)])->save();
        }
    }

    /**
     * ⚠️ NO ROW IS EVER RELEASED TWICE.
     *
     * The main flushes the owner's rolled-up Service Groups AND each placement
     * account flushes its own internal legs, so one row can satisfy two
     * different pairings. The run is only safe because releasing stamps
     * released_at and the next query cannot see it — if that ever stopped being
     * true, the same money would be paid from two accounts.
     */
    public function test_a_full_run_releases_every_row_at_most_once(): void
    {
        $owner = $this->owner();
        $accounts = $owner->hiddenAccounts()->orderBy('id')->get();

        // Balanced INSIDE the first account as well as across the owner's two
        // groups, so both pairings genuinely compete for the same rows.
        $this->seedRows($accounts[0], 1, 3);
        $this->seedRows($accounts[0], 2, 3);
        $this->seedRows($accounts[1], 1, 3);

        $totalBefore = (float) Commission::where('type', 'bonus')->sum('amount');

        // Run repeatedly — a second run must find nothing left to re-release.
        $paid = 0.0;
        $forfeited = 0.0;

        for ($i = 0; $i < 4; $i++) {
            $result = app(ServiceGroupFlush::class)->runAll();
            $paid += (float) $result['payout'];
            $forfeited += (float) $result['forfeit'];
        }

        $stillOnLegs = (float) Commission::where('type', 'bonus')
            ->whereNull('released_at')
            ->where('status', '!=', 'cancelled')
            ->sum('amount');

        $this->assertSame(
            $totalBefore,
            round($paid + $forfeited + $stillOnLegs, 2),
            'Every rupee is either paid once, forfeited once, or still waiting — never counted twice.',
        );
    }

    /** ⚠️ The preview must promise exactly what a run then pays. */
    public function test_the_preview_matches_the_run_when_both_pairings_compete(): void
    {
        $owner = $this->owner();
        $accounts = $owner->hiddenAccounts()->orderBy('id')->get();

        $this->seedRows($accounts[0], 1, 2);
        $this->seedRows($accounts[0], 2, 2);
        $this->seedRows($accounts[1], 1, 2);

        $preview = app(ServiceGroupFlush::class)->preview();
        $result = app(ServiceGroupFlush::class)->runAll();

        $this->assertSame((float) $preview['payout'], (float) $result['payout'], 'Released figure.');
        $this->assertSame((float) $preview['forfeit'], (float) $result['forfeit'], 'Company figure.');
    }

    /**
     * ⚠️ AN UNPLACED ACCOUNT'S MONEY IS NOT STRANDED.
     *
     * A bought set with no recorded placement has a null owner_group, so it is
     * deliberately in neither of the main's rolled-up legs. It must still be
     * settleable through its own account, or that income could never be paid.
     */
    public function test_income_on_an_unplaced_account_can_still_be_flushed(): void
    {
        $owner = $this->owner();

        app(\App\Services\HiddenAccountService::class)->addAccountSet($owner);
        $orphanLeg = $owner->fresh()->hiddenAccounts()->orderBy('id')->get()[3];

        $this->seedRows($orphanLeg, 1, 1);
        $this->seedRows($orphanLeg, 2, 1);

        // Not in the main's rollup...
        $mainState = app(ServiceGroupFlush::class)->state(
            $owner->fresh(),
            Group::where('consultant_id', $owner->id)->orderBy('id')->firstOrFail(),
            ServiceGroupFlush::defaultCutoff(),
        );

        $this->assertSame(0.0, $mainState['leg1'] + $mainState['leg2'], 'Placement unknown — neither group.');

        // ...but reachable on its own account, so the money is not lost.
        $ownState = app(ServiceGroupFlush::class)->state(
            $orphanLeg->fresh(),
            Group::where('consultant_id', $orphanLeg->id)->orderBy('id')->firstOrFail(),
            ServiceGroupFlush::defaultCutoff(),
        );

        $this->assertSame(5000.0, $ownState['leg1'] + $ownState['leg2'], 'Still on its own legs.');
    }

    /**
     * ⚠️ A HALF PAYER SETTLED UNDER THE OLD RULE IS NOT TOPPED UP TWICE.
     *
     * Students who half-paid before 2026-08-23 already have a seat and a slot
     * commission from that payment. Settling their balance now must not award a
     * second 2,500 — placeInto() reuses the seat and $alreadySettled blocks it.
     */
    public function test_settling_a_legacy_half_payment_awards_no_second_slot(): void
    {
        $account = $this->owner()->hiddenAccounts()->first();
        $course = Course::where('status', 'published')->firstOrFail();

        $student = User::factory()->create();
        $student->assignRole('student');

        $half = Payment::create([
            'payment_code' => 'PYM-'.Str::random(8),
            'user_id' => $student->id,
            'course_id' => $course->id,
            'consultant_id' => $account->id,
            'requested_tier' => 1,
            'amount' => 18000,
            'currency' => 'LKR',
            'method' => 'bank_transfer',
            'payment_type' => 'half',
            'status' => 'pending',
        ]);

        app(PaymentService::class)->verify($half, $this->accountant());

        // Simulate the OLD rule: the half payment had already created the seat
        // and its slot commission.
        $group = app(\App\Services\HiddenAccountService::class)->ensureGroup($account);
        $seat = GroupMember::create([
            'group_id' => $group->id,
            'user_id' => $student->id,
            'tier' => 1,
            'position' => 1,
            'commission_amount' => 2500,
            'joined_at' => now(),
        ]);
        Commission::create([
            'consultant_id' => $account->id,
            'user_id' => $student->id,
            'group_id' => $group->id,
            'group_member_id' => $seat->id,
            'payment_id' => $half->id,
            'type' => 'bonus',
            'tier' => 1,
            'amount' => 2500,
            'status' => 'pending',
        ]);

        $slotsBefore = Commission::where('consultant_id', $account->id)->where('type', 'bonus')->count();

        $balance = Payment::create([
            'payment_code' => 'PYM-'.Str::random(8),
            'user_id' => $student->id,
            'course_id' => $course->id,
            'consultant_id' => $account->id,
            'requested_tier' => 1,
            'amount' => 10000,
            'currency' => 'LKR',
            'method' => 'bank_transfer',
            'payment_type' => 'balance',
            'status' => 'pending',
        ]);

        app(PaymentService::class)->verify($balance, $this->accountant());

        $this->assertSame(
            $slotsBefore,
            Commission::where('consultant_id', $account->id)->where('type', 'bonus')->count(),
            'The existing seat was reused — no second 2,500 for the same student.',
        );
    }

    /** A half payment awards exactly one direct bonus, and the balance adds none. */
    public function test_the_direct_bonus_is_never_awarded_twice_across_half_and_balance(): void
    {
        $account = $this->owner()->hiddenAccounts()->first();
        $course = Course::where('status', 'published')->firstOrFail();

        $student = User::factory()->create();
        $student->assignRole('student');

        foreach (['half' => 18000, 'balance' => 10000] as $type => $amount) {
            $payment = Payment::create([
                'payment_code' => 'PYM-'.Str::random(8),
                'user_id' => $student->id,
                'course_id' => $course->id,
                'consultant_id' => $account->id,
                'requested_tier' => 1,
                'amount' => $amount,
                'currency' => 'LKR',
                'method' => 'bank_transfer',
                'payment_type' => $type,
                'status' => 'pending',
            ]);

            app(PaymentService::class)->verify($payment, $this->accountant());
        }

        $this->assertSame(
            1,
            Commission::where('consultant_id', $account->id)->where('type', 'referral')->count(),
            'One student brought in, one bonus.',
        );
    }

    /**
     * ⚠️ HELD INCOME STILL CANNOT LEAVE.
     *
     * The direct bonus is now approved the instant it is recorded, and
     * ConsultantFinanceService::hold() only touches PENDING rows — so a
     * consultant restricted afterwards holds an approved bonus that hold()
     * cannot claw back. It must still be impossible to PAY it.
     */
    public function test_an_approved_bonus_cannot_be_paid_while_the_consultant_is_restricted(): void
    {
        $owner = $this->owner();
        $account = $owner->hiddenAccounts()->first();
        $course = Course::where('status', 'published')->firstOrFail();

        $student = User::factory()->create();
        $student->assignRole('student');

        $payment = Payment::create([
            'payment_code' => 'PYM-'.Str::random(8),
            'user_id' => $student->id,
            'course_id' => $course->id,
            'consultant_id' => $account->id,
            'requested_tier' => 1,
            'amount' => 28000,
            'currency' => 'LKR',
            'method' => 'bank_transfer',
            'payment_type' => 'full',
            'status' => 'pending',
        ]);

        app(PaymentService::class)->verify($payment, $this->accountant());

        $bonus = Commission::where('consultant_id', $account->id)->where('type', 'referral')->firstOrFail();
        $this->assertSame('approved', $bonus->status, 'Approved on arrival.');

        // The consultant is restricted afterwards.
        $owner->update(['finance_status' => 'restricted']);

        $this->actingAs($this->accountant())
            ->patch(route('commissions.paid', $bonus))
            ->assertRedirect();

        $this->assertSame(
            'approved',
            $bonus->fresh()->status,
            'Still approved, NOT paid — the payout gate is what stops restricted money leaving.',
        );
    }

    /** ⚠️ Auto-approval must never bypass a hold. */
    public function test_a_held_consultants_bonus_is_not_auto_approved(): void
    {
        $owner = $this->owner();
        $owner->update(['finance_status' => 'restricted']);

        $account = $owner->fresh()->hiddenAccounts()->first();
        $course = Course::where('status', 'published')->firstOrFail();

        $student = User::factory()->create();
        $student->assignRole('student');

        $payment = Payment::create([
            'payment_code' => 'PYM-'.Str::random(8),
            'user_id' => $student->id,
            'course_id' => $course->id,
            'consultant_id' => $account->id,
            'requested_tier' => 1,
            'amount' => 28000,
            'currency' => 'LKR',
            'method' => 'bank_transfer',
            'payment_type' => 'full',
            'status' => 'pending',
        ]);

        app(PaymentService::class)->verify($payment, $this->accountant());

        $this->assertSame(
            0,
            Commission::where('consultant_id', $account->id)->where('status', 'approved')->count(),
            'A restricted consultant earns nothing approvable, automatically or otherwise.',
        );
    }

    /**
     * ⚠️ THE HEADLINE MUST NOT GO QUIETLY SHORT.
     *
     * Income on an account with no recorded placement sits in neither Service
     * Group, so the two headline figures cannot include it. It has to be
     * reported somewhere, or the top of the page understates what the
     * consultant is owed and reads as money that went missing.
     */
    public function test_unplaced_income_is_reported_separately_on_the_headline(): void
    {
        $owner = $this->owner();

        app(\App\Services\HiddenAccountService::class)->addAccountSet($owner);
        $orphanLeg = $owner->fresh()->hiddenAccounts()->orderBy('id')->get()[3];

        $this->seedRows($orphanLeg, 1, 1);
        $this->seedRows($orphanLeg, 2, 1);

        $response = $this->actingAs($owner->user)->get(route('consultant.groups.index'));
        $response->assertOk();

        $this->assertSame(0.0, (float) $response->viewData('pendingLeg1'));
        $this->assertSame(0.0, (float) $response->viewData('pendingLeg2'));
        $this->assertSame(
            5000.0,
            (float) $response->viewData('pendingUngrouped'),
            'Reported on its own rather than dropped.',
        );

        $response->assertSee('no recorded placement');
    }

    /**
     * ⚠️⚠️ INVERTED 2026-08-27 — THE TWO LOCKS NO LONGER MEAN THE SAME THING.
     *
     * This test used to assert the opposite, and its reasoning was: isUnlocked()
     * is checked against the account BEING flushed, which for a rollup is the
     * main, so the main released and forfeited money sitting on individually
     * locked accounts, defeating both locks at once.
     *
     * That still holds for an ADMIN FORCE-SHUT, which is pinned separately in
     * LockedGroupIsStillVisibleTest. It does NOT hold for the automatic
     * 12-student chain any more — the owner ruled that such an account must
     * still feed the main:
     *
     *   "Meke lock eka hari eth e account walin hamben ewa mekat add krnn e
     *    widiyt hadann. main eka flush out wenn one system run ekedi."
     *   = the lock is right, but what those accounts earn must be added to the
     *     main, and the main must flush it out at the System Run.
     *
     * The account's own flush is still gated, so "meke lock eka hari" holds:
     * the lock decides when the account can pay ITSELF, not whether its income
     * ever reaches the person who owns it.
     *
     * ⚠️ SECOND SETTING of this rule. Inverted rather than deleted, because git
     * history alone will not say which way is current — same convention as the
     * upline-recipient rule. Require an explicit, numbers-based confirmation
     * before flipping it a third time.
     */
    /**
     * ⚠️⚠️⚠️ THIRD SETTING OF THIS RULE IN ONE DAY — read the history before
     * touching it.
     *
     *   08-24: the main honoured every lock, so a chain-locked account's income
     *          was never released by anyone.
     *   08-27 (morning): the owner ruled that such income must still reach them
     *          — "meke lock eka hari eth e account walin hamben ewa mekat add
     *          krnn" — implemented by letting the MAIN release it on the
     *          account's behalf.
     *   08-27 (evening): the owner made the ACCOUNT the settlement unit, so the
     *          main no longer settles anybody. The morning requirement is kept
     *          by a different route: the 12-student chain no longer BLOCKS a
     *          flush (only an admin force-shut does), so the account releases
     *          its own income — subject, like every account, to both of its
     *          sides being able to pair.
     *
     * So a chain-locked account is paid, and an unbalanced one is not, which is
     * exactly what the owner asked for with SCN-00102: "eka group ehek wath
     * stage samana nathiw system run yann ba."
     */
    public function test_a_chain_locked_account_settles_its_own_balanced_income(): void
    {
        $owner = $this->owner();
        $accounts = $owner->hiddenAccounts()->orderBy('id')->get();

        // #1 is open; #2 is gated behind it until #1 has enough students.
        // Both sides seeded, so the locked account can actually pair.
        $this->seedRows($accounts[1], 1, 3);
        $this->seedRows($accounts[1], 2, 3);

        $staging = app(\App\Services\ServiceGroupStaging::class);
        $config = \App\Services\ServiceGroupStaging::config();

        $this->assertFalse(
            $staging->isUnlocked($accounts[1]->fresh(), $config),
            'Precondition: the second account is chain-locked.',
        );

        $lockedRowIds = Commission::where('consultant_id', $accounts[1]->id)->pluck('id');

        app(ServiceGroupFlush::class)->runAll();

        $this->assertGreaterThan(
            0,
            Commission::whereIn('id', $lockedRowIds)->whereNotNull('released_at')->count(),
            'The 12-student chain does not withhold the money — it only decides when the account stands alone.',
        );
    }

    /** An admin's deliberate closure still stops the money dead, by every route. */
    public function test_a_force_shut_account_settles_nothing(): void
    {
        $owner = $this->owner();
        $accounts = $owner->hiddenAccounts()->orderBy('id')->get();

        $accounts[0]->forceFill(['manual_lock_override' => Consultant::LOCK_FORCED_SHUT])->save();

        $this->seedRows($accounts[0], 1, 3);
        $this->seedRows($accounts[0], 2, 3);

        $rowIds = Commission::where('consultant_id', $accounts[0]->id)->pluck('id');

        app(ServiceGroupFlush::class)->runAll();

        $this->assertSame(
            0,
            Commission::whereIn('id', $rowIds)->whereNotNull('released_at')->count(),
            'A closed account is not paid out, however well it pairs.',
        );
        $this->assertSame(
            0,
            Commission::whereIn('id', $rowIds)->where('status', 'cancelled')->count(),
            'And nothing on it is forfeited to the company either.',
        );
    }

    /**
     * ⚠️ AN ACCOUNT THE RUN DRAINED MOVES UP THE LADDER TOO.
     *
     * A rollup flush settles rows that LIVE on the placement accounts, so they
     * empty — but only the main's stage was advancing. The drained accounts sat
     * frozen on 1-to-1 forever, still advertising a pairing that can never
     * happen, because the main reaches their money first every time.
     *
     * Reported after a real run (2026-08-25): "sub account walin flush out
     * wela, eth amount eka add wela na, stage update wel na."
     */
    /**
     * ⚠️ RE-AIMED AT THE ACCOUNT 2026-08-27. The guarantee is unchanged — an
     * account that a run empties must move UP the ladder rather than sit frozen
     * on 1-to-1 forever — but the main no longer drains anybody, so each account
     * now earns that advance through its own flush. See
     * EachAccountPairsItsOwnSidesTest for why the model changed.
     *
     * Both legs are seeded, because an account only settles when its two sides
     * can pair.
     */
    public function test_accounts_emptied_by_a_run_advance_their_stage(): void
    {
        $owner = $this->owner();
        $accounts = $owner->hiddenAccounts()->orderBy('id')->get();

        $accounts[1]->forceFill(['manual_lock_override' => Consultant::LOCK_FORCED_OPEN])->save();

        // 2,500 on each side of each account — one clean 1-to-1 apiece.
        foreach ([$accounts[0], $accounts[1]] as $account) {
            $this->seedRows($account, 1, 1);
            $this->seedRows($account, 2, 1);
        }

        $owner->forceFill(['lifetime_pair_stage' => \App\Services\ServiceGroupStaging::ONE_TIME_STAGES])->save();

        $flusher = app(ServiceGroupFlush::class);

        $this->assertSame(0, $flusher->stageOf($accounts[0]->fresh()), 'Precondition: bottom of the ladder.');

        $result = $flusher->runAll();

        $this->assertSame(10000.0, (float) $result['payout'], 'Two accounts, 5,000 each at 1-to-1.');

        foreach ([$accounts[0], $accounts[1]] as $drained) {
            $this->assertSame(
                0.0,
                (float) Commission::where('consultant_id', $drained->id)->whereNull('released_at')->sum('amount'),
                "{$drained->scn_code} was emptied by the run.",
            );
            $this->assertSame(
                1,
                $flusher->stageOf($drained->fresh()),
                "{$drained->scn_code} moved up a stage instead of staying frozen on 1-to-1.",
            );
        }
    }

    /** ...but the ladder is still capped — it cannot run past the repeating stage. */
    public function test_a_drained_accounts_stage_is_capped(): void
    {
        $owner = $this->owner();
        $accounts = $owner->hiddenAccounts()->orderBy('id')->get();

        $accounts[1]->forceFill(['manual_lock_override' => Consultant::LOCK_FORCED_OPEN])->save();

        $top = \App\Services\ServiceGroupStaging::ONE_TIME_STAGES;

        foreach ([$accounts[0], $accounts[1]] as $a) {
            $a->forceFill(['lifetime_pair_stage' => $top])->save();
        }
        $owner->forceFill(['lifetime_pair_stage' => $top])->save();

        $this->seedRows($accounts[0], 1, 3);
        $this->seedRows($accounts[1], 1, 3);

        app(ServiceGroupFlush::class)->runAll();

        foreach ([$accounts[0], $accounts[1]] as $a) {
            $this->assertSame($top, app(ServiceGroupFlush::class)->stageOf($a->fresh()));
        }
    }
}
