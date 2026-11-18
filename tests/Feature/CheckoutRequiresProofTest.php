<?php

namespace Tests\Feature;

use App\Models\Consultant;
use App\Models\Course;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use App\Services\FinanceSchemaRepair;
use App\Services\HiddenAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Buying a course must not reach the accountant with nothing to check.
 *
 * Two things reported together on 2026-09-10, both on the public checkout:
 *
 *  1. The page 500'd with "Undefined variable $canIssueFreeCard" — a stale
 *     CheckoutController on the server against a newer view. The variable is
 *     passed by show(), so the code was never wrong; the deploy was. The view
 *     is nonetheless made to degrade rather than take the buy page down, and
 *     the first test here is that guard.
 *  2. "Courses buy kran thana payment eka validation run wen na" — reference
 *     number and receipt were both plain `nullable`, so Submit for
 *     Verification worked with no proof of payment whatsoever.
 */
class CheckoutRequiresProofTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        FinanceSchemaRepair::ensure();
        Storage::fake('public');
    }

    private function course(): Course
    {
        return Course::where('status', 'published')->firstOrFail();
    }

    private function buyer(): User
    {
        $user = User::factory()->create();
        $user->syncRoles(['student']);

        Student::create([
            'user_id' => $user->id,
            'student_code' => 'SRN'.Str::random(8),
            'status' => 'active',
            'enrolled_at' => now(),
        ]);

        return $user;
    }

    private function consultantBuyer(): Consultant
    {
        $sponsor = User::where('email', 'consultant@etec.lk')->firstOrFail()->consultant;

        $user = User::factory()->create();
        $user->syncRoles(['consultant']);

        $consultant = Consultant::create([
            'user_id' => $user->id,
            'parent_consultant_id' => $sponsor->hiddenAccounts()->orderBy('id')->firstOrFail()->id,
            'consultant_code' => 'CON'.Str::random(8),
            'referral_code' => strtoupper(Str::random(10)),
            'status' => 'active',
        ]);

        app(HiddenAccountService::class)->ensureFor($consultant);

        return $consultant->fresh();
    }

    private function slip(): UploadedFile
    {
        return UploadedFile::fake()->image('slip.jpg');
    }

    // ----- the 500 -----------------------------------------------------------

    public function test_the_checkout_page_loads(): void
    {
        $this->actingAs($this->buyer())
            ->get(route('checkout.show', $this->course()))
            ->assertOk()
            ->assertSee('Payment method');
    }

    /**
     * ⚠️ THE EXACT PRODUCTION FAILURE. An older controller does not pass
     * canIssueFreeCard, and the view used it bare. Rendering must degrade to
     * "no free card option" rather than take the whole buy page down.
     */
    public function test_the_view_survives_a_controller_that_does_not_pass_the_flag(): void
    {
        $this->actingAs($this->buyer());

        // Rendering outside a request means the errors bag the layout reads is
        // not there — the middleware normally shares it. Supply an empty one so
        // the test exercises the missing FLAG, not a missing errors bag.
        \Illuminate\Support\Facades\View::share('errors', new \Illuminate\Support\ViewErrorBag);

        $html = view('pages.checkout', [
            'course' => $this->course(),
            'existing' => null,
            'referralConsultant' => null,
            'placementAccounts' => collect(),
            // canIssueFreeCard deliberately absent — this is the stale-deploy shape.
        ])->render();

        $this->assertStringContainsString('Payment method', $html);
        $this->assertStringNotContainsString('Free card', $html, 'the option is simply absent');
    }

    // ----- the validation ----------------------------------------------------

    public function test_a_purchase_with_no_proof_at_all_is_refused(): void
    {
        $buyer = $this->buyer();

        $this->actingAs($buyer)
            ->post(route('checkout.store', $this->course()), ['method' => 'bank_transfer'])
            ->assertSessionHasErrors(['reference_number', 'receipt']);

        $this->assertSame(0, Payment::where('user_id', $buyer->id)->count(), 'nothing reached the queue');
    }

    public function test_a_purchase_with_no_receipt_is_refused(): void
    {
        $buyer = $this->buyer();

        $this->actingAs($buyer)
            ->post(route('checkout.store', $this->course()), [
                'method' => 'bank_transfer',
                'reference_number' => 'TXN-123456',
            ])
            ->assertSessionHasErrors('receipt')
            ->assertSessionDoesntHaveErrors('reference_number');

        $this->assertSame(0, Payment::where('user_id', $buyer->id)->count());
    }

    public function test_a_purchase_with_no_reference_number_is_refused(): void
    {
        $buyer = $this->buyer();

        $this->actingAs($buyer)
            ->post(route('checkout.store', $this->course()), [
                'method' => 'bank_transfer',
                'receipt' => $this->slip(),
            ])
            ->assertSessionHasErrors('reference_number')
            ->assertSessionDoesntHaveErrors('receipt');

        $this->assertSame(0, Payment::where('user_id', $buyer->id)->count());
    }

    public function test_a_complete_purchase_still_goes_through(): void
    {
        $buyer = $this->buyer();

        $this->actingAs($buyer)
            ->post(route('checkout.store', $this->course()), [
                'method' => 'bank_transfer',
                'reference_number' => 'TXN-123456',
                'receipt' => $this->slip(),
            ])
            ->assertSessionHasNoErrors();

        $payment = Payment::where('user_id', $buyer->id)->firstOrFail();

        $this->assertSame('pending', $payment->status);
        $this->assertSame('TXN-123456', $payment->reference_number);
        $this->assertNotNull($payment->receipt_path, 'the slip was stored');
    }

    /**
     * ⚠️ A free card is the one plan with nothing to prove, because nothing is
     * collected. Same carve-out as the consultant's own registration screen.
     */
    public function test_a_free_card_needs_no_slip(): void
    {
        $consultant = $this->consultantBuyer();

        // A consultant must always name the account they place under — an
        // existing rule (CheckoutRequiresAPlacementTest), unrelated to proof.
        $account = $consultant->hiddenAccounts()->orderBy('id')->firstOrFail();

        $this->actingAs($consultant->user)
            ->post(route('checkout.store', $this->course()), [
                'method' => 'bank_transfer',
                'payment_type' => 'free_card',
                'placement_consultant_id' => $account->id,
                'placement_tier' => 1,
            ])
            ->assertSessionHasNoErrors();

        $payment = Payment::where('user_id', $consultant->user_id)->firstOrFail();

        $this->assertSame('free_card', $payment->payment_type);
        $this->assertSame(0.0, round((float) $payment->amount, 2), 'nothing is collected');
    }

    /** And a consultant paying normally still has to prove it. */
    public function test_a_consultant_paying_in_full_still_needs_proof(): void
    {
        $consultant = $this->consultantBuyer();

        $this->actingAs($consultant->user)
            ->post(route('checkout.store', $this->course()), [
                'method' => 'bank_transfer',
                'payment_type' => 'full',
            ])
            ->assertSessionHasErrors(['reference_number', 'receipt']);

        $this->assertSame(0, Payment::where('user_id', $consultant->user_id)->count());
    }
}
