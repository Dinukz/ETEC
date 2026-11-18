<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The two chase-up actions on Pending Students: re-send the registration email,
 * and delete a registration that was never paid for.
 *
 * The delete path is the one that matters most. Users are soft-deleted
 * everywhere else in this app, but `users.email` is uniquely indexed and
 * `enrollments` has a unique (user_id, course_id) — so a soft-deleted row keeps
 * blocking the address, which is exactly the "already added this email" dead end
 * the owner hit. Hence forceDelete(), with the same guard rails
 * students:purge-unpaid uses so a paying student can never be caught by it.
 */
class PendingRegistrationActionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function consultantUser(): User
    {
        return User::where('email', 'consultant@etec.lk')->firstOrFail();
    }

    /** A student registered by the seeded consultant, with one pending payment. */
    private function pendingRegistration(string $email, array $userAttributes = []): Payment
    {
        $consultant = $this->consultantUser()->consultant;

        $user = User::create(array_merge([
            'name' => 'Pending Person',
            'email' => $email,
            'password' => Hash::make('secret-password'),
            'status' => 'active',
            'password_must_change' => true,
        ], $userAttributes));

        Student::create([
            'user_id' => $user->id,
            'student_code' => 'SRN'.Str::random(6),
            'consultant_id' => $consultant->id,
            'status' => 'active',
            'enrolled_at' => now(),
        ]);

        return Payment::create([
            'payment_code' => 'PYM'.Str::random(6),
            'user_id' => $user->id,
            'course_id' => Course::first()->id,
            'consultant_id' => $consultant->id,
            'requested_tier' => 1,
            'amount' => 28000,
            'currency' => 'LKR',
            'method' => 'bank_transfer',
            'status' => 'pending',
            'paid_at' => null,
        ]);
    }

    // ----- Delete ------------------------------------------------------------

    public function test_deleting_an_unpaid_registration_frees_the_email_immediately(): void
    {
        $payment = $this->pendingRegistration('free-me@example.com');
        $userId = $payment->user_id;

        $this->actingAs($this->consultantUser())
            ->delete(route('consultant.payments.destroy', $payment))
            ->assertRedirect();

        // withTrashed(), because a soft delete would leave the row — and the
        // unique email index — in place, which is the whole bug.
        $this->assertNull(User::withTrashed()->find($userId), 'The user row must be gone entirely, not soft-deleted.');
        $this->assertDatabaseMissing('payments', ['id' => $payment->id]);
        $this->assertDatabaseMissing('students', ['user_id' => $userId]);
        $this->assertDatabaseMissing('enrollments', ['user_id' => $userId]);

        $reused = User::create([
            'name' => 'Someone Else',
            'email' => 'free-me@example.com',
            'password' => Hash::make('whatever'),
            'status' => 'active',
        ]);

        $this->assertDatabaseHas('users', ['id' => $reused->id, 'email' => 'free-me@example.com']);
    }

    public function test_a_verified_payment_cannot_be_deleted_from_this_screen(): void
    {
        $payment = $this->pendingRegistration('paid@example.com');
        $payment->update(['status' => 'verified', 'payment_type' => 'full', 'verified_at' => now()]);

        $this->actingAs($this->consultantUser())
            ->delete(route('consultant.payments.destroy', $payment))
            ->assertRedirect();

        $this->assertDatabaseHas('payments', ['id' => $payment->id]);
        $this->assertDatabaseHas('users', ['id' => $payment->user_id]);
    }

    /**
     * Someone who already paid for another course keeps their account — only
     * the unpaid registration is dropped. Mirrors the safety net in
     * students:purge-unpaid.
     */
    public function test_an_account_with_other_verified_money_survives_the_delete(): void
    {
        $payment = $this->pendingRegistration('half-paid@example.com');

        // No CourseFactory exists in this project, so build one by hand.
        $otherCourse = Course::create([
            'title' => 'Second Course',
            'slug' => 'second-course',
            'description' => 'Test course.',
            'price' => 28000,
            'currency' => 'LKR',
            'level' => 'beginner',
            'status' => 'published',
            'published_at' => now(),
        ]);
        Payment::create([
            'payment_code' => 'PYM'.Str::random(6),
            'user_id' => $payment->user_id,
            'course_id' => $otherCourse->id,
            'consultant_id' => $payment->consultant_id,
            'amount' => 28000,
            'currency' => 'LKR',
            'method' => 'bank_transfer',
            'payment_type' => 'full',
            'status' => 'verified',
            'paid_at' => now(),
            'verified_at' => now(),
        ]);

        $this->actingAs($this->consultantUser())
            ->delete(route('consultant.payments.destroy', $payment))
            ->assertRedirect();

        $this->assertDatabaseMissing('payments', ['id' => $payment->id]);
        $this->assertDatabaseHas('users', ['id' => $payment->user_id]);
    }

    public function test_a_consultant_cannot_delete_a_registration_that_is_not_theirs(): void
    {
        $payment = $this->pendingRegistration('not-yours@example.com');

        $outsider = User::factory()->create();
        $outsider->assignRole('consultant');
        \App\Models\Consultant::create([
            'user_id' => $outsider->id,
            'consultant_code' => 'CON'.Str::random(6),
            'referral_code' => strtoupper(Str::random(10)),
            'status' => 'active',
        ]);

        $this->actingAs($outsider)
            ->delete(route('consultant.payments.destroy', $payment))
            ->assertForbidden();

        $this->assertDatabaseHas('users', ['id' => $payment->user_id]);
    }

    // ----- Re-send -----------------------------------------------------------

    public function test_resending_issues_a_new_temporary_password_while_the_student_has_never_logged_in(): void
    {
        $payment = $this->pendingRegistration('resend-me@example.com');
        $originalHash = $payment->user->password;

        $this->actingAs($this->consultantUser())
            ->post(route('consultant.payments.resend-email', $payment))
            ->assertRedirect();

        $this->assertNotSame($originalHash, $payment->user->fresh()->password);
    }

    /**
     * Once the student has chosen their own password, password_must_change is
     * cleared — and a consultant clicking "re-send" must not silently lock them
     * out of the account they are already using.
     */
    public function test_resending_never_overwrites_a_password_the_student_chose_themselves(): void
    {
        $payment = $this->pendingRegistration('settled@example.com', ['password_must_change' => false]);
        $originalHash = $payment->user->password;

        $this->actingAs($this->consultantUser())
            ->post(route('consultant.payments.resend-email', $payment))
            ->assertRedirect();

        $this->assertSame($originalHash, $payment->user->fresh()->password);
    }

    public function test_resending_is_refused_once_the_payment_is_verified(): void
    {
        $payment = $this->pendingRegistration('already-paid@example.com');
        $payment->update(['status' => 'verified', 'payment_type' => 'full', 'verified_at' => now()]);
        $originalHash = $payment->user->password;

        $this->actingAs($this->consultantUser())
            ->post(route('consultant.payments.resend-email', $payment))
            ->assertSessionHas('error');

        $this->assertSame($originalHash, $payment->user->fresh()->password);
    }

    // ----- After registering -------------------------------------------------

    /**
     * Registration used to bounce to Direct Registrations. It now stays put and
     * hands the page the details for the saveable receipt popup.
     */
    public function test_registering_a_student_returns_to_the_form_with_the_receipt_details(): void
    {
        $course = Course::where('status', 'published')->firstOrFail();

        $response = $this->actingAs($this->consultantUser())->post(route('consultant.students.store'), [
            'name' => 'Brand New Student',
            'email' => 'brand-new@example.com',
            'course_id' => $course->id,
            'placement_tier' => 1,
        ]);

        $response->assertRedirect(route('consultant.students.create'));

        $created = session('student_created');
        $this->assertIsArray($created, 'The receipt payload must be flashed for the popup.');
        $this->assertSame('Brand New Student', $created['name']);
        $this->assertSame($course->title, $created['course']);
        $this->assertNotEmpty($created['payment_code'], 'The popup shows the Pay ID, so it has to be there.');
        $this->assertNotEmpty($created['expires_on']);
    }
}
