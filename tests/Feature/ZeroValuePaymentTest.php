<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\Consultant;
use App\Models\Course;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A payment worth nothing earns nothing (owner-confirmed 2026-08-19).
 *
 * A "free card" is recorded as a VERIFIED payment of 0.00
 * (EnrollmentService::amountForType), and handleVerifiedPayment() previously
 * excluded only 'balance' payments — so registering a free-card student minted
 * the full 2,500 slot + 4,000 direct bonus, plus 2,500 for every ancestor up
 * the chain, against a payment that brought the company nothing.
 *
 * Owner's words: "free card kenagen add kraham eyalage commission en na, mokad
 * eyalage amount ekak en na ne company ekata."
 *
 * ⚠️ This is ONLY about the free-card payment itself. A free-card student who
 * later becomes a consultant earns normally from the students THEY add —
 * "eyala add kran student lagen 2500 wage 4000 normal anith consultant lata
 * wage cal wenwa" — which the last test here pins.
 */
class ZeroValuePaymentTest extends TestCase
{
    use RefreshDatabase;

    private const SLOT = 2500;

    private const DIRECT = 4000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        \App\Models\CommissionSetting::where('is_active', true)->update([
            'commission_per_student' => self::SLOT,
            'direct_commission' => self::DIRECT,
        ]);
        \Illuminate\Support\Facades\Cache::forget('commission_settings.active');
    }

    private function verify(Consultant $account, float $amount, string $type): Payment
    {
        $student = User::factory()->create();

        Student::create([
            'user_id' => $student->id,
            'student_code' => 'SRN'.Str::random(6),
            'status' => 'active',
            'enrolled_at' => now(),
        ]);

        $payment = Payment::create([
            'payment_code' => 'PYM'.Str::random(6),
            'user_id' => $student->id,
            'course_id' => Course::first()->id,
            'consultant_id' => $account->id,
            'requested_tier' => 1,
            'amount' => $amount,
            'currency' => 'LKR',
            'method' => 'bank_transfer',
            'payment_type' => $type,
            'status' => 'pending',
            'paid_at' => now(),
        ]);

        app(PaymentService::class)->verify($payment, User::where('email', 'accountant@etec.lk')->firstOrFail());

        return $payment->fresh();
    }

    private function account(): Consultant
    {
        return User::where('email', 'consultant@etec.lk')->firstOrFail()
            ->consultant->hiddenAccounts()->first();
    }

    public function test_a_free_card_registration_earns_no_commission_at_all(): void
    {
        $account = $this->account();

        $this->verify($account, 0, 'free_card');

        $this->assertSame(
            0,
            Commission::where('consultant_id', $account->id)->count(),
            'No money reached the company, so no slot income and no direct bonus.',
        );
    }

    /** ...and nothing climbs the upline chain from it either. */
    public function test_a_free_card_registration_credits_no_upline(): void
    {
        $sponsorUser = User::where('email', 'consultant@etec.lk')->firstOrFail();
        $sponsor = $sponsorUser->consultant;
        $placement = $sponsor->hiddenAccounts()->orderBy('id')->first();

        $downlineUser = User::factory()->create();
        $downlineUser->assignRole('consultant');
        $downline = Consultant::create([
            'user_id' => $downlineUser->id,
            'parent_consultant_id' => $placement->id,
            'consultant_code' => 'CON'.Str::random(6),
            'referral_code' => strtoupper(Str::random(10)),
            'status' => 'active',
        ]);

        $sponsorBefore = (float) Commission::where('consultant_id', $sponsor->id)->sum('amount');

        $this->verify($downline->fresh()->hiddenAccounts()->first(), 0, 'free_card');

        $this->assertSame(
            $sponsorBefore,
            (float) Commission::where('consultant_id', $sponsor->id)->sum('amount'),
            'A zero-value payment must not pay the chain above it either.',
        );
    }

    /** A real payment is untouched by the guard. */
    public function test_a_paid_registration_still_earns_normally(): void
    {
        $account = $this->account();

        $this->verify($account, 28000, 'full');

        $this->assertSame(
            (float) self::SLOT,
            (float) Commission::where('consultant_id', $account->id)->where('type', 'bonus')->sum('amount'),
        );
        $this->assertSame(
            (float) self::DIRECT,
            (float) Commission::where('consultant_id', $account->id)->where('type', 'referral')->sum('amount'),
        );
    }

    /**
     * The free-card holder, once a consultant, earns normally from students
     * THEY add — the guard is about the zero-value payment, not the person.
     */
    public function test_a_free_card_holder_turned_consultant_earns_normally_from_their_own_students(): void
    {
        $freeCardUser = User::factory()->create();
        $freeCardUser->assignRole('consultant');

        $consultant = Consultant::create([
            'user_id' => $freeCardUser->id,
            'consultant_code' => 'CON'.Str::random(6),
            'referral_code' => strtoupper(Str::random(10)),
            'status' => 'active',
        ]);

        // They register a genuine, paying student.
        $this->verify($consultant->fresh()->hiddenAccounts()->first(), 28000, 'full');

        $account = $consultant->fresh()->hiddenAccounts()->first();

        $this->assertSame(
            (float) self::SLOT,
            (float) Commission::where('consultant_id', $account->id)->where('type', 'bonus')->sum('amount'),
            'A free-card consultant earns the same 2,500 as anyone else.',
        );
        $this->assertSame(
            (float) self::DIRECT,
            (float) Commission::where('consultant_id', $account->id)->where('type', 'referral')->sum('amount'),
            'And the same 4,000.',
        );
    }
}
