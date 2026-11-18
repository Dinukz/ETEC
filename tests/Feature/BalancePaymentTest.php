<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\Course;
use App\Models\Payment;
use App\Models\User;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A half-paying student's remaining balance, settled after the fact.
 *
 * Confirmed by the business owner (2026-08-04): a half payment already earns
 * its consultant the full slot income + direct bonus the instant it is
 * verified (see HalfPaymentRulesTest) — settling the balance afterwards must
 * NOT pay that money a second time. It is recorded as its own Payment row
 * (payment_type = 'balance') so it goes through the normal accountant
 * verification queue, but the commission engine skips it entirely; the whole
 * amount becomes company revenue instead.
 */
class BalancePaymentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function registerConfirmAndVerify(User $consultantUser, string $email, int $tier, int $partnerId, string $plan, User $accountant): Payment
    {
        $course = Course::where('status', 'published')->firstOrFail();

        $this->actingAs($consultantUser)->post(route('consultant.students.store'), [
            'name' => 'Student '.$email,
            'email' => $email,
            'course_id' => $course->id,
            'placement_tier' => $tier,
            'partner_consultant_id' => $partnerId,
        ])->assertRedirect();

        $payment = Payment::whereHas('user', fn ($q) => $q->where('email', $email))->firstOrFail();

        $this->actingAs($consultantUser)
            ->patch(route('consultant.payments.confirm', $payment), ['payment_type' => $plan, 'payment_date' => now()->toDateString()])
            ->assertRedirect();

        app(PaymentService::class)->verify($payment->fresh(), $accountant);

        return $payment->fresh();
    }

    public function test_a_verified_half_payment_shows_a_pay_balance_action(): void
    {
        $consultantUser = User::where('email', 'consultant@etec.lk')->firstOrFail();
        $accountant = User::where('email', 'accountant@etec.lk')->firstOrFail();
        $hidden = $consultantUser->consultant->hiddenAccounts()->first();

        $this->registerConfirmAndVerify($consultantUser, 'balance-a@example.com', 1, $hidden->id, 'half', $accountant);

        $response = $this->actingAs($consultantUser)->get(route('consultant.students.index'));

        $response->assertOk();
        $response->assertSee('Pay Balance');
        $response->assertSee('10,000.00'); // balance due: 28,000 - 18,000
    }

    public function test_recording_the_balance_creates_a_second_payment_pending_verification(): void
    {
        $consultantUser = User::where('email', 'consultant@etec.lk')->firstOrFail();
        $accountant = User::where('email', 'accountant@etec.lk')->firstOrFail();
        $hidden = $consultantUser->consultant->hiddenAccounts()->first();

        $payment = $this->registerConfirmAndVerify($consultantUser, 'balance-b@example.com', 1, $hidden->id, 'half', $accountant);

        $this->actingAs($consultantUser)
            ->post(route('consultant.payments.pay-balance', $payment), ['payment_reference_number' => 'REF-1', 'payment_date' => now()->toDateString()])
            ->assertRedirect();

        $this->assertDatabaseHas('payments', [
            'user_id' => $payment->user_id,
            'course_id' => $payment->course_id,
            'payment_type' => 'balance',
            'status' => 'pending',
            'amount' => 10000,
        ]);
    }

    /**
     * ⚠️ REVERSED 2026-08-23 — the balance now earns the SLOT income.
     *
     * This asserted the opposite: that the half payment earned everything
     * (5,500) and the balance earned nothing. The owner has split the two —
     * "half payment kranwanm eyta 2500 add wen na, 4000 withri add wenne...
     * full ekak dammoth withrk ita passe 2500 add wenwa" — so the bonus is
     * earned when the student is brought in and the 2,500 when the course is
     * actually paid for. The TOTAL across both payments is unchanged.
     */
    public function test_the_balance_payment_earns_the_slot_income_the_half_did_not(): void
    {
        $consultantUser = User::where('email', 'consultant@etec.lk')->firstOrFail();
        $accountant = User::where('email', 'accountant@etec.lk')->firstOrFail();
        $hidden = $consultantUser->consultant->hiddenAccounts()->first();

        $payment = $this->registerConfirmAndVerify($consultantUser, 'balance-c@example.com', 1, $hidden->id, 'half', $accountant);

        // The bonus only — the student was brought in, the course is half paid.
        $this->assertSame(3000.0, (float) Commission::where('payment_id', $payment->id)->sum('amount'));

        $this->actingAs($consultantUser)
            ->post(route('consultant.payments.pay-balance', $payment), ['payment_date' => now()->toDateString()])
            ->assertRedirect();

        $balancePayment = Payment::where('user_id', $payment->user_id)->where('payment_type', 'balance')->firstOrFail();

        $this->actingAs($accountant)
            ->patch(route('payments.verify', $balancePayment))
            ->assertRedirect();

        // ⚠️ 2,500 -> 5,000 on 2026-09-02, and the RULE is unchanged: one 2,500
        // per account the climb passes through. This sums every account, and the
        // climb now reaches the consultant's main as well as the placement
        // account the student sits on — the main used to be skipped.
        //
        // What this test is for is the TIMING, and that is untouched: the half
        // payment earned no slot income at all (asserted above), and settling
        // the balance is what earns it.
        $this->assertSame(
            5000.0,
            (float) Commission::where('payment_id', $balancePayment->id)->where('type', 'bonus')->sum('amount'),
            'The balance earns the 2,500 — on the placement account and on the main above it.',
        );
        $this->assertSame(
            0,
            Commission::where('payment_id', $balancePayment->id)->where('type', 'referral')->count(),
            'And no second direct bonus.',
        );
        // ⚠️ 5,500 -> 8,000 on 2026-09-02: the same 2,500, recorded once more on
        // the main the climb now reaches. The split this test pins is unchanged
        // — 3,000 direct at half time, 2,500 slot when the balance lands, and
        // never the other way round.
        $this->assertSame(8000.0, (float) Commission::sum('amount'), 'The split across the two payments is unchanged.');

        // Company revenue follows automatically: PaymentService::verify() books
        // `amount - commissions written against this payment_id`.
        //
        // ⚠️⚠️ 7,500 -> 5,000 ON 2026-09-02, AND THIS IS A REAL BUSINESS
        // CONSEQUENCE, NOT A TEST ADJUSTMENT. THE COMPANY KEEPS 2,500 LESS PER
        // REGISTRATION THAT CLIMBS TO A MAIN.
        //
        // Nothing about revenue was changed. It is arithmetic: giving the main
        // account its own commission row means one more 2,500 is written against
        // the payment, and revenue is the remainder. It is the direct, intended
        // cost of the owner's 2026-09-02 decision that the main must hold and
        // settle money of its own, chosen after being shown the alternatives —
        // and it is capped the same way as every other account, since a
        // repeating-stage run forfeits everything past 7,500 a side back to the
        // company.
        //
        // If this figure ever needs to go back up, the change is to the MODEL
        // (stop crediting the main), not to revenue booking. Do not "fix" it
        // here.
        $this->assertDatabaseHas('company_revenues', ['payment_id' => $balancePayment->id, 'amount' => 5000]);
    }

    public function test_the_balance_action_disappears_once_it_has_been_recorded(): void
    {
        $consultantUser = User::where('email', 'consultant@etec.lk')->firstOrFail();
        $accountant = User::where('email', 'accountant@etec.lk')->firstOrFail();
        $hidden = $consultantUser->consultant->hiddenAccounts()->first();

        $payment = $this->registerConfirmAndVerify($consultantUser, 'balance-d@example.com', 1, $hidden->id, 'half', $accountant);

        $this->actingAs($consultantUser)->post(route('consultant.payments.pay-balance', $payment), ['payment_date' => now()->toDateString()]);

        // A second attempt must be rejected — a balance payment is already open.
        $this->actingAs($consultantUser)
            ->post(route('consultant.payments.pay-balance', $payment), ['payment_date' => now()->toDateString()])
            ->assertSessionHas('error');

        $this->assertSame(1, Payment::where('user_id', $payment->user_id)->where('payment_type', 'balance')->count());
    }

    public function test_a_full_payment_has_no_balance_to_pay(): void
    {
        $consultantUser = User::where('email', 'consultant@etec.lk')->firstOrFail();
        $accountant = User::where('email', 'accountant@etec.lk')->firstOrFail();
        $hidden = $consultantUser->consultant->hiddenAccounts()->first();

        $payment = $this->registerConfirmAndVerify($consultantUser, 'balance-e@example.com', 1, $hidden->id, 'full', $accountant);

        $this->actingAs($consultantUser)
            ->post(route('consultant.payments.pay-balance', $payment), ['payment_date' => now()->toDateString()])
            ->assertSessionHas('error');

        $this->assertSame(0, Payment::where('user_id', $payment->user_id)->where('payment_type', 'balance')->count());
    }
}
