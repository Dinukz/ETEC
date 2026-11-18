<?php

namespace Tests\Feature;

use App\Http\Controllers\Dashboard\ImpersonationController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A super admin logs in as a user without their password, and comes back.
 *
 * Asked for 2026-09-14: "super adminta withrk userge account ekat ethaninm log
 * wenn password nathuw ... usergen aha aha inn one na kelinm account ekat log
 * wela check kragnn puluwn issue eka mokkd kiyla."
 */
class SuperAdminLogInAsUserTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function user(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    private function superAdmin(): User
    {
        return $this->user('superadmin@etec.lk');
    }

    private function consultant(): User
    {
        return $this->user('consultant@etec.lk');
    }

    public function test_a_super_admin_logs_in_as_a_user_without_their_password(): void
    {
        $this->actingAs($this->superAdmin())
            ->post(route('users.login-as', $this->consultant()))
            ->assertRedirect(route('consultant.dashboard'));

        $this->assertAuthenticatedAs($this->consultant());
        $this->assertSame($this->superAdmin()->id, session(ImpersonationController::SESSION_KEY));
    }

    public function test_the_bar_shows_on_their_pages_and_brings_the_super_admin_back(): void
    {
        $this->actingAs($this->superAdmin())->post(route('users.login-as', $this->consultant()));

        $this->get(route('consultant.dashboard'))
            ->assertOk()
            ->assertSee('You are logged in as')
            ->assertSee('Return to my account');

        $this->post(route('impersonation.leave'))
            ->assertRedirect(route('users.edit', $this->consultant()));

        $this->assertAuthenticatedAs($this->superAdmin());
        $this->assertFalse(session()->has(ImpersonationController::SESSION_KEY));
    }

    /** ⚠️ THE GUARD. Only a super admin — an admin gets nothing. */
    public function test_an_admin_cannot_log_in_as_a_user(): void
    {
        $admin = $this->user('admin@etec.lk');

        $this->actingAs($admin)
            ->post(route('users.login-as', $this->consultant()))
            ->assertForbidden();

        $this->assertAuthenticatedAs($admin);
    }

    public function test_nobody_is_logged_into_another_super_admin(): void
    {
        $other = User::factory()->create();
        $other->assignRole('super_admin');

        $this->actingAs($this->superAdmin())
            ->post(route('users.login-as', $other))
            ->assertSessionHas('error');

        $this->assertAuthenticatedAs($this->superAdmin());
    }

    public function test_it_cannot_be_started_by_opening_a_link(): void
    {
        $this->actingAs($this->superAdmin())
            ->get('/users/'.$this->consultant()->id.'/login-as')
            ->assertStatus(405);

        $this->assertAuthenticatedAs($this->superAdmin());
    }

    /** The way back is not a way in: with no login-as in progress it does nothing. */
    public function test_return_does_nothing_without_a_login_as(): void
    {
        $this->actingAs($this->consultant())
            ->post(route('impersonation.leave'))
            ->assertForbidden();

        $this->assertAuthenticatedAs($this->consultant());
    }

    /**
     * A user who still has to change a temporary password must not trap the
     * super admin on the profile page — nor have their password changed by them.
     */
    public function test_a_temporary_password_does_not_trap_the_super_admin(): void
    {
        $consultant = $this->consultant();
        $consultant->forceFill(['password_must_change' => true])->save();
        $hash = $consultant->fresh()->password;

        $this->actingAs($this->superAdmin())->post(route('users.login-as', $consultant));

        $this->get(route('consultant.dashboard'))->assertOk();

        $this->post(route('impersonation.leave'))->assertRedirect();

        $this->assertAuthenticatedAs($this->superAdmin());
        $this->assertSame($hash, $consultant->fresh()->password, 'their password was not touched');
        $this->assertTrue($consultant->fresh()->password_must_change, 'and they still have to change it themselves');
    }

    public function test_both_switches_are_written_to_the_activity_log(): void
    {
        $this->actingAs($this->superAdmin())->post(route('users.login-as', $this->consultant()));
        $this->post(route('impersonation.leave'));

        $descriptions = DB::table('activity_log')
            ->where('causer_id', $this->superAdmin()->id)
            ->where('subject_id', $this->consultant()->id)
            ->pluck('description')
            ->all();

        $this->assertContains('Super admin logged in as this user', $descriptions);
        $this->assertContains('Super admin returned from this user', $descriptions);
    }

    public function test_the_users_last_login_is_not_changed(): void
    {
        $this->assertNull($this->consultant()->last_login_at, 'guard');

        $this->actingAs($this->superAdmin())->post(route('users.login-as', $this->consultant()));

        $this->assertNull($this->consultant()->fresh()->last_login_at);
    }

    public function test_only_a_super_admin_sees_the_button(): void
    {
        $this->actingAs($this->superAdmin())
            ->get(route('users.edit', $this->consultant()))
            ->assertOk()
            ->assertSee('Log in as this user');

        $this->actingAs($this->user('admin@etec.lk'))
            ->get(route('users.edit', $this->consultant()))
            ->assertOk()
            ->assertDontSee('Log in as this user');
    }
}
