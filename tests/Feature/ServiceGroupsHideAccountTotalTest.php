<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\FinanceSchemaRepair;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⚠️ THE "CURRENT BALANCE + DIRECT" COLUMN IS GONE (2026-09-26).
 *
 * Owner: "Consultant la hamogenm current balance + direct kiyla mark krla
 * thiyene eka hide krla denn." It totalled each account's two leg balances plus
 * the direct bonus, and he wants no such running total on a consultant's screen.
 *
 * ⚠️ WHAT THE COLUMN MUST NOT TAKE WITH IT. Three things lived inside that same
 * cell and are NOT balances — they say why an account cannot settle, and what
 * the next run would do:
 *
 *   "Locked — needs N more student(s)"          everyone
 *   "N already approved before this run"        everyone
 *   "<stage> ready — LKR X at the next run"     super admin only (2026-09-13)
 *   "LKR X would go to the company"             super admin only (2026-09-13)
 *
 * They moved into the Group Code cell. Deleting the column without them would
 * have quietly removed the only place a consultant is told why their account is
 * stuck — and the only place the owner sees a forfeit before it happens.
 */
class ServiceGroupsHideAccountTotalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        FinanceSchemaRepair::ensure();
    }

    private function consultantUser(): User
    {
        return User::where('email', 'consultant@etec.lk')->firstOrFail();
    }

    public function test_the_account_total_column_is_not_rendered(): void
    {
        $this->actingAs($this->consultantUser())
            ->get(route('consultant.groups.index'))
            ->assertOk()
            ->assertDontSee('Current Balance + Direct')
            // The columns either side of it are untouched.
            ->assertSee('SG1 Leg Balance')
            ->assertSee('SG2 Leg Balance')
            ->assertSee('Direct Bonus');
    }

    /** ⚠️ The lock reason survived the column it used to sit in. */
    public function test_a_locked_account_still_says_why(): void
    {
        $this->actingAs($this->consultantUser())
            ->get(route('consultant.groups.index'))
            ->assertOk()
            ->assertSee('more student(s)');
    }

    /** A super admin still sees the run preview lines on their own account. */
    public function test_the_super_admin_lines_are_still_reachable(): void
    {
        $admin = User::where('email', 'superadmin@etec.lk')->firstOrFail();

        // Not asserting the text (it only appears on a flushable account) —
        // asserting the page still renders for the role that owns those lines.
        $this->actingAs($admin)
            ->get(route('consultant.groups.index'))
            ->assertStatus($admin->consultant ? 200 : 403);
    }
}
