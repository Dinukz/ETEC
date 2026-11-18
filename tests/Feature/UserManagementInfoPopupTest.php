<?php

namespace Tests\Feature;

use App\Livewire\UserAccountSummary;
use App\Models\Student;
use App\Models\User;
use App\Services\FinanceSchemaRepair;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The account summary popup, opened from User Management.
 *
 * Asked for 2026-09-14: the Direct Registrations "Info" button on every user
 * row — "userge athulta yan nathuw eliye idanm eka balagann", check a user
 * without opening their page.
 */
class UserManagementInfoPopupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        FinanceSchemaRepair::ensure();
    }

    private function user(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    public function test_every_row_has_an_info_button_and_the_popup_is_on_the_page(): void
    {
        $consultant = $this->user('consultant@etec.lk');

        $this->actingAs($this->user('admin@etec.lk'))
            ->get(route('users.index'))
            ->assertOk()
            ->assertSee("Livewire.dispatch('show-user-summary', { userId: {$consultant->id} })", false)
            ->assertSeeLivewire(UserAccountSummary::class);
    }

    public function test_an_admin_opens_a_consultant_with_their_accounts(): void
    {
        $consultant = $this->user('consultant@etec.lk');

        Livewire::actingAs($this->user('admin@etec.lk'))
            ->test(UserAccountSummary::class)
            ->call('openUser', $consultant->id)
            ->assertSet('error', null)
            ->assertSet('summary.name', $consultant->name)
            ->assertSet('summary.role', 'Consultant')
            ->assertSet('summary.account_count', count($consultant->consultant->orderedAccountIds()))
            ->assertSee($consultant->name);
    }

    /** ⚠️ THE GUARD. A consultant must not reach anybody's summary through this component. */
    public function test_a_consultant_cannot_open_it(): void
    {
        Livewire::actingAs($this->user('consultant@etec.lk'))
            ->test(UserAccountSummary::class)
            ->call('openUser', $this->user('student@etec.lk')->id)
            ->assertForbidden();
    }

    /** ...nor reach the second stage by setting a userId of their own choosing. */
    public function test_a_consultant_cannot_load_the_second_stage_either(): void
    {
        Livewire::actingAs($this->user('consultant@etec.lk'))
            ->test(UserAccountSummary::class)
            ->set('userId', $this->user('student@etec.lk')->id)
            ->call('loadDeep')
            ->assertForbidden();
    }

    /** Staff see the whole network — the sponsoring account is named, not hidden as "above you". */
    public function test_staff_see_the_sponsoring_account(): void
    {
        $sponsor = $this->user('consultant@etec.lk')->consultant;

        $user = User::factory()->create();
        $user->assignRole('student');
        Student::create([
            'user_id' => $user->id, 'student_code' => 'SRN-'.Str::random(6),
            'consultant_id' => $sponsor->id, 'status' => 'active',
        ]);

        Livewire::actingAs($this->user('admin@etec.lk'))
            ->test(UserAccountSummary::class)
            ->call('openUser', $user->id)
            ->assertSet('summary.origin.code', $sponsor->scn_code ?: $sponsor->consultant_code);
    }

    /** A staff account has no Student row at all, and must still open cleanly. */
    public function test_a_user_without_a_student_row_still_opens(): void
    {
        $accountant = $this->user('accountant@etec.lk');

        Livewire::actingAs($this->user('admin@etec.lk'))
            ->test(UserAccountSummary::class)
            ->call('openUser', $accountant->id)
            ->assertSet('error', null)
            ->assertSet('summary.name', $accountant->name)
            ->assertSet('summary.role', 'Accountant')
            ->assertSet('summary.accounts', [])
            ->assertSee('Accountant');
    }

    public function test_the_second_stage_loads_for_a_consultant(): void
    {
        Livewire::actingAs($this->user('admin@etec.lk'))
            ->test(UserAccountSummary::class)
            ->call('openUser', $this->user('consultant@etec.lk')->id)
            ->call('loadDeep')
            ->assertSet('deepLoaded', true)
            ->assertSet('deep.not_a_consultant', false);
    }
}
