<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Payment;
use App\Models\SystemRun;
use App\Models\User;
use App\Support\ConsultantPaymentDates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The date a consultant may put on a payment slip (2026-09-22).
 *
 * Owner: "system run wela thiyen daws saha future date walin date dekak withrk
 * pennala anith date block krala denn", then "Add Tomorrow". So: yesterday, today
 * and tomorrow (Sri Lanka) — and never a day a System Run has already settled.
 * Enforced on the server; the date box only mirrors it.
 */
class ConsultantPaymentDateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        \App\Services\FinanceSchemaRepair::ensure();
        Storage::fake('public');

        // Midday in Sri Lanka, so "today" is the same date in UTC and Colombo.
        Carbon::setTestNow(Carbon::parse('2026-09-22 12:00', 'Asia/Colombo'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function consultantUser(): User
    {
        return User::where('email', 'consultant@etec.lk')->firstOrFail();
    }

    private function unconfirmedPayment(string $status = 'pending', ?string $type = null): Payment
    {
        $student = User::factory()->create();
        $student->syncRoles(['student']);
        // Pending Students lists Student rows, so the table can show this one.
        \App\Models\Student::create([
            'user_id' => $student->id, 'student_code' => 'SRN'.Str::random(6),
            'consultant_id' => $this->consultantUser()->consultant->id, 'status' => 'active', 'enrolled_at' => now(),
        ]);
        $course = Course::where('status', 'published')->firstOrFail();
        $enrollment = Enrollment::create([
            'user_id' => $student->id, 'course_id' => $course->id, 'status' => 'pending', 'enrolled_at' => now(),
        ]);

        return Payment::create([
            'payment_code' => 'PYM'.Str::random(8), 'user_id' => $student->id,
            'course_id' => $course->id, 'enrollment_id' => $enrollment->id,
            'consultant_id' => $this->consultantUser()->consultant->id,
            'amount' => 0, 'currency' => 'LKR', 'method' => 'bank_transfer',
            'payment_type' => $type, 'status' => $status, 'paid_at' => null,
        ]);
    }

    private function confirm(string $date)
    {
        return $this->actingAs($this->consultantUser())
            ->from(route('consultant.students.pending'))
            ->patch(route('consultant.payments.confirm', $this->unconfirmedPayment()), [
                'payment_type' => 'free_card',
                'payment_date' => $date,
            ]);
    }

    private function settleUpTo(string $date): void
    {
        SystemRun::create([
            'run_by' => User::where('email', 'superadmin@etec.lk')->value('id'),
            'cutoff_at' => $date.' 23:59:59',
            'accounts_flushed' => 0, 'total_released' => 0, 'total_forfeited' => 0,
        ]);
    }

    public function test_yesterday_today_and_tomorrow_are_accepted(): void
    {
        foreach (['2026-09-21', '2026-09-22', '2026-09-23'] as $date) {
            $this->confirm($date)->assertSessionHasNoErrors();
        }
    }

    public function test_any_other_day_is_refused(): void
    {
        foreach (['2026-09-20', '2026-09-24', '2026-08-01'] as $date) {
            $this->confirm($date)->assertSessionHasErrors('payment_date');
        }
    }

    /** ⚠️ The point of it: a day a System Run already settled cannot be used. */
    public function test_a_day_a_system_run_already_settled_is_refused(): void
    {
        $this->settleUpTo('2026-09-21');

        $this->confirm('2026-09-21')->assertSessionHasErrors('payment_date');
        $this->confirm('2026-09-22')->assertSessionHasNoErrors();

        $this->assertSame(['2026-09-23', '2026-09-22'], ConsultantPaymentDates::allowed());
    }

    /** Runs are not always in date order — the latest DAY settled is what counts. */
    public function test_the_latest_settled_day_counts_not_the_latest_run(): void
    {
        $this->settleUpTo('2026-09-22');
        $this->settleUpTo('2026-09-10');

        $this->assertSame(['2026-09-23'], ConsultantPaymentDates::allowed());
    }

    public function test_the_balance_and_resubmit_forms_follow_the_same_rule(): void
    {
        $half = $this->unconfirmedPayment('verified', 'half');
        $half->update(['amount' => 18000, 'paid_at' => now(), 'verified_at' => now()]);

        $this->actingAs($this->consultantUser())
            ->post(route('consultant.payments.pay-balance', $half), [
                'payment_date' => '2026-09-19',
                'payment_reference_number' => 'REF-1',
                'payment_receipt' => UploadedFile::fake()->image('slip.jpg'),
            ])
            ->assertSessionHasErrors('payment_date');

        $rejected = $this->unconfirmedPayment('rejected', 'full');

        $this->actingAs($this->consultantUser())
            ->post(route('consultant.payments.resubmit', $rejected), [
                'payment_type' => 'full',
                'payment_date' => '2026-09-25',
                'payment_receipt' => UploadedFile::fake()->image('slip.jpg'),
            ])
            ->assertSessionHasErrors('payment_date');

        $this->actingAs($this->consultantUser())
            ->post(route('consultant.payments.resubmit', $rejected), [
                'payment_type' => 'full',
                'payment_date' => '2026-09-23',
                'payment_receipt' => UploadedFile::fake()->image('slip.jpg'),
            ])
            ->assertSessionHasNoErrors();
    }

    /** The form offers exactly the allowed range. */
    public function test_the_date_box_is_limited_to_the_allowed_days(): void
    {
        $this->unconfirmedPayment();

        $this->actingAs($this->consultantUser())
            ->get(route('consultant.students.pending'))
            ->assertOk()
            ->assertSee('min="2026-09-21"', false)
            ->assertSee('max="2026-09-23"', false);
    }
}
