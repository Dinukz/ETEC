<?php

namespace Tests\Feature;

use App\Models\Consultant;
use App\Models\Course;
use App\Models\Payment;
use App\Models\User;
use App\Services\FinanceSchemaRepair;
use App\Services\HiddenAccountService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The two consultant pickers on Student Registration.
 *
 * Reported 2026-09-11: *"Consultant (Requested By) methanat me consultant
 * yatathe inn okkom load wenwaa eth Partner Consultant (optional) ekat load
 * wen na"* — the downline loads into the first picker but appears not to reach
 * the second.
 *
 * The two lists answer different questions and must keep doing so:
 *   Requested By — WHICH PERSON registers the student and PAYS for it. Real,
 *                  login-backed consultants only; never changed by the
 *                  Partner pick (2026-11-03, RequestedByPaysForADownlineAccountTest).
 *   Partner      — WHICH ACCOUNT the student is seated in, and so where the
 *                  money is calculated from. $partnerConsultants stays
 *                  placement buckets only; a downline person's MAIN account
 *                  reaches the form through $partnerOptions (2026-11-03).
 *
 * The narrowing is client-side: the Partner list shows the accounts of the
 * selected Requested By's group. These tests pin that the server ships every
 * account that narrowing could ever need.
 */
class PartnerConsultantOptionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        FinanceSchemaRepair::ensure();
    }

    private function viewer(): Consultant
    {
        return User::where('email', 'consultant@etec.lk')->firstOrFail()->consultant;
    }

    /** A real consultant hanging under one of $parent's accounts. */
    private function downlineOf(Consultant $parent, string $name): Consultant
    {
        $user = User::factory()->create(['name' => $name]);
        $user->syncRoles(['consultant']);

        $consultant = Consultant::create([
            'user_id' => $user->id,
            'parent_consultant_id' => $parent->hiddenAccounts()->orderBy('id')->firstOrFail()->id,
            'consultant_code' => 'CON'.Str::random(8),
            'scn_code' => 'SCN-'.random_int(10000, 99999),
            'referral_code' => strtoupper(Str::random(10)),
            'status' => 'active',
        ]);

        app(HiddenAccountService::class)->ensureFor($consultant);

        return $consultant->fresh();
    }

    /** Buy a course so the buyer gains a set of three more accounts. */
    private function buyASet(Consultant $buyer): void
    {
        $course = Course::where('status', 'published')->firstOrFail();

        $payment = Payment::create([
            'payment_code' => 'PYM-'.Str::random(8),
            'user_id' => $buyer->user_id,
            'course_id' => $course->id,
            'consultant_id' => $buyer->hiddenAccounts()->orderBy('id')->firstOrFail()->id,
            'requested_tier' => 1,
            'amount' => 10000,
            'currency' => 'LKR',
            'method' => 'bank_transfer',
            'payment_type' => 'full',
            'status' => 'pending',
        ]);

        app(PaymentService::class)->verify($payment, User::role('admin')->first());
    }

    private function pickerLists(Consultant $viewer): array
    {
        $response = $this->actingAs($viewer->user)
            ->get(route('consultant.students.create'))
            ->assertOk();

        return [
            collect($response->viewData('requestedByConsultants')),
            collect($response->viewData('partnerConsultants')),
        ];
    }

    // ----- the reported symptom ----------------------------------------------

    /**
     * ⚠️ THE ONE THAT WAS REPORTED. Everyone in the downline reaches the first
     * picker; their ACCOUNTS must reach the second, or picking a downline
     * consultant leaves the partner list empty with no explanation.
     */
    public function test_a_downline_consultants_accounts_reach_the_partner_list(): void
    {
        $viewer = $this->viewer();
        $below = $this->downlineOf($viewer, 'Downline Person');

        [$requestedBy, $partners] = $this->pickerLists($viewer);

        $this->assertTrue(
            $requestedBy->contains('id', $below->id),
            'guard: they are offered as Requested By',
        );

        foreach ($below->hiddenAccounts()->pluck('id') as $accountId) {
            $this->assertTrue(
                $partners->contains('id', $accountId),
                "account {$accountId} of the downline consultant is offered as a partner",
            );
        }
    }

    /** Every partner option carries the owner, which is what the UI filters on. */
    public function test_every_partner_option_knows_who_owns_it(): void
    {
        $viewer = $this->viewer();
        $this->downlineOf($viewer, 'Downline Person');

        [, $partners] = $this->pickerLists($viewer);

        $this->assertTrue($partners->isNotEmpty(), 'guard: there are options');

        foreach ($partners as $account) {
            $this->assertNotNull($account->owner_consultant_id, 'an option with no owner can never be shown');
        }
    }

    // ----- accounts bought with a course -------------------------------------

    /** ⚠️ A bought set is still a placement bucket, so it belongs in the list. */
    public function test_accounts_gained_by_buying_a_course_are_offered(): void
    {
        $viewer = $this->viewer();

        $before = $viewer->hiddenAccounts()->count();
        $this->buyASet($viewer);
        $after = $viewer->fresh()->hiddenAccounts()->count();

        $this->assertGreaterThan($before, $after, 'guard: buying really opened more accounts');

        [, $partners] = $this->pickerLists($viewer->fresh());

        foreach ($viewer->fresh()->hiddenAccounts()->pluck('id') as $accountId) {
            $this->assertTrue($partners->contains('id', $accountId), "bought account {$accountId} is offered");
        }
    }

    // ----- what must NOT be in each list -------------------------------------

    /** A placement bucket has no login and cannot be the person credited. */
    public function test_the_requested_by_list_is_people_only(): void
    {
        $viewer = $this->viewer();
        $this->downlineOf($viewer, 'Downline Person');

        [$requestedBy] = $this->pickerLists($viewer);

        foreach ($requestedBy as $person) {
            $this->assertFalse((bool) $person->is_hidden, 'no placement account may be offered as Requested By');
            $this->assertNotNull($person->user, 'and every option is a real login');
        }
    }

    public function test_the_partner_list_is_accounts_only(): void
    {
        $viewer = $this->viewer();
        $this->downlineOf($viewer, 'Downline Person');

        [, $partners] = $this->pickerLists($viewer);

        foreach ($partners as $account) {
            $this->assertTrue((bool) $account->is_hidden, 'no main account may be offered as a partner');
        }
    }

    /** Nobody outside the network, in either list. */
    public function test_a_stranger_is_in_neither_list(): void
    {
        $viewer = $this->viewer();

        $strangerUser = User::factory()->create();
        $strangerUser->syncRoles(['consultant']);

        $stranger = Consultant::create([
            'user_id' => $strangerUser->id,
            'consultant_code' => 'CON'.Str::random(8),
            'scn_code' => 'SCN-9'.random_int(1000, 9999),
            'referral_code' => strtoupper(Str::random(10)),
            'status' => 'active',
        ]);

        app(HiddenAccountService::class)->ensureFor($stranger);

        [$requestedBy, $partners] = $this->pickerLists($viewer);

        $this->assertFalse($requestedBy->contains('id', $stranger->id));

        foreach ($stranger->fresh()->hiddenAccounts()->pluck('id') as $accountId) {
            $this->assertFalse($partners->contains('id', $accountId), 'a stranger account is never offered');
        }
    }

    /**
     * ⚠️ CHANGED ON 2026-09-25 — A DOWNLINE PERSON'S ACCOUNT IS NOW ALLOWED.
     *
     * This used to be refused: the partner account had to belong to whoever was
     * named in "Consultant (Requested By)", so filling a downline account meant
     * first knowing, and selecting, which person held it.
     *
     * The owner asked for the opposite workflow — pick the ACCOUNT you want
     * balanced: *"eyage yathe inn SCN 120 account ekat student kenk add krnwa"*.
     * The account decides the MONEY, because an account belongs to one person
     * and that is who the engine credits (RegisterOnADownlineAccountTest pins
     * the whole chain). Since 2026-11-03 Requested By (the viewer) stays and
     * PAYS — see RequestedByPaysForADownlineAccountTest.
     *
     * The boundary itself has NOT moved — see the test below.
     */
    public function test_a_downline_accounts_placement_is_accepted_and_follows_that_account(): void
    {
        $viewer = $this->viewer();
        $below = $this->downlineOf($viewer, 'Downline Person');
        $theirAccount = $below->hiddenAccounts()->orderBy('id')->firstOrFail();

        $course = Course::where('status', 'published')->firstOrFail();
        $email = 'placed'.Str::random(5).'@example.com';

        $this->actingAs($viewer->user)
            ->post(route('consultant.students.store'), [
                'name' => 'Placed On Downline',
                'email' => $email,
                'phone' => '0770000000',
                'date_of_birth' => '2000-01-01',
                'gender' => 'male',
                'address' => '1 Test Road',
                'city' => 'Colombo',
                'district' => 'Colombo',
                'nic' => '200012345'.random_int(100, 999),
                'nic_owned_by_student' => 1,
                'course_id' => $course->id,
                'language' => 'English',
                'class_type' => 'Online',
                'course_type' => 'Full',
                // Named as the viewer, placed in the DOWNLINE's account — the
                // account decides the money; the viewer stays and pays.
                'requested_by_consultant_id' => $viewer->id,
                'partner_consultant_id' => $theirAccount->id,
                'placement_tier' => 1,
            ])
            ->assertSessionHasNoErrors();

        $payment = \App\Models\Payment::whereHas('user', fn ($q) => $q->where('email', $email))->firstOrFail();

        $this->assertSame(
            (int) $theirAccount->id,
            (int) $payment->consultant_id,
            'the registration sits on the account that was picked',
        );
    }

    /** ⚠️ And the server still refuses an account outside this consultant's tree. */
    public function test_posting_a_partner_from_outside_the_downline_is_refused(): void
    {
        $viewer = $this->viewer();

        $strangerUser = User::factory()->create();
        $strangerUser->syncRoles(['consultant']);

        $stranger = Consultant::create([
            'user_id' => $strangerUser->id,
            'consultant_code' => 'CON'.Str::random(8),
            'scn_code' => 'SCN-8'.random_int(1000, 9999),
            'referral_code' => strtoupper(Str::random(10)),
            'status' => 'active',
        ]);

        app(\App\Services\HiddenAccountService::class)->ensureFor($stranger);

        $course = Course::where('status', 'published')->firstOrFail();

        $this->actingAs($viewer->user)
            ->post(route('consultant.students.store'), [
                'name' => 'Mismatched Partner',
                'email' => 'mismatch'.Str::random(5).'@example.com',
                'phone' => '0770000000',
                'date_of_birth' => '2000-01-01',
                'gender' => 'male',
                'address' => '1 Test Road',
                'city' => 'Colombo',
                'district' => 'Colombo',
                'nic' => '200012345'.random_int(100, 999),
                'nic_owned_by_student' => 1,
                'course_id' => $course->id,
                'language' => 'English',
                'class_type' => 'Online',
                'course_type' => 'Full',
                'requested_by_consultant_id' => $viewer->id,
                'partner_consultant_id' => $stranger->fresh()->hiddenAccounts()->orderBy('id')->firstOrFail()->id,
                'placement_tier' => 1,
            ])
            ->assertSessionHasErrors('partner_consultant_id');
    }
}
