<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\Consultant;
use App\Models\Course;
use App\Models\Group;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\Student;
use App\Models\SystemRun;
use App\Models\User;
use App\Services\PaymentService;
use App\Services\ServiceGroupFlush;
use App\Services\ServiceGroupStaging;
use App\Services\SystemRunReversal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A System Run for an old date still ends a 3-to-3 account at zero.
 *
 * Reported 2026-09-13/14. The owner re-ran "the 9th's" System Run on the test
 * site. SCN-00421 held SG1 17,500 / SG2 32,500 at 3-to-3; the consultant was
 * paid 15,000, but only 2,500 went to the company and 10,000 / 22,500 stayed
 * on the legs, because that money was entered after 9 Sep. The owner's rule:
 *
 *   SG1 17,500 → 7,500 to the consultant, 10,000 to the company
 *   SG2 32,500 → 7,500 to the consultant, 25,000 to the company
 *
 * — and running for old dates must stay possible: "day wise select krann one
 * ... naththn kohomd parana date walat yawanne system run". So the date still
 * decides who qualifies and what is released; at 3-to-3 the surplus reaches
 * past it.
 */
class OldDateRunEndsThreeToThreeAtZeroTest extends TestCase
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

    /** Register + verify a student; the PAYMENT DATE (paid_at — the run's scope key) is back-dated when given. */
    private function addStudent(Consultant $account, int $leg, ?Carbon $paymentDateUtc = null): void
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

        // Since 2026-09-14 the run scopes by the selected Payment Date (paid_at),
        // not the entry time — so that is what these tests back-date.
        if ($paymentDateUtc) {
            DB::table('payments')->where('id', $payment->id)->update(['paid_at' => $paymentDateUtc, 'created_at' => $paymentDateUtc]);
        }

        app(PaymentService::class)->verify($payment->fresh(), $this->accountant());
    }

    /** The old date the run is for: three days back, so money entered today is after it. */
    private function oldDate(): Carbon
    {
        return Carbon::now('Asia/Colombo')->subDays(3);
    }

    /**
     * Seat students on an account: $before[leg] students entered before the
     * old date, $after[leg] entered today.
     *
     * @param  array<int, int>  $before
     * @param  array<int, int>  $after
     */
    private function seat(Consultant $account, array $before, array $after): void
    {
        $beforeTheDate = Carbon::now('UTC')->subDays(5);

        foreach ($before as $leg => $students) {
            for ($i = 0; $i < $students; $i++) {
                $this->addStudent($account, $leg, $beforeTheDate);
            }
        }

        foreach ($after as $leg => $students) {
            for ($i = 0; $i < $students; $i++) {
                $this->addStudent($account, $leg);
            }
        }
    }

    private function atStage(Consultant $account, int $stage): Consultant
    {
        $account->forceFill(['lifetime_pair_stage' => $stage])->save();

        return $account->fresh();
    }

    /** SCN-00421 as it stood in the report: 7,500 / 10,000 before the date, 10,000 / 22,500 after. */
    private function theReportedAccount(): Consultant
    {
        $account = $this->atStage($this->account(), ServiceGroupStaging::ONE_TIME_STAGES);
        $this->seat($account, before: [1 => 3, 2 => 4], after: [1 => 4, 2 => 9]);

        return $account->fresh();
    }

    /** @return array{0: float, 1: float} what each leg holds, with no date at all */
    private function legs(Consultant $account): array
    {
        $group = Group::where('consultant_id', $account->id)->orderBy('id')->firstOrFail();
        $flush = app(ServiceGroupFlush::class);

        return [$flush->legBalance($account->fresh(), $group, 1), $flush->legBalance($account->fresh(), $group, 2)];
    }

    private function runFor(Carbon $date): SystemRun
    {
        $this->actingAs($this->accountant())
            ->post(route('system-run.run'), ['confirm' => 'RUN', 'cutoff' => $date->toDateString()])
            ->assertRedirect()
            ->assertSessionMissing('error');

        return SystemRun::latest('id')->firstOrFail();
    }

    /** @return array<string, mixed>|null this account's line in the run record */
    private function settled(SystemRun $run, Consultant $account): ?array
    {
        return collect($run->settledAccounts())->firstWhere('account_id', $account->id);
    }

    // ----- the report -----------------------------------------------------------

    /** ⚠️ THE REPORT, with the owner's numbers, run for a date three days back. */
    public function test_an_old_date_run_ends_a_3_to_3_account_at_zero(): void
    {
        $account = $this->theReportedAccount();

        $this->assertSame([17500.0, 32500.0], $this->legs($account), 'guard: the legs as they stood in the report');

        $run = $this->runFor($this->oldDate());
        $settled = $this->settled($run, $account);

        $this->assertNotNull($settled, 'the account took part in the run');
        $this->assertSame(15000.0, (float) $settled['released'], '7,500 a side to the consultant');
        $this->assertSame(35000.0, (float) $settled['forfeited'], '10,000 + 25,000 to the company');
        $this->assertSame([0.0, 0.0], $this->legs($account), 'both sides end at zero');

        $this->assertSame(
            $this->oldDate()->toDateString(),
            substr((string) $run->getRawOriginal('cutoff_at'), 0, 10),
            'the run is recorded for the date it was run for',
        );
    }

    /** The preview is what the accountant decides on, so it must say the same. */
    public function test_the_preview_for_that_date_says_the_same(): void
    {
        $account = $this->theReportedAccount();

        $response = $this->actingAs($this->accountant())
            ->get(route('system-run.index', ['cutoff' => $this->oldDate()->toDateString()]))
            ->assertOk()
            ->assertSee('name="cutoff" type="date"', false);

        $row = collect($response->viewData('preview')['accounts'])->firstWhere('id', $account->id);

        $this->assertNotNull($row, 'the account is listed to flush');
        $this->assertSame(15000.0, (float) $row['payout']);
        $this->assertSame(35000.0, (float) $row['forfeit']);
    }

    // ----- what the date still decides ---------------------------------------------

    /**
     * ⚠️ THE DATE STILL DECIDES WHO QUALIFIES. SG1 holds only 5,000 from before the
     * date — short of 7,500 — so a run for that date must not touch the account,
     * however much arrived afterwards.
     */
    public function test_the_date_still_decides_who_qualifies(): void
    {
        $account = $this->atStage($this->account(), ServiceGroupStaging::ONE_TIME_STAGES);
        $this->seat($account, before: [1 => 2, 2 => 3], after: [1 => 4]);

        $this->assertSame([15000.0, 7500.0], $this->legs($account), 'guard: plenty on the legs, but not before the date');

        $run = $this->runFor($this->oldDate());

        $this->assertNull($this->settled($run, $account), 'not settled — SG1 was short on that date');
        $this->assertSame([15000.0, 7500.0], $this->legs($account), 'and nothing was taken');
    }

    /** On the ramp, money entered after the date stays on the leg, exactly as before. */
    public function test_the_ramp_keeps_money_entered_after_the_date(): void
    {
        $account = $this->atStage($this->account(), 0);
        $this->seat($account, before: [1 => 1, 2 => 1], after: [1 => 2]);

        $run = $this->runFor($this->oldDate());
        $settled = $this->settled($run, $account);

        $this->assertNotNull($settled, 'the 1-to-1 pair settled');
        $this->assertSame(5000.0, (float) $settled['released']);
        $this->assertSame(0.0, (float) $settled['forfeited'], 'the ramp forfeits nothing');
        $this->assertSame([5000.0, 0.0], $this->legs($account), 'the two later students stay on Service Group 1');
    }

    /** Held income is never forfeited — a restricted consultant can still earn it back. */
    public function test_held_income_after_the_date_is_not_forfeited(): void
    {
        $account = $this->theReportedAccount();

        $held = Commission::where('consultant_id', $account->id)
            ->where('type', 'bonus')
            ->where('tier', 2)
            ->orderByDesc('id')
            ->firstOrFail();

        DB::table('commissions')->where('id', $held->id)->update(['held_at' => now()]);

        $run = $this->runFor($this->oldDate());

        $this->assertSame(32500.0, (float) $this->settled($run, $account)['forfeited'], '35,000 less the one held 2,500');

        $held->refresh();
        $this->assertNotSame('cancelled', $held->status, 'the held row is not forfeited');
        $this->assertNull($held->released_at, 'and not taken off the leg');
    }

    /** "Reverse this account" puts the money entered after the date back too. */
    public function test_a_reversal_puts_everything_back(): void
    {
        $account = $this->theReportedAccount();
        $run = $this->runFor($this->oldDate());

        $this->assertSame([0.0, 0.0], $this->legs($account), 'guard: the run emptied the account');

        $result = app(SystemRunReversal::class)->reverse($run, $account->id);

        $this->assertTrue($result['ok'], $result['message']);
        $this->assertSame([17500.0, 32500.0], $this->legs($account));
    }
}
