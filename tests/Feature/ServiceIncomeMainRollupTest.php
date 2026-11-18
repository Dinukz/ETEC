<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\Consultant;
use App\Models\Course;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Selecting the MAIN account on Service Income reports the person's combined
 * position, not the main account's own (empty) one.
 *
 * Since upline income moved onto the PLACEMENT accounts (2026-08-21) a
 * consultant's main account routinely holds nothing itself, so the Main tab
 * read Total Income / Payout / Pending all as 0 while the summary above the
 * tabs read 26,500 — reported 2026-08-22: "methana total income, payout,
 * pending okkom 0 widiyt thiyenne, dashboard eke wage ammount tika methanat
 * enn one".
 */
class ServiceIncomeMainRollupTest extends TestCase
{
    use RefreshDatabase;

    private const SLOT = 2500;

    private const DIRECT = 4000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        \App\Models\CommissionSetting::where('is_active', true)->update([
            'commission_per_student' => self::SLOT,
            'direct_commission' => self::DIRECT,
        ]);
        \Illuminate\Support\Facades\Cache::forget('commission_settings.active');
    }

    /** Register a student on $account and approve everything it produced. */
    private function earnOn(Consultant $account): void
    {
        $user = User::factory()->create();
        Student::create([
            'user_id' => $user->id, 'student_code' => 'SRN'.Str::random(8),
            'status' => 'active', 'enrolled_at' => now(),
        ]);

        $payment = Payment::create([
            'payment_code' => 'PYM'.Str::random(8), 'user_id' => $user->id, 'course_id' => Course::first()->id,
            'consultant_id' => $account->id, 'requested_tier' => 1, 'amount' => 39000, 'currency' => 'LKR',
            'method' => 'bank_transfer', 'payment_type' => 'full', 'status' => 'pending', 'paid_at' => now(),
        ]);

        app(PaymentService::class)->verify($payment, User::where('email', 'accountant@etec.lk')->firstOrFail());

        foreach (Commission::where('status', 'pending')->get() as $c) {
            $this->actingAs(User::where('email', 'admin@etec.lk')->firstOrFail())
                ->patch(route('commissions.approve', $c));
        }
    }

    public function test_the_main_tab_reports_the_combined_position(): void
    {
        $consultantUser = User::where('email', 'consultant@etec.lk')->firstOrFail();
        $consultant = $consultantUser->consultant;
        $placement = $consultant->hiddenAccounts()->orderBy('id')->first();

        // The student is registered on a PLACEMENT account. Most of what that
        // earns stays there — the 2,500 slot and the whole 4,000 direct bonus.
        $this->earnOn($placement);

        // ⚠️ NO LONGER "the main has earned nothing" (2026-09-02). Since the
        // climb runs all the way up, the main earns its own 2,500 for this
        // student. The rollup this test is named for still has work to do,
        // because the main's own figure is only PART of the person's position —
        // which is exactly what the tab must not show on its own.
        $ownOnly = (float) $consultant->fresh()->total_earned;

        $this->assertGreaterThan(0.0, $ownOnly, 'Precondition: the main earns its own climbed slot.');

        $response = $this->actingAs($consultantUser)->get(route('consultant.earnings.index'));
        $response->assertOk();

        $totals = $response->viewData('accountTotals');

        $this->assertGreaterThan(
            $ownOnly,
            (float) $totals['earned'],
            'The Main tab reports the whole person, not just the rows written against the main.',
        );

        // ...and it agrees with the grand total across every account.
        $overall = $response->viewData('totals');
        $this->assertSame($overall['earned'], $totals['earned'], 'Main and the headline must agree.');
        $this->assertSame($overall['paid'], $totals['paid']);
    }

    /** The stat cards follow the same scope, so they cannot contradict it. */
    public function test_the_main_tab_stat_cards_are_also_rolled_up(): void
    {
        $consultantUser = User::where('email', 'consultant@etec.lk')->firstOrFail();
        $placement = $consultantUser->consultant->hiddenAccounts()->orderBy('id')->first();

        $this->earnOn($placement);

        $stats = $this->actingAs($consultantUser)
            ->get(route('consultant.earnings.index'))
            ->viewData('stats');

        $this->assertGreaterThan(0, $stats['slot_income'] + $stats['direct_income'], 'Service Group Income / Direct Add Bonus must not both read 0.');
    }

    /** A PLACEMENT account still reports only its own — it is not a rollup. */
    public function test_a_placement_account_still_reports_only_itself(): void
    {
        $consultantUser = User::where('email', 'consultant@etec.lk')->firstOrFail();
        $accounts = $consultantUser->consultant->hiddenAccounts()->orderBy('id')->get();

        $this->earnOn($accounts[0]);

        // Look at the OTHER placement account, which earned nothing.
        $totals = $this->actingAs($consultantUser)
            ->get(route('consultant.earnings.index', ['account' => $accounts[1]->id]))
            ->viewData('accountTotals');

        $this->assertSame(0.0, $totals['earned'], 'A placement account reports its own position, not the rollup.');
    }

    /**
     * The statement covers the same accounts as the rolled-up cards.
     *
     * ⚠️ NO LONGER EQUAL, BY DESIGN (2026-09-13). The cards now show the
     * Dashboard's figures — direct bonuses plus service group income a System Run
     * has released (App\Services\SettledIncome) — while the statement still
     * credits every approved row. The gap is exactly the approved service group
     * income still waiting on the legs, so that is what this pins: the same
     * accounts, and one named difference.
     */
    public function test_the_statement_matches_the_rolled_up_cards(): void
    {
        $consultantUser = User::where('email', 'consultant@etec.lk')->firstOrFail();
        $placement = $consultantUser->consultant->hiddenAccounts()->orderBy('id')->first();

        $this->earnOn($placement);

        $response = $this->actingAs($consultantUser)->get(route('consultant.earnings.index'));

        $totals = $response->viewData('accountTotals');
        $credits = (float) collect($response->viewData('ledger')->items())->sum('credit');

        $waitingOnARun = (float) Commission::whereIn('consultant_id', $consultantUser->consultant->accountIds())
            ->where('type', '!=', \App\Services\CommissionService::TYPE_DIRECT)
            ->whereIn('status', ['approved', 'paid'])
            ->whereNull('released_at')
            ->sum('amount');

        $this->assertGreaterThan(0.0, $credits, 'A statement showing nothing under a non-zero total is the contradiction this avoids.');
        $this->assertSame($totals['earned'] + $waitingOnARun, $credits);
    }
}
