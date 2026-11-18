<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A rejection must be RECOVERABLE, not terminal (owner-reported 2026-08-18:
 * "student pending payment eka accountant or admin reject karoth eka pending
 * eken sampurnen remove weno, eke wenna one — reject karoth student wa eyta
 * aye re attach krann puluwn wenn one slip eka").
 *
 * Before this, rejecting dropped the registration off the consultant's
 * Pending Students page entirely (it filtered status = 'pending' only) and
 * nothing in their UI could revive it — the only way forward was to delete
 * the student and register them again, burning the payment code and login.
 *
 * The same Payment row is reused on re-submission so the payment code, the
 * enrolment and the full rejection history all survive.
 */
class RejectedPaymentResubmitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Storage::fake('public');
    }

    private function consultantUser(): User
    {
        return User::where('email', 'consultant@etec.lk')->firstOrFail();
    }

    private function accountant(): User
    {
        return User::where('email', 'accountant@etec.lk')->firstOrFail();
    }

    /** A registration that the consultant has already confirmed a plan for. */
    private function confirmedPayment(string $email): Payment
    {
        $consultant = $this->consultantUser()->consultant;

        $user = User::create([
            'name' => 'Rejected Person',
            'email' => $email,
            'password' => Hash::make('secret-password'),
            'status' => 'active',
        ]);

        Student::create([
            'user_id' => $user->id,
            'student_code' => 'SRN'.Str::random(6),
            'consultant_id' => $consultant->id,
            'status' => 'active',
            'enrolled_at' => now(),
        ]);

        return Payment::create([
            'payment_code' => 'PYM'.Str::random(6),
            'user_id' => $user->id,
            'course_id' => Course::first()->id,
            'consultant_id' => $consultant->id,
            'requested_tier' => 1,
            'amount' => 28000,
            'currency' => 'LKR',
            'method' => 'bank_transfer',
            'payment_type' => 'full',
            'status' => 'pending',
            'paid_at' => now(),
        ]);
    }

    private function reject(Payment $payment, string $reason = 'Slip is unreadable'): Payment
    {
        $this->actingAs($this->accountant())
            ->patch(route('payments.reject', $payment), ['remarks' => $reason])
            ->assertRedirect();

        return $payment->fresh();
    }

    public function test_a_rejected_registration_stays_on_the_pending_students_page(): void
    {
        $payment = $this->confirmedPayment('rejected-a@example.com');
        $this->reject($payment);

        $response = $this->actingAs($this->consultantUser())->get(route('consultant.students.pending'));

        $response->assertOk();
        $response->assertSee('Rejected Person');
        $response->assertSee('Re-attach Slip');
    }

    public function test_the_rejection_reason_is_shown_so_the_consultant_knows_what_to_fix(): void
    {
        $payment = $this->confirmedPayment('rejected-b@example.com');
        $this->reject($payment, 'Amount on the slip does not match');

        $this->actingAs($this->consultantUser())
            ->get(route('consultant.students.pending'))
            ->assertOk()
            ->assertSee('Amount on the slip does not match');
    }

    public function test_re_attaching_a_slip_sends_the_same_payment_back_for_verification(): void
    {
        $payment = $this->confirmedPayment('rejected-c@example.com');
        $this->reject($payment);

        $originalCode = $payment->payment_code;

        $this->actingAs($this->consultantUser())
            ->post(route('consultant.payments.resubmit', $payment), [
                'payment_type' => 'full',
                'payment_date' => now()->toDateString(),
                'payment_reference_number' => 'CORRECTED-REF',
                'payment_receipt' => UploadedFile::fake()->create('corrected-slip.pdf', 40, 'application/pdf'),
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $fresh = $payment->fresh();

        $this->assertSame('pending', $fresh->status, 'It goes back into the accountant\'s queue.');
        $this->assertNull($fresh->verified_at, 'The old rejection timestamp must not date the new submission.');
        $this->assertSame('CORRECTED-REF', $fresh->reference_number);
        $this->assertNotNull($fresh->receipt_path, 'The corrected slip is stored.');

        // The SAME row — not a replacement — so nothing downstream is orphaned.
        $this->assertSame($originalCode, $fresh->payment_code);
        $this->assertSame(
            1,
            Payment::where('user_id', $payment->user_id)->count(),
            'Re-submitting must reuse the existing payment, not create a second one.',
        );

        // The rejection itself stays on the record.
        $this->assertDatabaseHas('payment_verifications', [
            'payment_id' => $payment->id,
            'action' => 'rejected',
        ]);
    }

    public function test_the_accountant_can_verify_a_re_submitted_payment_normally(): void
    {
        $payment = $this->confirmedPayment('rejected-d@example.com');
        $this->reject($payment);

        $this->actingAs($this->consultantUser())
            ->post(route('consultant.payments.resubmit', $payment), [
                'payment_type' => 'full',
                'payment_date' => now()->toDateString(),
                'payment_receipt' => UploadedFile::fake()->create('slip.pdf', 40, 'application/pdf'),
            ])->assertRedirect();

        $this->actingAs($this->accountant())
            ->patch(route('payments.verify', $payment))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame('verified', $payment->fresh()->status, 'A re-submitted payment verifies like any other.');
    }

    public function test_a_slip_is_required_when_re_submitting(): void
    {
        $payment = $this->confirmedPayment('rejected-e@example.com');
        $this->reject($payment);

        // Re-sending the same evidence that was just turned down is never the
        // intent, so the corrected slip is mandatory here (unlike the first
        // confirmation, where it is optional).
        $this->actingAs($this->consultantUser())
            ->post(route('consultant.payments.resubmit', $payment), [
                'payment_type' => 'full',
                'payment_date' => now()->toDateString(),
            ])
            ->assertSessionHasErrors('payment_receipt');

        $this->assertSame('rejected', $payment->fresh()->status, 'A failed re-submission leaves it rejected.');
    }

    public function test_only_a_rejected_payment_can_be_re_submitted(): void
    {
        $payment = $this->confirmedPayment('rejected-f@example.com'); // still pending

        $this->actingAs($this->consultantUser())
            ->post(route('consultant.payments.resubmit', $payment), [
                'payment_type' => 'full',
                'payment_date' => now()->toDateString(),
                'payment_receipt' => UploadedFile::fake()->create('slip.pdf', 40, 'application/pdf'),
            ])
            ->assertSessionHas('error');
    }

    public function test_a_consultant_cannot_re_submit_someone_elses_payment(): void
    {
        $payment = $this->confirmedPayment('rejected-g@example.com');
        $this->reject($payment);

        $stranger = User::factory()->create();
        $stranger->assignRole('consultant');
        \App\Models\Consultant::create([
            'user_id' => $stranger->id,
            'consultant_code' => 'CON'.Str::random(6),
            'referral_code' => strtoupper(Str::random(10)),
            'status' => 'active',
        ]);

        $this->actingAs($stranger)
            ->post(route('consultant.payments.resubmit', $payment), [
                'payment_type' => 'full',
                'payment_date' => now()->toDateString(),
                'payment_receipt' => UploadedFile::fake()->create('slip.pdf', 40, 'application/pdf'),
            ])
            ->assertForbidden();

        $this->assertSame('rejected', $payment->fresh()->status);
    }
}
