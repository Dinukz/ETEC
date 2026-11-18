<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\Consultant;
use App\Models\SystemRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The Payment Summary's income columns are what a System Run PAID OUT in the
 * range, plus the direct bonus — nothing else (2026-09-23).
 *
 * Owner: "eke 2500 dapuwth add wela thiyeno. ekat add wenn one system run eken
 * out wena stage 1to1, 2to2, 3to3 tikai consultant hambena direct bonus 4000i
 * withri enn one."
 *
 * ⚠️ THE BUG WAS THE DATE, NOT THE FILTER. Released-only was already enforced,
 * but the range was applied to the day the income was EARNED. So a 2,500 earned
 * on the 5th and released by a run on the 20th counted in a report for the 1st
 * to the 10th — a window in which no run paid that consultant anything. Slot
 * income is now dated by the run that released it, so this column and the
 * "Paid out by each System Run" table below it always agree.
 */
class PaymentSummaryCountsRunPayoutsOnlyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        \App\Services\FinanceSchemaRepair::ensure();
    }

    private function consultant(): Consultant
    {
        $user = User::where('email', 'consultant@etec.lk')->firstOrFail();
        $this->grantConsultantPages($user, ['payment_summary']);

        return $user->consultant;
    }

    /** One slot row: earned on $earnedOn, released by a run on $releasedOn (or never). */
    private function slot(Consultant $account, string $earnedOn, ?string $releasedOn, float $amount = 2500): Commission
    {
        return Commission::create([
            'consultant_id' => $account->id,
            'group_id' => $account->groups()->value('id'),
            'user_id' => User::factory()->create()->id,
            'amount' => $amount,
            'slots' => 1,
            'tier' => 1,
            'type' => 'bonus',
            'status' => 'approved',
            'approved_at' => Carbon::parse($earnedOn, 'Asia/Colombo'),
            'earned_at' => Carbon::parse($earnedOn, 'Asia/Colombo'),
            'released_at' => $releasedOn ? Carbon::parse($releasedOn, 'Asia/Colombo')->utc() : null,
        ]);
    }

    private function directBonus(Consultant $account, string $earnedOn, float $amount = 4000): Commission
    {
        return Commission::create([
            'consultant_id' => $account->id,
            'user_id' => User::factory()->create()->id,
            'amount' => $amount,
            'slots' => 1,
            'tier' => 1,
            'type' => 'referral',
            'status' => 'approved',
            'approved_at' => Carbon::parse($earnedOn, 'Asia/Colombo'),
            'earned_at' => Carbon::parse($earnedOn, 'Asia/Colombo'),
        ]);
    }

    private function recordRun(Consultant $account, string $ranAt, float $released): void
    {
        $run = SystemRun::create([
            'run_by' => User::where('email', 'superadmin@etec.lk')->value('id'),
            'cutoff_at' => Carbon::parse($ranAt, 'Asia/Colombo')->toDateString().' 23:59:59',
            'accounts_flushed' => 1,
            'total_released' => $released,
            'total_forfeited' => 0,
            // ⚠️ released_ids, like a real run writes: the report subtracts
            // any of those rows an admin later struck off, and cannot find
            // them without the ids.
            'accounts' => json_encode([[
                'account_id' => $account->id,
                'code' => $account->scn_code,
                'released' => $released,
                'forfeited' => 0,
                'released_ids' => Commission::where('consultant_id', $account->id)->whereNotNull('released_at')->pluck('id')->all(),
            ]]),
        ]);

        $run->forceFill(['created_at' => Carbon::parse($ranAt, 'Asia/Colombo')->utc()])->save();
    }

    /** @return array{0: float, 1: float} [service group income, total income] for the range */
    private function incomeFor(string $from, string $to): array
    {
        $rows = $this->actingAs($this->consultant()->user)
            ->get(route('reports.payments', ['from' => $from, 'to' => $to]))
            ->assertOk()
            ->viewData('commissions');

        return [(float) $rows->sum('group_income'), (float) $rows->sum('total_income')];
    }

    /**
     * ⚠️ REVERSED ON 2026-09-24, AND THIS IS THE RECORD OF IT.
     *
     * From 2026-09-23 this asserted the opposite: that the RUN's date put slot
     * income in a range. The owner reported that with his own case — a slip
     * dated the 21st, verified after the 21st run had already been done, so the
     * next run settled it and the 2,500 landed on the later day: *"verify krana
     * dawsat newei, payslip eka add kran dawsat tham add wenne kiyala haduwa,
     * eth eka hariyt wela na."*
     *
     * Measured on the 21 Sep data: 4,201 of 4,312 released rows (97%) were
     * reported on a different day from their payslip, up to 16 days out, while
     * the direct bonus on the SAME payment used the payslip date. The run's own
     * selected Payment Date is not the answer either — a run settles everything
     * up to its cutoff, and it matched the payslip in only 28% of those rows.
     *
     * The rule now: a run must have RELEASED it (that part never changed), and
     * it counts in the range its PAYSLIP falls in.
     */
    public function test_stage_income_lands_in_the_range_the_run_settled_up_to(): void
    {
        $account = $this->consultant();
        $this->slot($account, '2026-09-05 10:00', '2026-09-20 09:00');
        $this->slot($account, '2026-09-05 10:00', '2026-09-20 09:00');
        $this->recordRun($account, '2026-09-20 09:00', 5000);

        $this->assertSame([5000.0, 5000.0], $this->incomeFor('2026-09-15', '2026-09-22'), 'the 1-to-1 the run released');
        $this->assertSame([0.0, 0.0], $this->incomeFor('2026-09-01', '2026-09-10'), 'no run settled anything in those days');
    }

    public function test_income_still_on_the_leg_is_never_counted(): void
    {
        $account = $this->consultant();
        $this->slot($account, '2026-09-18 10:00', null);

        $this->assertSame([0.0, 0.0], $this->incomeFor('2026-09-01', '2026-09-22'));
    }

    /** The direct bonus never goes through a run, so it keeps its own date. */
    public function test_the_direct_bonus_counts_on_the_day_it_was_earned(): void
    {
        $account = $this->consultant();
        $this->directBonus($account, '2026-09-05 10:00');

        $this->assertSame([0.0, 4000.0], $this->incomeFor('2026-09-01', '2026-09-10'));
        $this->assertSame([0.0, 0.0], $this->incomeFor('2026-09-15', '2026-09-22'));
    }

    /**
     * ⚠️ THE TWO TABLES ON THIS PAGE MUST SHOW THE SAME MONEY (2026-09-25).
     *
     * They are now built from the same source — the runs' own snapshots, keyed
     * on the date each run settled up to — so the per-consultant Service Group
     * column and the "Paid out by each System Run" total below it cannot
     * disagree. Reported when they did: a report filtered to the 23rd listed
     * accounts that were not in the 23rd run, with part-stage figures like
     * 2,500 and 7,500 that no run ever paid.
     */
    public function test_the_income_column_equals_what_the_runs_paid_out(): void
    {
        $account = $this->consultant();
        $this->slot($account, '2026-09-05 10:00', '2026-09-20 09:00');
        $this->slot($account, '2026-09-05 10:00', '2026-09-20 09:00');
        $this->recordRun($account, '2026-09-20 09:00', 5000);
        $this->directBonus($account, '2026-09-19 10:00');

        $page = $this->actingAs($account->user)
            ->get(route('reports.payments', ['from' => '2026-09-15', 'to' => '2026-09-22']))
            ->assertOk();

        $this->assertSame(
            (float) $page->viewData('runPayouts')->sum('amount'),
            (float) $page->viewData('commissions')->sum('group_income'),
            'the Service Group column IS what the runs paid out',
        );

        // A whole stage, never a part of one.
        $this->assertSame(5000.0, (float) $page->viewData('commissions')->sum('group_income'));
        $this->assertSame(9000.0, (float) $page->viewData('commissions')->sum('total_income'), 'plus the 4,000 bonus');
    }

    /**
     * ⚠️ A RUN PERFORMED LATE STILL BELONGS TO THE DAY IT SETTLED.
     *
     * The owner's screen: "RUN AT Sep 24 5:41pm · SETTLED UP TO Sep 23". That
     * money is the 23rd's, and a report for the 23rd must hold it — which is
     * also what makes re-running an old date report on that old date.
     */
    public function test_a_run_performed_later_reports_on_the_date_it_settled(): void
    {
        $account = $this->consultant();
        $this->slot($account, '2026-09-23 10:00', '2026-09-24 17:41');

        // Settled up to the 23rd, but the button was pressed on the 24th.
        $run = SystemRun::create([
            'run_by' => User::where('email', 'superadmin@etec.lk')->value('id'),
            'cutoff_at' => '2026-09-23 23:59:59',
            'accounts_flushed' => 1,
            'total_released' => 2500,
            'total_forfeited' => 0,
            'accounts' => json_encode([[
                'account_id' => $account->id,
                'code' => $account->scn_code,
                'released' => 2500,
                'forfeited' => 0,
                'released_ids' => Commission::where('consultant_id', $account->id)->whereNotNull('released_at')->pluck('id')->all(),
            ]]),
        ]);

        $run->forceFill(['created_at' => Carbon::parse('2026-09-24 17:41', 'Asia/Colombo')->utc()])->save();

        $this->assertSame([2500.0, 2500.0], $this->incomeFor('2026-09-23', '2026-09-23'), 'the day it settled');
        $this->assertSame([0.0, 0.0], $this->incomeFor('2026-09-24', '2026-09-24'), 'not the day it was pressed');
    }

    /** A row a run released and an admin then removed is gone from both. */
    public function test_money_removed_after_a_run_is_not_counted(): void
    {
        $account = $this->consultant();
        $row = $this->slot($account, '2026-09-18 10:00', '2026-09-20 09:00');
        $this->recordRun($account, '2026-09-20 09:00', 2500);

        $row->update(['status' => 'cancelled', 'removed_amount' => 2500, 'amount' => 0, 'removed_at' => now()]);

        $this->assertSame([0.0, 0.0], $this->incomeFor('2026-09-15', '2026-09-22'));
    }

    /** Staff see the same rule, per marketer. */
    public function test_staff_see_the_same_figures(): void
    {
        $account = $this->consultant();
        $this->slot($account, '2026-09-05 10:00', '2026-09-20 09:00');
        $this->recordRun($account, '2026-09-20 09:00', 2500);

        // The run's window — staff read the same rule.
        $rows = $this->actingAs(User::where('email', 'admin@etec.lk')->firstOrFail())
            ->get(route('reports.payments', ['from' => '2026-09-15', 'to' => '2026-09-22', 'marketer' => $account->id]))
            ->assertOk()
            ->viewData('commissions');

        $this->assertSame(2500.0, (float) $rows->sum('total_income'));
    }

    /** Odd helper guard: the seeded consultant has a group to hang rows on. */
    public function test_the_fixture_has_a_group(): void
    {
        $this->assertNotNull($this->consultant()->groups()->value('id'), 'guard: '.Str::of('seeded consultant has a service group'));
    }
}
