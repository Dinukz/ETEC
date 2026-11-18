<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\Course;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\User;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The instalment ("half") plan rules, confirmed by the business owner:
 *  - the first instalment is a fixed 18,000 of the 28,000 course, NOT half of
 *    it, leaving a 10,000 balance;
 *  - a half-paying student earns their consultant BOTH the 3,000 direct-add
 *    bonus AND the 2,500 service-group slot income, immediately, exactly like
 *    a full payment — the instalment plan changes nothing about what is
 *    earned, only how much of the course price has been collected so far.
 *    (Before 2026-08-04 a half payment earned no slot income at all; that
 *    exclusion was deliberately removed at the owner's explicit request.)
 *  - a half-paying student's seat counts toward the leg's earning cap the
 *    same as any other seat.
 */
class HalfPaymentRulesTest extends TestCase
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

    public function test_the_half_plan_charges_18000_not_half_of_the_course_price(): void
    {
        $consultantUser = User::where('email', 'consultant@etec.lk')->firstOrFail();
        $accountant = User::where('email', 'accountant@etec.lk')->firstOrFail();
        $hidden = $consultantUser->consultant->hiddenAccounts()->first();

        $payment = $this->registerConfirmAndVerify($consultantUser, 'half-a@example.com', 1, $hidden->id, 'half', $accountant);

        $this->assertSame(18000.0, (float) $payment->amount, 'The first instalment is a fixed 18,000, not 28,000 / 2.');
        $this->assertSame(10000.0, (float) $payment->course->effective_price - (float) $payment->amount, 'Leaving a 10,000 balance.');
    }

    public function test_the_half_instalment_amount_is_configurable_without_a_code_change(): void
    {
        Setting::set('half_payment_amount', '20000', 'integer', 'commission');

        $consultantUser = User::where('email', 'consultant@etec.lk')->firstOrFail();
        $accountant = User::where('email', 'accountant@etec.lk')->firstOrFail();
        $hidden = $consultantUser->consultant->hiddenAccounts()->first();

        $payment = $this->registerConfirmAndVerify($consultantUser, 'half-b@example.com', 1, $hidden->id, 'half', $accountant);

        $this->assertSame(20000.0, (float) $payment->amount);
    }

    /**
     * ⚠️ REVERSED 2026-08-23 — a half payment earns the BONUS ONLY.
     *
     * From 2026-08-04 it earned exactly like a full payment. The owner has
     * split it by what each part pays for: "student kenk add kraddi half
     * payment kranwanm eyta 2500 add wen na, 4000 withri add wenne". The
     * student really was brought in, so the direct bonus is earned; the slot is
     * only worth paying for once the course behind it has been.
     */
    public function test_a_half_payment_earns_the_direct_bonus_but_no_slot_income(): void
    {
        $consultantUser = User::where('email', 'consultant@etec.lk')->firstOrFail();
        $accountant = User::where('email', 'accountant@etec.lk')->firstOrFail();
        $hidden = $consultantUser->consultant->hiddenAccounts()->first();

        $this->registerConfirmAndVerify($consultantUser, 'half-c@example.com', 1, $hidden->id, 'half', $accountant);

        $this->assertDatabaseHas('commissions', ['consultant_id' => $hidden->id, 'type' => 'referral', 'amount' => 3000]);
        $this->assertDatabaseMissing('commissions', ['consultant_id' => $hidden->id, 'type' => 'bonus']);
        $this->approveAllCommissions();

        $this->assertSame(3000.0, (float) $hidden->fresh()->total_earned, 'The bonus only — the 2,500 waits for the balance.');
    }

    /** ...and the balance is what finally earns it. */
    public function test_settling_the_balance_earns_the_slot_income(): void
    {
        $consultantUser = User::where('email', 'consultant@etec.lk')->firstOrFail();
        $accountant = User::where('email', 'accountant@etec.lk')->firstOrFail();
        $hidden = $consultantUser->consultant->hiddenAccounts()->first();

        $payment = $this->registerConfirmAndVerify($consultantUser, 'half-d@example.com', 1, $hidden->id, 'half', $accountant);

        $this->assertSame(0.0, (float) Commission::where('type', 'bonus')->sum('amount'), 'Nothing yet.');

        $this->settleBalance($payment, $accountant);

        // ⚠️ 2,500 -> 5,000 on 2026-09-02. Unscoped sum, and the climb now
        // reaches the main as well as the placement account. The timing rule
        // this test pins — nothing at half, everything at the balance — is
        // asserted above and is unchanged.
        $this->assertSame(5000.0, (float) Commission::where('type', 'bonus')->sum('amount'), 'The balance earns the 2,500, on both accounts the climb touches.');
        $this->assertSame(
            1,
            Commission::where('type', 'referral')->count(),
            'And no second direct bonus — that was earned when the student was brought in.',
        );

        // Force-approved: the slot is staged like any other, so "approve all"
        // correctly leaves it until the account's legs balance. What this pins
        // is that the money EXISTS to be approved, which it did not before.
        foreach (Commission::where('status', 'pending')->get() as $c) {
            app(\App\Services\CommissionApproval::class)->approve($c, force: true, actorId: null);
        }

        $this->assertSame(5500.0, (float) $hidden->fresh()->total_earned, 'Fully settled: 2,500 + 3,000.');
    }

    /** ⚠️ An ancestor's 2,500 waits for the balance too. */
    public function test_the_upline_earns_nothing_until_the_balance_is_paid(): void
    {
        $consultantUser = User::where('email', 'consultant@etec.lk')->firstOrFail();
        $accountant = User::where('email', 'accountant@etec.lk')->firstOrFail();
        $owner = $consultantUser->consultant;

        // The SECOND account hangs under the first, so a student in it credits
        // the account above once — and only once the course is paid for.
        $accounts = $owner->hiddenAccounts()->orderBy('id')->get();
        $target = $accounts[1];

        $payment = $this->registerConfirmAndVerify($consultantUser, 'half-up@example.com', 1, $target->id, 'half', $accountant);

        $uplineOn = fn () => (float) Commission::whereIn('consultant_id', $owner->accountIds())
            ->where('type', 'bonus')
            ->sum('amount');

        $this->assertSame(0.0, $uplineOn(), 'No slot income anywhere in the tree yet.');

        $this->settleBalance($payment, $accountant);

        $this->assertGreaterThan(0.0, $uplineOn(), 'The balance releases it.');
    }

    /** Record and verify the remaining balance for a half payment. */
    private function settleBalance(Payment $payment, User $accountant): void
    {
        $balance = Payment::create([
            'payment_code' => 'PYM-BAL-'.$payment->id,
            'user_id' => $payment->user_id,
            'course_id' => $payment->course_id,
            'enrollment_id' => $payment->enrollment_id,
            'consultant_id' => $payment->consultant_id,
            'requested_tier' => $payment->requested_tier,
            'amount' => (float) $payment->course->effective_price - (float) $payment->amount,
            'currency' => $payment->currency,
            'method' => 'bank_transfer',
            'payment_type' => 'balance',
            'status' => 'pending',
        ]);

        app(\App\Services\PaymentService::class)->verify($balance, $accountant);
    }
}
