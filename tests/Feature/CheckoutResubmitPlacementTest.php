<?php

namespace Tests\Feature;

use App\Models\Consultant;
use App\Models\Course;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ⚠️ Submitting checkout a SECOND time must update the pending payment.
 *
 * It used to be silently discarded: EnrollmentService::enroll() skipped its
 * whole write block when a pending payment already existed, while the
 * controller still redirected with a success message — and the checkout page
 * itself promises "Submitting again updates your payment details".
 *
 * That is what the owner reported as an off-by-one (2026-08-22): "kalin
 * gaththe 124 dammaham gaththe 123 group eka" — picking SCN-00124 kept the
 * SCN-00123 chosen on the FIRST submit, i.e. the row above it in the picker.
 * The picker itself was exact; the second pick never reached the database.
 *
 * The existing CheckoutPlacementTest never caught it because every test there
 * does exactly one POST per buyer.
 */
class CheckoutResubmitPlacementTest extends TestCase
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

    public function test_a_second_submit_moves_the_placement_to_the_newly_chosen_account(): void
    {
        $buyer = $this->consultantUser();
        $accounts = $buyer->consultant->hiddenAccounts()->orderBy('id')->get();

        [$first, $second] = [$accounts[0], $accounts[1]];

        // First submit — picks the FIRST account, leg 2.
        $this->actingAs($buyer)->post(route('checkout.store', $this->course()), [
            'method' => 'bank_transfer',
            'receipt' => \Illuminate\Http\UploadedFile::fake()->create('slip.pdf', 8, 'application/pdf'),
            'reference_number' => 'FIRST-REF',
            'placement_consultant_id' => $first->id,
            'placement_tier' => 2,
        ])->assertRedirect();

        // Second submit — changes their mind to the SECOND account, leg 1.
        $this->actingAs($buyer)->post(route('checkout.store', $this->course()), [
            'method' => 'online',
            'receipt' => \Illuminate\Http\UploadedFile::fake()->create('slip.pdf', 8, 'application/pdf'),
            'reference_number' => 'SECOND-REF',
            'placement_consultant_id' => $second->id,
            'placement_tier' => 1,
        ])->assertRedirect();

        $payment = Payment::where('user_id', $buyer->id)->where('course_id', $this->course()->id)->firstOrFail();

        $this->assertSame($second->id, $payment->consultant_id, 'The placement moved to the account picked on the SECOND submit.');
        $this->assertSame(1, (int) $payment->requested_tier, 'And so did the Service Group.');
        $this->assertSame('SECOND-REF', $payment->reference_number);
        $this->assertSame('online', $payment->method);
    }

    /** Only ONE payment exists — a re-submit updates, it does not duplicate. */
    public function test_a_second_submit_does_not_create_a_second_payment(): void
    {
        $buyer = $this->consultantUser();
        $accounts = $buyer->consultant->hiddenAccounts()->orderBy('id')->get();

        foreach ([$accounts[0], $accounts[1]] as $target) {
            $this->actingAs($buyer)->post(route('checkout.store', $this->course()), [
                'method' => 'bank_transfer',
                'reference_number' => 'TXN-TEST',
                'receipt' => \Illuminate\Http\UploadedFile::fake()->create('slip.pdf', 8, 'application/pdf'),
                'placement_consultant_id' => $target->id,
                'placement_tier' => 1,
            ])->assertRedirect();
        }

        $this->assertSame(
            1,
            Payment::where('user_id', $buyer->id)->where('course_id', $this->course()->id)->count(),
        );
    }

    /**
     * ⚠️ A deliberate placement re-points the Student row too, or Pending
     * Students matches on two columns that disagree and shows nothing.
     */
    public function test_a_deliberate_placement_repoints_an_existing_student_row(): void
    {
        $buyer = User::factory()->create();
        $buyer->assignRole('student');

        $original = $this->consultantUser()->consultant;

        // They already have a Student row pointing at their original sponsor.
        Student::create([
            'user_id' => $buyer->id,
            'student_code' => 'SRN'.Str::random(8),
            'consultant_id' => $original->id,
            'status' => 'active',
            'enrolled_at' => now(),
        ]);

        $target = $original->hiddenAccounts()->orderBy('id')->get()[1];

        $this->actingAs($buyer)
            ->withSession(['referral_code' => $original->referral_code])
            ->post(route('checkout.store', $this->course()), [
                'method' => 'bank_transfer',
                'reference_number' => 'TXN-TEST',
                'receipt' => \Illuminate\Http\UploadedFile::fake()->create('slip.pdf', 8, 'application/pdf'),
                'placement_consultant_id' => $target->id,
                'placement_tier' => 1,
            ])->assertRedirect();

        $this->assertSame(
            $target->id,
            Student::where('user_id', $buyer->id)->value('consultant_id'),
            'The Student row follows the deliberate placement, so both columns agree.',
        );
    }

    /** ...but a bare ?ref= purchase must NOT steal an existing student. */
    public function test_a_bare_referral_purchase_does_not_repoint_an_existing_student(): void
    {
        $buyer = User::factory()->create();
        $buyer->assignRole('student');

        $original = $this->consultantUser()->consultant;
        $originalId = $original->id;

        Student::create([
            'user_id' => $buyer->id,
            'student_code' => 'SRN'.Str::random(8),
            'consultant_id' => $originalId,
            'status' => 'active',
            'enrolled_at' => now(),
        ]);

        // A different consultant's referral link, no deliberate placement.
        $otherUser = User::factory()->create();
        $otherUser->assignRole('consultant');
        $other = Consultant::create([
            'user_id' => $otherUser->id,
            'consultant_code' => 'CON'.Str::random(8),
            'referral_code' => strtoupper(Str::random(10)),
            'scn_code' => 'SCN-9'.random_int(1000, 9999),
            'status' => 'active',
        ]);

        $this->actingAs($buyer)
            ->withSession(['referral_code' => $other->referral_code])
            ->post(route('checkout.store', $this->course()), ['method' => 'bank_transfer', 'reference_number' => 'TXN-TEST', 'receipt' => \Illuminate\Http\UploadedFile::fake()->create('slip.pdf', 8, 'application/pdf')])
            ->assertRedirect();

        $this->assertSame(
            $originalId,
            Student::where('user_id', $buyer->id)->value('consultant_id'),
            'A referral link must never move an existing student away from the consultant who signed them up.',
        );
    }

    /**
     * ⚠️ A STALE ?ref= MUST NOT SPONSOR A CONSULTANT'S OWN PURCHASE.
     *
     * A consultant who had ever browsed someone else's referral link still
     * carried that code in their session, and store() fell straight through to
     * it whenever no placement was picked. The stranger then collected the
     * 2,500 slot income and 4,000 direct bonus on a course the consultant paid
     * for themselves — and the accounts that purchase opened showed a stranger's
     * SCN as their placement. Surfaced 2026-08-22 as "SCN 124 enne kohomd".
     */
    public function test_a_stale_referral_does_not_sponsor_an_existing_consultants_own_purchase(): void
    {
        $buyer = $this->consultantUser();

        $strangerUser = User::factory()->create();
        $strangerUser->assignRole('consultant');
        $stranger = Consultant::create([
            'user_id' => $strangerUser->id,
            'consultant_code' => 'CON'.Str::random(8),
            'referral_code' => strtoupper(Str::random(10)),
            'scn_code' => 'SCN-9'.random_int(1000, 9999),
            'status' => 'active',
        ]);

        // ⚠️ THE ASSERTION CHANGED 2026-08-27, THE SECURITY PROPERTY DID NOT.
        //
        // This used to assert that the purchase went through and landed on the
        // buyer's OWN account. An existing consultant must now choose which of
        // their accounts a course is bought under, because the purchase opens a
        // set of three and the old fallback stranded it under their main, in
        // neither Service Group — see CheckoutRequiresAPlacementTest.
        //
        // So the stale link is still powerless, by a stronger route: nothing is
        // recorded at all. Both halves are asserted below, because "no payment"
        // would also be true if checkout were simply broken.
        $this->actingAs($buyer)
            ->withSession(['referral_code' => $stranger->referral_code])
            ->post(route('checkout.store', $this->course()), ['method' => 'bank_transfer', 'reference_number' => 'TXN-TEST', 'receipt' => \Illuminate\Http\UploadedFile::fake()->create('slip.pdf', 8, 'application/pdf')])
            ->assertSessionHasErrors('placement_consultant_id');

        $this->assertNull(
            Payment::where('user_id', $buyer->id)->where('course_id', $this->course()->id)->first(),
            'A link must not hand a stranger the commission — and nothing is recorded to be attributed at all.',
        );

        $this->assertSame(
            0,
            Payment::where('consultant_id', $stranger->id)->count(),
            'The stranger gains nothing from the stale link.',
        );

        // ...and the deliberate purchase still works, so this is a rule, not a
        // broken checkout.
        $this->actingAs($buyer)
            ->withSession(['referral_code' => $stranger->referral_code])
            ->post(route('checkout.store', $this->course()), [
                'method' => 'bank_transfer',
                'reference_number' => 'TXN-TEST',
                'receipt' => \Illuminate\Http\UploadedFile::fake()->create('slip.pdf', 8, 'application/pdf'),
                'placement_consultant_id' => $buyer->consultant->id,
                'placement_tier' => 1,
            ])->assertRedirect()->assertSessionHasNoErrors();

        $payment = Payment::where('user_id', $buyer->id)->where('course_id', $this->course()->id)->firstOrFail();

        $this->assertNotSame($stranger->id, $payment->consultant_id, 'A link must not hand a stranger the commission.');
        $this->assertSame(
            $buyer->consultant->id,
            $payment->consultant_id,
            "A consultant's own purchase sits in their own network — a self-purchase, which pays no slot or direct bonus.",
        );
    }

    /** A buyer who is NOT a consultant yet still belongs to whoever referred them. */
    public function test_a_referral_still_sponsors_a_buyer_who_is_not_a_consultant(): void
    {
        $buyer = User::factory()->create();
        $buyer->assignRole('student');

        $sponsor = $this->consultantUser()->consultant;

        $this->actingAs($buyer)
            ->withSession(['referral_code' => $sponsor->referral_code])
            ->post(route('checkout.store', $this->course()), ['method' => 'bank_transfer', 'reference_number' => 'TXN-TEST', 'receipt' => \Illuminate\Http\UploadedFile::fake()->create('slip.pdf', 8, 'application/pdf')])
            ->assertRedirect();

        $this->assertSame(
            $sponsor->id,
            Payment::where('user_id', $buyer->id)->value('consultant_id'),
        );
    }

    /** ⚠️ A set-main represents no Service Group, so it is not offerable. */
    public function test_a_set_main_is_hidden_from_the_placement_picker(): void
    {
        $consultantUser = $this->consultantUser();
        $consultant = $consultantUser->consultant;

        app(\App\Services\HiddenAccountService::class)->addAccountSet($consultant);

        $setMain = $consultant->hiddenAccounts()->orderBy('id')->get()[2]; // #3
        $this->assertNull($setMain->serviceGroupLeg(), 'Precondition: #3 is the set-main.');

        $this->actingAs($consultantUser)
            ->get(route('checkout.show', $this->course()))
            ->assertOk()
            ->assertDontSee($setMain->scn_code);

        // ...and a hand-posted placement under it is refused.
        $this->actingAs($consultantUser)->post(route('checkout.store', $this->course()), [
            'method' => 'bank_transfer',
            'reference_number' => 'TXN-TEST',
            'receipt' => \Illuminate\Http\UploadedFile::fake()->create('slip.pdf', 8, 'application/pdf'),
            'placement_consultant_id' => $setMain->id,
            'placement_tier' => 1,
        ])->assertSessionHasErrors('placement_consultant_id');
    }
}
