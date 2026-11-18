<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\Consultant;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Payment;
use App\Models\Student;
use App\Models\SystemRun;
use App\Models\User;
use App\Services\FinanceSchemaRepair;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ⚠️ A PAYSLIP KEEPS ITS OWN DATE, HOWEVER LATE IT IS VERIFIED OR SETTLED.
 *
 * The owner's case, 2026-09-24: "Payment slip eka 21 eka dapu ekak verify krla
 * thiyenne 21 system run eka yawwata passe. E payment awilla na — eka 22 tham
 * add wela thiyenne. Kalin meka haduwa: verify krana dawasata nemei, payslip
 * eka add karana dawasata thama add wenne kiyala. Eth eka hariyata wela na."
 *
 * Three different dates exist for one 2,500 and they are routinely days apart:
 *
 *   payslip date   payments.paid_at        — the Payment Date the consultant typed
 *   verify date    commissions.created_at  — when the accountant pressed Verify
 *   run date       commissions.released_at — when the System Run button was pressed
 *
 * Measured on the 21 Sep live data: of 4,312 released slot rows, 4,201 (97%)
 * had a run date different from their payslip, up to 16 days later — while the
 * DIRECT BONUS on the same payment was already dated by the payslip. So one
 * payment was split across two days on the same report.
 *
 * This file pins the rule for all three kinds of lateness at once.
 *
 * ⚠️ NOTHING ABOUT THE MONEY CHANGED. What a run settles, what it pays and what
 * it forfeits are untouched; only the day a report files it under.
 */
