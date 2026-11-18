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
 * "Service Group Income" on the Service Income page counts only slot money a
 * System Run has released — the same rule as the Dashboard's "Total Earned".
 *
 * Reported 2026-09-14: "consultant ge dashboard ekai service income ekai normal
 * 2500 and 4000 add weno, ethanat add wenn one system run eken out wen gan
 * withri". The stat card summed every APPROVED 2,500, so it showed slot income
 * before a run had released it — disagreeing with the Total Earned figure and
 * the Dashboard.
 */
class ServiceIncomeShowsOnlyReleasedSlotTest extends TestCase
{
    use RefreshDatabase;

    private const SLOT = 2500;

    private const DIRECT = 4000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
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

    private function account(): Consultant
    {
        return $this->consultantUser()->consultant->hiddenAccounts()->orderBy('id')->firstOrFail();
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

        app(PaymentService::class)->verify($payment->fresh(), User::where('email', 'accountant@etec.lk')->firstOrFail());
    }

    private function serviceIncomeStats(): array
    {
        return $this->actingAs($this->consultantUser())
            ->get(route('consultant.earnings.index'))
            ->assertOk()
            ->viewData('stats');
    }

    private function dashboardStats(): array
    {
        return $this->actingAs($this->consultantUser())
            ->get(route('consultant.dashboard'))
            ->assertOk()
            ->viewData('stats');
    }

    /** ⚠️ THE REPORT. A balanced, approved pair that no run has released shows as 0 Service Group Income. */
    public function test_approved_but_unreleased_slot_is_not_counted(): void
    {
        $account = $this->account();

        $this->addStudent($account, 1);
        $this->addStudent($account, 2);

        // Verified → the 2,500s are approved and sitting on the legs, but no
        // System Run has released them.
        $this->assertTrue(
            Commission::where('consultant_id', $account->id)->where('type', 'bonus')
                ->where('status', 'approved')->whereNull('released_at')->exists(),
            'guard: approved slot income waiting on a run',
        );

        $stats = $this->serviceIncomeStats();

        $this->assertSame(0.0, $stats['slot_income'], 'Service Group Income counts nothing until a run releases it');
        $this->assertSame(2 * (float) self::DIRECT, $stats['direct_income'], 'the direct bonus still shows — it is paid on approval');
    }

    /** ...and once a System Run releases it, it shows. */
    public function test_released_slot_is_counted(): void
    {
        $account = $this->account();

        $this->addStudent($account, 1);
        $this->addStudent($account, 2);

        app(ServiceGroupFlush::class)->runAll(ServiceGroupFlush::latestSelectableCutoff());

        $this->assertSame(2 * (float) self::SLOT, $this->serviceIncomeStats()['slot_income']);
    }

    /** The card, the Total Earned figure and the Dashboard all agree. */
    public function test_the_page_agrees_with_the_dashboard(): void
    {
        $account = $this->account();

        // One released pair, plus a third approved-but-unreleased slot.
        $this->addStudent($account, 1);
        $this->addStudent($account, 2);
        app(ServiceGroupFlush::class)->runAll(ServiceGroupFlush::latestSelectableCutoff());
        $this->addStudent($account, 1);

        $stats = $this->serviceIncomeStats();
        $totals = $this->actingAs($this->consultantUser())->get(route('consultant.earnings.index'))->viewData('totals');
        $dashboard = $this->dashboardStats();

        // Service Group Income + Direct Add Bonus == Total Earned == Dashboard.
        $this->assertSame($totals['earned'], $stats['slot_income'] + $stats['direct_income']);
        $this->assertSame($dashboard['total_earned'], $totals['earned']);
    }
}
