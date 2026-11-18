<?php

namespace Tests\Feature;

use App\Models\Consultant;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Payment;
use App\Models\User;
use App\Services\HiddenAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ⚠️ THE EDIT USER SCREEN — asked for 2026-09-10.
 *
 * "Edit user giyama e userge SCN numbers penwann one, e wagem eyage profile eke
 * thiyen okkom details (Personal information, Bank Details, Beneficiary Details)
 * methant load wenn one saha ewa super adminta edit krnn puluwn wenn one, e
 * wagem ethant registration email eka re-send krnn button ekak dala denna."
 *
 * Before this, the screen edited four fields (name, email, phone, status) and
 * showed nothing about the consultant's own profile.
 *
 * ⚠️ TWO RULES THIS FILE EXISTS TO HOLD:
 *  - Bank details are what money is paid into. SUPER ADMIN ONLY, refused in the
 *    action and not merely hidden in the view.
 *  - The profile lock is neither set nor cleared by an admin edit. Clearing it
 *    would quietly re-open the bank details to the consultant; setting it would
 *    lock out someone still filling their profile in.
 */
class EditUserProfileDetailsTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private User $admin;

    private User $consultantUser;

    private Consultant $consultant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        \App\Services\FinanceSchemaRepair::ensure();

        $this->superAdmin = User::role('super_admin')->firstOrFail();
        $this->admin = User::where('email', 'admin@etec.lk')->firstOrFail();
        $this->consultantUser = User::where('email', 'consultant@etec.lk')->firstOrFail();
        $this->consultant = $this->consultantUser->consultant;
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'nic' => '199012345678',
            'date_of_birth' => '1990-05-05',
            'gender' => 'male',
            'address' => '42 Temple Road, Kandy',
            'bank_name' => 'Bank of Ceylon',
            'bank_account_name' => 'D Perera',
            'bank_account_number' => '1234567890',
            'bank_branch' => 'Kandy',
            'beneficiary_name' => 'S Perera',
            'beneficiary_relationship' => 'Spouse',
            'beneficiary_nic' => '199512345678',
            'beneficiary_date_of_birth' => '1995-02-02',
            'beneficiary_phone' => '0771234567',
        ], $overrides);
    }

    // ----- the screen shows what it should ----------------------------------

    public function test_the_edit_screen_shows_the_users_scn_numbers(): void
    {
        $codes = $this->actingAs($this->superAdmin)
            ->get(route('users.edit', $this->consultantUser))
            ->assertOk()
            ->viewData('scnCodes');

        $this->assertNotEmpty($codes, 'a consultant has at least their main SCN');
        $this->assertSame(
            $this->consultant->scn_code ?? $this->consultant->consultant_code,
            $codes[0],
            'the main account comes first',
        );
    }

    public function test_a_non_consultant_has_no_scn_numbers(): void
    {
        $this->assertSame(
            [],
            $this->actingAs($this->superAdmin)
                ->get(route('users.edit', $this->admin))
                ->viewData('scnCodes'),
        );
    }

    public function test_a_super_admin_sees_the_profile_panels(): void
    {
        $this->actingAs($this->superAdmin)
            ->get(route('users.edit', $this->consultantUser))
            ->assertOk()
            ->assertSee('Personal information')
            ->assertSee('Bank details')
            ->assertSee('Beneficiary details');
    }

    /** An ordinary admin may READ the panels, but gets no form. */
    public function test_an_ordinary_admin_does_not_get_the_form(): void
    {
        $this->actingAs($this->admin)
            ->get(route('users.edit', $this->consultantUser))
            ->assertOk()
            ->assertSee('only be edited by a Super Admin')
            ->assertDontSee('Save profile details');
    }

    /**
     * ⚠️ READ-ONLY IS NOT ABSENT (2026-09-22). This panel used to be one
     * sentence and no data at all, so an admin could not look up the NIC or the
     * bank of a user they were working on — *"User Management eke thiyen user
     * lage profile eka view krnn puluwn widiyt hadann"*. Editing is still Super
     * Admin only; that is asserted by test_an_ordinary_admin_is_refused().
     */
    public function test_an_ordinary_admin_can_still_read_the_profile(): void
    {
        $this->consultant->update([
            'nic' => '199012345678',
            'address' => '42 Temple Road, Kandy',
            'bank_name' => 'Bank of Ceylon',
            'bank_account_number' => '1234567890',
            'beneficiary_name' => 'K Perera',
        ]);

        $this->actingAs($this->admin)
            ->get(route('users.edit', $this->consultantUser))
            ->assertOk()
            ->assertSee('Personal information')
            ->assertSee('Bank details')
            ->assertSee('Beneficiary details')
            ->assertSee('199012345678')
            ->assertSee('42 Temple Road, Kandy')
            ->assertSee('Bank of Ceylon')
            ->assertSee('1234567890')
            ->assertSee('K Perera')
            ->assertDontSee('Save profile details');
    }

    /**
     * The Service Group details of an SCN holder, on their own page: which leg
     * each person is seated on, not just how many students the account holds.
     */
    public function test_the_service_group_accounts_name_who_sits_on_each_leg(): void
    {
        $account = $this->consultant->hiddenAccounts()->orderBy('id')->firstOrFail();

        $buyer = User::factory()->create(['name' => 'Seated Student']);
        $buyer->assignRole('student');

        $course = Course::where('status', 'published')->firstOrFail();

        $enrollment = Enrollment::create([
            'user_id' => $buyer->id, 'course_id' => $course->id,
            'status' => 'pending', 'enrolled_at' => now(),
        ]);

        $payment = Payment::create([
            'payment_code' => 'PYM'.Str::random(8), 'user_id' => $buyer->id,
            'course_id' => $course->id, 'enrollment_id' => $enrollment->id,
            'consultant_id' => $account->id, 'requested_tier' => 1,
            'amount' => 39000, 'currency' => 'LKR', 'method' => 'bank_transfer',
            'payment_type' => 'full', 'status' => 'pending', 'paid_at' => now(),
        ]);

        app(\App\Services\PaymentService::class)->verify(
            $payment,
            User::where('email', 'accountant@etec.lk')->firstOrFail(),
        );

        $this->actingAs($this->admin)
            ->get(route('users.edit', $this->consultantUser))
            ->assertOk()
            ->assertSee('Service Group 1')
            ->assertSee('Service Group 2')
            ->assertSee('Seated Student')
            ->assertSee('Nobody on this leg yet');
    }

    /**
     * ⚠️ THE CONSULTANT'S OWN SERVICE GROUPS FIGURES, ON THE ADMIN'S SCREEN
     * (2026-09-23) — *"consultant ge service group eka, eyata service income …
     * userge edit page ekat awaham"*.
     *
     * The assertion that matters is not that a number is printed: it is that
     * the number is the SAME one the consultant's own page shows. Both read
     * ServiceGroupFlush::state(), so a drift here means somebody re-derived the
     * balance on one of the two screens.
     */
    public function test_the_edit_page_shows_the_same_leg_balances_as_the_consultants_own_page(): void
    {
        $account = $this->consultant->hiddenAccounts()->orderBy('id')->firstOrFail();

        $this->seatStudentOn($account, 'Leg Balance Student');

        $flusher = app(\App\Services\ServiceGroupFlush::class);
        $group = \App\Models\Group::where('consultant_id', $account->id)->orderBy('id')->firstOrFail();
        $expected = $flusher->state($account, $group, \App\Services\ServiceGroupFlush::defaultCutoff());

        $this->assertGreaterThan(0, $expected['leg1_total'], 'the fixture really put money on a leg');

        $response = $this->actingAs($this->admin)->get(route('users.edit', $this->consultantUser));

        $response->assertOk()->assertSee('Service Groups')->assertSee('SG1 Leg Balance');

        $snapshot = $response->viewData('serviceGroups');
        $row = collect($snapshot['rows'])->firstWhere('code', $account->scn_code ?: $account->consultant_code);

        $this->assertNotNull($row);
        $this->assertSame((float) $expected['leg1_total'], $row['leg1'], 'same leg balance as their own page');
        $this->assertSame((float) $expected['leg2_total'], $row['leg2']);

        // And the income block is the Dashboard's rule, not consultants.total_earned.
        $this->assertSame(
            \App\Services\SettledIncome::totals($this->consultant->accountIds()),
            $snapshot['income'],
        );
    }

    /** A user who holds no SCN account gets no Service Groups panel at all. */
    public function test_a_non_consultant_has_no_service_groups_panel(): void
    {
        $student = User::factory()->create();
        $student->assignRole('student');

        $this->actingAs($this->admin)
            ->get(route('users.edit', $student))
            ->assertOk()
            ->assertDontSee('SG1 Leg Balance');
    }

    /** Verified purchase → a seat and its 2,500 on $account. */
    private function seatStudentOn(Consultant $account, string $name): void
    {
        $buyer = User::factory()->create(['name' => $name]);
        $buyer->assignRole('student');

        $course = Course::where('status', 'published')->firstOrFail();

        $enrollment = Enrollment::create([
            'user_id' => $buyer->id, 'course_id' => $course->id,
            'status' => 'pending', 'enrolled_at' => now(),
        ]);

        $payment = Payment::create([
            'payment_code' => 'PYM'.Str::random(8), 'user_id' => $buyer->id,
            'course_id' => $course->id, 'enrollment_id' => $enrollment->id,
            'consultant_id' => $account->id, 'requested_tier' => 1,
            'amount' => 39000, 'currency' => 'LKR', 'method' => 'bank_transfer',
            'payment_type' => 'full', 'status' => 'pending', 'paid_at' => now(),
        ]);

        app(\App\Services\PaymentService::class)->verify(
            $payment,
            User::where('email', 'accountant@etec.lk')->firstOrFail(),
        );
    }

    /** The student side of the same person, which was on no admin screen at all. */
    public function test_the_registration_details_are_shown(): void
    {
        $student = \App\Models\Student::create([
            'user_id' => $this->consultantUser->id,
            'student_code' => 'SRN-TESTED1',
            'nic' => '200012345678',
            'city' => 'Negombo',
            'district' => 'Gampaha',
            'status' => 'active',
        ]);

        $this->actingAs($this->admin)
            ->get(route('users.edit', $this->consultantUser))
            ->assertOk()
            ->assertSee('Registration details')
            ->assertSee($student->student_code)
            ->assertSee('200012345678')
            ->assertSee('Negombo')
            ->assertSee('Gampaha');
    }

    // ----- editing ----------------------------------------------------------

    public function test_a_super_admin_can_save_all_three_groups(): void
    {
        $this->actingAs($this->superAdmin)
            ->from(route('users.edit', $this->consultantUser))
            ->post(route('users.profile-details', $this->consultantUser), $this->payload())
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $fresh = $this->consultant->fresh();

        $this->assertSame('199012345678', $fresh->nic);
        $this->assertSame('42 Temple Road, Kandy', $fresh->address);
        $this->assertSame('1234567890', $fresh->bank_account_number);
        $this->assertSame('S Perera', $fresh->beneficiary_name);
    }

    /** ⚠️ THE GATE. Bank details are not an ordinary admin's business. */
    public function test_an_ordinary_admin_is_refused(): void
    {
        $this->actingAs($this->admin)
            ->post(route('users.profile-details', $this->consultantUser), $this->payload())
            ->assertForbidden();

        $this->assertNotSame('1234567890', $this->consultant->fresh()->bank_account_number);
    }

    public function test_a_consultant_cannot_reach_it_at_all(): void
    {
        $this->actingAs($this->consultantUser)
            ->post(route('users.profile-details', $this->consultantUser), $this->payload())
            ->assertForbidden();
    }

    /**
     * ⚠️ Every field is optional here. The consultant's own form requires them
     * all, which is right for completing a profile and wrong for correcting one
     * — an admin fixing a typo must not be forced to re-enter everything.
     */
    public function test_one_field_can_be_corrected_on_its_own(): void
    {
        $this->actingAs($this->superAdmin)
            ->post(route('users.profile-details', $this->consultantUser), ['bank_account_number' => '9999'])
            ->assertSessionHasNoErrors();

        $this->assertSame('9999', $this->consultant->fresh()->bank_account_number);
    }

    /** Format is still enforced — nullable is not "anything goes". */
    public function test_a_bad_value_is_still_refused(): void
    {
        $this->actingAs($this->superAdmin)
            ->post(route('users.profile-details', $this->consultantUser), [
                'gender' => 'banana',
                'date_of_birth' => now()->addYear()->toDateString(),
            ])
            ->assertSessionHasErrors(['gender', 'date_of_birth']);
    }

    /** ⚠️ THE LOCK IS NOT TOUCHED — neither direction. */
    public function test_an_admin_edit_neither_sets_nor_clears_the_profile_lock(): void
    {
        $this->consultantUser->update(['profile_locked_at' => now()->subDay()]);
        $lockedAt = $this->consultantUser->fresh()->profile_locked_at;

        $this->actingAs($this->superAdmin)
            ->post(route('users.profile-details', $this->consultantUser), $this->payload())
            ->assertSessionHasNoErrors();

        $this->assertEquals(
            $lockedAt,
            $this->consultantUser->fresh()->profile_locked_at,
            'saving here must not unlock the consultant\'s own page',
        );
    }

    public function test_an_unlocked_profile_is_not_locked_by_an_admin_edit(): void
    {
        $this->consultantUser->update(['profile_locked_at' => null]);

        $this->actingAs($this->superAdmin)
            ->post(route('users.profile-details', $this->consultantUser), $this->payload())
            ->assertSessionHasNoErrors();

        $this->assertNull($this->consultantUser->fresh()->profile_locked_at);
    }

    public function test_a_user_who_is_not_a_consultant_is_reported_not_crashed(): void
    {
        $this->actingAs($this->superAdmin)
            ->post(route('users.profile-details', $this->admin), $this->payload())
            ->assertSessionHas('error');
    }

    // ----- re-sending the registration email ---------------------------------

    /** @return array{0: User, 1: Payment} */
    private function pendingRegistration(): array
    {
        $student = User::factory()->create(['password_must_change' => true]);
        $student->syncRoles(['student']);

        $course = Course::where('status', 'published')->firstOrFail();
        $enr = Enrollment::create([
            'user_id' => $student->id, 'course_id' => $course->id,
            'status' => 'pending', 'enrolled_at' => now(),
        ]);

        $payment = Payment::create([
            'payment_code' => 'PYM'.Str::random(8), 'user_id' => $student->id,
            'course_id' => $course->id, 'enrollment_id' => $enr->id,
            'consultant_id' => $this->consultant->hiddenAccounts()->orderBy('id')->value('id'),
            'amount' => 39000, 'currency' => 'LKR', 'method' => 'bank_transfer',
            'payment_type' => 'full', 'status' => 'pending', 'paid_at' => now(),
        ]);

        return [$student, $payment];
    }

    public function test_the_button_is_offered_when_a_registration_is_waiting(): void
    {
        [$student] = $this->pendingRegistration();

        $this->assertNotNull(
            $this->actingAs($this->superAdmin)
                ->get(route('users.edit', $student))
                ->viewData('resendPayment'),
        );
    }

    /**
     * ⚠️ REVISED 2026-09-22. With no pending registration there is still no
     * payment to quote — but the button no longer refuses: it sends login
     * details instead (see ResendLoginDetailsTest). This admin chose their own
     * password, so they get a reset link and nothing else changes.
     */
    public function test_without_a_pending_registration_it_sends_login_details_instead(): void
    {
        Mail::fake();

        $this->assertNull(
            $this->actingAs($this->superAdmin)
                ->get(route('users.edit', $this->admin))
                ->viewData('resendPayment'),
        );

        $before = $this->admin->fresh()->password;

        $this->actingAs($this->superAdmin)
            ->post(route('users.resend-registration', $this->admin))
            ->assertSessionHas('success');

        $this->assertSame($before, $this->admin->fresh()->password);
    }

    public function test_it_resends_and_issues_a_password_for_someone_who_never_logged_in(): void
    {
        Mail::fake();
        [$student] = $this->pendingRegistration();
        $before = $student->password;

        $this->actingAs($this->superAdmin)
            ->post(route('users.resend-registration', $student))
            ->assertSessionHas('success');

        $this->assertNotSame($before, $student->fresh()->password, 'a fresh temporary password was issued');
    }

    /**
     * ⚠️ THE RULE THAT MATTERS. Once they have chosen their own password, a
     * re-send must not silently replace it — that locks them out of the account
     * they are already using.
     */
    public function test_it_never_overwrites_a_password_the_student_chose(): void
    {
        Mail::fake();
        [$student] = $this->pendingRegistration();

        $student->forceFill([
            'password' => Hash::make('TheirOwnPassword1!'),
            'password_must_change' => false,
        ])->save();

        $before = $student->fresh()->password;

        $this->actingAs($this->superAdmin)
            ->post(route('users.resend-registration', $student))
            ->assertSessionHas('success');

        $this->assertSame($before, $student->fresh()->password);
    }
}
