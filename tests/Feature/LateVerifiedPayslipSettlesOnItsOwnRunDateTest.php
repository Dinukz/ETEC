<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\CommissionSetting;
use App\Models\Consultant;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use App\Services\FinanceSchemaRepair;
use App\Services\PaymentService;
use App\Services\ServiceGroupFlush;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ⚠️ A SLIP VERIFIED AFTER ITS OWN RUN MUST STILL SETTLE ON ITS OWN DATE.
 *
 * The owner's case, 2026-09-24: "21 damm payslip eka 22 verify krala, system
 * run eka — 21 system run eka yawanakota penwan na, eth 22 system run eke
 * penwanawa. Eka wardi. Eka penwann one 21 system run eke, nathuw 22 dawase
 * newei."
 *
 * This is about the RUN, not about a report. A run settles by the Payment Date
 * the operator selects (DATE(paid_at) <= cutoff, since 2026-09-14), so the
 * questions this file answers are:
 *
 *   1. Re-running the 21st AFTER the late verification — does the money appear?
 *   2. Does the next day's run sweep it up instead?
 *
 * ⚠️ READ-ONLY ABOUT THE MONEY RULES. Nothing here changes what a run releases,
 * pairs or forfeits — these tests only establish which run a late-verified
 * payslip lands in.
 */
class LateVerifiedPayslipSettlesOnItsOwnRunDateTest extends TestCase
{
    use RefreshDatabase;

    private const SLIP_DAY = '2026-09-21';

    private const VERIFY_DAY = '2026-09-22';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        FinanceSchemaRepair::ensure();

