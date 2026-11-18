<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\Consultant;
use App\Models\Course;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\Student;
use App\Models\SystemRun;
use App\Models\User;
use App\Services\PaymentService;
use App\Services\ServiceGroupStaging;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A leg that is not zero right after a System Run must say why.
 *
 * Reported 2026-09-13: after run #39 (settled up to 9 Sep) SCN-00136 still read
 * 10,000 / 30,000 and the owner called the run broken — "ehem unaham enn widiyk
 * na ne ethkot meka error ekak wenwa". It was correct: every rupee left had been
 * entered after 9 Sep, or verified after the run. The consultant page measures
 * against the end of today, so it never had anything "after" to explain, and a
 * green "Ready" sat under money the run could not touch.
 */
class ServiceGroupsAfterLastRunTest extends TestCase
{
    use RefreshDatabase;

    private const SLOT = 2500;

    private const LINE = 'came in after the last System Run';

    /** The exact Ready badge markup, so the word elsewhere on the page cannot match. */
    private const READY = 'dark:text-emerald-400">Ready</span>';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        // system_runs is created by the self-healing repair, not by a migration.
        \App\Services\FinanceSchemaRepair::ensure();

        \App\Models\CommissionSetting::where('is_active', true)->update([
            'commission_per_student' => self::SLOT,
            'direct_commission' => 4000,
        ]);
        \Illuminate\Support\Facades\Cache::forget('commission_settings.active');

        Setting::set('stage_first_pairs', '1', 'integer', 'commission');
        Setting::set('stage_second_pairs', '2', 'integer', 'commission');
        Setting::set('stage_repeat_pairs', '3', 'integer', 'commission');
        Setting::set('account_unlock_students', '12', 'integer', 'commission');
        ServiceGroupStaging::forget();

