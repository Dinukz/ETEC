<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\CompanyRevenue;
use App\Models\Course;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Income belongs to the day the money moved, not the day someone got round to
 * checking the slip.
 *
 * At 1000+ registrations a day the accountant cannot verify same-day, so
 * verification routinely runs a week behind. Stamping the commission with
 * now() put every finance report on the wrong date; the owner reported it on
 * 2026-09-11 and chose `paid_at` — the "Payment Date" field on the Confirm
 * Payment dialog — as the date that counts.
 *
 * Pins App\Services\CommissionService::earnedAtFor() and the CompanyRevenue
 * insert in App\Services\PaymentService::verify().
 */
class CommissionEarnedOnPaymentDateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    /** A payment banked on $paidAt but still sitting unverified. */
    private function pendingPayment(int $consultantId, int $tier, \Illuminate\Support\Carbon $paidAt): Payment
    {
        $user = User::factory()->create();
        Student::create([
            'user_id' => $user->id,
            'student_code' => 'SRN'.Str::random(6),
            'status' => 'pending',
            'enrolled_at' => null,
        ]);

        return Payment::create([
            'payment_code' => 'PYM'.Str::random(6),
            'user_id' => $user->id,
            'course_id' => Course::first()->id,
            'consultant_id' => $consultantId,
            'requested_tier' => $tier,
            'amount' => 28000,
            'currency' => 'LKR',
            'method' => 'bank_transfer',
            'payment_type' => 'full',
            'status' => 'pending',
            'paid_at' => $paidAt,
        ]);
    }

    public function test_commission_is_earned_on_the_payment_date_not_the_verification_date(): void
    {
        $consultant = User::where('email', 'consultant@etec.lk')->firstOrFail()->consultant;
        $account = $consultant->hiddenAccounts()->first();
        $accountant = User::where('email', 'accountant@etec.lk')->firstOrFail();

        $paidAt = now()->subDays(9)->startOfDay()->addHours(10);

        $payment = $this->pendingPayment($account->id, 1, $paidAt);

        app(PaymentService::class)->verify($payment, $accountant, null);

        $commissions = Commission::where('payment_id', $payment->id)->get();

        $this->assertTrue($commissions->isNotEmpty(), 'Verifying the payment must create commission rows.');

        foreach ($commissions as $row) {
            $this->assertNotNull($row->earned_at, 'Every commission must carry an earned_at.');
            $this->assertSame(
                $paidAt->toDateString(),
                $row->earned_at->toDateString(),
                'Commission must be earned on the payment date, not the day it was verified.',
            );
        }

        $this->assertNotSame(
            now()->toDateString(),
            $commissions->first()->earned_at->toDateString(),
            'The test is meaningless unless the payment date and today actually differ.',
        );
    }

    public function test_company_revenue_is_recorded_on_the_payment_date(): void
    {
        $consultant = User::where('email', 'consultant@etec.lk')->firstOrFail()->consultant;
        $account = $consultant->hiddenAccounts()->first();
        $accountant = User::where('email', 'accountant@etec.lk')->firstOrFail();

        $paidAt = now()->subDays(4)->startOfDay()->addHours(15);

        $payment = $this->pendingPayment($account->id, 1, $paidAt);

        app(PaymentService::class)->verify($payment, $accountant, null);

        $revenue = CompanyRevenue::where('payment_id', $payment->id)->first();

        $this->assertNotNull($revenue, 'Verifying a payment must record the company remainder.');
        $this->assertSame(
            $paidAt->toDateString(),
            $revenue->recorded_at->toDateString(),
            'Company income has to land on the same day as the commissions it is the remainder of.',
        );
    }

    /**
     * Older payments predate the Payment Date field, so paid_at can be NULL.
     * Those must still be dated rather than dropping out of the reports.
     */
    public function test_a_payment_with_no_payment_date_falls_back_to_when_it_was_created(): void
    {
        $consultant = User::where('email', 'consultant@etec.lk')->firstOrFail()->consultant;
        $account = $consultant->hiddenAccounts()->first();
        $accountant = User::where('email', 'accountant@etec.lk')->firstOrFail();

        $payment = $this->pendingPayment($account->id, 1, now());
        $payment->forceFill(['paid_at' => null])->save();

        app(PaymentService::class)->verify($payment->fresh(), $accountant, null);

        $commission = Commission::where('payment_id', $payment->id)->first();

        $this->assertNotNull($commission, 'A payment with no paid_at must still pay commission.');
        $this->assertNotNull($commission->earned_at, 'It must still be dated, from created_at.');
        $this->assertSame(
            $payment->created_at->toDateString(),
            $commission->earned_at->toDateString(),
        );
    }
}
