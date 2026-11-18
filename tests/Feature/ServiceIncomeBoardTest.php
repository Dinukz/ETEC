<?php

namespace Tests\Feature;

use App\Livewire\ServiceIncomeBoard;
use App\Models\Commission;
use App\Models\Consultant;
use App\Models\GroupMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Service Income queue, actioned without a page reload.
 *
 * Reported 2026-08-23: approving one row reloaded the whole page, collapsing
 * every dropdown, so the accountant had to scroll back and re-open the person
 * for each record — "eka godak time eka yana wadk kattiya godk innkot".
 */
class ServiceIncomeBoardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        \App\Services\FinanceSchemaRepair::ensure();
    }

    private function accountant(): User
    {
        $user = User::factory()->create();
        $user->assignRole('accountant');

        return $user;
    }

    /** A consultant account holding one pending slot row and one pending bonus. */
    private function seedIncome(): array
    {
        $user = User::factory()->create();
        $user->assignRole('consultant');

        $account = Consultant::create([
            'user_id' => $user->id,
            'consultant_code' => 'CON'.Str::random(8),
            'referral_code' => strtoupper(Str::random(10)),
            'scn_code' => 'SCN-7'.random_int(1000, 9999),
            'status' => 'active',
        ]);

        $group = app(\App\Services\HiddenAccountService::class)->ensureGroup($account);

        $student = User::factory()->create();
        $student->assignRole('student');

        $seat = GroupMember::create([
            'group_id' => $group->id,
            'user_id' => $student->id,
            'tier' => 1,
            'position' => 1,
            'commission_amount' => 0,
            'joined_at' => now(),
        ]);

        $slot = Commission::create([
            'consultant_id' => $account->id,
            'user_id' => $student->id,
            'group_id' => $group->id,
            'group_member_id' => $seat->id,
            'type' => 'bonus',
            'tier' => 1,
            'amount' => 2500,
            'status' => 'pending',
        ]);

        $direct = Commission::create([
            'consultant_id' => $account->id,
            'user_id' => $student->id,
            'type' => 'referral',
            'amount' => 4000,
            'status' => 'pending',
        ]);

        return [$account, $slot, $direct];
    }

    public function test_approving_a_row_updates_it_without_a_page_reload(): void
    {
        [, , $direct] = $this->seedIncome();

        Livewire::actingAs($this->accountant())
            ->test(ServiceIncomeBoard::class)
            ->call('approveRow', $direct->id)
            // No redirect: a redirect IS the page reload we removed.
            ->assertNoRedirect()
            ->assertSet('level', 'success')
            ->assertSee('approved for payout');

        $this->assertSame('approved', $direct->fresh()->status);
    }

    public function test_marking_paid_works_in_place(): void
    {
        [$account, , $direct] = $this->seedIncome();
        $direct->update(['status' => 'approved']);

        Livewire::actingAs($this->accountant())
            ->test(ServiceIncomeBoard::class)
            ->call('payRow', $direct->id)
            ->assertNoRedirect()
            ->assertSee('marked as paid');

        $this->assertSame('paid', $direct->fresh()->status);
        $this->assertSame(4000.0, (float) $account->fresh()->total_paid);
    }

    /**
     * ⚠️ THE STATE THIS COMPONENT EXISTS FOR. An open dropdown must survive the
     * re-render that follows an action — that is the whole complaint.
     */
    public function test_an_open_dropdown_stays_open_after_approving(): void
    {
        [$account, , $direct] = $this->seedIncome();
        $owner = $account->owningPerson();

        Livewire::actingAs($this->accountant())
            ->test(ServiceIncomeBoard::class)
            ->call('toggle', $owner->id)
            ->assertSet("open.{$owner->id}", true)
            ->call('approveRow', $direct->id)
            ->assertSet("open.{$owner->id}", true);
    }

    public function test_toggle_opens_and_closes(): void
    {
        [$account] = $this->seedIncome();
        $owner = $account->owningPerson();

        Livewire::actingAs($this->accountant())
            ->test(ServiceIncomeBoard::class)
            ->call('toggle', $owner->id)
            ->assertSet("open.{$owner->id}", true)
            ->call('toggle', $owner->id)
            ->assertSet("open.{$owner->id}", false);
    }

    /**
     * ⚠️ THE SECURITY HOLE LIVEWIRE INTRODUCES IF YOU FORGET.
     *
     * /commissions sits behind `role:super_admin|admin|accountant`, but a
     * Livewire action does NOT arrive through that route — it posts to
     * Livewire's own update endpoint, carrying only the framework's middleware.
     * Without the check inside the component, any logged-in user could approve
     * and pay commissions, including a consultant paying themselves.
     */
    public function test_a_consultant_cannot_action_the_queue(): void
    {
        [, , $direct] = $this->seedIncome();

        $intruder = User::factory()->create();
        $intruder->assignRole('consultant');

        Livewire::actingAs($intruder)
            ->test(ServiceIncomeBoard::class)
            ->call('approveRow', $direct->id)
            ->assertForbidden();

        $this->assertSame('pending', $direct->fresh()->status, 'The money must not have moved.');
    }

    public function test_a_student_cannot_mark_income_paid(): void
    {
        [, , $direct] = $this->seedIncome();
        $direct->update(['status' => 'approved']);

        $intruder = User::factory()->create();
        $intruder->assignRole('student');

        Livewire::actingAs($intruder)
            ->test(ServiceIncomeBoard::class)
            ->call('payRow', $direct->id)
            ->assertForbidden();

        $this->assertSame('approved', $direct->fresh()->status);
    }

    /** A deleted row must report, not crash — the page is live, rows can vanish. */
    public function test_actioning_a_missing_row_reports_instead_of_erroring(): void
    {
        Livewire::actingAs($this->accountant())
            ->test(ServiceIncomeBoard::class)
            ->call('approveRow', 999999)
            ->assertSet('level', 'error')
            ->assertSee('no longer exists');
    }

    /** Bulk approve for one person, still in place. */
    public function test_approve_all_for_a_consultant(): void
    {
        [$account, , $direct] = $this->seedIncome();

        Livewire::actingAs($this->accountant())
            ->test(ServiceIncomeBoard::class)
            ->call('approveOwner', $account->owningPerson()->id)
            ->assertNoRedirect();

        $this->assertSame('approved', $direct->fresh()->status, 'The direct bonus never waits for a pair.');
    }

    /** The page itself still renders and mounts the component. */
    public function test_the_dashboard_page_renders_the_component(): void
    {
        $this->seedIncome();

        $this->actingAs($this->accountant())
            ->get(route('commissions.index'))
            ->assertOk()
            ->assertSeeLivewire(ServiceIncomeBoard::class);
    }

    /**
     * ⚠️ SILENT-FAILURE GUARD. config/livewire.php sets inject_assets to false
     * because app.js bundles Livewire itself, so @livewireScriptConfig in the
     * layout is the ONLY thing telling the runtime its endpoint and CSRF
     * token. Drop that directive and the page still renders perfectly — every
     * button just quietly stops working.
     */
    public function test_the_layout_ships_the_livewire_runtime_config(): void
    {
        $this->seedIncome();

        $this->actingAs($this->accountant())
            ->get(route('commissions.index'))
            ->assertOk()
            ->assertSee('window.livewireScriptConfig', false);
    }

    /**
     * Group heads and their legs are told apart, so the board can colour them.
     *
     * Requested 2026-08-23: "main account ekai sub account ekai color dekin
     * dala denn" — nine near-identical blocks gave no clue which account
     * belonged to which. A head is the person's main or a bought set's own
     * main; the accounts hanging beneath are legs.
     */
    public function test_group_heads_are_distinguished_from_their_legs(): void
    {
        $owner = User::where('email', 'consultant@etec.lk')->firstOrFail()->consultant;
        $legOne = $owner->hiddenAccounts()->orderBy('id')->first();

        app(\App\Services\HiddenAccountService::class)->addAccountSet($owner);
        $accounts = $owner->fresh()->hiddenAccounts()->orderBy('id')->get();

        $map = app(\App\Services\AccountPlacementMap::class)->for($owner->fresh());
        $isHead = fn ($account) => in_array(
            $map[$account->id]['kind'] ?? '',
            ['main', 'bought', 'bought-foreign', 'unknown'],
            true,
        );

        $this->assertTrue($isHead($owner), 'The person\'s own account is a head.');
        $this->assertFalse($isHead($legOne), 'The opening pair are legs.');
        $this->assertFalse($isHead($accounts[1]), 'Both of them.');
        $this->assertTrue($isHead($accounts[2]), "A bought set's own main is a head.");
        $this->assertFalse($isHead($accounts[3]), 'Its two accounts are legs.');
        $this->assertFalse($isHead($accounts[4]));
    }

    /** ⚠️ The old form-post routes still work — nothing was removed. */
    public function test_the_original_http_routes_still_approve(): void
    {
        [, , $direct] = $this->seedIncome();

        $this->actingAs($this->accountant())
            ->patch(route('commissions.approve', $direct))
            ->assertRedirect();

        $this->assertSame('approved', $direct->fresh()->status);
    }
}