        CommissionSetting::where('is_active', true)->update([
            'commission_per_student' => 2500,
            'direct_commission' => 4000,
        ]);
        Cache::forget('commission_settings.active');
    }

    private function consultant(): Consultant
    {
        return User::where('email', 'consultant@etec.lk')->firstOrFail()->consultant;
    }

    /**
     * A registration on $account's leg $tier, dated $slipDate, verified $verifiedAt.
     *
     * Goes through the real PaymentService so the seat, the commission and
     * earned_at are all produced by the engine under test.
     */
    private function payOn(Consultant $account, int $tier, string $slipDate, string $verifiedAt): Payment
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
            'consultant_id' => $account->id, 'requested_tier' => $tier,
            'amount' => 39000, 'currency' => 'LKR', 'method' => 'bank_transfer',
            'payment_type' => 'full', 'status' => 'pending',
            'paid_at' => Carbon::parse($slipDate.' 10:00', 'Asia/Colombo'),
        ]);

        Carbon::setTestNow(Carbon::parse($verifiedAt.' 14:00', 'Asia/Colombo'));

        try {
            app(PaymentService::class)->verify($payment, User::where('email', 'accountant@etec.lk')->firstOrFail());
        } finally {
            Carbon::setTestNow();
        }

        return $payment->fresh();
    }

    /** The end of $date in Colombo — the cutoff the run page builds from a picked date. */
    private function cutoff(string $date): Carbon
    {
        return Carbon::parse($date, 'Asia/Colombo')->endOfDay();
    }

    /** What a run for $date would release on this account, without running it. */
    private function previewFor(Consultant $account, string $date): float
    {
        $flush = app(ServiceGroupFlush::class);
        $group = \App\Models\Group::where('consultant_id', $account->id)->orderBy('id')->firstOrFail();

        $state = $flush->state($account, $group, $this->cutoff($date));

        return (float) ($state['payout'] ?? 0);
    }

    /**
     * Both legs loaded on the 21st, but the SECOND one verified on the 22nd —
     * after a 21st run would already have been performed.
     */
    private function accountWithALateSlip(): Consultant
    {
        $account = $this->consultant()->hiddenAccounts()->orderBy('id')->firstOrFail();

        // On time: verified the same day it was banked.
        $this->payOn($account, 1, self::SLIP_DAY, self::SLIP_DAY);
        // Late: banked the 21st, verified the 22nd.
        $this->payOn($account, 2, self::SLIP_DAY, self::VERIFY_DAY);

        return $account->fresh();
    }

    // ------------------------------------------------------------------------

    /** Guard: the fixture really is one on-time and one late-verified slip. */
    public function test_the_fixture_has_one_late_verified_slip(): void
    {
        $account = $this->accountWithALateSlip();

        $rows = Commission::where('consultant_id', $account->id)
            ->where('type', '!=', 'referral')
            ->get();

        $this->assertCount(2, $rows, 'one 2,500 on each leg');

        foreach ($rows as $row) {
            $this->assertSame(
                self::SLIP_DAY,
                $row->earned_at?->setTimezone('Asia/Colombo')->toDateString(),
                'both are 21st money',
            );
        }

        $this->assertSame(
            [self::SLIP_DAY, self::VERIFY_DAY],
            $rows->map(fn ($r) => $r->created_at->setTimezone('Asia/Colombo')->toDateString())->sort()->values()->all(),
            'and one of them was written a day later',
        );
    }

    /**
     * ⚠️ THE REPORTED CASE. Running the 21st again, after the late verification,
     * must settle the 21st money.
     */
    public function test_re_running_the_slip_date_settles_the_late_verified_money(): void
    {
        $account = $this->accountWithALateSlip();

        $this->assertSame(5000.0, $this->previewFor($account, self::SLIP_DAY), 'a 1-to-1 on the 21st');
    }

    /** And actually performing it releases both rows, stamped by that run. */
    public function test_the_run_for_the_slip_date_releases_both_rows(): void
    {
        $account = $this->accountWithALateSlip();

        app(ServiceGroupFlush::class)->runAll($this->cutoff(self::SLIP_DAY));

        $released = Commission::where('consultant_id', $account->id)
            ->where('type', '!=', 'referral')
            ->whereNotNull('released_at')
            ->sum('amount');

        $this->assertSame(5000.0, (float) $released, 'the late slip settled on its own date');
    }

    /**
     * ⚠️ WHAT THE OWNER IS SEEING. If the 21st run was performed BEFORE the late
     * verification, that run could not have settled it — and the next run sweeps
     * it up, so the money surfaces under the 22nd.
     */
    public function test_a_run_done_before_the_verification_leaves_it_for_the_next_run(): void
    {
        $account = $this->consultant()->hiddenAccounts()->orderBy('id')->firstOrFail();

        $this->payOn($account, 1, self::SLIP_DAY, self::SLIP_DAY);

        // The 21st run happens now — the late slip does not exist yet.
        app(ServiceGroupFlush::class)->runAll($this->cutoff(self::SLIP_DAY));

        $this->assertSame(
            0.0,
            (float) Commission::where('consultant_id', $account->id)->where('type', '!=', 'referral')->whereNotNull('released_at')->sum('amount'),
            'one leg alone releases nothing',
        );

        // Only now is the second slip verified.
        $this->payOn($account, 2, self::SLIP_DAY, self::VERIFY_DAY);

        // The 22nd run sweeps it up, because its scope is DATE(paid_at) <= 22.
        app(ServiceGroupFlush::class)->runAll($this->cutoff(self::VERIFY_DAY));

        $this->assertSame(
            5000.0,
            (float) Commission::where('consultant_id', $account->id)->where('type', '!=', 'referral')->whereNotNull('released_at')->sum('amount'),
            'the money is settled — but by the 22nd run, which is what was reported',
        );
    }

    // ----- what the operator is now told -------------------------------------

    /**
     * ⚠️ THE FIX. The late slip is named, with its own date and amount, so the
     * 21st can be run again instead of the money landing on the 22nd.
     */
    public function test_a_late_arrival_is_reported_against_its_own_date(): void
    {
        $account = $this->consultant()->hiddenAccounts()->orderBy('id')->firstOrFail();

        $this->payOn($account, 1, self::SLIP_DAY, self::SLIP_DAY);
        app(ServiceGroupFlush::class)->runAll($this->cutoff(self::SLIP_DAY));
        $this->recordRun(self::SLIP_DAY);

        $this->payOn($account, 2, self::SLIP_DAY, self::VERIFY_DAY);

        $late = ServiceGroupFlush::lateArrivals();

        $this->assertCount(1, $late, 'one date is waiting');
        $this->assertSame(self::SLIP_DAY, $late->first()['date'], 'reported against the payslip date, not the verify date');

        // ⚠️ 5,000, NOT 2,500 — and that is right. Slot income CLIMBS, so one
        // late registration writes a row on the placement account AND on the
        // main above it. This figure is network-wide money waiting, which is
        // what the operator needs to see; it is not one account's balance.
        $this->assertSame(5000.0, $late->first()['amount']);
        $this->assertSame(2, $late->first()['rows'], 'the placement account and the main');
    }

    /**
     * ⚠️ NOT EVERY UNRELEASED ROW. Money simply waiting for its other leg — the
     * bulk of what sits on legs — is where it belongs and must not be reported
     * as a late arrival, or the notice is noise on every run.
     */
    public function test_money_that_merely_waits_for_a_pair_is_not_a_late_arrival(): void
    {
        $account = $this->consultant()->hiddenAccounts()->orderBy('id')->firstOrFail();

        // One leg only, entered and verified the same day, then a run.
        $this->payOn($account, 1, self::SLIP_DAY, self::SLIP_DAY);
        app(ServiceGroupFlush::class)->runAll($this->cutoff(self::SLIP_DAY));
        $this->recordRun(self::SLIP_DAY);

        $this->assertTrue(
            ServiceGroupFlush::lateArrivals()->isEmpty(),
            'it was in the run, it simply had nothing to pair with',
        );
    }

    /** Before any run at all there is nothing to be late for. */
    public function test_nothing_is_late_before_the_first_run(): void
    {
        $account = $this->consultant()->hiddenAccounts()->orderBy('id')->firstOrFail();
        $this->payOn($account, 1, self::SLIP_DAY, self::VERIFY_DAY);

        $this->assertTrue(ServiceGroupFlush::lateArrivals()->isEmpty());
    }

    /** ⚠️ The run refuses to fold an older day's money in without being told to. */
    public function test_running_a_later_date_must_be_confirmed(): void
    {
        $account = $this->consultant()->hiddenAccounts()->orderBy('id')->firstOrFail();

        $this->payOn($account, 1, self::SLIP_DAY, self::SLIP_DAY);
        app(ServiceGroupFlush::class)->runAll($this->cutoff(self::SLIP_DAY));
        $this->recordRun(self::SLIP_DAY);

        $this->payOn($account, 2, self::SLIP_DAY, self::VERIFY_DAY);

        $admin = User::where('email', 'superadmin@etec.lk')->firstOrFail();

        $this->actingAs($admin)
            ->post(route('system-run.run'), ['confirm' => 'RUN', 'cutoff' => self::VERIFY_DAY])
            ->assertSessionHas('error');

        $this->assertSame(
            0.0,
            (float) Commission::where('consultant_id', $account->id)
                ->where('type', '!=', 'referral')
                ->whereNotNull('released_at')
                ->sum('amount'),
            'nothing was settled by the refused run',
        );

        // Ticking the box lets it through, unchanged.
        $this->actingAs($admin)
            ->post(route('system-run.run'), ['confirm' => 'RUN', 'cutoff' => self::VERIFY_DAY, 'confirm_sweeps_earlier' => 1])
            ->assertSessionHas('success');
    }

    /** Running the slip's OWN date is never blocked — that is the thing to do. */
    public function test_running_the_slips_own_date_needs_no_confirmation(): void
    {
        $account = $this->consultant()->hiddenAccounts()->orderBy('id')->firstOrFail();

        $this->payOn($account, 1, self::SLIP_DAY, self::SLIP_DAY);
        app(ServiceGroupFlush::class)->runAll($this->cutoff(self::SLIP_DAY));
        $this->recordRun(self::SLIP_DAY);

        $this->payOn($account, 2, self::SLIP_DAY, self::VERIFY_DAY);

        $this->actingAs(User::where('email', 'superadmin@etec.lk')->firstOrFail())
            ->post(route('system-run.run'), ['confirm' => 'RUN', 'cutoff' => self::SLIP_DAY])
            ->assertSessionHas('success');

        $this->assertSame(
            5000.0,
            (float) Commission::where('consultant_id', $account->id)
                ->where('type', '!=', 'referral')
                ->whereNotNull('released_at')
                ->sum('amount'),
            'settled on the 21st, where it belongs',
        );
    }

    /** The page names the waiting date. */
    public function test_the_run_page_names_the_waiting_date(): void
    {
        $account = $this->consultant()->hiddenAccounts()->orderBy('id')->firstOrFail();

        $this->payOn($account, 1, self::SLIP_DAY, self::SLIP_DAY);
        app(ServiceGroupFlush::class)->runAll($this->cutoff(self::SLIP_DAY));
        $this->recordRun(self::SLIP_DAY);

        $this->payOn($account, 2, self::SLIP_DAY, self::VERIFY_DAY);

        $this->actingAs(User::where('email', 'superadmin@etec.lk')->firstOrFail())
            ->get(route('system-run.index', ['cutoff' => self::VERIFY_DAY]))
            ->assertOk()
            ->assertSee('was recorded after it')
            ->assertSee(Carbon::parse(self::SLIP_DAY)->format('D j M'))
            ->assertSee('verified after the run')
            // ⚠️ 2026-10-01: never a link to run the OLD date — at 3-to-3 an
            // old-date run closes off everything entered after it.
            ->assertDontSee('Run '.Carbon::parse(self::SLIP_DAY)->format('j M').'</a>', false)
            ->assertSee('Recommended: run');
    }

    /**
     * ⚠️ A REPAIR IS NOT A LATE SLIP (2026-10-01).
     *
     * The owner's page read "52 payments dated 9 Sep … verified after Run #59":
     * three-week-old slips that could not all have been verified that late. A
     * row Commission Health → Repair writes for an OLD payment is new, so it
     * missed the run too — but its payment was verified long before. The page
     * has to say which.
     */
    public function test_a_row_written_by_a_repair_is_not_called_a_late_slip(): void
    {
        $account = $this->consultant()->hiddenAccounts()->orderBy('id')->firstOrFail();

        // Banked AND verified on the slip day, before the run.
        $payment = $this->payOn($account, 1, self::SLIP_DAY, self::SLIP_DAY);
        app(ServiceGroupFlush::class)->runAll($this->cutoff(self::SLIP_DAY));
        $this->recordRun(self::SLIP_DAY);

        // What a repair does: writes the row now, for that old payment.
        Commission::where('payment_id', $payment->id)
            ->where('type', '!=', 'referral')
            ->get()
            ->each(fn ($row) => $row->forceFill(['created_at' => Carbon::parse(self::VERIFY_DAY.' 14:00', 'Asia/Colombo')])->save());

        $late = ServiceGroupFlush::lateArrivals()->first();

        $this->assertNotNull($late);
        $this->assertSame(0, $late['late_slip'], 'its slip was verified before the run');
        $this->assertSame(1, $late['repaired'], 'it was completed afterwards');
    }

    /** "52 payments" was 52 ROWS. One payment climbs onto several accounts. */
    public function test_payments_are_counted_as_payments_not_rows(): void
    {
        $account = $this->consultant()->hiddenAccounts()->orderBy('id')->firstOrFail();

        $this->payOn($account, 1, self::SLIP_DAY, self::SLIP_DAY);
        app(ServiceGroupFlush::class)->runAll($this->cutoff(self::SLIP_DAY));
        $this->recordRun(self::SLIP_DAY);

        $this->payOn($account, 2, self::SLIP_DAY, self::VERIFY_DAY);

        $late = ServiceGroupFlush::lateArrivals()->first();

        $this->assertSame(2, $late['rows'], 'the placement account and the main');
        $this->assertSame(1, $late['payments'], 'but ONE payment');
        $this->assertSame(1, $late['late_slip']);
    }

    /**
     * A run row on record, so lateArrivals() has something to measure against.
     *
     * runAll() moves the money but does not write system_runs — the controller
     * does that — so a test that calls the service directly has to record it.
     */
    private function recordRun(string $date): void
    {
        \App\Models\SystemRun::create([
            'run_by' => User::where('email', 'superadmin@etec.lk')->value('id'),
            'cutoff_at' => $date.' 23:59:59',
            'accounts_flushed' => 1,
            'total_released' => 0,
            'total_forfeited' => 0,
        ])->forceFill([
            // Finished at the end of that day — anything written later is late.
            'created_at' => Carbon::parse($date.' 23:00', 'Asia/Colombo')->utc(),
        ])->save();
    }
}
