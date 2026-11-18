<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\Consultant;
use App\Models\Course;
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
 * ⚠️⚠️ DOUBLY STALE as of 2026-08-16 — read this before the 2026-08-09/13
 * history below, which it supersedes twice over.
 *
 * The whole 1-to-1 (5,000) / 2-to-2 (10,000) / 3-to-3 (15,000) DISCOUNT
 * PROGRESSION this file is built around is now RETIRED. Every flush, for
 * every account, every time, needs the full 7,500 on each side — there is no
 * cheaper first or second release any more, and `lifetime_pair_stage` is no
 * longer read for this purpose (ServiceGroupStaging::stepFor(),
 * ONE_TIME_STAGES are dead in the live path). This was not a preference
 * change — the discount caused a real bug: a person's accounts are processed
 * by id within one System Run and shared ONE `lifetime_pair_stage`, so
 * whichever account flushed first consumed the cheap stage and could silently
 * raise the threshold under a second account of the same person before it was
 * even checked. See ServiceGroupFlush's class docblock for the full account,
 * and FixedFlushThresholdTest for current coverage including the specific
 * order-independence case.
 *
 * What still holds, unrelated to the stage progression: the UNCAPPED 4,000
 * direct bonus per student, and a consultant's second account releasing
 * nothing until the first has 12 students.
 *
 * Left as-is rather than rewritten blind, same reasoning as the 2026-08-13
 * note it replaces: the local database has been unavailable throughout this
 * work, so corrected expectations could not be verified by execution, and
 * guessed numbers in a money test are worse than visibly stale ones.
 *
 * ----- Original 2026-08-09/13 note, kept for history ------------------------
 * The staged service-group release confirmed by the owner on 2026-08-09:
 *
 *   1-to-1  →  5,000   once per consultant lifetime
 *   2-to-2  → 10,000   once per consultant lifetime
 *   3-to-3  → 15,000   repeating forever
 *
 * ⚠️ THE AMOUNTS ABOVE ARE STILL CURRENT. THE TIMING IS NOT (2026-08-13).
 *
 * Release is no longer automatic. A balanced pair used to become payable the
 * moment it was placed; the consultant now has to FLUSH their two legs by hand,
 * which releases the matched rows and FORFEITS everything else on both legs to
 * company revenue (see ServiceGroupFlush and ServiceGroupFlushTest).
 *
 * So any test below that verifies a payment and then expects money to be
 * approvable without a flush is now testing the old contract and will fail.
 * That was expected then — it did NOT mean the engine drifted.
 */