class LateVerifiedPayslipKeepsItsOwnDateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        FinanceSchemaRepair::ensure();
    }

    private function consultant(): Consultant
    {
        $user = User::where('email', 'consultant@etec.lk')->firstOrFail();
        $this->grantConsultantPages($user, ['payment_summary']);

        return $user->consultant;
    }

    /**
     * A registration whose slip is DATED $slipDate but verified at $verifiedAt.
     *
     * Goes through PaymentService::verify(), so earned_at is stamped by the
     * real engine rather than by the test — that is the behaviour under test.
     */
    private function payOn(Consultant $account, string $slipDate, string $verifiedAt): Payment
    {
        $buyer = User::factory()->create();
        $buyer->assignRole('student');

        Student::create([
            'user_id' => $buyer->id, 'student_code' => 'SRN'.Str::random(8),
            'status' => 'active', 'enrolled_at' => Carbon::parse($slipDate),
        ]);

        $course = Course::where('status', 'published')->firstOrFail();

        $enrollment = Enrollment::create([
            'user_id' => $buyer->id, 'course_id' => $course->id,
            'status' => 'pending', 'enrolled_at' => Carbon::parse($slipDate),
        ]);

        $payment = Payment::create([
            'payment_code' => 'PYM'.Str::random(8), 'user_id' => $buyer->id,
            'course_id' => $course->id, 'enrollment_id' => $enrollment->id,
            'consultant_id' => $account->id, 'requested_tier' => 1,
            'amount' => 39000, 'currency' => 'LKR', 'method' => 'bank_transfer',
            'payment_type' => 'full', 'status' => 'pending',
            // The Payment Date the consultant typed on the Confirm Payment form.
            'paid_at' => Carbon::parse($slipDate, 'Asia/Colombo'),
        ]);

        // Verified days later — the accountant's clock, not the payslip's.
        Carbon::setTestNow(Carbon::parse($verifiedAt, 'Asia/Colombo'));

        try {
            app(PaymentService::class)->verify($payment, User::where('email', 'accountant@etec.lk')->firstOrFail());
        } finally {
            Carbon::setTestNow();
        }

        return $payment->fresh();
    }

    /** Release every slot row this account holds, as a run on $ranAt would. */
    private function runOn(Consultant $account, string $ranAt): void
    {
        $rows = Commission::whereIn('consultant_id', $account->accountIds())
            ->where('type', '!=', 'referral')
            ->whereNull('released_at')
            ->get();

        foreach ($rows as $row) {
            $row->forceFill(['released_at' => Carbon::parse($ranAt, 'Asia/Colombo')->utc()])->save();
        }

        $run = SystemRun::create([
            'run_by' => User::where('email', 'superadmin@etec.lk')->value('id'),
            'cutoff_at' => Carbon::parse($ranAt, 'Asia/Colombo')->toDateString().' 23:59:59',
            'accounts_flushed' => 1,
            'total_released' => (float) $rows->sum('amount'),
            'total_forfeited' => 0,
            'accounts' => json_encode([[
                'account_id' => $account->id,
                'code' => $account->scn_code,
                'released' => (float) $rows->sum('amount'),
                'forfeited' => 0,
                'released_ids' => $rows->pluck('id')->all(),
            ]]),
        ]);

        $run->forceFill(['created_at' => Carbon::parse($ranAt, 'Asia/Colombo')->utc()])->save();
    }

    /** @return array{direct: float, group: float} the report's figures for a window */
    private function reportFor(Consultant $account, string $from, string $to): array
    {
        $rows = $this->actingAs($account->user)
            ->get(route('reports.payments', ['from' => $from, 'to' => $to]))
            ->assertOk()
            ->viewData('commissions');

        return [
            'direct' => (float) $rows->sum('direct_income'),
            'group' => (float) $rows->sum('group_income'),
        ];
    }

    // ------------------------------------------------------------------------

    /** ⚠️ THE ENGINE: the commission is stamped with the PAYSLIP's date. */
    public function test_a_late_verified_payment_stamps_the_payslip_date(): void
    {
        $account = $this->consultant();

        $payment = $this->payOn($account, '2026-09-21 10:00', '2026-09-22 14:00');

        $rows = Commission::where('payment_id', $payment->id)->get();

        $this->assertTrue($rows->isNotEmpty(), 'the verification produced income');

        foreach ($rows as $row) {
            $this->assertSame(
                '2026-09-21',
                $row->earned_at?->setTimezone('Asia/Colombo')->toDateString(),
                'earned_at follows the payslip, not the verify click',
            );
            $this->assertSame(
                '2026-09-22',
                $row->created_at->setTimezone('Asia/Colombo')->toDateString(),
                'guard: it really was written a day later',
            );
        }
    }

    /**
     * ⚠️ THE STAGE MONEY FOLLOWS THE RUN THAT SETTLED IT — AND THAT RUN SHOULD
     * BE THE SLIP'S OWN DATE.
     *
     * For one day (2026-09-24) this file asserted that the stage money was
     * reported on the PAYSLIP's date regardless of the run. The owner then
     * showed why that cannot work: a run releases a whole STAGE built from
     * 2,500 rows on two legs, so dating the rows one by one splits a 5,000
     * payout across two reports — the 2,500 and 7,500 lines he found on the
     * 25th. The report now reads what the RUN recorded, keyed on the date the
     * run settled up to.
     *
     * The answer to the original complaint is unchanged, and this test is it:
     * re-run the slip's own date, and the money reports on that date. The
     * System Run page now names any slip that missed its run, so it can be.
     */
    public function test_the_income_is_reported_on_the_date_the_run_settled(): void
    {
        $account = $this->consultant();

        $this->payOn($account, '2026-09-21 10:00', '2026-09-22 14:00');

        // The operator runs the 21st — the slip's own date — on the 23rd.
        $this->runOn($account, '2026-09-21 09:00');

        $onTheSlipDay = $this->reportFor($account, '2026-09-21', '2026-09-21');

        $this->assertGreaterThan(0, $onTheSlipDay['direct'], 'the 4,000 is dated by the payslip');
        $this->assertGreaterThan(0, $onTheSlipDay['group'], 'and the run settled up to the 21st, so its money is here too');
    }

    /**
     * ⚠️ AND IF A LATER RUN SWEEPS IT UP, IT REPORTS ON THAT RUN'S DATE.
     *
     * This is the behaviour the System Run page now warns about before it can
     * happen (see LateVerifiedPayslipSettlesOnItsOwnRunDateTest): the money is
     * not lost, it is simply filed under the day it was settled.
     */
    public function test_a_later_run_reports_the_stage_money_on_its_own_date(): void
    {
        $account = $this->consultant();

        $this->payOn($account, '2026-09-21 10:00', '2026-09-22 14:00');
        $this->runOn($account, '2026-09-23 09:00');

        $this->assertSame(0.0, $this->reportFor($account, '2026-09-21', '2026-09-21')['group'], 'no run settled up to the 21st');
        $this->assertGreaterThan(0, $this->reportFor($account, '2026-09-23', '2026-09-23')['group'], 'the run that did settle it');

        // The direct bonus never goes through a run, so it stays on the payslip.
        $this->assertGreaterThan(0, $this->reportFor($account, '2026-09-21', '2026-09-21')['direct']);
        $this->assertSame(0.0, $this->reportFor($account, '2026-09-23', '2026-09-23')['direct']);
    }

    /** ⚠️ And un-released stage money is still not income at all. */
    public function test_stage_money_no_run_has_released_is_still_not_counted(): void
    {
        $account = $this->consultant();

        $this->payOn($account, '2026-09-21 10:00', '2026-09-22 14:00');
        // No run at all this time.

        $day = $this->reportFor($account, '2026-09-21', '2026-09-21');

        $this->assertGreaterThan(0, $day['direct'], 'the bonus never needs a run');
        $this->assertSame(0.0, $day['group'], 'the stage money is still on the leg');
    }
}
