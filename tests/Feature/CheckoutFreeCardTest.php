<?php

namespace Tests\Feature;

use App\Models\CommissionSetting;
use App\Models\Commission;
use App\Models\Consultant;
use App\Models\Course;
use App\Models\GroupMember;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use App\Services\HiddenAccountService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ⚠️ A FREE CARD AT CHECKOUT — asked for 2026-09-07.
 *
 * "Mekata course buy kran thanat free card option ekak dala denn, free card
 * option eka dammaham 2500 upline ekat calculate wen nathi wenn hadal denn."
 *
 * The money rule was ALREADY true and already pinned by ZeroValuePaymentTest —
 * CommissionService keys on the AMOUNT, not on payment_type, so anything worth
 * 0 pays nobody. What did not exist was the option on the public checkout form.
 * This file pins the new surface: who may ask for one, that it really is priced
 * at 0, and that the upline consequence holds on THIS path too — because a rule
 * that is true in one entry point and untested in another is one refactor away
 * from being false.
 */
class CheckoutFreeCardTest extends TestCase
{
    use RefreshDatabase;

    private Consultant $sponsor;

    protected function setUp(): void
    {
        parent::setUp();
        // Checkout now requires a slip; keep it out of the real disk.
        \Illuminate\Support\Facades\Storage::fake('public');
        $this->seed();
        \App\Services\FinanceSchemaRepair::ensure();

        CommissionSetting::where('is_active', true)->update([
            'commission_per_student' => 2500,
            'direct_commission' => 4000,
        ]);
        Cache::forget('commission_settings.active');

        $this->sponsor = User::where('email', 'consultant@etec.lk')->firstOrFail()->consultant;
    }

    private function course(): Course
    {
        return Course::where('status', 'published')->firstOrFail();
    }

    /** A real consultant hanging under the sponsor, the way one comes to exist. */
    private function downlineConsultant(string $name): Consultant
    {
        $user = User::factory()->create(['name' => $name]);
        $user->syncRoles(['consultant']);

        $consultant = Consultant::create([
            'user_id' => $user->id,
            'parent_consultant_id' => $this->sponsor->hiddenAccounts()->orderBy('id')->firstOrFail()->id,
            'consultant_code' => 'CON'.Str::random(8),
            'referral_code' => strtoupper(Str::random(10)),
            'status' => 'active',
        ]);

        app(HiddenAccountService::class)->ensureFor($consultant);

        return $consultant->fresh();
    }

    private function plainStudent(): User
    {
        $user = User::factory()->create();
        $user->syncRoles(['student']);
        Student::create([
            'user_id' => $user->id, 'student_code' => 'SRN'.Str::random(8),
            'status' => 'active', 'enrolled_at' => now(),
        ]);

        return $user;
    }

    private function verifyLatestPayment(): Payment
    {
        $payment = Payment::latest('id')->firstOrFail();

        app(PaymentService::class)->verify($payment, User::where('email', 'accountant@etec.lk')->firstOrFail());

        return $payment->fresh();
    }

    // ----- the option exists, for the right people --------------------------

    public function test_a_consultant_sees_the_free_card_option(): void
    {
        $consultant = $this->downlineConsultant('Buying Consultant');

        $this->actingAs($consultant->user)
            ->get(route('checkout.show', $this->course()))
            ->assertOk()
            ->assertSee('Free card')
            ->assertSee('Payment plan');
    }

    public function test_a_plain_student_does_not_see_it(): void
    {
        $this->actingAs($this->plainStudent())
            ->get(route('checkout.show', $this->course()))
            ->assertOk()
            ->assertDontSee('Free card')
            ->assertDontSee('Payment plan');
    }

    /**
     * ⚠️ HIDING IT IS NOT THE RULE. A hand-posted value must be refused, the
     * same way a hand-posted placement account already is.
     */
    public function test_a_plain_student_cannot_post_one(): void
    {
        $student = $this->plainStudent();

        $this->actingAs($student)
            ->post(route('checkout.store', $this->course()), [
                'method' => 'bank_transfer',
                'reference_number' => 'TXN-TEST',
                'receipt' => \Illuminate\Http\UploadedFile::fake()->create('slip.pdf', 8, 'application/pdf'),
                'payment_type' => 'free_card',
            ])
            ->assertSessionHasErrors('payment_type');

        $this->assertSame(0, Payment::where('user_id', $student->id)->count(), 'nothing was created');
    }

    // ----- what it does -----------------------------------------------------

    public function test_a_free_card_checkout_is_priced_at_zero(): void
    {
        $consultant = $this->downlineConsultant('Free Card Buyer');
        $account = $consultant->hiddenAccounts()->orderBy('id')->firstOrFail();

        $this->actingAs($consultant->user)
            ->post(route('checkout.store', $this->course()), [
                'method' => 'bank_transfer',
                'reference_number' => 'TXN-TEST',
                'receipt' => \Illuminate\Http\UploadedFile::fake()->create('slip.pdf', 8, 'application/pdf'),
                'payment_type' => 'free_card',
                'placement_consultant_id' => $account->id,
                'placement_tier' => 1,
            ])
            ->assertSessionHasNoErrors();

        $payment = Payment::where('user_id', $consultant->user_id)->latest('id')->firstOrFail();

        $this->assertSame('free_card', $payment->payment_type);
        $this->assertSame(0.0, (float) $payment->amount);
    }

