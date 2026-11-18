<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\Consultant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ⚠️ THE PAYMENT SUMMARY CARRIES IN-RANGE MONEY ONLY — AND THIS FILE IS THE
 * RECORD OF THE RULE BEING REVERSED.
 *
 * 2026-08-24 the owner asked for three payout-desk columns beside the income
 * figures: *"day eke kochchara enwada week eke kochchra enwad kiyla wen wenama
 * danna, pending eke ammount kiyak enn one d kiyalath dann."* They were built
 * as Released Today / Released This Week / Pending.
 *
 * 2026-09-24 the same owner reported this report as showing MORE than the
 * consultants were actually paid, and restated the rule for the third time:
 * *"system run eken out wena stage 1to1, 2to2, 3to3 tikai, consultant hambena
 * direct bonus 4000i withri enn one … api select karana date ekata ena tika."*
 *
 * Those three columns were the excess, for two different reasons:
 *   - Released Today and Released This Week are FIXED windows. They ignore the
 *     date filter completely, so the page showed figures from outside the range
 *     the reader had chosen.
 *   - Pending is slot income NO RUN HAS RELEASED — LKR 9,077,500 across the
 *     network on the 21 Sep data — printed in the same table as money that had
 *     really been paid.
 *
 * So the report now carries exactly: the direct bonus, and stage income a
 * System Run released, both inside the selected dates. This file pins that, and
 * pins that the removed columns cannot come back by accident.
 *
 * Un-released money is NOT hidden from anybody — it is on the Service Groups
 * page, which exists to show it (ServiceGroupFlush::state()).
 */