        Notification::fake();
    }

    private function consultantUser(): User
    {
        return User::where('email', 'consultant@etec.lk')->firstOrFail();
    }

    private function account(): Consultant
    {
        return $this->consultantUser()->consultant->hiddenAccounts()->orderBy('id')->firstOrFail();
    }

    private function accountant(): User
    {
        return User::where('email', 'accountant@etec.lk')->firstOrFail();
    }

    /** A pending registration, its entry time (payments.created_at, UTC) set by hand. */
    private function registerPending(Consultant $account, int $leg, ?Carbon $enteredAtUtc = null): Payment
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

        // Since 2026-09-14 the run scopes by the selected Payment Date (paid_at);
        // these tests back-date that, keeping created_at with it.
        if ($enteredAtUtc) {
            DB::table('payments')->where('id', $payment->id)->update(['paid_at' => $enteredAtUtc, 'created_at' => $enteredAtUtc]);
        }

        return $payment->fresh();
    }

    private function verify(Payment $payment): void
    {
        app(PaymentService::class)->verify($payment->fresh(), $this->accountant());
    }

    private function addStudent(Consultant $account, int $leg, ?Carbon $enteredAtUtc = null): Payment
    {
        $payment = $this->registerPending($account, $leg, $enteredAtUtc);
        $this->verify($payment);

        return $payment->fresh();
    }

    /** A run record settled up to the end of $day in Sri Lanka — stored exactly as the controller stores it. */
    private function recordRun(Carbon $dayColombo): SystemRun
    {
        return SystemRun::create([
            'run_by' => $this->accountant()->id,
            'cutoff_at' => $dayColombo->copy()->endOfDay(),
            'accounts_flushed' => 0,
            'total_released' => 0,
            'total_forfeited' => 0,
        ]);
    }

    private function row($response, Consultant $account): array
    {
        return collect($response->viewData('accountRows'))->firstWhere('id', $account->id);
    }

    // ----- the report -----------------------------------------------------------

    /** ⚠️ THE REPORT. Money entered after the last run's cutoff is named under its leg, with its amount. */
    public function test_money_entered_after_the_last_runs_cutoff_is_named(): void
    {
        $account = $this->account();

        $run = $this->recordRun(Carbon::now('Asia/Colombo')->subDay());
        $this->addStudent($account, 1); // entered today, after yesterday's cutoff

        $response = $this->actingAs($this->consultantUser())->get(route('consultant.groups.index'))->assertOk();

        $this->assertSame((float) self::SLOT, $this->row($response, $account)['after_last_run'][1]);

        // Under the leg figure in the table — a <span>. The headline uses a <p>,
        // and this student also climbs to the main, so a looser match would pass
        // on the headline alone.
        $this->assertMatchesRegularExpression(
            '#<span class="block text-xs text-slate-400">\s*LKR 2,500 '.self::LINE.' \(settled up to '.preg_quote($run->cutoffLabel(), '#').'\) — it settles at the next run\s*</span>#u',
            $response->getContent(),
        );
    }

    /**
     * The headline reads the main row's own figure, so the two cannot disagree —
     * and prints the line under its own figure, not only in the table.
     *
     * Money reaches the main the way it does live: a student on the account that
     * IS the main's Service Group 1 / 2 climbs to the main's own leg.
     */
    public function test_the_headline_uses_the_main_rows_figure(): void
    {
        $main = $this->consultantUser()->consultant;
        [$sg1, $sg2] = $main->hiddenAccounts()->orderBy('id')->take(2)->get()->all();

        $run = $this->recordRun(Carbon::now('Asia/Colombo')->subDay());
        $this->addStudent($sg1, 1);
        $this->addStudent($sg2, 2);

        $response = $this->actingAs($this->consultantUser())->get(route('consultant.groups.index'))->assertOk();
        $mainRow = collect($response->viewData('accountRows'))->firstWhere('is_owner', true);

        $this->assertGreaterThan(
            0.0,
            (float) $response->viewData('afterRunLeg1') + (float) $response->viewData('afterRunLeg2'),
            'guard: the main really holds money that came in after the run',
        );

        $this->assertSame((float) $mainRow['after_last_run'][1], (float) $response->viewData('afterRunLeg1'));
        $this->assertSame((float) $mainRow['after_last_run'][2], (float) $response->viewData('afterRunLeg2'));

        // The headline prints it in a <p>; the table cells use a <span>.
        $this->assertMatchesRegularExpression(
            '#<p class="mt-0\.5 text-xs text-slate-500 dark:text-slate-400">\s*LKR [\d,]+ came in after the last System Run \(settled up to '.preg_quote($run->cutoffLabel(), '#').'\)#',
            $response->getContent(),
        );
    }

    public function test_before_any_run_exists_nothing_is_said(): void
    {
        $account = $this->account();
        $this->addStudent($account, 1);

        $response = $this->actingAs($this->consultantUser())->get(route('consultant.groups.index'))->assertOk();

        $this->assertSame([1 => 0.0, 2 => 0.0], $this->row($response, $account)['after_last_run']);
        $response->assertDontSee(self::LINE);
    }

    /** A slip entered before the cutoff but verified after the run was not there to settle. */
    public function test_a_slip_entered_before_the_cutoff_but_verified_after_the_run_is_counted(): void
    {
        $account = $this->account();

        $payment = $this->registerPending($account, 1, Carbon::now('UTC')->subDays(3));
        $this->recordRun(Carbon::now('Asia/Colombo')->subDay());

        $this->travel(5)->minutes();
        $this->verify($payment);

        $response = $this->actingAs($this->consultantUser())->get(route('consultant.groups.index'))->assertOk();

        $this->assertSame((float) self::SLOT, $this->row($response, $account)['after_last_run'][1]);
    }

    /**
     * ⚠️ WHAT MUST NOT BE COUNTED. A real 1-to-1 run releases one row per side;
     * the second row on the heavier side was in scope and present at run time.
     * Timing is not why it is still there, so it must not be explained as such.
     */
    public function test_money_left_by_the_ramp_is_not_called_late(): void
    {
        $account = $this->account();
        $threeDaysAgo = Carbon::now('UTC')->subDays(3);

        $this->addStudent($account, 1, $threeDaysAgo);
        $this->addStudent($account, 1, $threeDaysAgo);
        $this->addStudent($account, 2, $threeDaysAgo);

        $this->actingAs($this->accountant())
            ->post(route('system-run.run'), [
                'confirm' => 'RUN',
                'cutoff' => Carbon::now('Asia/Colombo')->subDay()->toDateString(),
            ])
            ->assertRedirect();

        $this->assertSame(1, SystemRun::count(), 'guard: the run really happened');

        $response = $this->actingAs($this->consultantUser())->get(route('consultant.groups.index'))->assertOk();
        $row = $this->row($response, $account);

        $this->assertSame((float) self::SLOT, (float) $row['flush']['leg1_total'], 'guard: one row really was left by the ramp');
        $this->assertSame(0.0, $row['after_last_run'][1], 'but it was there at run time, so it is not "late"');
    }

    /**
     * A payment DATED after the run's cutoff date counts as "after".
     *
     * ⚠️ THE WALL-CLOCK TRAP IS GONE (2026-09-14). The run scopes by DATE(paid_at)
     * now — the day the consultant selected — so a payment's time-of-day no
     * longer decides its day, and a payment dated ON the cutoff day settles in
     * that run rather than being stranded "after" it. This pins the plain rule:
     * dated the day AFTER the run's cutoff → came in after.
     */
    public function test_a_payment_dated_after_the_run_counts_as_after(): void
    {
        $account = $this->account();
        $cutoffDay = Carbon::now('Asia/Colombo')->subDays(3)->startOfDay();

        // Dated the day AFTER the run settled up to.
        $paymentDate = $cutoffDay->copy()->addDay();
        $this->addStudent($account, 1, $paymentDate);

        $this->travel(2)->minutes();
        $this->recordRun($cutoffDay);

        $response = $this->actingAs($this->consultantUser())->get(route('consultant.groups.index'))->assertOk();

        $this->assertSame((float) self::SLOT, $this->row($response, $account)['after_last_run'][1]);
    }

    /** ...and one dated ON the cutoff day is NOT after — the run settled up to that day. */
    public function test_a_payment_dated_on_the_cutoff_day_is_not_after(): void
    {
        $account = $this->account();
        $cutoffDay = Carbon::now('Asia/Colombo')->subDays(3)->startOfDay();

        $this->addStudent($account, 1, $cutoffDay->copy());

        $this->travel(2)->minutes();
        $this->recordRun($cutoffDay);

        $response = $this->actingAs($this->consultantUser())->get(route('consultant.groups.index'))->assertOk();

        $this->assertSame(0.0, $this->row($response, $account)['after_last_run'][1], 'dated the cutoff day, so it was settleable by that run');
    }

    // ----- Ready ----------------------------------------------------------------

    /** Ready is shown only when the account can actually settle, not when one side is full. */
    public function test_ready_needs_both_sides(): void
    {
        $account = $this->account();

        $this->addStudent($account, 1);

        $this->actingAs($this->consultantUser())
            ->get(route('consultant.groups.index'))
            ->assertOk()
            ->assertDontSee(self::READY, false);

        $this->addStudent($account, 2);

        $this->actingAs($this->consultantUser())
            ->get(route('consultant.groups.index'))
            ->assertOk()
            ->assertSee(self::READY, false);
    }
}
