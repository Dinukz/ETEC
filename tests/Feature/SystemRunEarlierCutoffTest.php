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
 * A run date earlier than the last run's must be confirmed.
 *
 * Reported 2026-09-13: run #39 was given 9 Sep after runs #37 and #38 had
 * already settled up to 10 Sep, and nothing warned. Running for an old date is
 * a normal thing to do, so it stays possible — but since 2026-09-14 a 3-to-3
 * account that settles in such a run also loses everything entered after that
 * date, so it must never happen by accident.
 */
class SystemRunEarlierCutoffTest extends TestCase
{
    use RefreshDatabase;

    private const SLOT = 2500;

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

    private function accountant(): User
    {
        return User::where('email', 'accountant@etec.lk')->firstOrFail();
    }

    private function account(): Consultant
    {
        return User::where('email', 'consultant@etec.lk')->firstOrFail()->consultant->hiddenAccounts()->orderBy('id')->firstOrFail();
    }

    private function addStudent(Consultant $account, int $leg, Carbon $enteredAtUtc): void
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

        // Since 2026-09-14 the run scopes by the selected Payment Date (paid_at).
        DB::table('payments')->where('id', $payment->id)->update(['paid_at' => $enteredAtUtc, 'created_at' => $enteredAtUtc]);

        app(PaymentService::class)->verify($payment->fresh(), $this->accountant());
    }

    /** A previous run that settled up to the end of yesterday, Sri Lanka time. */
    private function previousRun(): SystemRun
    {
        return SystemRun::create([
            'run_by' => $this->accountant()->id,
            'cutoff_at' => Carbon::now('Asia/Colombo')->subDay()->endOfDay(),
            'accounts_flushed' => 0,
            'total_released' => 0,
            'total_forfeited' => 0,
        ]);
    }

    /** A balanced 1-to-1 pair entered four days ago, so an older run date still has something to settle. */
    private function balancedPairFourDaysAgo(): void
    {
        $when = Carbon::now('UTC')->subDays(4);

        $this->addStudent($this->account(), 1, $when);
        $this->addStudent($this->account(), 2, $when);
    }

    private function twoDaysAgo(): string
    {
        return Carbon::now('Asia/Colombo')->subDays(2)->toDateString();
    }

    /** Slot rows a run has stamped released — on one account, or anywhere at all. */
    private function released(?Consultant $on = null): int
    {
        return Commission::where('type', 'bonus')
            ->whereNotNull('released_at')
            ->when($on, fn ($q) => $q->where('consultant_id', $on->id))
            ->count();
    }

    public function test_an_earlier_date_shows_the_warning_and_the_tick_box(): void
    {
        $this->previousRun();

        $response = $this->actingAs($this->accountant())
            ->get(route('system-run.index', ['cutoff' => $this->twoDaysAgo()]))
            ->assertOk();

        $this->assertTrue($response->viewData('earlierThanLast'));
        $response->assertSee('This date is earlier than the last System Run.');
        $response->assertSee('an account that settles at 3-to-3 also');
        $response->assertSee('name="confirm_earlier"', false);
    }

    /** ⚠️ THE GUARD. Without the tick the run does nothing at all. */
    public function test_an_earlier_date_without_the_tick_is_refused(): void
    {
        $this->previousRun();
        $this->balancedPairFourDaysAgo();

        $this->actingAs($this->accountant())
            ->post(route('system-run.run'), ['confirm' => 'RUN', 'cutoff' => $this->twoDaysAgo()])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(1, SystemRun::count(), 'no second run was recorded');
        $this->assertSame(0, $this->released(), 'and nothing was released');
    }

    public function test_an_earlier_date_with_the_tick_runs(): void
    {
        $this->previousRun();
        $this->balancedPairFourDaysAgo();

        $this->actingAs($this->accountant())
            ->post(route('system-run.run'), ['confirm' => 'RUN', 'cutoff' => $this->twoDaysAgo(), 'confirm_earlier' => '1'])
            ->assertRedirect();

        $this->assertSame(2, SystemRun::count());
        $this->assertSame(2, $this->released($this->account()), 'the balanced pair was settled');
    }

    public function test_the_same_or_a_later_date_needs_no_tick(): void
    {
        $this->previousRun();

        $sameDay = Carbon::now('Asia/Colombo')->subDay()->toDateString();

        $this->actingAs($this->accountant())
            ->get(route('system-run.index', ['cutoff' => $sameDay]))
            ->assertOk()
            ->assertDontSee('This date is earlier than the last System Run.');

        $this->actingAs($this->accountant())
            ->post(route('system-run.run'), ['confirm' => 'RUN', 'cutoff' => Carbon::now('Asia/Colombo')->toDateString()])
            ->assertRedirect()
            ->assertSessionMissing('error');

        $this->assertSame(2, SystemRun::count(), 'a later date runs without any tick');
    }

    public function test_the_very_first_run_needs_no_tick(): void
    {
        $this->actingAs($this->accountant())
            ->get(route('system-run.index', ['cutoff' => $this->twoDaysAgo()]))
            ->assertOk()
            ->assertDontSee('This date is earlier than the last System Run.');
    }
}
