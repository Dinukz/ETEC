<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * "Re-send" for a user whose registration is already paid (2026-09-22).
 *
 * Owner: "email re send option eka normal penwanawa eth api userwa search
 * krahama eka button eka fade wela non clickable". Not the search — the button
 * only worked while a PENDING payment existed. User 3452 on live had one payment,
 * already verified (free card), and had never logged in, so there was no way to
 * send her login details again.
 *
 * Now, with nothing pending, the button sends LOGIN DETAILS instead:
 *   - never logged in (password_must_change) → a new temporary password;
 *   - chose their own password               → a reset link, password untouched.
 */
class ResendLoginDetailsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Mail::fake();
    }

    private function superAdmin(): User
    {
        return User::where('email', 'superadmin@etec.lk')->firstOrFail();
    }

    /** A student whose only payment is already verified — nothing to "chase". */
    private function paidStudent(bool $neverLoggedIn): User
    {
        $student = User::factory()->create([
            'password' => Hash::make('Original1!'),
            'password_must_change' => $neverLoggedIn,
            'username' => 'SRN-0'.random_int(10000, 99999),
        ]);
        $student->syncRoles(['student']);

        $course = Course::where('status', 'published')->firstOrFail();
        $enrollment = Enrollment::create([
            'user_id' => $student->id, 'course_id' => $course->id, 'status' => 'active', 'enrolled_at' => now(),
        ]);

        Payment::create([
            'payment_code' => 'PYM'.Str::random(8), 'user_id' => $student->id,
            'course_id' => $course->id, 'enrollment_id' => $enrollment->id,
            'amount' => 0, 'currency' => 'LKR', 'method' => 'bank_transfer',
            'payment_type' => 'free_card', 'status' => 'verified', 'paid_at' => now(), 'verified_at' => now(),
        ]);

        return $student->fresh();
    }

    public function test_the_button_is_clickable_for_a_paid_student(): void
    {
        $student = $this->paidStudent(neverLoggedIn: true);

        $this->actingAs($this->superAdmin())
            ->get(route('users.edit', $student))
            ->assertOk()
            ->assertSee(route('users.resend-registration', $student), false)
            ->assertSee('Sends their login details')
            ->assertDontSee('No registration is waiting for payment.');
    }

    /** ⚠️ Live case: user 3452 — paid, never logged in. */
    public function test_a_paid_student_who_never_logged_in_gets_a_new_temporary_password(): void
    {
        $student = $this->paidStudent(neverLoggedIn: true);
        $before = $student->password;

        $this->actingAs($this->superAdmin())
            ->post(route('users.resend-registration', $student))
            ->assertRedirect()
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'Login details'));

        $this->assertNotSame($before, $student->fresh()->password, 'a fresh temporary password was issued');
        $this->assertTrue((bool) $student->fresh()->password_must_change, 'and they still set their own on first login');
    }

    /** ⚠️ THE RULE THAT MATTERS: a password they chose is never replaced. */
    public function test_a_paid_student_with_their_own_password_gets_a_reset_link_instead(): void
    {
        $student = $this->paidStudent(neverLoggedIn: false);
        $before = $student->password;

        $this->actingAs($this->superAdmin())
            ->post(route('users.resend-registration', $student))
            ->assertRedirect()
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'reset'));

        $this->assertSame($before, $student->fresh()->password, 'their password is untouched');
        $this->assertTrue(
            DB::table(config('auth.passwords.users.table'))->where('email', $student->email)->exists(),
            'a real reset token was issued, so the link in the email works',
        );
    }

    public function test_the_edit_page_says_which_email_will_go(): void
    {
        $this->actingAs($this->superAdmin())
            ->get(route('users.edit', $this->paidStudent(neverLoggedIn: false)))
            ->assertSee('Sends a password reset link');
    }
}
