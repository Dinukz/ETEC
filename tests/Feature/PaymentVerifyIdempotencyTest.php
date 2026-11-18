<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\CompanyRevenue;
use App\Models\Course;
use App\Models\GroupMember;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Verifying the same payment twice (an impatient double-click on "Verify", or
 * two accountants racing) must never pay commission out twice. The old code
 * only guarded against this by checking $payment->status on the model
 * instance the caller already held — a SECOND request that fetched its own
 * copy of the payment before the first one committed would sail straight
 * past that check and re-run the whole commission engine. Simulated here by
 * fetching the payment into two separate variables (like two separate HTTP
 * requests each doing their own route-model-binding fetch) and verifying
 * with both, sequentially.
 */
class PaymentVerifyIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_verifying_the_same_payment_twice_never_double_pays_commission(): void
    {
        $verifier = User::where('email', 'accountant@etec.lk')->firstOrFail();
        $consultantUser = User::where('email', 'consultant@etec.lk')->firstOrFail();
        $consultant = $consultantUser->consultant;

        $service = app(PaymentService::class);

        $makePayment = function (int $tier) use ($consultant): Payment {
            $studentUser = User::factory()->create();
            Student::create(['user_id' => $studentUser->id, 'student_code' => 'SRN'.Str::random(6), 'status' => 'active', 'enrolled_at' => now()]);

            return Payment::create([
                'payment_code' => 'PYM'.Str::random(6),
                'user_id' => $studentUser->id,
                'course_id' => Course::first()->id,
                'consultant_id' => $consultant->id,
                'requested_tier' => $tier,
                'amount' => 28000,
                'currency' => 'LKR',
                'method' => 'bank_transfer',
                'payment_type' => 'full',
                'status' => 'pending',
                'paid_at' => now(),
            ]);
        };

        // First student (leg 1) earns its own slot AND direct bonus immediately
        // — neither waits for anything else. Normal single verify.
        $service->verify(Payment::find($makePayment(1)->id), $verifier);
        $this->assertSame(1, Commission::where('type', 'bonus')->count(), 'The slot pays immediately, no pairing wait.');
        $this->assertSame(1, Commission::where('type', 'referral')->count(), 'The direct bonus is paid on verification.');

        // Second student (leg 2) SHOULD pay 5,500 (2,500 slot + 3,000 direct)
        // exactly once — this is the payment we "double click" verify.
        $secondPayment = $makePayment(2);
        $copyA = Payment::find($secondPayment->id);
        $copyB = Payment::find($secondPayment->id);

        $service->verify($copyA, $verifier);
        $service->verify($copyB, $verifier); // the "double click"

        // One slot row + one direct-bonus row for THIS payment. The key
        // assertion is "not doubled" (it would be 4 if the double-click had
        // paid twice).
        $this->assertSame(2, Commission::where('payment_id', $secondPayment->id)->count(), 'One slot row + one direct bonus, not doubled.');
        $this->assertSame(1, CompanyRevenue::where('payment_id', $secondPayment->id)->count(), 'Only one revenue row should exist.');
        $this->assertSame(1, GroupMember::where('payment_id', $secondPayment->id)->count(), 'The student must only occupy one slot, not two.');
        $this->approveAllCommissions();

        $this->assertSame(11000.0, (float) $consultant->fresh()->total_earned, 'Two independent students (5,500 each), not double (22,000).');
    }
}
