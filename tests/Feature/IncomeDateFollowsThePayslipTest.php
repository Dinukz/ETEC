<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\Consultant;
use App\Models\Course;
use App\Models\Payment;
use App\Models\User;
use App\Services\CommissionService;
use App\Services\FinanceSchemaRepair;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Income is dated by the payslip, not by the day someone got round to it.
 *
 * Reported 2026-09-10: *"Payment slip eka dala daws sathiyakin verify kraddi
 * meke ganne verify krapu date eka, eka wardi — payslip eka attach kran date
 * eka thama gann one."* A commission row is only inserted at verification, so
 * `created_at` on it can be a week or a month after the money arrived, which
 * put income in the wrong week and — across a month boundary — in the wrong
 * report entirely.
 *
 * ⚠️ THE CONSTRAINT THIS FILE EXISTS TO PROVE. The owner's standing rule is
 * that commission logic must not change. Pair matching, forfeits, staging rank
 * and the System Run all order by row id, and the run's cutoff reads
 * payments.created_at — so re-dating income must move no money at all. The
 * money guard below is the test that says so.
 */
class IncomeDateFollowsThePayslipTest extends TestCase
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

    private function consultant(): Consultant
    {
        return User::where('email', 'consultant@etec.lk')->firstOrFail()->consultant;
    }

    /**
     * A payment banked $daysAgo days ago and verified today.
     *
     * created_at is forced with a query-builder update rather than the model,
     * because Eloquent overwrites it on save — and it is precisely the column
     * under test.
     */
    private function payslipFrom(int $daysAgo): Payment
    {
        $student = User::factory()->create();
        $student->assignRole('student');

        $course = Course::where('status', 'published')->firstOrFail();

        $payment = Payment::create([
            'payment_code' => 'PYM-'.Str::random(8),
            'user_id' => $student->id,
            'course_id' => $course->id,
            'consultant_id' => $this->consultant()->id,
            'amount' => 10000,
            'currency' => 'LKR',
            'method' => 'bank_transfer',
            'payment_type' => 'full',
            'status' => 'pending',
            'paid_at' => now()->subDays($daysAgo),
        ]);

        DB::table('payments')->where('id', $payment->id)
            ->update(['created_at' => now()->subDays($daysAgo)]);

        return $payment->fresh();
    }

    private function verify(Payment $payment): void
    {
        app(PaymentService::class)->verify($payment, $this->admin());
    }

    // ----- the report itself -------------------------------------------------

    public function test_income_is_dated_by_the_payslip_not_by_the_verification(): void
    {
        $payment = $this->payslipFrom(7);
        $banked = $payment->created_at->toDateString();

        $this->verify($payment);

        $rows = Commission::where('payment_id', $payment->id)->get();

        $this->assertTrue($rows->isNotEmpty(), 'guard: verification recorded income');

        foreach ($rows as $row) {
            $this->assertSame(
                $banked,
                $row->incomeDate()->toDateString(),
                "a {$row->type} row must be dated by the payslip",
            );

            // And the audit trail is intact: the row itself really was
            // inserted today, and still says so.
            $this->assertSame(
                now()->toDateString(),
                $row->created_at->toDateString(),
                'created_at still means when the row appeared',
            );
        }
    }

    /** Both kinds of income, not just one of them. */
    public function test_the_direct_bonus_is_dated_the_same_way(): void
    {
        $payment = $this->payslipFrom(10);
        $banked = $payment->created_at->toDateString();

        $this->verify($payment);

        $direct = Commission::where('payment_id', $payment->id)
            ->where('type', CommissionService::TYPE_DIRECT)
            ->first();

        $this->assertNotNull($direct, 'guard: a direct bonus was recorded');
        $this->assertSame($banked, $direct->incomeDate()->toDateString());
    }

    // ----- the screens -------------------------------------------------------

    public function test_the_history_filter_finds_it_under_the_banked_day_not_the_verify_day(): void
    {
        $payment = $this->payslipFrom(7);
        $banked = $payment->created_at->copy();

        $this->verify($payment);

        $onBankedDay = $this->actingAs($this->admin())
            ->get(route('commissions.history', [
                'from' => $banked->toDateString(),
                'to' => $banked->toDateString(),
            ]))
            ->assertOk()
            ->viewData('commissions');

        $this->assertGreaterThan(0, $onBankedDay->total(), 'listed on the day it was banked');

        $onVerifyDay = $this->actingAs($this->admin())
            ->get(route('commissions.history', [
                'from' => now()->toDateString(),
                'to' => now()->toDateString(),
            ]))
            ->assertOk()
            ->viewData('commissions');

        $ours = collect($onVerifyDay->items())->where('payment_id', $payment->id);

        $this->assertTrue($ours->isEmpty(), 'not listed on the day it was merely verified');
    }

    public function test_the_payment_summary_report_counts_it_in_the_banked_window(): void
    {
        $payment = $this->payslipFrom(20);
        $banked = $payment->created_at->copy();

        $this->verify($payment);

        $totals = $this->actingAs($this->admin())
            ->get(route('reports.payments', [
                'from' => $banked->copy()->subDay()->toDateString(),
                'to' => $banked->copy()->addDay()->toDateString(),
            ]))
            ->assertOk()
            ->viewData('totals');

        $this->assertSame(
            10000.0,
            round($totals['payments'], 2),
            'the payment total follows the day it was banked, not the day it was verified',
        );
    }

    /**
     * ⚠️ A rendered assertion, not view data. On 2026-09-10 a panel that
     * existed in the view data was never actually on the page.
     */
    public function test_the_board_renders_the_banked_date(): void
    {
        $payment = $this->payslipFrom(9);

        $this->verify($payment);

        $this->actingAs($this->admin())
            ->get(route('commissions.history'))
            ->assertOk()
            ->assertSee($payment->created_at->format('M d, Y'));
    }

    // ----- the money guard ---------------------------------------------------

    /**
     * ⚠️ THE TEST THAT PROVES THE CONSTRAINT WAS HONOURED.
     *
     * Two identical fixtures, one banked a week ago and one banked today, must
     * produce exactly the same money: same number of rows, same amounts, same
     * legs, same statuses. If re-dating ever starts moving income between legs
     * or between people, this goes red.
     */
    public function test_re_dating_moves_no_money(): void
    {
        $shape = function (Payment $payment): array {
            return Commission::where('payment_id', $payment->id)
                ->orderBy('id')
                ->get(['consultant_id', 'tier', 'type', 'amount', 'status'])
                ->map(fn ($c) => [
                    (int) $c->consultant_id, (int) $c->tier, $c->type,
                    round((float) $c->amount, 2), $c->status,
                ])
                ->all();
        };

        $backdated = $this->payslipFrom(7);
        $this->verify($backdated);
        $fromBackdated = $shape($backdated);

        $today = $this->payslipFrom(0);
        $this->verify($today);
        $fromToday = $shape($today);

        $this->assertNotEmpty($fromBackdated, 'guard: the fixture earns something');

        // Consultant ids differ only where the seat differs; compare the money
        // shape, which is what must not move.
        $strip = fn (array $rows) => array_map(fn ($r) => [$r[1], $r[2], $r[3], $r[4]], $rows);

        $this->assertSame(
            $strip($fromToday),
            $strip($fromBackdated),
            'a back-dated payslip earns exactly what a same-day one earns',
        );
    }

    /** The System Run reads payments.created_at, never the income date. */
    public function test_the_system_run_still_sees_a_backdated_payment(): void
    {
        $payment = $this->payslipFrom(7);
        $this->verify($payment);

        $rows = Commission::where('payment_id', $payment->id)->get();

        $this->assertTrue($rows->isNotEmpty());

        foreach ($rows as $row) {
            $this->assertNull($row->released_at, 'still sitting on its leg, as before');
        }
    }

    // ----- Run Repair --------------------------------------------------------

    /**
     * The owner's answer for everything already verified: one button.
     * Commission Health → Run Repair calls this command.
     */
    public function test_repair_dates_income_that_predates_the_column(): void
    {
        $payment = $this->payslipFrom(12);
        $this->verify($payment);

        // Put the rows back the way they looked before this feature existed.
        DB::table('commissions')->where('payment_id', $payment->id)->update(['earned_at' => null]);

        $stale = Commission::where('payment_id', $payment->id)->first();
        $this->assertSame(
            $stale->created_at->toDateString(),
            $stale->incomeDate()->toDateString(),
            'guard: without the column it falls back to the verify day',
        );

        $this->artisan('commissions:backfill')->assertSuccessful();

        foreach (Commission::where('payment_id', $payment->id)->get() as $row) {
            $this->assertSame(
                $payment->created_at->toDateString(),
                $row->incomeDate()->toDateString(),
                'Repair dated it by the payslip',
            );
        }
    }

    /** Pressing Repair twice must not move a date that is already right. */
    public function test_repair_is_safe_to_run_twice(): void
    {
        $payment = $this->payslipFrom(5);
        $this->verify($payment);

        $this->artisan('commissions:backfill')->assertSuccessful();
        $first = Commission::where('payment_id', $payment->id)->orderBy('id')->pluck('earned_at')->all();

        $this->artisan('commissions:backfill')->assertSuccessful();
        $second = Commission::where('payment_id', $payment->id)->orderBy('id')->pluck('earned_at')->all();

        $this->assertEquals($first, $second);
    }

    /** Hand-entered income has no payment, and keeps the day it was typed in. */
    public function test_income_with_no_payment_keeps_its_own_date(): void
    {
        $consultant = $this->consultant();

        $manual = Commission::create([
            'consultant_id' => $consultant->id,
            'user_id' => $consultant->user_id,
            'amount' => 500,
            'slots' => 1,
            'type' => CommissionService::TYPE_DIRECT,
            'status' => 'approved',
        ]);

        $this->artisan('commissions:backfill')->assertSuccessful();

        $manual->refresh();

        $this->assertNull($manual->earned_at, 'nothing to date it by');
        $this->assertSame(
            $manual->created_at->toDateString(),
            $manual->incomeDate()->toDateString(),
            'so it falls back to the day it was entered',
        );
    }

    // ----- the un-healed database -------------------------------------------

    /**
     * ⚠️ Naming a column that does not exist yet takes out every Service
     * Income page. Production heals itself on the first request, but a
     * half-deployed server must degrade to the old behaviour, not to a 500.
     */
    public function test_the_screens_still_work_before_the_column_exists(): void
    {
        $payment = $this->payslipFrom(6);
        $this->verify($payment);

        // The index goes first — SQLite refuses to drop a column an index
        // still names.
        \Illuminate\Support\Facades\Schema::table('commissions', function ($table) {
            $table->dropIndex('commissions_consultant_earned_idx');
        });

        \Illuminate\Support\Facades\Schema::table('commissions', function ($table) {
            $table->dropColumn('earned_at');
        });

        FinanceSchemaRepair::forgetMemo();
        \Illuminate\Support\Facades\Cache::flush();

        $this->assertFalse(FinanceSchemaRepair::earnedAtReady(), 'guard: the column really is gone');
        $this->assertSame('commissions.created_at', FinanceSchemaRepair::incomeDateSql());

        $this->actingAs($this->admin())
            ->get(route('commissions.history', ['from' => now()->subMonth()->toDateString()]))
            ->assertOk();

        $this->assertSame(
            Commission::where('payment_id', $payment->id)->first()->created_at->toDateString(),
            Commission::where('payment_id', $payment->id)->first()->incomeDate()->toDateString(),
            'falls back to the old meaning',
        );
    }
}
