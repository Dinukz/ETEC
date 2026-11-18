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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A System Run scopes by the SELECTED PAYMENT DATE (paid_at), not the entry date.
 *
 * Owner, 2026-09-14: "payment eka kawada dammath ... select krana Payment Date e
 * widiyt en one". Worked example: a payment DATED 11 Sep for SCN-00295, verified
 * today (14 Sep), must settle in a System Run dated 11 Sep. The consultant picks
 * the Payment Date on the form; it is stored as paid_at with that exact date and
 * whatever the time of submission was.
 */
class SystemRunScopesByPaymentDateTest extends TestCase
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

    /**
     * Register a student and verify TODAY, but with the consultant's chosen
     * Payment Date backdated to $paymentDay — stored the real way (that date,
     * at the time of submission), and entered/verified now.
     */
    private function addStudentDated(Consultant $account, int $leg, string $paymentDay): void
    {
        $user = User::factory()->create();
        Student::create([
            'user_id' => $user->id, 'student_code' => 'SRN'.Str::random(6),
            'status' => 'active', 'enrolled_at' => now(),
        ]);

        $payment = Payment::create([
            'payment_code' => 'PYM'.Str::random(6), 'user_id' => $user->id, 'course_id' => Course::first()->id,
            'consultant_id' => $account->id, 'requested_tier' => $leg, 'amount' => 28000, 'currency' => 'LKR',
            'method' => 'bank_transfer', 'payment_type' => 'full', 'status' => 'pending',
            // Exactly how StudentController builds it: the chosen date at now()'s time.
            'paid_at' => Carbon::parse($paymentDay)->setTimeFrom(now()),
        ]);

        app(PaymentService::class)->verify($payment->fresh(), $this->accountant());
    }

    private function legs(Consultant $account): array
    {
        $group = Group::where('consultant_id', $account->id)->orderBy('id')->firstOrFail();
        $flush = app(ServiceGroupFlush::class);

        return [$flush->legBalance($account, $group, 1), $flush->legBalance($account, $group, 2)];
    }

    private function runFor(string $day): SystemRun
    {
        $this->actingAs($this->accountant())
            ->post(route('system-run.run'), ['confirm' => 'RUN', 'cutoff' => $day, 'confirm_earlier' => '1'])
            ->assertRedirect();

        return SystemRun::latest('id')->firstOrFail();
    }

    // ----- the owner's example -----------------------------------------------------

    /** ⚠️ THE REPORT. A payment dated the 11th, verified today, settles in an 11th run. */
    public function test_a_payment_dated_the_11th_settles_in_an_11th_run(): void
    {
        $eleventh = Carbon::now('Asia/Colombo')->subDays(3)->toDateString();
        $account = $this->account();

        // A balanced 1-to-1 pair, both DATED the 11th, both verified today.
        $this->addStudentDated($account, 1, $eleventh);
        $this->addStudentDated($account, 2, $eleventh);

        $this->assertSame([(float) self::SLOT, (float) self::SLOT], $this->legs($account), 'guard: on the legs');

        $run = $this->runFor($eleventh);

        $settled = collect($run->settledAccounts())->firstWhere('account_id', $account->id);
        $this->assertNotNull($settled, 'the account settled in the 11th run');
        $this->assertSame(5000.0, (float) $settled['released']);
        $this->assertSame([0.0, 0.0], $this->legs($account->fresh()), 'both legs cleared');
    }

    /** ...and a run dated the DAY BEFORE the payment date does not settle it. */
    public function test_a_run_before_the_payment_date_settles_nothing(): void
    {
        $eleventh = Carbon::now('Asia/Colombo')->subDays(3);
        $account = $this->account();

        $this->addStudentDated($account, 1, $eleventh->toDateString());
        $this->addStudentDated($account, 2, $eleventh->toDateString());

        $tenth = $eleventh->copy()->subDay()->toDateString();

        $preview = $this->actingAs($this->accountant())
            ->get(route('system-run.index', ['cutoff' => $tenth]))
            ->assertOk()
            ->viewData('preview');

        $this->assertSame(0, $preview['count'], 'the 10th run touches nothing dated the 11th');
    }

    /**
     * ⚠️ THE WHOLE POINT: the ENTRY date does not decide it, the PAYMENT DATE does.
     * A payment ENTERED today but DATED the 8th settles in an 8th run; one ENTERED
     * days ago but DATED today does not.
     */
    public function test_the_entry_date_does_not_decide_scope(): void
    {
        $account = $this->account();
        $eighth = Carbon::now('Asia/Colombo')->subDays(6)->toDateString();

        // Dated the 8th, entered + verified now.
        $this->addStudentDated($account, 1, $eighth);
        $this->addStudentDated($account, 2, $eighth);

        // A run up to the 8th settles them, even though they were entered today.
        $run = $this->runFor($eighth);
        $this->assertNotNull(collect($run->settledAccounts())->firstWhere('account_id', $account->id));
    }

    /** A row with no paid_at falls back to its entry date, so it is never stranded. */
    public function test_a_row_without_a_payment_date_falls_back_to_entry(): void
    {
        $account = $this->account();
        $twoDaysAgo = Carbon::now('UTC')->subDays(2);

        foreach ([1, 2] as $leg) {
            $user = User::factory()->create();
            Student::create(['user_id' => $user->id, 'student_code' => 'SRN'.Str::random(6), 'status' => 'active', 'enrolled_at' => now()]);
            $payment = Payment::create([
                'payment_code' => 'PYM'.Str::random(6), 'user_id' => $user->id, 'course_id' => Course::first()->id,
                'consultant_id' => $account->id, 'requested_tier' => $leg, 'amount' => 28000, 'currency' => 'LKR',
                'method' => 'bank_transfer', 'payment_type' => 'full', 'status' => 'pending', 'paid_at' => now(),
            ]);
            app(PaymentService::class)->verify($payment->fresh(), $this->accountant());
            // Strip paid_at and pin the entry date, the shape of old / free-card rows.
            DB::table('payments')->where('id', $payment->id)->update(['paid_at' => null, 'created_at' => $twoDaysAgo]);
        }

        $run = $this->runFor(Carbon::now('Asia/Colombo')->subDay()->toDateString());
        $this->assertNotNull(collect($run->settledAccounts())->firstWhere('account_id', $account->id), 'settled by its entry date');
    }
}
