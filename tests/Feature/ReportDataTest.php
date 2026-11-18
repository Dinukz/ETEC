<?php

namespace Tests\Feature;

use App\Models\Consultant;
use App\Models\Course;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use App\Services\CommissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The reports a consultant reads on their phone. These broke because a
 * consultant's students/income live across ALL of their accounts, not just the
 * one row matching their consultant id.
 */
class ReportDataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        // ⚠️ BEFORE ANY MONEY IS MADE, NOT AFTER. FinanceSchemaRepair caches its
        // "is this column here yet" answers, so healing the schema only once a
        // fixture has already asked leaves the cached NO in place and the flush
        // silently settles nothing.
        \App\Services\FinanceSchemaRepair::ensure();
    }

    private function makeConsultant(): Consultant
    {
        $user = User::factory()->create();
        $user->assignRole('consultant');

        return Consultant::create([
            'user_id' => $user->id,
            'consultant_code' => 'CON-'.Str::random(6),
            'referral_code' => strtoupper(Str::random(8)),
            'status' => 'active',
        ]);
    }

    private function placeStudent(Consultant $account, int $tier): void
    {
        $user = User::factory()->create();

        Student::create([
            'user_id' => $user->id,
            'student_code' => 'SRN'.Str::random(6),
            'status' => 'active',
            'enrolled_at' => now(),
        ]);

        $payment = Payment::create([
            'payment_code' => 'PYM'.Str::random(6),
            'user_id' => $user->id,
            'course_id' => Course::first()->id,
            'consultant_id' => $account->id,
            'requested_tier' => $tier,
            'amount' => 28000,
            'currency' => 'LKR',
            'method' => 'bank_transfer',
            'payment_type' => 'full',
            'status' => 'pending',
            'paid_at' => now(),
        ]);

        app(CommissionService::class)->handleVerifiedPayment($payment);
    }

    public function test_admission_report_counts_students_in_placement_accounts(): void
    {
        $c = $this->makeConsultant();
        $hidden = $c->hiddenAccounts()->first();

        // One student on the main account, one on a placement account.
        $this->placeStudent($c, 1);
        $this->placeStudent($hidden, 1);

        $this->grantConsultantPages($c->user, ['admissions']); // a grant since 2026-09-22
        $response = $this->actingAs($c->user)->get(route('reports.admissions'));
        $response->assertOk();

        $total = collect($response->viewData('rows'))->sum('student_count');
        $this->assertSame(2, $total, 'Students placed in a placement account must still be reported.');
    }

    /**
     * A real System Run, recorded the way SystemRunController records it.
     *
     * runAll() moves the money and hands back the per-account snapshot; the
     * controller is what writes the system_runs row. A test that measures the
     * Payment Summary needs both, because that report reads the row.
     */
    private function performSystemRun(): void
    {
        $result = app(\App\Services\ServiceGroupFlush::class)->runAll();

        \App\Models\SystemRun::create([
            'run_by' => User::where('email', 'superadmin@etec.lk')->value('id'),
            'cutoff_at' => $result['cutoff'],
            'accounts_flushed' => $result['flushed'],
            'total_released' => $result['payout'],
            'total_forfeited' => $result['forfeit'],
            'accounts' => $result['accounts'] ? json_encode($result['accounts']) : null,
        ]);
    }

    public function test_payment_summary_splits_direct_bonus_from_service_group_income(): void
    {
        $c = $this->makeConsultant();

        // A balanced pair on the first group pays 5000 slot + 6000 direct.
        $this->placeStudent($c, 1);
        $this->placeStudent($c, 2);

        // ⚠️ THE RUN COMES FIRST (2026-09-25). Service Group income on this
        // report is what the System Run RECORDED it released, and a run pays
        // out the rows that were still PENDING when it ran — a row approved by
        // hand beforehand keeps its place in the threshold but has nothing
        // left to pay, so the run books it as zero. Approving first therefore
        // measures the wrong thing; the run approves what it releases.
        $this->performSystemRun();

        // The direct bonus never goes through a run, and the report counts
        // approved money only.
        $this->approveAllCommissions();

        $this->grantConsultantPages($c->user, ['payment_summary']); // a grant since 2026-09-22
        $response = $this->actingAs($c->user)->get(route('reports.payments'));
        $response->assertOk();

        $rows = collect($response->viewData('commissions'));

        $this->assertSame(6000.0, (float) $rows->sum('direct_income'), 'Direct bonus should be 2 x 3000.');
        $this->assertSame(5000.0, (float) $rows->sum('group_income'), 'Service group income should be 2 x 2500.');
        $this->assertSame(11000.0, (float) $rows->sum('total_income'));
    }

    public function test_service_income_statement_exports_csv_and_pdf(): void
    {
        $c = $this->makeConsultant();
        $this->placeStudent($c, 1);
        $this->placeStudent($c, 2);

        // The statement only lists APPROVED money, so approve it first —
        // otherwise both exports are legitimately empty.
        $this->approveAllCommissions();

        $this->actingAs($c->user)
            ->get(route('consultant.earnings.index', ['export' => 'pdf']))
            ->assertOk()
            ->assertSee('Service Income Statement')
            ->assertSee('Save as PDF');

        $csv = $this->actingAs($c->user)->get(route('consultant.earnings.index', ['export' => 'csv']));
        $csv->assertOk();
        $this->assertStringContainsString('text/csv', $csv->headers->get('Content-Type'));
    }

    public function test_the_statement_credits_income_and_debits_payouts_with_a_running_balance(): void
    {
        $c = $this->makeConsultant();
        $this->placeStudent($c, 1);
        $this->approveAllCommissions();

        // One student on the main account: 2,500 slot + 3,000 bonus, both
        // credited, nothing paid out yet — so the balance owed is 5,500.
        $response = $this->actingAs($c->user)->get(route('consultant.earnings.index'));
        $response->assertOk();

        $ledger = collect($response->viewData('ledger')->items());

        $this->assertSame(5500.0, (float) $ledger->sum('credit'), 'Both income kinds are credited.');
        $this->assertSame(0.0, (float) $ledger->sum('debit'), 'Nothing has been paid out yet.');

        // Newest row first, so the top row carries the final balance.
        $this->assertSame(5500.0, (float) $ledger->first()['balance']);

        // The direct bonus must appear as its own line, not folded into the
        // service-group income.
        $this->assertTrue(
            $ledger->contains(fn ($row) => str_contains($row['description'], 'Direct Add Bonus')),
            'The direct-add bonus needs its own statement line.',
        );
        $this->assertTrue(
            $ledger->contains(fn ($row) => str_contains($row['description'], 'Service Group Income')),
            'The slot income needs its own statement line.',
        );
    }
}
