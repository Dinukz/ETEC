<?php

namespace Tests\Feature;

use App\Models\Consultant;
use App\Models\User;
use App\Services\FinanceSchemaRepair;
use App\Services\HiddenAccountService;
use App\Services\UplineContinuity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Deleting one consultant must not cut the branch below them.
 *
 * ⚠️ THE BUG THIS PINS, FOUND 2026-09-11.
 * `consultants.parent_consultant_id` carries ON DELETE SET NULL. Deleting a
 * consultant therefore succeeded quietly and emptied the upline of everyone
 * directly beneath them. Their whole branch then vanished from the real
 * sponsor's screens, and no income could climb past the gap, because every
 * upline walk in the app is written `while ($node && $node->parent_consultant_id)`.
 *
 * It happened for real: on 2026-09-07 the user "kavindu rukshan" was deleted,
 * and Anishka Prabath (SCN-00169) — who hung off one of kavindu's service
 * group accounts — lost her upline. Her 18 students and 2 consultants
 * disappeared from SCN-00109's network without a single one of those rows
 * being touched.
 *
 * The shape below is that exact incident, in miniature.
 */
class UplineSurvivesADeletionTest extends TestCase
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

    private function superAdmin(): User
    {
        return User::role('super_admin')->firstOrFail();
    }

    /** A consultant hanging under $parentAccount, with their own two accounts. */
    private function consultantUnder(Consultant $parentAccount, string $name): Consultant
    {
        $user = User::factory()->create(['name' => $name]);
        $user->syncRoles(['consultant']);

        $consultant = Consultant::create([
            'user_id' => $user->id,
            'parent_consultant_id' => $parentAccount->id,
            'consultant_code' => 'CON'.Str::random(8),
            'scn_code' => 'SCN-'.random_int(10000, 99999),
            'referral_code' => strtoupper(Str::random(10)),
            'status' => 'active',
        ]);

        app(HiddenAccountService::class)->ensureFor($consultant);

        return $consultant->fresh();
    }

    /**
     * The incident shape: a sponsor, a middle person under one of the
     * sponsor's service group accounts, and a branch under the middle person.
     *
     * @return array{0: Consultant, 1: Consultant, 2: Consultant}
     */
    private function threeDeep(): array
    {
        $sponsor = User::where('email', 'consultant@etec.lk')->firstOrFail()->consultant;
        $sponsorLeg = $sponsor->hiddenAccounts()->orderBy('id')->firstOrFail();

        $middle = $this->consultantUnder($sponsorLeg, 'Middle Person');
        $middleLeg = $middle->hiddenAccounts()->orderBy('id')->firstOrFail();

        $bottom = $this->consultantUnder($middleLeg, 'Bottom Person');

        return [$sponsor, $middle, $bottom];
    }

    // ----- prevention --------------------------------------------------------

    /** ⚠️ THE HEADLINE. The branch is re-hung, not cut loose. */
    public function test_deleting_the_middle_person_lifts_their_branch_onto_the_sponsor(): void
    {
        [$sponsor, $middle, $bottom] = $this->threeDeep();

        $sponsorLeg = $middle->parent_consultant_id;

        $this->actingAs($this->superAdmin())
            ->delete(route('users.destroy', $middle->user))
            ->assertRedirect();

        $this->assertNull(User::find($middle->user_id), 'guard: the person really was deleted');

        $bottom->refresh();

        $this->assertNotNull($bottom->parent_consultant_id, 'the branch kept an upline');
        $this->assertSame(
            (int) $sponsorLeg,
            (int) $bottom->parent_consultant_id,
            'and it hangs off exactly where the deleted person hung off',
        );
    }

    /** And the sponsor can still see them, which is what was actually reported. */
    public function test_the_branch_is_still_inside_the_sponsors_network(): void
    {
        [$sponsor, $middle, $bottom] = $this->threeDeep();

        $this->assertContains($bottom->id, $sponsor->downlineIds(), 'guard: visible before');

        $this->actingAs($this->superAdmin())->delete(route('users.destroy', $middle->user));

        $this->assertContains(
            $bottom->id,
            $sponsor->fresh()->downlineIds(),
            'still visible after — this is the symptom the owner reported',
        );
    }

    /**
     * ⚠️ A deletion that cannot be healed is refused, not done quietly.
     * With nothing above the leaver there is nowhere to lift the branch to.
     */
    public function test_a_deletion_that_would_strand_a_branch_is_refused(): void
    {
        [, $middle, $bottom] = $this->threeDeep();

        // Cut the middle person loose from the top, so nothing sits above them.
        $middle->forceFill(['parent_consultant_id' => null])->save();

        $this->actingAs($this->superAdmin())
            ->delete(route('users.destroy', $middle->user))
            ->assertRedirect();

        $this->assertNotNull(User::find($middle->user_id), 'the deletion was refused');
        $this->assertNotNull($bottom->fresh()->parent_consultant_id, 'and the branch is untouched');
    }

    public function test_deleting_someone_with_nobody_below_them_still_works(): void
    {
        [, , $bottom] = $this->threeDeep();

        $this->actingAs($this->superAdmin())
            ->delete(route('users.destroy', $bottom->user))
            ->assertRedirect();

        $this->assertNull(User::find($bottom->user_id));
    }

    // ----- repair ------------------------------------------------------------

    /**
     * The damage already done. An orphan is found, and the audit log is read
     * back to work out where it belongs — the same climb that resolves the
     * real Anishka row to SCN-00111.
     */
    public function test_an_orphan_is_listed_with_the_size_of_its_branch(): void
    {
        [, $middle, $bottom] = $this->threeDeep();

        // Reproduce what the foreign key used to do.
        $middle->forceFill(['parent_consultant_id' => null])->save();

        $found = UplineContinuity::orphans()->firstWhere('id', $middle->id);

        $this->assertNotNull($found, 'the cut branch is found');
        $this->assertTrue($found['lost_an_upline'], 'and told apart from the top of the network');
        $this->assertGreaterThanOrEqual(1, $found['downline_size'], 'with the people it carries');
    }

    /** The network's own root has no sponsor and is not damage. */
    public function test_the_top_of_the_network_is_not_reported_as_broken(): void
    {
        $root = Consultant::where('is_hidden', false)->whereNull('parent_consultant_id')->first();

        if (! $root) {
            $this->markTestSkipped('This seed has no rootless consultant.');
        }

        $found = UplineContinuity::orphans()->firstWhere('id', $root->id);

        $this->assertNotNull($found);
        $this->assertFalse($found['lost_an_upline'], 'a root is listed, but never as a broken branch');
    }

    public function test_a_super_admin_can_reconnect_an_orphan(): void
    {
        [$sponsor, $middle, $bottom] = $this->threeDeep();

        $target = $sponsor->hiddenAccounts()->orderBy('id')->firstOrFail();
        $middle->forceFill(['parent_consultant_id' => null])->save();

        $this->actingAs($this->superAdmin())
            ->post(route('commission-health.reconnect-upline'), [
                'consultant_id' => $middle->id,
                'sponsor_id' => $target->id,
            ])
            ->assertRedirect();

        $this->assertSame((int) $target->id, (int) $middle->fresh()->parent_consultant_id);
        $this->assertContains($bottom->id, $sponsor->fresh()->downlineIds(), 'the whole branch came back');
    }

    /** ⚠️ Hanging someone off their own downline would loop every upline walk. */
    public function test_reconnecting_into_your_own_downline_is_refused(): void
    {
        [, $middle, $bottom] = $this->threeDeep();

        $middle->forceFill(['parent_consultant_id' => null])->save();

        $this->actingAs($this->superAdmin())
            ->post(route('commission-health.reconnect-upline'), [
                'consultant_id' => $middle->id,
                'sponsor_id' => $bottom->id,
            ])
            ->assertRedirect();

        $this->assertNull($middle->fresh()->parent_consultant_id, 'the loop was refused');
    }

    public function test_someone_who_already_has_an_upline_cannot_be_repointed_here(): void
    {
        [$sponsor, $middle] = $this->threeDeep();

        $original = $middle->parent_consultant_id;

        $this->actingAs($this->superAdmin())
            ->post(route('commission-health.reconnect-upline'), [
                'consultant_id' => $middle->id,
                'sponsor_id' => $sponsor->id,
            ])
            ->assertRedirect();

        $this->assertSame((int) $original, (int) $middle->fresh()->parent_consultant_id, 'untouched');
    }

    public function test_an_ordinary_admin_cannot_reach_the_repair(): void
    {
        $this->actingAs($this->admin())
            ->get(route('commission-health.broken-uplines'))
            ->assertForbidden();
    }

    public function test_the_page_renders_for_a_super_admin(): void
    {
        [, $middle] = $this->threeDeep();
        $middle->forceFill(['parent_consultant_id' => null])->save();

        $this->actingAs($this->superAdmin())
            ->get(route('commission-health.broken-uplines'))
            ->assertOk()
            ->assertSee('Consultants with no upline')
            ->assertSee($middle->scn_code);
    }
}
