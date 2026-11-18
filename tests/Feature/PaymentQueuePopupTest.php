<?php

namespace Tests\Feature;

use App\Livewire\PaymentQueue;
use App\Models\Course;
use App\Models\Payment;
use App\Models\User;
use App\Services\FinanceSchemaRepair;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Reviewing a payment in a popup instead of on another page.
 *
 * Asked for 2026-09-10: *"review denkot eka wen page ehekt ywan nathiw browes
 * popup ehekin load wenn hadann... popup ek verify kraham livewire walin page
 * ekama refresh kran nathiw payment verify wenn hadal denn filters dala
 * thiyenwa nm eka refresh wen nathiw thiyenn one."*
 *
 * The screen is live with 730 pending payments, so half of these tests are
 * about what must NOT have changed: the old page, the old PATCH routes, and
 * the view data four other tests read.
 */
class PaymentQueuePopupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        FinanceSchemaRepair::ensure();
    }

    private function admin(): User
    {
        return User::where('email', 'admin@etec.lk')->firstOrFail();
    }

    private function pendingPayment(string $type = 'full'): Payment
    {
        $student = User::factory()->create();
        $student->assignRole('student');

        $course = Course::where('status', 'published')->firstOrFail();
        $consultant = User::where('email', 'consultant@etec.lk')->firstOrFail()->consultant;

        return Payment::create([
            'payment_code' => 'PYM-'.Str::random(8),
            'user_id' => $student->id,
            'course_id' => $course->id,
            'consultant_id' => $consultant->id,
            'amount' => 10000,
            'currency' => 'LKR',
            'method' => 'bank_transfer',
            'payment_type' => $type,
            'status' => 'pending',
            'paid_at' => now(),
        ]);
    }

    // ----- the popup ---------------------------------------------------------

    public function test_review_opens_the_payment_in_the_component_rather_than_navigating(): void
    {
        $payment = $this->pendingPayment();

        Livewire::actingAs($this->admin())
            ->test(PaymentQueue::class)
            ->call('open', $payment->id)
            ->assertOk()
            ->assertSet('selectedId', $payment->id)
            ->assertSee($payment->payment_code);
    }

    public function test_the_popup_carries_the_payslip_and_a_link_to_its_own_page(): void
    {
        $payment = $this->pendingPayment();
        $payment->update(['receipt_path' => 'receipts/slip.jpg']);

        $detail = Livewire::actingAs($this->admin())
            ->test(PaymentQueue::class)
            ->call('open', $payment->id)
            ->get('detail');

        $this->assertSame(route('payments.receipt', $payment), $detail['receipt_url']);
        $this->assertTrue($detail['receipt_is_image'], 'a jpg zooms as an image');
        $this->assertSame(route('payments.show', $payment), $detail['page_url']);
    }

    /** ⚠️ A payslip can be a PDF — scaling one means nothing, so it is not an image. */
    public function test_a_pdf_payslip_is_not_treated_as_a_zoomable_image(): void
    {
        $payment = $this->pendingPayment();
        $payment->update(['receipt_path' => 'receipts/slip.pdf']);

        $detail = Livewire::actingAs($this->admin())
            ->test(PaymentQueue::class)
            ->call('open', $payment->id)
            ->get('detail');

        $this->assertFalse($detail['receipt_is_image']);
        $this->assertSame('PDF', $detail['receipt_kind']);
    }

    // ----- verifying ---------------------------------------------------------

    public function test_verifying_in_the_popup_moves_the_payment(): void
    {
        $payment = $this->pendingPayment();

        Livewire::actingAs($this->admin())
            ->test(PaymentQueue::class)
            ->call('open', $payment->id)
            ->call('verifyPayment')
            ->assertOk();

        $this->assertSame('verified', $payment->fresh()->status);
    }

    /**
     * ⚠️ THE POINT OF THE WHOLE BATCH. A verify must not clear the filters the
     * accountant set — that was the complaint.
     */
    public function test_the_filters_survive_a_verify(): void
    {
        $payment = $this->pendingPayment();

        Livewire::actingAs($this->admin())
            ->test(PaymentQueue::class)
            ->set('q', 'PYM')
            ->set('status', 'pending')
            ->set('from', now()->subDays(3)->toDateString())
            ->call('open', $payment->id)
            ->call('verifyPayment')
            ->assertSet('q', 'PYM')
            ->assertSet('status', 'pending')
            ->assertSet('from', now()->subDays(3)->toDateString());
    }

    /** And the banner admits what it cannot refresh, rather than pretending. */
    public function test_the_result_banner_says_the_cards_are_stale(): void
    {
        $payment = $this->pendingPayment();

        Livewire::actingAs($this->admin())
            ->test(PaymentQueue::class)
            ->call('open', $payment->id)
            ->call('verifyPayment')
            ->assertSee('update when you reload');
    }

    public function test_an_already_processed_payment_is_refused(): void
    {
        $payment = $this->pendingPayment();
        $payment->update(['status' => 'verified']);

        Livewire::actingAs($this->admin())
            ->test(PaymentQueue::class)
            ->call('open', $payment->id)
            ->call('verifyPayment')
            ->assertSet('problem', 'This payment has already been processed.');
    }

    /** A registration whose consultant never confirmed a plan is not actionable. */
    public function test_a_payment_with_no_plan_is_refused(): void
    {
        $payment = $this->pendingPayment();
        $payment->update(['payment_type' => null]);

        $component = Livewire::actingAs($this->admin())
            ->test(PaymentQueue::class)
            ->call('open', $payment->id)
            ->call('verifyPayment');

        $this->assertStringContainsString('payment plan', (string) $component->get('problem'));
        $this->assertSame('pending', $payment->fresh()->status);
    }

    public function test_rejecting_without_a_reason_is_refused(): void
    {
        $payment = $this->pendingPayment();

        Livewire::actingAs($this->admin())
            ->test(PaymentQueue::class)
            ->call('open', $payment->id)
            ->call('rejectPayment')
            ->assertSet('problem', 'A reason is required to reject a payment.');

        $this->assertSame('pending', $payment->fresh()->status);
    }

    public function test_rejecting_with_a_reason_works(): void
    {
        $payment = $this->pendingPayment();

        Livewire::actingAs($this->admin())
            ->test(PaymentQueue::class)
            ->call('open', $payment->id)
            ->set('remarks', 'Slip is unreadable.')
            ->call('rejectPayment');

        $this->assertSame('rejected', $payment->fresh()->status);
    }

    // ----- who may act -------------------------------------------------------

    /**
     * ⚠️ Livewire actions bypass the route middleware, so a consultant who can
     * reach no payment screen at all can still post to the update endpoint.
     */
    public function test_a_consultant_cannot_verify_through_the_livewire_endpoint(): void
    {
        $payment = $this->pendingPayment();
        $consultant = User::where('email', 'consultant@etec.lk')->firstOrFail();

        Livewire::actingAs($consultant)
            ->test(PaymentQueue::class)
            ->call('open', $payment->id)
            ->assertForbidden();

        $this->assertSame('pending', $payment->fresh()->status);
    }

    public function test_a_student_cannot_verify_through_the_livewire_endpoint(): void
    {
        $payment = $this->pendingPayment();
        $student = User::factory()->create();
        $student->assignRole('student');

        Livewire::actingAs($student)
            ->test(PaymentQueue::class)
            ->set('selectedId', $payment->id)
            ->call('verifyPayment')
            ->assertForbidden();

        $this->assertSame('pending', $payment->fresh()->status);
    }

    // ----- filtering ---------------------------------------------------------

    public function test_the_status_filter_narrows_the_list(): void
    {
        $pending = $this->pendingPayment();
        $verified = $this->pendingPayment();
        $verified->update(['status' => 'verified']);

        Livewire::actingAs($this->admin())
            ->test(PaymentQueue::class)
            ->set('status', 'verified')
            ->assertSee($verified->payment_code)
            ->assertDontSee($pending->payment_code);
    }

    /** The default queue hides registrations with no confirmed plan. */
    public function test_the_default_queue_excludes_payments_with_no_plan(): void
    {
        $planless = $this->pendingPayment();
        $planless->update(['payment_type' => null]);

        Livewire::actingAs($this->admin())
            ->test(PaymentQueue::class)
            ->assertDontSee($planless->payment_code);
    }

    // ----- what must not have changed ----------------------------------------

    /** ⚠️ The old page is the no-JavaScript path and four other tests use it. */
    public function test_the_full_payment_page_still_renders(): void
    {
        $payment = $this->pendingPayment();

        $this->actingAs($this->admin())
            ->get(route('payments.show', $payment))
            ->assertOk()
            ->assertSee($payment->payment_code);
    }

    /** ⚠️ The PATCH route stays — CommissionSchemaRepairTest and others use it. */
    public function test_the_patch_verify_route_still_works(): void
    {
        $payment = $this->pendingPayment();

        $this->actingAs($this->admin())
            ->patch(route('payments.verify', $payment))
            ->assertRedirect();

        $this->assertSame('verified', $payment->fresh()->status);
    }

    /** ⚠️ The controller must keep handing both of these to the view. */
    public function test_the_controller_still_passes_stats_and_payments(): void
    {
        $this->pendingPayment();

        $response = $this->actingAs($this->admin())->get(route('payments.index'))->assertOk();

        $this->assertIsArray($response->viewData('stats'));
        $this->assertNotEmpty($response->viewData('payments')->items());
    }

    /** ⚠️ ConsultantNameDisplayTest's rule, restated as a rule of this screen. */
    public function test_the_index_never_says_direct_signup(): void
    {
        $payment = $this->pendingPayment();
        $payment->update(['consultant_id' => null]);

        $this->actingAs($this->admin())
            ->get(route('payments.index'))
            ->assertOk()
            ->assertDontSee('Direct signup');
    }

    public function test_the_table_still_renders_inside_the_page(): void
    {
        $payment = $this->pendingPayment();

        $this->actingAs($this->admin())
            ->get(route('payments.index'))
            ->assertOk()
            ->assertSee($payment->payment_code);
    }
}