    /**
     * ⚠️⚠️ THE OWNER'S ACTUAL REQUIREMENT: "2500 upline ekat calculate wen nathi
     * wenn". Asserted on the sponsor's whole account set, before and after.
     */
    public function test_a_free_card_pays_the_upline_nothing(): void
    {
        $consultant = $this->downlineConsultant('Free Card Buyer');
        $account = $consultant->hiddenAccounts()->orderBy('id')->firstOrFail();

        $uplineBefore = (float) Commission::whereIn('consultant_id', $this->sponsor->accountIds())->sum('amount');

        $this->actingAs($consultant->user)
            ->post(route('checkout.store', $this->course()), [
                'method' => 'bank_transfer',
                'reference_number' => 'TXN-TEST',
                'receipt' => \Illuminate\Http\UploadedFile::fake()->create('slip.pdf', 8, 'application/pdf'),
                'payment_type' => 'free_card',
                'placement_consultant_id' => $account->id,
                'placement_tier' => 1,
            ])
            ->assertSessionHasNoErrors();

        $payment = $this->verifyLatestPayment();

        $this->assertSame('verified', $payment->status, 'guard: it really was verified');

        $this->assertSame(
            $uplineBefore,
            (float) Commission::whereIn('consultant_id', $this->sponsor->accountIds())->sum('amount'),
            'not one rupee reached the sponsor chain',
        );

        $this->assertSame(
            0,
            Commission::where('payment_id', $payment->id)->count(),
            'and no commission row exists against the payment at all',
        );
    }

    /** But the buyer is still placed — a free card is an enrolment, not a no-op. */
    public function test_a_free_card_buyer_is_still_seated(): void
    {
        $consultant = $this->downlineConsultant('Free Card Buyer');
        $account = $consultant->hiddenAccounts()->orderBy('id')->firstOrFail();

        $this->actingAs($consultant->user)
            ->post(route('checkout.store', $this->course()), [
                'method' => 'bank_transfer',
                'reference_number' => 'TXN-TEST',
                'receipt' => \Illuminate\Http\UploadedFile::fake()->create('slip.pdf', 8, 'application/pdf'),
                'payment_type' => 'free_card',
                'placement_consultant_id' => $account->id,
                'placement_tier' => 1,
            ]);

        $this->verifyLatestPayment();

        $this->assertTrue(
            GroupMember::where('user_id', $consultant->user_id)->exists(),
            'occupying a seat is a placement fact, not an earning',
        );
    }

    /**
     * ⚠️ INVERTED 2026-09-14: a course bought on a free card opens its set.
     *
     * This pinned "a free card must not mint three SCN accounts for nothing".
     * The owner reported the missing accounts as a bug — "courses buy krankot
     * free card dammaham user management eke e accounts penwan na" — and on the
     * live site 23 such purchases had none while all 141 paid ones did. See
     * FreeCardPurchaseOpensAccountsTest.
     */
    public function test_a_free_card_purchase_opens_its_account_set(): void
    {
        $consultant = $this->downlineConsultant('Free Card Buyer');
        $account = $consultant->hiddenAccounts()->orderBy('id')->firstOrFail();
        $before = $consultant->fresh()->hiddenAccounts()->count();

        $this->actingAs($consultant->user)
            ->post(route('checkout.store', $this->course()), [
                'method' => 'bank_transfer',
                'reference_number' => 'TXN-TEST',
                'receipt' => \Illuminate\Http\UploadedFile::fake()->create('slip.pdf', 8, 'application/pdf'),
                'payment_type' => 'free_card',
                'placement_consultant_id' => $account->id,
                'placement_tier' => 1,
            ]);

        $this->verifyLatestPayment();

        $this->assertSame(
            $before + 3,
            $consultant->fresh()->hiddenAccounts()->count(),
            'a free card opens the same set of three a paid purchase does',
        );
    }

    // ----- and the normal path is untouched ---------------------------------

    /** ⚠️ The guard on "anith logic walat mukuth kran nathuw". */
    public function test_an_ordinary_checkout_still_charges_the_full_price(): void
    {
        $consultant = $this->downlineConsultant('Paying Consultant');
        $account = $consultant->hiddenAccounts()->orderBy('id')->firstOrFail();

        $this->actingAs($consultant->user)
            ->post(route('checkout.store', $this->course()), [
                'method' => 'bank_transfer',
                'reference_number' => 'TXN-TEST',
                'receipt' => \Illuminate\Http\UploadedFile::fake()->create('slip.pdf', 8, 'application/pdf'),
                'placement_consultant_id' => $account->id,
                'placement_tier' => 1,
            ])
            ->assertSessionHasNoErrors();

        $payment = Payment::where('user_id', $consultant->user_id)->latest('id')->firstOrFail();

        $this->assertSame('full', $payment->payment_type, 'no payment_type posted still means a full payment');
        $this->assertSame((float) $this->course()->effective_price, (float) $payment->amount);
    }

    /** A paid purchase still pays the upline — the free card must not have broken that. */
    public function test_a_paid_checkout_still_credits_the_upline(): void
    {
        $consultant = $this->downlineConsultant('Paying Consultant');
        $account = $consultant->hiddenAccounts()->orderBy('id')->firstOrFail();

        $uplineBefore = (float) Commission::whereIn('consultant_id', $this->sponsor->accountIds())->sum('amount');

        $this->actingAs($consultant->user)
            ->post(route('checkout.store', $this->course()), [
                'method' => 'bank_transfer',
                'reference_number' => 'TXN-TEST',
                'receipt' => \Illuminate\Http\UploadedFile::fake()->create('slip.pdf', 8, 'application/pdf'),
                'placement_consultant_id' => $account->id,
                'placement_tier' => 1,
            ]);

        $this->verifyLatestPayment();

        $this->assertGreaterThan(
            $uplineBefore,
            (float) Commission::whereIn('consultant_id', $this->sponsor->accountIds())->sum('amount'),
            'a real purchase still climbs the sponsor chain',
        );
    }
}
