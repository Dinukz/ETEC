<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\Course;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use App\Services\EnrollmentService;
use App\Services\FinanceSchemaRepair;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ⚠️ A REJECTED BALANCE PAYMENT STAYS A BALANCE PAYMENT (2026-09-30).
 *
 * Reported on SRN-07405: a half payment, verified; then the balance slip,
 * rejected by the admin. "Re-attach Slip" offered Full / Half / Free card with
 * FULL pre-selected — no option matched "balance", so the browser fell to the
 * first — and resubmitPayment() rewrote the row's type and amount from the
 * pick. Sent as "full" and verified, the balance would have become a second
 * full purchase: the whole course billed again, and the engine handed a fresh
 * purchase to pay commission on. *"eya half gewala thiyenne."*
 *
 * The fixture uses the real routes throughout — confirm, verify, pay-balance,
 * reject, resubmit — because the bug lived in how those hand the row on.
 */
class RejectedBalanceStaysABalanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        FinanceSchemaRepair::ensure();
        Storage::fake('public');

        // AppServiceProvider reads mail settings on every request boot; see
        // RegisterOnADownlineAccountTest for why this is primed.
        Cache::forever('settings.mail.grouped', collect());
    }

    private function consultantUser(): User
    {
        return User::where('email', 'consultant@etec.lk')->firstOrFail();
    }

    private function accountant(): User
    {
        return User::where('email', 'accountant@etec.lk')->firstOrFail();
    }

    private function course(): Course
    {
        return Course::where('status', 'published')->firstOrFail();
    }

    /** A student whose HALF payment is verified. */
    private function halfPaid(string $name): Payment
    {
        $consultant = $this->consultantUser()->consultant;

        $user = User::create([
            'name' => $name,
            'email' => Str::slug($name).Str::random(4).'@example.com',
            'password' => Hash::make('secret-password'),
            'status' => 'active',
        ]);
        $user->assignRole('student');

        Student::create([
            'user_id' => $user->id, 'student_code' => 'SRN'.Str::random(6),
            'consultant_id' => $consultant->id, 'status' => 'active', 'enrolled_at' => now(),
        ]);

        $half = Payment::create([
            'payment_code' => 'PYM'.Str::random(6), 'user_id' => $user->id,
            'course_id' => $this->course()->id, 'consultant_id' => $consultant->id,
            'requested_tier' => 1,
            'amount' => EnrollmentService::amountForType($this->course(), 'half'),
            'currency' => 'LKR', 'method' => 'bank_transfer',
            'payment_type' => 'half', 'status' => 'pending', 'paid_at' => now(),
        ]);

        app(PaymentService::class)->verify($half, $this->accountant());

        return $half->fresh();
    }

    /** The balance, recorded through the real Pay Balance form. */
    private function balanceFor(Payment $half): Payment
    {
        $this->actingAs($this->consultantUser())
            ->post(route('consultant.payments.pay-balance', $half), [
                'payment_date' => now()->toDateString(),
                'payment_reference_number' => 'BAL-'.Str::random(4),
                'payment_receipt' => UploadedFile::fake()->image('slip.jpg'),
            ])
            ->assertSessionHasNoErrors();

        return Payment::where('user_id', $half->user_id)->where('payment_type', 'balance')->latest('id')->firstOrFail();
    }

    private function reject(Payment $payment): Payment
    {
        $this->actingAs($this->accountant())
            ->patch(route('payments.reject', $payment), ['remarks' => 'Wrong slip'])
            ->assertRedirect();

        return $payment->fresh();
    }

    /** Re-attach exactly as the OLD modal would have sent it: plan = full. */
    private function resubmitAsTheOldModalDid(Payment $payment): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->consultantUser())
            ->post(route('consultant.payments.resubmit', $payment), [
                'payment_type' => 'full',
                'payment_date' => now()->toDateString(),
                'payment_reference_number' => 'BAL-FIXED',
                'payment_receipt' => UploadedFile::fake()->image('fixed.jpg'),
            ]);
    }

    // ------------------------------------------------------------------------

    /** Guard: the fixture really is a verified half and a rejected balance. */
    public function test_the_fixture_is_a_verified_half_and_a_rejected_balance(): void
    {
        $half = $this->halfPaid('Guard Student');
        $balance = $this->reject($this->balanceFor($half));

        $this->assertSame('verified', $half->status);
        $this->assertSame('balance', $balance->payment_type);
        $this->assertSame('rejected', $balance->status);
    }

    /**
     * ⚠️ THE REPORTED CASE. Whatever the form sends, the row stays a balance,
     * for what is actually left to pay.
     */
    public function test_a_rejected_balance_resubmits_as_a_balance(): void
    {
        $half = $this->halfPaid('Wanigabadhu');
        $balance = $this->reject($this->balanceFor($half));

        $owed = (float) $this->course()->effective_price - (float) $half->amount;

        $this->resubmitAsTheOldModalDid($balance)->assertSessionHasNoErrors();

        $balance->refresh();

        $this->assertSame('balance', $balance->payment_type, 'never turned into a full purchase');
        $this->assertSame($owed, (float) $balance->amount, 'the rest of the course, not the whole of it');
        $this->assertSame('pending', $balance->status, 'back with the accountant');
    }

    /**
     * ⚠️ AND VERIFYING IT PAYS EXACTLY WHAT AN ORDINARY BALANCE PAYS.
     *
     * A control student pays half, then the balance, with no rejection. The
     * student whose balance was rejected and re-attached must end up with the
     * same payments total and the same commission — not a second purchase's
     * worth.
     */
    public function test_verifying_it_pays_the_same_as_an_ordinary_balance(): void
    {
        // Control: half, balance, verified — nothing rejected.
        $controlHalf = $this->halfPaid('Control Student');
        app(PaymentService::class)->verify($this->balanceFor($controlHalf), $this->accountant());

        // Subject: half, balance rejected, re-attached, then verified.
        $half = $this->halfPaid('Subject Student');
        $balance = $this->reject($this->balanceFor($half));
        $this->resubmitAsTheOldModalDid($balance)->assertSessionHasNoErrors();
        app(PaymentService::class)->verify($balance->fresh(), $this->accountant());

        $paid = fn (Payment $p) => (float) Payment::where('user_id', $p->user_id)
            ->where('course_id', $p->course_id)->where('status', 'verified')->sum('amount');

        $this->assertSame((float) $this->course()->effective_price, $paid($half), 'the course is paid once, in full');
        $this->assertSame($paid($controlHalf), $paid($half));

        $earned = fn (Payment $p) => [
            Commission::where('user_id', $p->user_id)->where('status', '!=', 'cancelled')->count(),
            (float) Commission::where('user_id', $p->user_id)->where('status', '!=', 'cancelled')->sum('amount'),
        ];

        $this->assertSame($earned($controlHalf), $earned($half), 'same commission as a balance that was never rejected');
    }

    /**
     * A balance already on its way means the rejected one cannot come back too.
     *
     * Two guards stand here, deliberately: the explicit "another balance is
     * live" check, and remainingBalance() reading zero once that other balance
     * is counted. Removing either one alone leaves this test green — checked
     * by mutation — because the other still refuses. That is the point; this
     * pins the behaviour, not one line.
     */
    public function test_it_cannot_be_resubmitted_when_another_balance_is_live(): void
    {
        $half = $this->halfPaid('Double Balance');
        $rejected = $this->reject($this->balanceFor($half));

        // A fresh balance recorded through the form after the rejection.
        $this->balanceFor($half);

        $this->resubmitAsTheOldModalDid($rejected)->assertSessionHas('error');

        $this->assertSame('rejected', $rejected->fresh()->status, 'left alone');
    }

    /** A balance always has a slip number — the Pay Balance form's own rule. */
    public function test_a_reference_is_required_for_a_balance(): void
    {
        $half = $this->halfPaid('No Reference');
        $balance = $this->reject($this->balanceFor($half));

        $this->actingAs($this->consultantUser())
            ->post(route('consultant.payments.resubmit', $balance), [
                'payment_date' => now()->toDateString(),
                'payment_receipt' => UploadedFile::fake()->image('fixed.jpg'),
            ])
            ->assertSessionHasErrors('payment_reference_number');
    }

    /** The modal no longer offers a plan for a balance. */
    public function test_the_modal_shows_a_balance_not_a_plan_choice(): void
    {
        $half = $this->halfPaid('Modal Student');
        $balance = $this->reject($this->balanceFor($half));

        $html = view('consultant.students._resubmit-payment-modal', ['pay' => $balance])->render();

        $this->assertStringContainsString('Balance payment', $html);
        $this->assertStringNotContainsString('name="payment_type"', $html, 'no Full / Half / Free choice');
    }

    /** A rejected FULL payment still offers the choice — unchanged. */
    public function test_a_rejected_full_payment_still_offers_the_plan(): void
    {
        $consultant = $this->consultantUser()->consultant;
        $user = User::factory()->create();

        $full = Payment::create([
            'payment_code' => 'PYM'.Str::random(6), 'user_id' => $user->id,
            'course_id' => $this->course()->id, 'consultant_id' => $consultant->id,
            'requested_tier' => 1, 'amount' => 28000, 'currency' => 'LKR',
            'method' => 'bank_transfer', 'payment_type' => 'full', 'status' => 'rejected',
        ]);

        $html = view('consultant.students._resubmit-payment-modal', ['pay' => $full])->render();

        $this->assertStringContainsString('name="payment_type"', $html);
    }
}
