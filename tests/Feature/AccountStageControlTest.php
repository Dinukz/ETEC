<?php

namespace Tests\Feature;

use App\Models\Consultant;
use App\Models\User;
use App\Services\FinanceSchemaRepair;
use App\Services\ServiceGroupStaging;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The admin's per-account staged-release control (owner-requested 2026-08-18).
 *
 * An account walks 1-to-1 → 2-to-2 → 3-to-3 automatically as it flushes. This
 * control exists because existing consultants who have already had their cheap
 * releases must not be handed them again: "dn meka denn one nathi ayath inwa".
 *
 * ⚠️ The forward-only guard is the security-critical part. Stage 1 releases
 * 5,000 for one student a side; letting an admin reset an account to stage 1
 * after every flush would be an unlimited money tap.
 */
class AccountStageControlTest extends TestCase
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

    private function account(): Consultant
    {
        return User::where('email', 'consultant@etec.lk')->firstOrFail()
            ->consultant->hiddenAccounts()->first();
    }

    public function test_an_admin_can_move_an_account_forward_to_the_normal_stage(): void
    {
        $account = $this->account();

        $this->assertSame(0, (int) $account->lifetime_pair_stage, 'A fresh account starts on stage 1 (1-to-1).');

        $this->actingAs($this->admin())
            ->patch(route('users.account-stage', $account), ['stage' => 3])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(
            ServiceGroupStaging::ONE_TIME_STAGES,
            (int) $account->fresh()->lifetime_pair_stage,
            'Stage 3 stores as 2 consumed one-time stages — the repeating 3-to-3.',
        );
    }

    public function test_an_admin_can_move_an_account_one_step_forward(): void
    {
        $account = $this->account();

        $this->actingAs($this->admin())
            ->patch(route('users.account-stage', $account), ['stage' => 2])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(1, (int) $account->fresh()->lifetime_pair_stage);
    }

    /** ⚠️ The money-safety guard: a used stage can never be handed back. */
    public function test_a_used_stage_cannot_be_granted_again(): void
    {
        $account = $this->account();
        $account->update(['lifetime_pair_stage' => ServiceGroupStaging::ONE_TIME_STAGES]);

        $this->actingAs($this->admin())
            ->patch(route('users.account-stage', $account), ['stage' => 1])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(
            ServiceGroupStaging::ONE_TIME_STAGES,
            (int) $account->fresh()->lifetime_pair_stage,
            'Re-granting stage 1 would let an admin mint unlimited 5,000 releases.',
        );
    }

    public function test_moving_back_one_step_is_also_refused(): void
    {
        $account = $this->account();
        $account->update(['lifetime_pair_stage' => 1]); // on stage 2

        $this->actingAs($this->admin())
            ->patch(route('users.account-stage', $account), ['stage' => 1])
            ->assertSessionHas('error');

        $this->assertSame(1, (int) $account->fresh()->lifetime_pair_stage);
    }

    public function test_setting_the_stage_it_is_already_on_is_a_no_op(): void
    {
        $account = $this->account();

        $this->actingAs($this->admin())
            ->patch(route('users.account-stage', $account), ['stage' => 1])
            ->assertSessionHas('success');

        $this->assertSame(0, (int) $account->fresh()->lifetime_pair_stage);
    }

    public function test_the_stage_is_rejected_when_out_of_range(): void
    {
        $this->actingAs($this->admin())
            ->patch(route('users.account-stage', $this->account()), ['stage' => 4])
            ->assertSessionHasErrors('stage');
    }

    public function test_a_consultant_cannot_change_their_own_stage(): void
    {
        $consultantUser = User::where('email', 'consultant@etec.lk')->firstOrFail();

        $this->actingAs($consultantUser)
            ->patch(route('users.account-stage', $this->account()), ['stage' => 3])
            ->assertForbidden();
    }

    /** The Edit User page offers the ladder, and hides used stages from the picker. */
    public function test_the_edit_user_page_only_offers_stages_at_or_ahead_of_the_current_one(): void
    {
        $consultantUser = User::where('email', 'consultant@etec.lk')->firstOrFail();
        $account = $this->account();

        // Fresh account — all three stages are still reachable.
        $this->actingAs($this->admin())
            ->get(route('users.edit', $consultantUser))
            ->assertOk()
            ->assertSee('Release stage')
            ->assertSee('Stage 1 — 1-to-1 · 5,000')
            ->assertSee('Stage 3 — 3-to-3 · 15,000 (repeating)');

        // Once EVERY account on the page reaches the repeating stage there is
        // nothing left to pick. All of them must be advanced, not just one —
        // the page lists the main account and both placement accounts, and any
        // one still on stage 1 would legitimately keep offering it.
        Consultant::whereIn('id', $consultantUser->consultant->accountIds())
            ->update(['lifetime_pair_stage' => ServiceGroupStaging::ONE_TIME_STAGES]);

        $this->actingAs($this->admin())
            ->get(route('users.edit', $consultantUser))
            ->assertOk()
            ->assertSee('This is the repeating stage.')
            ->assertDontSee('Stage 1 — 1-to-1 · 5,000');
    }
}