class PaymentSummaryPayoutWindowsTest extends TestCase
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
        return User::where('email', 'accountant@etec.lk')->firstOrFail();
    }

    private function consultant(): Consultant
    {
        return User::where('email', 'consultant@etec.lk')->firstOrFail()->consultant;
    }

    /** One commission in a given state, dated relative to now. */
    private function commission(Consultant $account, array $attributes): Commission
    {
        $student = User::factory()->create();
        $student->assignRole('student');

        $row = Commission::create(array_merge([
            'consultant_id' => $account->id,
            'user_id' => $student->id,
            'type' => 'bonus',
            'tier' => 1,
            'amount' => 2500,
            'status' => 'approved',
        ], \Illuminate\Support\Arr::only($attributes, ['type', 'amount', 'status', 'tier'])));

        $stamps = \Illuminate\Support\Arr::only($attributes, ['released_at', 'approved_at', 'created_at', 'earned_at']);

        if ($stamps !== []) {
            $row->forceFill($stamps)->save();
        }

        return $row;
    }

    /**
     * Record the run that released those rows.
     *
     * ⚠️ SINCE 2026-09-25 THE REPORT READS THE RUN, NOT THE ROWS. A released_at
     * stamp with no run behind it is money no run ever paid, and the report is
     * right to ignore it — so a fixture about reported income has to record the
     * run as well, exactly as the real one does.
     */
    private function recordRunFor(Commission $row, ?string $cutoff = null): void
    {
        $run = \App\Models\SystemRun::create([
            'run_by' => User::where('email', 'superadmin@etec.lk')->value('id'),
            'cutoff_at' => ($cutoff ?? now('Asia/Colombo')->toDateString()).' 23:59:59',
            'accounts_flushed' => 1,
            'total_released' => (float) $row->amount,
            'total_forfeited' => 0,
            'accounts' => json_encode([[
                'account_id' => (int) $row->consultant_id,
                'released' => (float) $row->amount,
                'forfeited' => 0,
                'released_ids' => [$row->id],
            ]]),
        ]);

        $run->forceFill(['created_at' => now()])->save();
    }

    private function rowsFor(User $actor, array $query = []): \Illuminate\Support\Collection
    {
        // A per-consultant grant since 2026-09-22.
        if ($actor->hasRole('consultant') && $actor->consultant) {
            $this->grantConsultantPages($actor, ['payment_summary']);
        }

        $response = $this->actingAs($actor)->get(route('reports.payments', $query));
        $response->assertOk();

        return collect($response->viewData('commissions'));
    }

    // ----- what the report counts -------------------------------------------

    /** Stage income a run released inside the range is the Service Group column. */
    public function test_income_a_run_released_in_range_is_counted(): void
    {
        $account = $this->consultant()->hiddenAccounts()->first();

        $this->recordRunFor($this->commission($account, ['released_at' => now(), 'approved_at' => now()]));

        $row = $this->rowsFor($this->accountant())->firstWhere('consultant_id', $account->id);

        $this->assertNotNull($row);
        $this->assertSame(2500.0, (float) $row->group_income);
        $this->assertSame(2500.0, (float) $row->total_income);
    }

    /**
     * ⚠️ THE ONE THE OWNER REPORTED. Slot income no run has released must not
     * appear anywhere on this report — not as a column, not in a total.
     */
    public function test_unreleased_income_is_not_on_the_report_at_all(): void
    {
        $account = $this->consultant()->hiddenAccounts()->first();

        // 2,500 sitting on a leg, approved but never flushed…
        $this->commission($account, ['status' => 'approved', 'released_at' => null, 'approved_at' => now()]);
        // …beside 2,500 a run really did release.
        $this->recordRunFor($this->commission($account, ['released_at' => now(), 'approved_at' => now()]));

        $row = $this->rowsFor($this->accountant())->firstWhere('consultant_id', $account->id);

        $this->assertNotNull($row);
        $this->assertSame(2500.0, (float) $row->total_income, 'only the released half');

        foreach (['day_payout', 'week_payout', 'pending_payout'] as $gone) {
            $this->assertObjectNotHasProperty($gone, $row, "{$gone} was removed from this report");
        }
    }

    /**
     * ⚠️ THE BONUS MUST NOT VANISH. It is approved on arrival and never
     * flushed, so it carries no released_at — counting only that stamp would
     * drop every direct bonus off the report.
     */
    public function test_a_never_flushed_bonus_still_counts(): void
    {
        $account = $this->consultant()->hiddenAccounts()->first();

        $this->commission($account, [
            'type' => 'referral',
            'amount' => 4000,
            'status' => 'approved',
            'released_at' => null,
            'approved_at' => now(),
            'earned_at' => now(),
        ]);

        $row = $this->rowsFor($this->accountant())->firstWhere('consultant_id', $account->id);

        $this->assertSame(4000.0, (float) $row->direct_income);
        $this->assertSame(0.0, (float) $row->group_income);
    }

    /**
     * Earned outside the selected range — released, but not this period's money.
     *
     * ⚠️ DATED BY THE PAYSLIP since 2026-09-24, so `earned_at` is what puts a
     * row in or out of the window; released_at only decides whether it counts
     * at all. See PaymentSummaryCountsRunPayoutsOnlyTest for why that reversed.
     */
    public function test_income_earned_outside_the_range_is_not_counted(): void
    {
        $account = $this->consultant()->hiddenAccounts()->first();

        $this->commission($account, [
            'released_at' => now()->subDays(40),
            'approved_at' => now()->subDays(40),
            'earned_at' => now()->subDays(40),
        ]);

        $row = $this->rowsFor($this->accountant(), [
            'from' => now()->subDays(5)->toDateString(),
            'to' => now()->toDateString(),
        ])->firstWhere('consultant_id', $account->id);

        $this->assertNull($row, 'nothing was released to this account in the chosen days');
    }

    // ----- the removed columns ----------------------------------------------

    /** The screen no longer offers them. */
    public function test_the_screen_does_not_show_the_out_of_range_columns(): void
    {
        $account = $this->consultant()->hiddenAccounts()->first();
        $this->commission($account, ['released_at' => now(), 'approved_at' => now()]);

        $response = $this->actingAs($this->accountant())->get(route('reports.payments'));

        $response->assertOk()
            ->assertDontSee('Released Today')
            ->assertDontSee('Released This Week')
            ->assertSee('Total Income');
    }

    /** Nor does the CSV, which the accounts desk reconciles from. */
    public function test_the_csv_export_carries_the_five_in_range_columns(): void
    {
        $account = $this->consultant()->hiddenAccounts()->first();
        $this->commission($account, ['released_at' => now(), 'approved_at' => now()]);

        $body = $this->actingAs($this->accountant())
            ->get(route('reports.payments', ['export' => 'csv']))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('Direct Income', $body);
        $this->assertStringContainsString('Service Group Income', $body);
        $this->assertStringContainsString('Total Income', $body);
        $this->assertStringNotContainsString('Released Today', $body);
        $this->assertStringNotContainsString('Pending (not yet released)', $body);
    }

    // ----- the boundary that must not move ----------------------------------

    /** ⚠️ A consultant must never see another consultant's income. */
    public function test_a_consultant_sees_only_their_own_income(): void
    {
        $mine = $this->consultant()->hiddenAccounts()->first();

        $strangerUser = User::factory()->create();
        $strangerUser->assignRole('consultant');
        $stranger = Consultant::create([
            'user_id' => $strangerUser->id,
            'consultant_code' => 'CON'.Str::random(8),
            'referral_code' => strtoupper(Str::random(10)),
            'scn_code' => 'SCN-5'.random_int(1000, 9999),
            'status' => 'active',
        ]);

        $this->commission($mine, ['released_at' => now(), 'approved_at' => now()]);
        $this->commission($stranger, ['released_at' => now(), 'approved_at' => now()]);

        $rows = $this->rowsFor($this->consultant()->user);

        $this->assertTrue(
            $rows->every(fn ($r) => in_array((int) $r->consultant_id, $this->consultant()->accountIds(), true)),
            'Only their own accounts are listed.',
        );
    }
}
