<?php

namespace Tests\Feature;

use App\Models\Consultant;
use App\Models\Course;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The buyer chooses their consultant placement at checkout (2026-08-19).
 *
 * ⚠️ SCOPE CORRECTED the same day. The first build offered EVERY consultant
 * account in the system, so a consultant logged in as SCN-00106 saw
 * SCN-00100..00105 — strangers — and none of their own. The picker is scoped to
 * the buyer's OWN network: "log wela inn consultant adala account tika withrk
 * penwana widiyta [hadann]".
 *
 * That follows from what the placement is FOR — an existing consultant buying
 * another course opens a further SCN account inside THEIR OWN tree — so placing
 * under your own account is the normal case here, not something to prevent.
 */
class CheckoutPlacementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Checkout now requires a slip; keep it out of the real disk.
        \Illuminate\Support\Facades\Storage::fake('public');
        $this->seed();
        \App\Services\FinanceSchemaRepair::ensure();
    }

    private function course(): Course
    {
        return Course::where('status', 'published')->firstOrFail();
    }

    private function consultantUser(): User
    {
        return User::where('email', 'consultant@etec.lk')->firstOrFail();
    }

    /** A consultant unrelated to the seeded one — used as an out-of-network target. */
    private function strangerAccount(): Consultant
    {
        $user = User::factory()->create();
        $user->assignRole('consultant');

        return Consultant::create([
            'user_id' => $user->id,
            'consultant_code' => 'CON'.Str::random(6),
            'referral_code' => strtoupper(Str::random(10)),
            'scn_code' => 'SCN-9'.random_int(1000, 9999),
            'status' => 'active',
        ]);
    }

    public function test_the_picker_shows_the_buyers_own_accounts(): void
    {
        $consultantUser = $this->consultantUser();
        $own = $consultantUser->consultant;

        $response = $this->actingAs($consultantUser)->get(route('checkout.show', $this->course()));

        $response->assertOk();
        $response->assertSee('Consultant placement');
        $response->assertSee($own->scn_code);
    }

    /** ⚠️ The reported bug: strangers' accounts must NOT be offered. */
    public function test_the_picker_does_not_show_accounts_outside_the_buyers_network(): void
    {
        $stranger = $this->strangerAccount();

        $this->actingAs($this->consultantUser())
            ->get(route('checkout.show', $this->course()))
            ->assertOk()
            ->assertDontSee($stranger->scn_code);
    }

    /**
     * Placing under YOUR OWN account is the normal case — an existing
     * consultant buying another course opens a further SCN account in their
     * own tree.
     */
    public function test_a_consultant_can_place_under_their_own_account(): void
    {
        $consultantUser = $this->consultantUser();
        $own = $consultantUser->consultant;

        $this->actingAs($consultantUser)->post(route('checkout.store', $this->course()), [
            'method' => 'bank_transfer',
            'reference_number' => 'TXN-TEST',
            'receipt' => \Illuminate\Http\UploadedFile::fake()->create('slip.pdf', 8, 'application/pdf'),
            'placement_consultant_id' => $own->id,
            'placement_tier' => 2,
        ])->assertRedirect();

        $payment = Payment::where('user_id', $consultantUser->id)->firstOrFail();

        $this->assertSame($own->id, $payment->consultant_id);
        $this->assertSame(2, (int) $payment->requested_tier, 'The chosen Service Group is recorded.');
    }

    public function test_one_of_their_own_hidden_accounts_can_also_be_chosen(): void
    {
        $consultantUser = $this->consultantUser();
        $hidden = $consultantUser->consultant->hiddenAccounts()->orderBy('id')->first();

        $this->actingAs($consultantUser)->post(route('checkout.store', $this->course()), [
            'method' => 'bank_transfer',
            'reference_number' => 'TXN-TEST',
            'receipt' => \Illuminate\Http\UploadedFile::fake()->create('slip.pdf', 8, 'application/pdf'),
            'placement_consultant_id' => $hidden->id,
            'placement_tier' => 1,
        ])->assertRedirect();

        $this->assertSame(
            $hidden->id,
            Payment::where('user_id', $consultantUser->id)->value('consultant_id'),
            'Both main and hidden accounts are valid targets.',
        );
    }

    /** Picking an account without a Service Group is refused, not silently defaulted. */
    public function test_a_service_group_is_required_once_an_account_is_picked(): void
    {
        $consultantUser = $this->consultantUser();

        $this->actingAs($consultantUser)->post(route('checkout.store', $this->course()), [
            'method' => 'bank_transfer',
            'reference_number' => 'TXN-TEST',
            'receipt' => \Illuminate\Http\UploadedFile::fake()->create('slip.pdf', 8, 'application/pdf'),
            'placement_consultant_id' => $consultantUser->consultant->id,
        ])->assertSessionHasErrors('placement_tier');

        $this->assertSame(0, Payment::where('user_id', $consultantUser->id)->count());
    }

    /** ⚠️ A hand-posted id must not reach an account outside the buyer's network. */
    public function test_an_out_of_network_account_cannot_be_posted(): void
    {
        $consultantUser = $this->consultantUser();
        $stranger = $this->strangerAccount();

        $this->actingAs($consultantUser)->post(route('checkout.store', $this->course()), [
            'method' => 'bank_transfer',
            'reference_number' => 'TXN-TEST',
            'receipt' => \Illuminate\Http\UploadedFile::fake()->create('slip.pdf', 8, 'application/pdf'),
            'placement_consultant_id' => $stranger->id,
            'placement_tier' => 1,
        ])->assertSessionHasErrors('placement_consultant_id');

        $this->assertSame(0, Payment::where('user_id', $consultantUser->id)->count());
    }

    /** ⚠️ ...nor a suspended one, even inside the network. */
    public function test_a_suspended_account_cannot_be_posted(): void
    {
        $consultantUser = $this->consultantUser();
        $hidden = $consultantUser->consultant->hiddenAccounts()->orderBy('id')->first();
        $hidden->update(['status' => 'suspended']);

        $this->actingAs($consultantUser)->post(route('checkout.store', $this->course()), [
            'method' => 'bank_transfer',
            'reference_number' => 'TXN-TEST',
            'receipt' => \Illuminate\Http\UploadedFile::fake()->create('slip.pdf', 8, 'application/pdf'),
            'placement_consultant_id' => $hidden->id,
            'placement_tier' => 1,
        ])->assertSessionHasErrors('placement_consultant_id');
    }

    /** A brand-new buyer on a ?ref= link sees the REFERRER's network. */
    public function test_a_new_buyer_on_a_referral_link_sees_the_referrers_network(): void
    {
        $referrer = $this->consultantUser()->consultant;

        $buyer = User::factory()->create();
        $buyer->assignRole('student');

        $this->actingAs($buyer)
            ->withSession(['referral_code' => $referrer->referral_code])
            ->get(route('checkout.show', $this->course()))
            ->assertOk()
            ->assertSee($referrer->scn_code);
    }

    /** Buying with no placement still works exactly as before. */
    public function test_a_plain_purchase_without_a_placement_still_works(): void
    {
        $buyer = User::factory()->create();
        $buyer->assignRole('student');

        $this->actingAs($buyer)->post(route('checkout.store', $this->course()), [
            'method' => 'bank_transfer',
            'reference_number' => 'TXN-TEST',
            'receipt' => \Illuminate\Http\UploadedFile::fake()->create('slip.pdf', 8, 'application/pdf'),
        ])->assertRedirect();

        $this->assertSame(1, Payment::where('user_id', $buyer->id)->count());
    }

    /** The chosen leg survives onto the consultant record when they are promoted. */
    public function test_the_chosen_leg_is_carried_onto_the_consultant_on_promotion(): void
    {
        $referrer = $this->consultantUser()->consultant;

        $buyer = User::factory()->create();
        $buyer->assignRole('student');

        $this->actingAs($buyer)
            ->withSession(['referral_code' => $referrer->referral_code])
            ->post(route('checkout.store', $this->course()), [
                'method' => 'bank_transfer',
                'reference_number' => 'TXN-TEST',
                'receipt' => \Illuminate\Http\UploadedFile::fake()->create('slip.pdf', 8, 'application/pdf'),
                'placement_consultant_id' => $referrer->id,
                'placement_tier' => 2,
            ])->assertRedirect();

        $admin = User::where('email', 'admin@etec.lk')->firstOrFail();
        $this->actingAs($admin)
            ->patch(route('users.role', $buyer), ['role' => 'consultant'])
            ->assertRedirect();

        $this->assertSame(
            2,
            (int) Consultant::where('user_id', $buyer->id)->value('placement_tier'),
            'The Service Group chosen at checkout is what their downline income later climbs to.',
        );
    }
}
