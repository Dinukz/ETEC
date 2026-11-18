<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\CompanyRevenue;
use App\Models\Consultant;
use App\Models\ConsultantFinancePeriod;
use App\Models\Course;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\Student;
use App\Models\User;
use App\Services\ConsultantFinanceService;
use App\Services\PaymentService;
use App\Support\FinanceWeek;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The weekly student-quota engine: active -> restricted -> suspended ->
 * (admin) -> active. Every rule here was explicitly confirmed by the owner —
 * see the consultant-weekly-quota memory entry.
 *
 * Quota is fixed at 2/week and grace at 5 weeks for every test, so the exact
 * boundaries (which week restricts, which week suspends) are unambiguous.
 */
class ConsultantFinanceComplianceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Setting::set('finance_week_start_day', '2', 'integer', 'finance'); // Tuesday
        Setting::set('finance_week_quota', '2', 'integer', 'finance');
        Setting::set('finance_grace_weeks', '5', 'integer', 'finance');
        FinanceWeek::forget();

        // The seeder gives the sample consultant a `created_at` of "now" (test
        // run time), which would make evaluate()'s walk-forward start AFTER
        // every backdated period these tests use, short-circuiting straight to
        // checkOpenPeriodRelease() and never exercising the state machine at
        // all. Backdate well past every fixture's oldest period (minus(6)).
        $this->consultant()->forceFill(['created_at' => now()->subWeeks(20)])->save();
    }

    private function consultant(): Consultant
    {
        return User::where('email', 'consultant@etec.lk')->firstOrFail()->consultant;
    }

    /**
     * A verified student registered under $account, with `created_at`
     * (both students AND payments) backdated to $when — the quota counts the
     * REGISTRATION date, not when the payment happened to be verified.
     */
    private function verifiedStudentAt(Consultant $account, \DateTimeInterface $when): Payment
    {
        $accountant = User::where('email', 'accountant@etec.lk')->firstOrFail();
        $user = User::factory()->create(['created_at' => $when]);

        $student = Student::create([
            'user_id' => $user->id, 'student_code' => 'SRN'.Str::random(6),
            'status' => 'active', 'enrolled_at' => $when,
        ]);
        $student->forceFill(['created_at' => $when])->save();

        $payment = Payment::create([
            'payment_code' => 'PYM'.Str::random(6), 'user_id' => $user->id, 'course_id' => Course::first()->id,
            'consultant_id' => $account->id, 'requested_tier' => 1, 'amount' => 28000, 'currency' => 'LKR',
            'method' => 'bank_transfer', 'payment_type' => 'full', 'status' => 'pending', 'paid_at' => $when,
        ]);

        app(PaymentService::class)->verify($payment, $accountant);

        return $payment->fresh();
    }

    private function service(): ConsultantFinanceService
    {
        return app(ConsultantFinanceService::class);
    }

    protected function approveAllCommissions(): void
    {
        $accountant = User::where('email', 'accountant@etec.lk')->firstOrFail();
        $this->actingAs($accountant)->patch(route('commissions.approve-all'));
    }

    // ----- The period boundary, end to end (not just the unit test) --------

    public function test_meeting_the_quota_keeps_a_consultant_active(): void
    {
        $consultant = $this->consultant();
        $period = FinanceWeek::current()->minus(1);

        $this->verifiedStudentAt($consultant, $period->start->addDay());
        $this->verifiedStudentAt($consultant, $period->start->addDays(2));

        $status = $this->service()->evaluate($consultant->fresh());

        $this->assertSame('active', $status);
        $this->assertSame(0, Commission::whereNotNull('held_at')->count());
    }

    // ----- Restriction ------------------------------------------------------

    /**
     * ⚠️ RESTRICTS, BUT NO LONGER HOLDS (2026-09-15). This asserted the
     * opposite until the owner reported that the Tuesday boundary made every
     * restricted consultant's Service Groups read LKR 0 — see
     * WeeklyRestrictionDoesNotZeroIncomeTest for the full rule.
     */
    public function test_missing_the_quota_in_a_closed_period_restricts_without_hiding_income(): void
    {
        $consultant = $this->consultant();
        $period = FinanceWeek::current()->minus(1);

        // Only 1 of the required 2.
        $this->verifiedStudentAt($consultant, $period->start->addDay());

        $status = $this->service()->evaluate($consultant->fresh());

        $this->assertSame('restricted', $status);

        $fresh = $consultant->fresh();
        $this->assertSame('restricted', $fresh->finance_status);
        $this->assertSame($period->key(), $fresh->finance_restricted_period->toDateString());

        $this->assertSame(
            0,
            Commission::whereIn('consultant_id', $consultant->accountIds())->whereNotNull('held_at')->count(),
            'The restriction must not hide the money — the payout gates stop it instead.',
        );
    }

    /**
     * The headline safety property: held money cannot be approved even if
     * an accountant clicks Approve All. total_earned must stay untouched.
     */
    public function test_held_income_cannot_be_approved_even_via_approve_all(): void
    {
        $consultant = $this->consultant();
        $period = FinanceWeek::current()->minus(1);

        $this->verifiedStudentAt($consultant, $period->start->addDay()); // only 1 — misses quota
        $this->service()->evaluate($consultant->fresh());

        $this->assertSame('restricted', $consultant->fresh()->finance_status);

        $this->approveAllCommissions();

        $this->assertSame(0.0, (float) $consultant->fresh()->total_earned, 'Held money must not be credited by Approve All.');
        $this->assertSame(0, Commission::where('status', 'approved')->count());
    }

    // ----- Release ------------------------------------------------------------

    public function test_meeting_the_quota_again_releases_the_restriction_and_the_hold(): void
    {
        $consultant = $this->consultant();
        $restrictingPeriod = FinanceWeek::current()->minus(2);
        $recoveryPeriod = FinanceWeek::current()->minus(1);

        $this->verifiedStudentAt($consultant, $restrictingPeriod->start->addDay()); // misses (1 of 2)
        $this->service()->evaluate($consultant->fresh());
        $this->assertSame('restricted', $consultant->fresh()->finance_status);

        $this->verifiedStudentAt($consultant, $recoveryPeriod->start->addDay());
        $this->verifiedStudentAt($consultant, $recoveryPeriod->start->addDays(2)); // meets (2 of 2)

        $status = $this->service()->evaluate($consultant->fresh());

        $this->assertSame('active', $status);
        $this->assertNull($consultant->fresh()->finance_restricted_period);
        $this->assertSame(0, Commission::whereIn('consultant_id', $consultant->accountIds())->whereNotNull('held_at')->count());

        // Releasing must move NO counter — the money is credited only when
        // actually approved, exactly once, and must equal what was actually approved.
        $this->assertSame(0.0, (float) $consultant->fresh()->total_earned);
        $this->approveAllCommissions();

        $approvedTotal = (float) Commission::whereIn('consultant_id', $consultant->accountIds())
            ->where('status', 'approved')->sum('amount');

        $this->assertGreaterThan(0.0, $approvedTotal);
        $this->assertSame(round($approvedTotal, 2), round((float) $consultant->fresh()->total_earned, 2));
    }

    /** The still-OPEN current period can release immediately — no need to wait for it to close. */
    public function test_meeting_the_quota_in_the_still_open_current_period_releases_immediately(): void
    {
        $consultant = $this->consultant();
        $restrictingPeriod = FinanceWeek::current()->minus(1);

        $this->verifiedStudentAt($consultant, $restrictingPeriod->start->addDay());
        $this->service()->evaluate($consultant->fresh());
        $this->assertSame('restricted', $consultant->fresh()->finance_status);

        // Two students TODAY, in the open current period.
        $this->verifiedStudentAt($consultant, now());
        $this->verifiedStudentAt($consultant, now());

        $status = $this->service()->evaluate($consultant->fresh());

        $this->assertSame('active', $status, 'The open period must be able to release without waiting for it to close.');
    }

    // ----- Grace and suspension — the exact off-by-one ------------------------

    public function test_restricted_stays_restricted_through_the_grace_window_then_suspends_on_the_next_miss(): void
    {
        $consultant = $this->consultant();

        // P0 (6 periods ago) misses -> restricts. P1-P4 (grace) miss -> stays
        // restricted. P5 (the 6th consecutive miss, since grace = 5) -> suspends.
        for ($i = 6; $i >= 1; $i--) {
            $period = FinanceWeek::current()->minus($i);
            $this->verifiedStudentAt($consultant, $period->start->addDay()); // 1 of 2 required — always misses
        }

        $status = $this->service()->evaluate($consultant->fresh());

        $this->assertSame('suspended', $status);
        $this->assertNotNull($consultant->fresh()->finance_suspended_at);
    }

    public function test_exactly_grace_weeks_of_misses_is_not_yet_enough_to_suspend(): void
    {
        $consultant = $this->consultant();

        // Only 5 misses total (P0 restricts, P1-P4 are the 4 grace weeks after
        // it) — one fewer than the suspend test above. Must still be restricted.
        for ($i = 5; $i >= 1; $i--) {
            $period = FinanceWeek::current()->minus($i);
            $this->verifiedStudentAt($consultant, $period->start->addDay());
        }

        $status = $this->service()->evaluate($consultant->fresh());

        $this->assertSame('restricted', $status, 'Grace is 5 weeks after the restricting one — this is one week short of suspension.');
    }

    public function test_suspended_stays_suspended_even_if_the_quota_is_later_met(): void
    {
        $consultant = $this->consultant();

        for ($i = 6; $i >= 1; $i--) {
            $this->verifiedStudentAt($consultant, FinanceWeek::current()->minus($i)->start->addDay());
        }
        $this->service()->evaluate($consultant->fresh());
        $this->assertSame('suspended', $consultant->fresh()->finance_status);

        // Meets quota in the current open period — must NOT auto-reactivate.
        $this->verifiedStudentAt($consultant, now());
        $this->verifiedStudentAt($consultant, now());

        $status = $this->service()->evaluate($consultant->fresh());

        $this->assertSame('suspended', $status, 'Only an admin reactivates — meeting quota again must not do it automatically.');
    }

    // ----- Admin reactivation -------------------------------------------------

    public function test_admin_reactivation_releases_the_hold_and_does_not_immediately_resuspend(): void
    {
        $consultant = $this->consultant();
        $admin = User::where('email', 'admin@etec.lk')->firstOrFail();

        for ($i = 6; $i >= 1; $i--) {
            $this->verifiedStudentAt($consultant, FinanceWeek::current()->minus($i)->start->addDay());
        }
        $this->service()->evaluate($consultant->fresh());
        $this->assertSame('suspended', $consultant->fresh()->finance_status);

        $this->service()->reactivate($consultant->fresh(), $admin);

        $fresh = $consultant->fresh();
        $this->assertSame('active', $fresh->finance_status);
        $this->assertSame($admin->id, $fresh->finance_reactivated_by);
        $this->assertSame(0, Commission::whereIn('consultant_id', $consultant->accountIds())->whereNotNull('held_at')->count());

        // Re-evaluating right away must NOT re-suspend on the same historical misses.
        $status = $this->service()->evaluate($fresh);
        $this->assertSame('active', $status);
    }

    public function test_reactivation_is_admin_only_via_the_route(): void
    {
        $consultant = $this->consultant();

        for ($i = 6; $i >= 1; $i--) {
            $this->verifiedStudentAt($consultant, FinanceWeek::current()->minus($i)->start->addDay());
        }
        $this->service()->evaluate($consultant->fresh());

        $this->actingAs($consultant->user)
            ->post(route('finance.reactivate', $consultant))
            ->assertForbidden();

        $this->assertSame('suspended', $consultant->fresh()->finance_status);
    }

    // ----- The bug class most likely to restrict innocent people ---------------

    public function test_students_placed_on_a_hidden_placement_account_still_count_toward_the_owners_quota(): void
    {
        $consultant = $this->consultant();
        $hidden = $consultant->hiddenAccounts()->first();
        $period = FinanceWeek::current()->minus(1);

        // Both registered through the HIDDEN account, not the main one.
        $this->verifiedStudentAt($hidden, $period->start->addDay());
        $this->verifiedStudentAt($hidden, $period->start->addDays(2));

        $status = $this->service()->evaluate($consultant->fresh());

        $this->assertSame('active', $status, "Students on a hidden placement account must count toward the real owner's quota.");
    }

    public function test_an_unverified_registration_does_not_count_toward_the_quota(): void
    {
        $consultant = $this->consultant();
        $period = FinanceWeek::current()->minus(1);

        // One verified...
        $this->verifiedStudentAt($consultant, $period->start->addDay());

        // ...one registered but never verified.
        $user = User::factory()->create(['created_at' => $period->start->addDays(2)]);
        $student = Student::create(['user_id' => $user->id, 'student_code' => 'SRN'.Str::random(6), 'status' => 'active', 'enrolled_at' => now()]);
        $student->forceFill(['created_at' => $period->start->addDays(2)])->save();
        Payment::create([
            'payment_code' => 'PYM'.Str::random(6), 'user_id' => $user->id, 'course_id' => Course::first()->id,
            'consultant_id' => $consultant->id, 'requested_tier' => 1, 'amount' => 28000, 'currency' => 'LKR',
            'method' => 'bank_transfer', 'payment_type' => 'full', 'status' => 'pending', 'paid_at' => now(),
        ]);

        $status = $this->service()->evaluate($consultant->fresh());

        $this->assertSame('restricted', $status, 'Only the verified registration counts — an accountant backlog is not the consultant\'s fault, but an unverified one genuinely has not landed yet.');
    }

    // ----- A consultant absent for a long time — the catch-up walk -------------

    public function test_a_consultant_who_never_logged_in_for_months_is_still_correctly_evaluated_in_one_pass(): void
    {
        $consultant = $this->consultant();
        // Nothing registered at all for 30 weeks — should walk all the way to suspended.
        $consultant->forceFill(['created_at' => now()->subWeeks(40)])->save();

        $status = $this->service()->evaluate($consultant->fresh());

        $this->assertSame('suspended', $status);
        $this->assertGreaterThanOrEqual(6, ConsultantFinancePeriod::where('consultant_id', $consultant->id)->count());
    }

    // ----- The money invariants --------------------------------------------------

    public function test_holding_and_releasing_never_move_total_earned_only_approval_does(): void
    {
        $consultant = $this->consultant();
        $period = FinanceWeek::current()->minus(1);

        $this->verifiedStudentAt($consultant, $period->start->addDay()); // misses
        $this->service()->evaluate($consultant->fresh());
        $this->assertSame(0.0, (float) $consultant->fresh()->total_earned, 'Holding must not move the counter.');

        $this->verifiedStudentAt($consultant, now());
        $this->verifiedStudentAt($consultant, now()); // meets, releases
        $this->service()->evaluate($consultant->fresh());
        $this->assertSame(0.0, (float) $consultant->fresh()->total_earned, 'Releasing must not move the counter either.');

        $this->approveAllCommissions();
        $this->assertGreaterThan(0.0, (float) $consultant->fresh()->total_earned, 'Only approval moves it, exactly once.');
    }

    public function test_company_revenue_still_counts_a_held_commission_no_double_spend(): void
    {
        $consultant = $this->consultant();
        $period = FinanceWeek::current()->minus(1);

        // Restrict the consultant first (misses quota in a closed period)...
        $this->verifiedStudentAt($consultant, $period->start->addDay());
        $this->service()->evaluate($consultant->fresh());
        $this->assertSame('restricted', $consultant->fresh()->finance_status);

        // ...then a NEW registration while restricted is recorded normally.
        // ⚠️ NOT held since 2026-09-15 (CommissionService::heldAtFor) — income
        // born held read as zero on the consultant's own Service Groups.
        $payment = $this->verifiedStudentAt($consultant->fresh(), now());

        $this->assertSame(0, Commission::where('payment_id', $payment->id)->whereNotNull('held_at')->count());

        // CompanyRevenue = payment amount - commission recorded, regardless of
        // hold state — a held row must still be subtracted here, or it would
        // be double-counted as company revenue AND later paid to the consultant.
        $revenue = CompanyRevenue::where('payment_id', $payment->id)->first();
        $commissionSum = (float) Commission::where('payment_id', $payment->id)->sum('amount');

        $this->assertNotNull($revenue);
        $this->assertSame(round((float) $payment->amount - $commissionSum, 2), round((float) $revenue->amount, 2));
    }

    public function test_hold_and_release_are_idempotent(): void
    {
        $consultant = $this->consultant();
        $this->verifiedStudentAt($consultant, now());

        $first = $this->service()->hold($consultant->fresh());
        $second = $this->service()->hold($consultant->fresh());

        $this->assertGreaterThan(0, $first);
        $this->assertSame(0, $second, 'A repeat hold must affect zero rows.');

        $firstRelease = $this->service()->release($consultant->fresh());
        $secondRelease = $this->service()->release($consultant->fresh());

        $this->assertGreaterThan(0, $firstRelease);
        $this->assertSame(0, $secondRelease, 'A repeat release must affect zero rows.');
    }
}
