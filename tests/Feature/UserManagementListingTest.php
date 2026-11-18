<?php

namespace Tests\Feature;

use App\Models\Consultant;
use App\Models\User;
use App\Services\HiddenAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ⚠️ USER MANAGEMENT: SELECT-ALL, PAGE SIZE, AND APPLY WITHOUT A RELOAD.
 *
 * Asked for 2026-08-31: "User management ekata select all button ekak dala denn.
 * E wage showing ekata 10, 50, 100, 500, 1000 widiyt showing wen pramanay select
 * krann drop down ekak hadal denn. User edit ekat giyama methan ekin eka select
 * krala Apply krann one — page eka refresh kran nathuwm ethama update wen
 * widiyta."
 *
 * The riskiest part is the bulk delete, so most of this file is about what it
 * REFUSES.
 */
class UserManagementListingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        \App\Services\FinanceSchemaRepair::ensure();
    }

    private function admin(): User
    {
        return User::role('super_admin')->firstOrFail();
    }

    private function someUser(): User
    {
        $user = User::factory()->create();
        $user->assignRole('student');

        return $user;
    }

    // ---- Page size ----------------------------------------------------------

    public function test_the_page_size_can_be_chosen(): void
    {
        User::factory()->count(12)->create()->each->assignRole('student');

        $this->actingAs($this->admin())
            ->get(route('users.index', ['per_page' => 10]))
            ->assertOk()
            ->assertViewHas('perPage', 10);
    }

    /**
     * ⚠️ An unlisted size must be ignored, not obeyed. `?per_page=999999` would
     * otherwise load every user and their roles into memory at once.
     */
    public function test_an_unlisted_page_size_falls_back_to_the_default(): void
    {
        $this->actingAs($this->admin())
            ->get(route('users.index', ['per_page' => 999999]))
            ->assertOk()
            ->assertViewHas('perPage', 50);
    }

    public function test_changing_the_page_size_keeps_the_search(): void
    {
        $this->actingAs($this->admin())
            ->get(route('users.index', ['per_page' => 100, 'q' => 'nobody-matches-this']))
            ->assertOk()
            ->assertViewHas('perPage', 100)
            ->assertSee('nobody-matches-this', false);
    }

    // ---- Bulk delete --------------------------------------------------------

    public function test_it_deletes_the_selected_users(): void
    {
        $a = $this->someUser();
        $b = $this->someUser();
        $keep = $this->someUser();

        $this->actingAs($this->admin())
            ->delete(route('users.bulk-destroy'), ['ids' => [$a->id, $b->id]])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('users', ['id' => $a->id]);
        $this->assertDatabaseMissing('users', ['id' => $b->id]);
        $this->assertDatabaseHas('users', ['id' => $keep->id]);
    }

    /** ⚠️ Deleting yourself out of the system by ticking every box. */
    public function test_it_refuses_to_delete_you(): void
    {
        $admin = $this->admin();
        $other = $this->someUser();

        $this->actingAs($admin)
            ->delete(route('users.bulk-destroy'), ['ids' => [$admin->id, $other->id]]);

        $this->assertDatabaseHas('users', ['id' => $admin->id]);
        $this->assertDatabaseMissing('users', ['id' => $other->id]);
    }

    /** ⚠️ And locking everybody out by removing the last super admin. */
    public function test_it_refuses_to_delete_the_last_super_admin(): void
    {
        $admin = $this->admin();

        // A second super admin does the deleting, so the "cannot delete
        // yourself" rule is not what saves the first one.
        $second = User::factory()->create();
        $second->assignRole('super_admin');

        $this->actingAs($second)
            ->delete(route('users.bulk-destroy'), ['ids' => [$admin->id]]);

        // Two exist, so one may go.
        $this->assertDatabaseMissing('users', ['id' => $admin->id]);

        $this->actingAs($second)
            ->delete(route('users.bulk-destroy'), ['ids' => [$second->id]]);

        // ...but the last one may not — and note this is ALSO the actor, so the
        // "cannot delete yourself" rule would catch it too. The protection that
        // matters here is the count, which is why the first half deletes a
        // DIFFERENT super admin first.
        $this->assertDatabaseHas('users', ['id' => $second->id]);
    }

    /**
     * ⚠️ A consultant's hidden placement accounts have no login and no life of
     * their own — deleting the person must take them too, or the SCN accounts
     * are orphaned and still counted by every rollup.
     */
    public function test_deleting_a_consultant_takes_their_hidden_accounts(): void
    {
        $user = User::factory()->create();
        $user->assignRole('consultant');

        $consultant = Consultant::create([
            'user_id' => $user->id,
            'consultant_code' => 'CON'.Str::random(8),
            'referral_code' => strtoupper(Str::random(10)),
            'status' => 'active',
        ]);
        app(HiddenAccountService::class)->ensureFor($consultant);

        $hiddenIds = $consultant->fresh()->hiddenAccounts()->pluck('id');
        $this->assertCount(2, $hiddenIds, 'guard: they have two');

        $this->actingAs($this->admin())
            ->delete(route('users.bulk-destroy'), ['ids' => [$user->id]]);

        foreach ($hiddenIds as $id) {
            $this->assertDatabaseMissing('consultants', ['id' => $id]);
        }
    }

    public function test_it_needs_at_least_one_selection(): void
    {
        $this->actingAs($this->admin())
            ->delete(route('users.bulk-destroy'), ['ids' => []])
            ->assertSessionHasErrors('ids');
    }

    // ---- Apply without a reload ---------------------------------------------

    /** @return Consultant a placement account that can be locked */
    private function placementAccount(): Consultant
    {
        $main = User::where('email', 'consultant@etec.lk')->firstOrFail()->consultant;

        return $main->hiddenAccounts()->orderBy('id')->firstOrFail();
    }

    public function test_apply_answers_json_when_asked(): void
    {
        $account = $this->placementAccount();

        $this->actingAs($this->admin())
            ->patchJson(route('users.account-lock', $account), ['state' => Consultant::LOCK_FORCED_SHUT])
            ->assertOk()
            ->assertJson(['ok' => true])
            ->assertJsonStructure(['ok', 'message']);

        $this->assertSame(Consultant::LOCK_FORCED_SHUT, $account->fresh()->lockOverride());
    }

    /**
     * ⚠️ AND STILL WORKS AS AN ORDINARY FORM POST. The page falls back to one
     * when fetch fails, and a browser with JavaScript off never uses fetch at
     * all — so the action must not depend on the background request.
     */
    public function test_apply_still_redirects_for_a_plain_form_post(): void
    {
        $account = $this->placementAccount();

        $this->actingAs($this->admin())
            ->patch(route('users.account-lock', $account), ['state' => Consultant::LOCK_FORCED_OPEN])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(Consultant::LOCK_FORCED_OPEN, $account->fresh()->lockOverride());
    }

    public function test_the_stage_apply_answers_json_too(): void
    {
        $account = $this->placementAccount();

        $this->actingAs($this->admin())
            ->patchJson(route('users.account-stage', $account), ['stage' => 2])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertSame(1, (int) $account->fresh()->lifetime_pair_stage, 'stage 2 in the UI is 1 stored');
    }

    /** A refused stage move reports as not-ok rather than looking like a save. */
    public function test_a_backwards_stage_move_reports_as_failed_json(): void
    {
        $account = $this->placementAccount();
        $account->forceFill(['lifetime_pair_stage' => 2])->save();

        $this->actingAs($this->admin())
            ->patchJson(route('users.account-stage', $account), ['stage' => 1])
            ->assertOk()
            ->assertJson(['ok' => false]);

        $this->assertSame(2, (int) $account->fresh()->lifetime_pair_stage, 'unchanged');
    }

    // ---- The screen ---------------------------------------------------------

    public function test_the_page_renders_the_select_all_and_the_size_picker(): void
    {
        $this->actingAs($this->admin())
            ->get(route('users.index'))
            ->assertOk()
            ->assertSee('id="select-all"', false)
            ->assertSee('Show 1,000', false);
    }

    /**
     * The profile photo opens from the list (2026-09-23) — *"userge athulat
     * yann nathuw eliye idnm profile photo eka click krla view krnn"*.
     */
    public function test_a_users_photo_can_be_opened_from_the_list(): void
    {
        $user = $this->someUser();
        $user->forceFill(['avatar' => 'avatars/test-photo.jpg'])->save();

        $this->actingAs($this->admin())
            ->get(route('users.index', ['q' => $user->email]))
            ->assertOk()
            ->assertSee('id="photo-viewer"', false)
            ->assertSee('data-photo="'.route('media.show', $user->avatar).'"', false);
    }

    /**
     * ⚠️ NO TRIGGER WITHOUT A PHOTO. The fallback is a letter in a circle;
     * a button over it would open an empty viewer and read as broken.
     */
    public function test_a_user_without_a_photo_gets_no_trigger(): void
    {
        $user = $this->someUser();
        $user->forceFill(['avatar' => null])->save();

        $html = $this->actingAs($this->admin())
            ->get(route('users.index', ['q' => $user->email]))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('data-photo=', $html);
    }
}
