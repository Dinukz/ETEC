<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/**
 * End-to-end: get a real reset token the way Password::sendResetLink() would
 * hand it to the (overridden) User::sendPasswordResetNotification(), submit
 * the reset form, then log in with the new password through the real
 * LoginController every user actually hits.
 */
class PasswordResetFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_full_reset_flow_then_login_with_new_password(): void
    {
        $user = User::where('email', 'student@etec.lk')->firstOrFail();

        // The same raw token Password::sendResetLink() would generate and hand
        // to sendPasswordResetNotification() to embed in the emailed URL.
        $token = Password::createToken($user);

        $response = $this->post(route('password.update'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'BrandNewPass123!',
            'password_confirmation' => 'BrandNewPass123!',
        ]);

        // Reset now signs the user straight in and lands them on their
        // profile — no separate login step needed.
        $response->assertRedirect(route('profile.edit'));
        $response->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($user->fresh());

        // The new password must also work for a subsequent, ordinary login.
        Auth::logout();
        $login = $this->post('/login', [
            'login' => $user->email,
            'password' => 'BrandNewPass123!',
        ]);

        $login->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($user->fresh());
    }

    public function test_reset_flow_via_username_login_too(): void
    {
        $user = User::where('email', 'consultant@etec.lk')->firstOrFail();
        $user->update(['username' => 'CONS-TEST-01']);

        $token = Password::createToken($user);

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'AnotherPass456!',
            'password_confirmation' => 'AnotherPass456!',
        ])->assertRedirect(route('profile.edit'));

        Auth::logout();
        $login = $this->post('/login', [
            'login' => $user->username,
            'password' => 'AnotherPass456!',
        ]);

        $login->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($user->fresh());
    }

    /**
     * Reproduces the real production bug: a brand-new student is still logged
     * in (forced onto /profile with a temp password) when they open the reset
     * link in the same browser. ForcePasswordChange's allow-list previously
     * had a typo ('password.store' instead of the real route name
     * 'password.update'), so submitting the new password was silently
     * redirected back to /profile WITHOUT ever running the reset — the
     * password never actually changed, so the next login attempt failed.
     */
    public function test_reset_works_while_still_logged_in_with_a_forced_password_change(): void
    {
        $user = User::where('email', 'student@etec.lk')->firstOrFail();
        $user->update(['password_must_change' => true]);

        $this->actingAs($user);

        $token = Password::createToken($user);

        $response = $this->post(route('password.update'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'StillLoggedIn789!',
            'password_confirmation' => 'StillLoggedIn789!',
        ]);

        $response->assertRedirect(route('profile.edit'));
        $this->assertFalse($user->fresh()->password_must_change, 'The forced-change flag must be cleared.');

        Auth::logout();
        $login = $this->post('/login', [
            'login' => $user->email,
            'password' => 'StillLoggedIn789!',
        ]);

        $login->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($user->fresh());
    }
}
