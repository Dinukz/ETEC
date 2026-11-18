<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\Consultant;
use App\Models\Course;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\Student;
use App\Models\User;
use App\Services\PaymentService;
use App\Services\ServiceGroupFlush;
use App\Services\ServiceGroupStaging;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Service Income's top figures are the Dashboard's figures.
 *
 * Reported 2026-09-13: "Service Income eke loku gank penwanwa total income and
 * Unpaid Approved Income ekat eka wardi ... dashboard eke thiyeno wage Total
 * Earned and Pending balance". Service Income summed consultants.total_earned /
 * total_paid, which count every APPROVED row — including service group income
 * approved before any System Run released it — so it read above the Dashboard
 * for the same person.
 */
class ServiceIncomeMatchesTheDashboardTest extends TestCase
{
    use RefreshDatabase;

    private const SLOT = 2500;

    private const DIRECT = 4000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        // released_at is created by the self-healing repair, not by a migration.
        \App\Services\FinanceSchemaRepair::ensure();

        \App\Models\CommissionSetting::where('is_active', true)->update([
            'commission_per_student' => self::SLOT,
            'direct_commission' => self::DIRECT,
        ]);
        \Illuminate\Support\Facades\Cache::forget('commission_settings.active');

        Setting::set('stage_first_pairs', '1', 'integer', 'commission');
        Setting::set('stage_second_pairs', '2', 'integer', 'commission');
        Setting::set('stage_repeat_pairs', '3', 'integer', 'commission');
        Setting::set('account_unlock_students', '12', 'integer', 'commission');
        ServiceGroupStaging::forget();

        Notification::fake();
    }

    private function consultantUser(): User
    {
        return User::where('email', 'consultant@etec.lk')->firstOrFail();
    }

    private function addStudent(Consultant $account, int $leg): void
    {
        $user = User::factory()->create();
        Student::create([
            'user_id' => $user->id, 'student_code' => 'SRN'.Str::random(6),
            'status' => 'active', 'enrolled_at' => now(),
        ]);

        $payment = Payment::create([
            'payment_code' => 'PYM'.Str::random(6), 'user_id' => $user->id, 'course_id' => Course::first()->id,
            'consultant_id' => $account->id, 'requested_tier' => $leg, 'amount' => 28000, 'currency' => 'LKR',
            'method' => 'bank_transfer', 'payment_type' => 'full', 'status' => 'pending', 'paid_at' => now(),
        ]);

        app(PaymentService::class)->verify($payment, User::where('email', 'accountant@etec.lk')->firstOrFail());
    }

    /**
     * A person whose approved income is more than the Dashboard counts: a pair
     * a System Run released, plus a third student whose 2,500 was approved at
     * verification and is still waiting on the leg for a run.
     */
    private function personWithUnreleasedApprovedIncome(): Consultant
    {
        $account = $this->consultantUser()->consultant->hiddenAccounts()->orderBy('id')->firstOrFail();

        $this->addStudent($account, 1);
        $this->addStudent($account, 2);
        app(ServiceGroupFlush::class)->runAll(ServiceGroupFlush::latestSelectableCutoff());

        $this->addStudent($account, 1);

        $this->assertTrue(
            Commission::where('consultant_id', $account->id)
                ->where('type', 'bonus')
                ->where('status', 'approved')
                ->whereNull('released_at')
                ->exists(),
            'guard: a 2,500 approved at verification that no System Run has released',
        );

        return $account;
    }

    private function dashboardStats(): array
    {
        return $this->actingAs($this->consultantUser())
            ->get(route('consultant.dashboard'))
            ->assertOk()
            ->viewData('stats');
    }

    /** ⚠️ THE REPORT. The top figures are the Dashboard's, under the Dashboard's names. */
    public function test_the_top_figures_are_the_dashboards(): void
    {
        $this->personWithUnreleasedApprovedIncome();

        $dashboard = $this->dashboardStats();

        // The old figure, so this cannot pass against the old code.
        $columns = (float) Consultant::whereIn('id', $this->consultantUser()->consultant->accountIds())->sum('total_earned');
        $this->assertGreaterThan($dashboard['total_earned'], $columns, 'guard: the stored columns read higher than the Dashboard');

        $page = $this->actingAs($this->consultantUser())->get(route('consultant.earnings.index'))->assertOk();
        $totals = $page->viewData('totals');

        $this->assertSame($dashboard['total_earned'], $totals['earned']);
        $this->assertSame($dashboard['total_paid'], $totals['paid']);
        $this->assertSame($dashboard['pending_balance'], $totals['pending']);

        $page->assertSee('Total Earned')
            ->assertSee('Pending balance')
            ->assertDontSee('Unpaid Approved Income')
            ->assertDontSee('Total Income');
    }

    /** The Main tab is the whole person, so it shows those figures again. */
    public function test_the_main_tab_shows_the_same_figures(): void
    {
        $this->personWithUnreleasedApprovedIncome();

        $dashboard = $this->dashboardStats();

        $accountTotals = $this->actingAs($this->consultantUser())
            ->get(route('consultant.earnings.index'))
            ->viewData('accountTotals');

        $this->assertSame($dashboard['total_earned'], $accountTotals['earned']);
        $this->assertSame($dashboard['pending_balance'], $accountTotals['pending']);
    }

    /** A placement account's tab counts the same way, for that account alone. */
    public function test_a_placement_tab_counts_the_same_way(): void
    {
        $account = $this->personWithUnreleasedApprovedIncome();

        $accountTotals = $this->actingAs($this->consultantUser())
            ->get(route('consultant.earnings.index', ['account' => $account->id]))
            ->viewData('accountTotals');

        // Three direct bonuses (12,000) and the released pair (5,000). The 2,500
        // approved by hand does not count until a System Run releases it.
        $this->assertSame(17000.0, $accountTotals['earned']);
        $this->assertSame(17000.0, $accountTotals['pending']);
    }
}
