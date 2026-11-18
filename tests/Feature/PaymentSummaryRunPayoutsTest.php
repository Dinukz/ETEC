<?php

namespace Tests\Feature;

use App\Models\Consultant;
use App\Models\SystemRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * What each System Run paid out, date by date, on the Payment Summary Report
 * (2026-09-22): "Payment summary eketh system run krana parak gane out wechcha
 * gana date wise penwann one".
 *
 * Read from each run's own `accounts` snapshot — the amount that run RELEASED to
 * each account, exactly as recorded on the day — so it cannot drift from what
 * the run actually did. An account reversed after the run is left out.
 */
class PaymentSummaryRunPayoutsTest extends TestCase
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
        $user = User::where('email', 'consultant@etec.lk')->firstOrFail();
        $this->grantConsultantPages($user, ['payment_summary']);

        return $user;
    }

    private function stranger(): Consultant
    {
        $user = User::factory()->create();
        $user->syncRoles(['consultant']);

        return Consultant::create([
            'user_id' => $user->id, 'consultant_code' => 'CON'.Str::random(8),
            'referral_code' => strtoupper(Str::random(10)), 'status' => 'active',
        ])->fresh();
    }

    /** @param  array<int, array{0: Consultant, 1: float, 2?: bool}>  $paid  [account, released, reversed?] */
    private function recordRun(string $ranAt, string $cutoff, array $paid): SystemRun
    {
        $run = SystemRun::create([
            'run_by' => User::where('email', 'superadmin@etec.lk')->value('id'),
            'cutoff_at' => $cutoff.' 23:59:59',
            'accounts_flushed' => count($paid),
            'total_released' => array_sum(array_map(fn ($p) => $p[1], $paid)),
            'total_forfeited' => 0,
            'accounts' => json_encode(array_map(fn ($p) => array_filter([
                'account_id' => $p[0]->id,
                'code' => $p[0]->scn_code,
                'released' => $p[1],
                'forfeited' => 0,
                'reversed_at' => ($p[2] ?? false) ? '2026-09-20 10:00:00' : null,
            ], fn ($v) => $v !== null), $paid)),
        ]);

        $run->forceFill(['created_at' => Carbon::parse($ranAt, 'Asia/Colombo')->utc()])->save();

        return $run;
    }

    public function test_a_consultant_sees_what_each_run_paid_them_date_by_date(): void
    {
        $user = $this->consultantUser();
        $main = $user->consultant;
        $placement = $main->hiddenAccounts()->orderBy('id')->firstOrFail();
        $stranger = $this->stranger();

        $this->recordRun('2026-09-15 10:00', '2026-09-10', [[$main, 5000], [$placement, 10000], [$stranger, 99000]]);
        $this->recordRun('2026-09-18 10:00', '2026-09-17', [[$placement, 2500]]);

        $rows = $this->actingAs($user)
            ->get(route('reports.payments', ['from' => '2026-09-01', 'to' => '2026-09-22']))
            ->assertOk()
            ->assertSee('Paid out by each System Run')
            ->viewData('runPayouts');

        $this->assertSame([2500.0, 15000.0], $rows->pluck('amount')->all(), 'newest first, both of their accounts added together');
        $this->assertSame(['2026-09-18', '2026-09-15'], $rows->pluck('date')->all());
        $this->assertSame(['2026-09-17', '2026-09-10'], $rows->pluck('settled_up_to')->all());
    }

    /** ⚠️ Never someone else's payout. */
    public function test_another_consultants_payout_is_never_counted(): void
    {
        $user = $this->consultantUser();
        $stranger = $this->stranger();

        $this->recordRun('2026-09-15 10:00', '2026-09-10', [[$stranger, 99000]]);

        $rows = $this->actingAs($user)
            ->get(route('reports.payments', ['from' => '2026-09-01', 'to' => '2026-09-22']))
            ->viewData('runPayouts');

        $this->assertSame([0.0], $rows->pluck('amount')->all(), 'the run is listed, with nothing for them');
        $this->assertStringNotContainsString('99,000', $this->actingAs($user)
            ->get(route('reports.payments', ['from' => '2026-09-01', 'to' => '2026-09-22']))->getContent());
    }

    public function test_an_account_reversed_after_the_run_is_left_out(): void
    {
        $user = $this->consultantUser();

        $this->recordRun('2026-09-15 10:00', '2026-09-10', [[$user->consultant, 5000, true]]);

        $rows = $this->actingAs($user)
            ->get(route('reports.payments', ['from' => '2026-09-01', 'to' => '2026-09-22']))
            ->viewData('runPayouts');

        $this->assertSame([0.0], $rows->pluck('amount')->all());
    }

    public function test_only_runs_inside_the_date_range_are_listed(): void
    {
        $user = $this->consultantUser();

        $this->recordRun('2026-08-01 10:00', '2026-07-31', [[$user->consultant, 7000]]);
        $this->recordRun('2026-09-15 10:00', '2026-09-10', [[$user->consultant, 5000]]);
        // The day after the range ends, by the Sri Lanka date — right at the edge.
        $this->recordRun('2026-09-23 01:00', '2026-09-22', [[$user->consultant, 3000]]);

        $rows = $this->actingAs($user)
            ->get(route('reports.payments', ['from' => '2026-09-01', 'to' => '2026-09-22']))
            ->viewData('runPayouts');

        // ⚠️ KEYED ON WHAT EACH RUN SETTLED UP TO, NOT WHEN IT RAN
        // (2026-09-25): the run performed at 01:00 on the 23rd settled up to
        // the 22nd, so it is the 22nd's money and belongs in this range.
        $this->assertSame(['2026-09-22', '2026-09-10'], $rows->pluck('settled_up_to')->all());
    }

    /** Staff see every account's payout per run, and can narrow to one marketer. */
    public function test_staff_see_the_whole_run_and_can_narrow_to_a_marketer(): void
    {
        $main = $this->consultantUser()->consultant;
        $stranger = $this->stranger();

        $this->recordRun('2026-09-15 10:00', '2026-09-10', [[$main, 5000], [$stranger, 99000]]);

        $admin = User::where('email', 'admin@etec.lk')->firstOrFail();

        $all = $this->actingAs($admin)
            ->get(route('reports.payments', ['from' => '2026-09-01', 'to' => '2026-09-22']))
            ->viewData('runPayouts');
        $this->assertSame([104000.0], $all->pluck('amount')->all());

        $one = $this->actingAs($admin)
            ->get(route('reports.payments', ['from' => '2026-09-01', 'to' => '2026-09-22', 'marketer' => $main->id]))
            ->viewData('runPayouts');
        $this->assertSame([5000.0], $one->pluck('amount')->all());
    }
}
