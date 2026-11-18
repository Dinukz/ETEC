<?php

namespace Tests\Feature;

use App\Models\Consultant;
use App\Models\Student;
use App\Models\User;
use App\Services\HiddenAccountService;
use App\Support\Navigation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Pages a consultant may open only when an admin grants them (2026-09-22).
 *
 * Owner: "downline ekata access dunna wage Admission report eka, payment summary
 * report, My bookings, All bookings ewa access eken dena widiyt dala denn one buy
 * one access denn saha okkom access denn". Chosen on asking: OFF for everyone on
 * upload; Admission Report access includes the whole downline, with who each
 * account is under ("kage yatathe inne kiylath penwann one").
 *
 * Also here: the consultant Dashboard no longer shows "Pending balance".
 */
class ConsultantPageAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        \App\Services\FinanceSchemaRepair::ensure();
    }

    private function consultantUser(): User
    {
        return User::where('email', 'consultant@etec.lk')->firstOrFail();
    }

    private function grant(User $user, array $pages): void
    {
        $this->actingAs(User::where('email', 'admin@etec.lk')->firstOrFail())
            ->patch(route('users.page-access', $user), ['pages' => $pages])
            ->assertRedirect();
    }

    /** @return array<string, string> page key => route name */
    private function pages(): array
    {
        return [
            'admissions' => 'reports.admissions',
            'payment_summary' => 'reports.payments',
            'my_bookings' => 'bookings.index',
            'all_bookings' => 'bookings.all',
        ];
    }

    private function menu(User $user): array
    {
        return array_column(Navigation::for($user->fresh()), 'label');
    }

    // ----- off by default ---------------------------------------------------------------

    public function test_a_consultant_starts_with_none_of_the_four_pages(): void
    {
        $user = $this->consultantUser();

        foreach ($this->pages() as $route) {
            $this->actingAs($user)->get(route($route))->assertForbidden();
        }

        foreach (['Admission Report', 'Payment Summary Report', 'My Bookings', 'All Bookings'] as $label) {
            $this->assertNotContains($label, $this->menu($user), "{$label} must be hidden until granted");
        }

        $this->assertContains('Finance Report', $this->menu($user), 'the Finance Report is not part of this');
    }

    public function test_booking_actions_follow_my_bookings(): void
    {
        $this->actingAs($this->consultantUser())->get(route('bookings.create'))->assertForbidden();
    }

    // ----- granting ---------------------------------------------------------------------

    public function test_each_grant_opens_exactly_its_own_page(): void
    {
        foreach ($this->pages() as $key => $route) {
            $user = $this->consultantUser();
            $this->grant($user, [$key]);

            foreach ($this->pages() as $otherKey => $otherRoute) {
                $status = $this->actingAs($user->fresh())->get(route($otherRoute))->getStatusCode();

                $key === $otherKey
                    ? $this->assertSame(200, $status, "{$key} grant must open {$otherRoute}")
                    : $this->assertSame(403, $status, "{$key} grant must not open {$otherRoute}");
            }
        }
    }

    public function test_give_all_opens_all_four_and_shows_them_in_the_menu(): void
    {
        $user = $this->consultantUser();
        $this->grant($user, array_keys($this->pages()));

        foreach ($this->pages() as $route) {
            $this->actingAs($user->fresh())->get(route($route))->assertOk();
        }

        foreach (['Admission Report', 'Payment Summary Report', 'My Bookings', 'All Bookings'] as $label) {
            $this->assertContains($label, $this->menu($user));
        }
    }

    public function test_removing_all_closes_them_again(): void
    {
        $user = $this->consultantUser();
        $this->grant($user, array_keys($this->pages()));
        $this->grant($user, []);

        $this->actingAs($user->fresh())->get(route('reports.admissions'))->assertForbidden();
    }

    /** They were granted the admissions view by hand before this existed. */
    public function test_an_old_downline_grantee_keeps_the_admission_report(): void
    {
        $user = $this->consultantUser();
        $user->consultant->forceFill(['can_view_downline_report' => true])->save();

        $this->actingAs($user->fresh())->get(route('reports.admissions'))->assertOk();
        $this->actingAs($user->fresh())->get(route('reports.payments'))->assertForbidden();
    }

    public function test_only_an_admin_can_change_access(): void
    {
        $user = $this->consultantUser();

        $this->actingAs(User::where('email', 'accountant@etec.lk')->firstOrFail())
            ->patch(route('users.page-access', $user), ['pages' => ['admissions']])
            ->assertForbidden();

        $this->actingAs($user)->patch(route('users.page-access', $user), ['pages' => ['admissions']])->assertForbidden();

        $this->actingAs($user->fresh())->get(route('reports.admissions'))->assertForbidden();
    }

    public function test_the_edit_user_page_offers_the_four_switches(): void
    {
        $this->actingAs(User::where('email', 'admin@etec.lk')->firstOrFail())
            ->get(route('users.edit', $this->consultantUser()))
            ->assertOk()
            ->assertSee('Page access')
            ->assertSee('Payment Summary Report')
            ->assertSee('All Bookings');
    }

    /** Staff are not consultants — nothing changes for them. */
    public function test_staff_are_unaffected(): void
    {
        $accountant = User::where('email', 'accountant@etec.lk')->firstOrFail();

        foreach ($this->pages() as $route) {
            $this->actingAs($accountant)->get(route($route))->assertOk();
        }
    }

    // ----- the Admission Report shows the team ----------------------------------------------

    private function downlineUnder(Consultant $sponsorAccount, string $name): Consultant
    {
        $user = User::factory()->create(['name' => $name]);
        $user->syncRoles(['consultant']);

        $consultant = Consultant::create([
            'user_id' => $user->id, 'parent_consultant_id' => $sponsorAccount->id,
            'consultant_code' => 'CON'.Str::random(8), 'referral_code' => strtoupper(Str::random(10)),
            'status' => 'active',
        ]);
        app(HiddenAccountService::class)->ensureFor($consultant);

        return $consultant->fresh();
    }

    private function admitStudentOn(Consultant $account): void
    {
        $student = User::factory()->create();
        Student::create([
            'user_id' => $student->id, 'student_code' => 'SRN'.Str::random(6),
            'consultant_id' => $account->id, 'status' => 'active', 'enrolled_at' => now(),
        ]);
    }

    public function test_the_admission_report_shows_the_whole_team_and_who_each_is_under(): void
    {
        $me = $this->consultantUser()->consultant;
        $child = $this->downlineUnder($me->hiddenAccounts()->orderBy('id')->firstOrFail(), 'Child Marketer');
        $grandchild = $this->downlineUnder($child->hiddenAccounts()->orderBy('id')->firstOrFail(), 'Grandchild Marketer');
        $this->admitStudentOn($grandchild);

        // A separate tree the viewer has no part in.
        $stranger = User::factory()->create(['name' => 'Stranger Top']);
        $stranger->syncRoles(['consultant']);
        $strangerMain = Consultant::create([
            'user_id' => $stranger->id, 'consultant_code' => 'CON'.Str::random(8),
            'referral_code' => strtoupper(Str::random(10)), 'status' => 'active',
        ]);
        $outsider = $this->downlineUnder($strangerMain->fresh()->hiddenAccounts()->orderBy('id')->firstOrFail(), 'Outsider Marketer');
        $this->admitStudentOn($outsider);

        $this->grant($this->consultantUser(), ['admissions']);

        $this->actingAs($this->consultantUser()->fresh())
            ->get(route('reports.admissions'))
            ->assertOk()
            ->assertSee('Under')
            ->assertSee('Grandchild Marketer')
            ->assertSee('Child Marketer', false)
            ->assertSee($child->scn_code)
            ->assertDontSee('Outsider Marketer');
    }

    public function test_the_admission_csv_carries_the_under_column(): void
    {
        $me = $this->consultantUser()->consultant;
        $child = $this->downlineUnder($me->hiddenAccounts()->orderBy('id')->firstOrFail(), 'Csv Child');
        $this->admitStudentOn($child);
        $this->grant($this->consultantUser(), ['admissions']);

        $body = $this->actingAs($this->consultantUser()->fresh())
            ->get(route('reports.admissions', ['export' => 'csv']))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('Under', $body);
        $this->assertStringContainsString($me->scn_code, $body, 'the child is shown as under its sponsor');
    }

    // ----- the Dashboard ----------------------------------------------------------------

    public function test_the_dashboard_no_longer_shows_the_pending_balance(): void
    {
        $this->actingAs($this->consultantUser())
            ->get(route('consultant.dashboard'))
            ->assertOk()
            ->assertDontSee('Pending balance');
    }
}