class StagedServiceIncomeTest extends TestCase
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

    private function consultant(): Consultant
    {
        return User::where('email', 'consultant@etec.lk')->firstOrFail()->consultant;
    }

    /** Register + verify one student on a specific leg of a specific account. */
    private function addStudent(Consultant $account, int $leg): void
    {
        $accountant = User::where('email', 'accountant@etec.lk')->firstOrFail();
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

        app(PaymentService::class)->verify($payment, $accountant);
    }

    /** Add $n students to each leg of $account. */
    private function addBalanced(Consultant $account, int $n): void
    {
        for ($i = 0; $i < $n; $i++) {
            $this->addStudent($account, 1);
            $this->addStudent($account, 2);
        }
    }

    /** Everything RECORDED as service-group income — visible to the consultant straight away. */
    private function slotIncomeOn(Consultant $account): float
    {
        return (float) Commission::where('consultant_id', $account->id)->where('type', 'bonus')->sum('amount');
    }

    /** What an admin could actually APPROVE — i.e. what the staged release has cleared. */
    private function approvableSlotOn(Consultant $account): float
    {
        $this->approveAllCommissions();

        return (float) Commission::where('consultant_id', $account->id)
            ->where('type', 'bonus')
            ->whereIn('status', ['approved', 'paid'])
            ->sum('amount');
    }

    private function directIncomeOn(Consultant $account): float
    {
        return (float) Commission::where('consultant_id', $account->id)->where('type', 'referral')->sum('amount');
    }

    protected function approveAllCommissions(): void
    {
        $admin = User::where('email', 'admin@etec.lk')->firstOrFail();
        $this->actingAs($admin)->patch(route('commissions.approve-all'));
    }

    // ----- The owner's worked example, stage by stage ------------------------

    /**
     * The headline behaviour the owner asked for: the 2,500 is VISIBLE
     * immediately, it just cannot be paid out until the legs balance.
     */
    public function test_a_lone_student_records_2500_immediately_but_it_is_not_payable(): void
    {
        $account = $this->consultant()->hiddenAccounts()->first();

        $this->addStudent($account, 1); // leg 1 only — nothing to pair with

        $this->assertSame(2500.0, $this->slotIncomeOn($account), 'The 2,500 must SHOW straight away.');
        $this->assertSame(4000.0, $this->directIncomeOn($account), 'The direct bonus never waits.');
        $this->assertSame(0.0, $this->approvableSlotOn($account), 'But it cannot be paid until the group balances.');
    }

    public function test_one_to_one_makes_5000_payable_and_is_consumed_for_life(): void
    {
        $account = $this->consultant()->hiddenAccounts()->first();

        $this->addBalanced($account, 1); // 1 + 1

        $this->assertSame(5000.0, $this->slotIncomeOn($account), '1-to-1 = 2 students x 2,500.');
        $this->assertSame(8000.0, $this->directIncomeOn($account), '2 students x 4,000 direct.');
        $this->assertSame(5000.0, $this->approvableSlotOn($account), 'Balanced, so payable.');
        $this->assertSame(1, (int) $this->consultant()->fresh()->lifetime_pair_stage);
    }

    public function test_two_to_two_makes_10000_more_payable(): void
    {
        $account = $this->consultant()->hiddenAccounts()->first();

        $this->addBalanced($account, 1); // 1-to-1  →  5,000
        $this->addBalanced($account, 2); // 2-to-2  → 10,000

        $this->assertSame(15000.0, $this->slotIncomeOn($account), '5,000 + 10,000 recorded.');
        $this->assertSame(24000.0, $this->directIncomeOn($account), '6 students x 4,000.');
        $this->assertSame(15000.0, $this->approvableSlotOn($account), 'Both stages balanced.');
        $this->assertSame(2, (int) $this->consultant()->fresh()->lifetime_pair_stage);
    }

    public function test_three_to_three_pays_15000_and_then_repeats(): void
    {
        $account = $this->consultant()->hiddenAccounts()->first();

        $this->addBalanced($account, 1); //  5,000   (2 students)
        $this->addBalanced($account, 2); // 10,000   (6 students)
        $this->addBalanced($account, 3); // 15,000   (12 students)

        $this->assertSame(30000.0, $this->approvableSlotOn($account));

        $this->addBalanced($account, 3); // 15,000 again — repeating

        $this->assertSame(45000.0, $this->approvableSlotOn($account), '3-to-3 repeats forever.');
        $this->assertSame(2, (int) $this->consultant()->fresh()->lifetime_pair_stage, 'Still 2 — the repeat stage consumes nothing.');
    }

    /**
     * A partial stage shows its money but pays none of it — the exact case in
     * the owner's screenshot (Service Group 1 had 3, Service Group 2 had 2).
     */
    public function test_a_half_finished_stage_shows_its_money_but_pays_none_of_it(): void
    {
        $account = $this->consultant()->hiddenAccounts()->first();

        $this->addBalanced($account, 1); // 1-to-1 done → 5,000 payable
        $this->addBalanced($account, 1); // only 1 of the 2 pairs the 2-to-2 needs

        $this->assertSame(10000.0, $this->slotIncomeOn($account), 'All four students show their 2,500.');
        $this->assertSame(5000.0, $this->approvableSlotOn($account), 'But only the completed 1-to-1 is payable.');
    }

    /** The "how many more do I need" figure the owner asked to see on screen. */
    public function test_progress_reports_exactly_how_many_more_students_each_leg_needs(): void
    {
        $account = $this->consultant()->hiddenAccounts()->first();
        $staging = app(ServiceGroupStaging::class);
        $group = \App\Models\Group::where('consultant_id', $account->id)->firstOrFail();

        $this->addBalanced($account, 1); // 1-to-1 done; next stage is 2-to-2 (target depth 3)
        $this->addStudent($account, 1);  // SG1 = 2, SG2 = 1

        $progress = $staging->progressFor($account->fresh(), $group->fresh());

        $this->assertSame(2, $progress['leg1']);
        $this->assertSame(1, $progress['leg2']);
        $this->assertSame('2-to-2', $progress['next_label']);
        $this->assertSame(3, $progress['next_target']);
        $this->assertSame(1, $progress['need_leg1'], 'Service Group 1 needs 1 more to reach 3.');
        $this->assertSame(2, $progress['need_leg2'], 'Service Group 2 needs 2 more to reach 3.');
        $this->assertSame(10000.0, $progress['next_amount'], '2 pairs x 2 students x 2,500.');
        $this->assertFalse($progress['releasable_now']);
    }

    // ----- Lifetime scope ----------------------------------------------------

    public function test_a_second_account_skips_the_one_time_stages_and_starts_at_three_to_three(): void
    {
        $consultant = $this->consultant();
        [$first, $second] = $consultant->hiddenAccounts()->orderBy('id')->take(2)->get()->all();

        // Burn both one-time stages on the first account, and take it to 12
        // students so the second account unlocks.
        $this->addBalanced($first, 1);
        $this->addBalanced($first, 2);
        $this->addBalanced($first, 3);
        $this->assertSame(12, app(ServiceGroupStaging::class)->studentsOn($first->id));

        // 2 pairs on the second account — enough for a 1-to-1 AND a 2-to-2 if
        // those were still available, but they are not.
        $this->addBalanced($second, 2);
        $this->assertSame(0.0, $this->approvableSlotOn($second), 'Both one-time stages are already spent for this person.');

        $this->addBalanced($second, 1); // now 3 pairs
        $this->assertSame(15000.0, $this->approvableSlotOn($second), 'Straight to 3-to-3.');
    }

    // ----- The account unlock -------------------------------------------------

    public function test_a_second_account_releases_nothing_until_the_first_has_12_students(): void
    {
        $consultant = $this->consultant();
        [$first, $second] = $consultant->hiddenAccounts()->orderBy('id')->take(2)->get()->all();

        // First account gets only 6 students — under the 12 needed to unlock.
        $this->addBalanced($first, 3);
        $this->assertSame(6, app(ServiceGroupStaging::class)->studentsOn($first->id));

        $this->addBalanced($second, 3); // plenty of pairs, but the account is locked

        $this->assertSame(0.0, $this->approvableSlotOn($second), 'A locked account pays out no group income.');
        $this->assertSame(15000.0, $this->slotIncomeOn($second), 'The money is still recorded and visible, though.');
        $this->assertSame(
            24000.0,
            $this->directIncomeOn($second),
            'And the direct bonus still pays — it follows the person, not the account.',
        );
    }

    public function test_the_lock_lifts_and_back_income_releases_once_the_first_account_reaches_12(): void
    {
        $consultant = $this->consultant();
        [$first, $second] = $consultant->hiddenAccounts()->orderBy('id')->take(2)->get()->all();

        $this->addBalanced($first, 3);   // 6 students — still locked
        $this->addBalanced($second, 3);  // recorded, but not payable
        $this->assertSame(0.0, $this->approvableSlotOn($second));

        // Take the first account to 12 — this unlocks the second.
        $this->addBalanced($first, 3);

        $this->assertGreaterThan(
            0.0,
            $this->approvableSlotOn($second),
            'Once unlocked, the pairs that were already balanced become payable — no new placement needed.',
        );
    }

    /**
     * A group that balanced BEFORE staging existed still has released_depth 0.
     * advance() must catch it up on read, or upgrading the app would strand
     * income that was already legitimately earned.
     */
    public function test_a_group_that_balanced_before_this_feature_is_caught_up_on_read(): void
    {
        $account = $this->consultant()->hiddenAccounts()->first();
        $this->addBalanced($account, 1);

        // Simulate the pre-upgrade state: the money exists, the counter does not.
        \App\Models\Group::where('consultant_id', $account->id)->update(['released_depth' => 0]);
        Consultant::whereKey($this->consultant()->id)->update(['lifetime_pair_stage' => 0]);

        $this->assertSame(5000.0, $this->approvableSlotOn($account), 'Already-earned income must not be stranded by the upgrade.');
    }

    // ----- The direct bonus is uncapped ---------------------------------------

    public function test_the_direct_bonus_has_no_cap(): void
    {
        $account = $this->consultant()->hiddenAccounts()->first();

        // 20 students on one leg — far past the old 3-per-leg earning cap,
        // and never balanced, so group income stays at zero throughout.
        for ($i = 0; $i < 20; $i++) {
            $this->addStudent($account, 1);
        }

        $this->assertSame(80000.0, $this->directIncomeOn($account), '20 x 4,000 — the old 6-per-account cap is gone.');
        $this->assertSame(50000.0, $this->slotIncomeOn($account), 'All 20 record their 2,500...');
        $this->assertSame(0.0, $this->approvableSlotOn($account), '...but none of it is payable with nothing to pair against.');
    }

    // ----- Idempotency ---------------------------------------------------------

    public function test_re_verifying_never_releases_a_stage_twice(): void
    {
        $account = $this->consultant()->hiddenAccounts()->first();
        $this->addBalanced($account, 1);

        $before = $this->slotIncomeOn($account);
        $stageBefore = (int) $this->consultant()->fresh()->lifetime_pair_stage;

        // Replay the engine over every verified payment, as the backfill does.
        foreach (Payment::where('status', 'verified')->get() as $payment) {
            app(\App\Services\CommissionService::class)->handleVerifiedPayment($payment);
        }

        $this->assertSame($before, $this->slotIncomeOn($account), 'Re-running settles nothing twice.');
        $this->assertSame($stageBefore, (int) $this->consultant()->fresh()->lifetime_pair_stage, 'And burns no extra lifetime stage.');
    }
}
