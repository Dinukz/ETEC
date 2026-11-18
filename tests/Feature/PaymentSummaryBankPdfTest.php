<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\Consultant;
use App\Models\Course;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The Payment Summary Report's PDF export exists to be handed to the bank for
 * an actual deposit run (reported 2026-08-18: "bank ekat denn one salli
 * deposit krann eka nisa"). Unlike the on-screen table and the CSV/Excel
 * export — which stay grouped by SCN account, useful for admin auditing — the
 * PDF rolls every account up to the REAL PERSON who owns it, because a hidden
 * placement account has no bank details of its own and cannot be paid
 * directly (see Consultant::getDisplayNameAttribute() for the same borrowing
 * pattern applied to names).
 */
class PaymentSummaryBankPdfTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        // ⚠️ ADDED 2026-09-23. Without this the `released_at` column does not
        // exist in this test's database, and SQLite reads a quoted identifier it
        // cannot resolve as a STRING LITERAL — so "released_at IS NOT NULL" was
        // always true here and every leg row counted as released. The report's
        // released-only rule was never actually exercised by this file.
        \App\Services\FinanceSchemaRepair::ensure();
    }

    /**
     * Stamp what a System Run releases. The bank list pays out slot income only
     * once a run has released it (2026-08-27), so a test about the payout has to
     * settle the money first — otherwise it is only asserting on direct bonuses.
     */
    private function releaseEverySlot(): void
    {
        $rows = Commission::where('type', 'bonus')->whereIn('status', ['approved', 'paid'])->get();

        Commission::whereIn('id', $rows->pluck('id'))->update(['released_at' => now()]);

        // ⚠️ AND RECORD THE RUN (2026-09-25). The report now reads what the
        // RUN recorded it released, not the rows, so a stamp with no run
        // behind it is money no run ever paid — which is exactly what the
        // report must refuse to show.
        $run = \App\Models\SystemRun::create([
            'run_by' => User::where('email', 'superadmin@etec.lk')->value('id'),
            'cutoff_at' => now('Asia/Colombo')->toDateString().' 23:59:59',
            'accounts_flushed' => $rows->pluck('consultant_id')->unique()->count(),
            'total_released' => (float) $rows->sum('amount'),
            'total_forfeited' => 0,
            'accounts' => $rows->groupBy('consultant_id')->map(fn ($g, $id) => [
                'account_id' => (int) $id,
                'released' => (float) $g->sum('amount'),
                'forfeited' => 0,
                'released_ids' => $g->pluck('id')->all(),
            ])->values()->toJson(),
        ]);

        $run->forceFill(['created_at' => now()])->save();
    }

    private function makeConsultantWithBankDetails(): Consultant
    {
        $user = User::factory()->create(['name' => 'Nimal Perera']);
        $user->assignRole('consultant');

        return Consultant::create([
            'user_id' => $user->id,
            'consultant_code' => 'CON-'.Str::random(6),
            'referral_code' => strtoupper(Str::random(8)),
            'status' => 'active',
            'bank_name' => 'Sampath Bank',
            'bank_branch' => 'Colombo 07',
            'bank_account_name' => 'Nimal Perera',
            'bank_account_number' => '1234567890',
            'nic' => '199012345678',
        ]);
    }

    private function registerAndVerify(Consultant $account, int $tier): Payment
    {
        $studentUser = User::factory()->create();

        Student::create([
            'user_id' => $studentUser->id,
            'student_code' => 'SRN'.Str::random(6),
            'status' => 'active',
            'enrolled_at' => now(),
        ]);

        $payment = Payment::create([
            'payment_code' => 'PYM'.Str::random(6),
            'user_id' => $studentUser->id,
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

        app(\App\Services\CommissionService::class)->handleVerifiedPayment($payment);

        return $payment->fresh();
    }

    /**
     * Force-approves every pending commission via the per-row override,
     * NOT the bulk approve-all — slot income now needs a System Run to
     * release it (see FixedFlushThresholdTest / ServiceGroupFlush), and this
     * test only cares about what the report shows once money IS approved,
     * not about the flush mechanics that got it there.
     */
    private function forceApproveEveryCommission(): void
    {
        $admin = User::where('email', 'admin@etec.lk')->firstOrFail();

        foreach (Commission::where('status', 'pending')->get() as $commission) {
            $this->actingAs($admin)->patch(route('commissions.approve', $commission));
        }
    }

    public function test_the_bank_pdf_combines_every_account_of_one_person_into_one_line(): void
    {
        $admin = User::where('email', 'admin@etec.lk')->firstOrFail();
        $consultant = $this->makeConsultantWithBankDetails();
        $hidden = $consultant->hiddenAccounts()->first();

        // Earns on the MAIN account directly...
        $this->registerAndVerify($consultant, 1);
        // ...and separately on a HIDDEN placement account, which gets its OWN
        // distinct scn_code the same way the main one just did.
        $this->registerAndVerify($hidden, 1);

        $this->forceApproveEveryCommission();
        $this->releaseEverySlot();

        $consultant->refresh();
        $hidden->refresh();
        $this->assertNotNull($consultant->scn_code, 'Precondition: the main account earned its own SCN.');
        $this->assertNotNull($hidden->scn_code, 'Precondition: the hidden account earned its own, DIFFERENT SCN.');
        $this->assertNotSame($consultant->scn_code, $hidden->scn_code);

        $response = $this->actingAs($admin)->get(route('reports.payments', ['export' => 'pdf']));
        $response->assertOk();

        // One combined line under the MAIN scn_code...
        $response->assertSeeText($consultant->scn_code);
        // ...not a second line under the hidden placement's own code.
        $response->assertDontSeeText($hidden->scn_code);

        // Bank details attached, so the report is actually usable to deposit.
        $response->assertSeeText('Sampath Bank');
        $response->assertSeeText('Colombo 07');
        $response->assertSeeText('1234567890');

        // ⚠️ AND THE CONSULTANT'S NIC (2026-09-17): "Payment summary pdf ekat
        // Consultant ge NIC number ekath load wenn one".
        $response->assertSeeText('NIC');
        $response->assertSeeText('199012345678');

        // Two registrations, one per account — each earns direct + slot
        // income, and the combined line must carry BOTH accounts' total, not
        // just whichever one happens to be the main account.
        $settings = \App\Models\CommissionSetting::current();
        $perRegistration = (float) $settings->direct_commission + (float) $settings->commission_per_student;

        // ⚠️ PLUS ONE CLIMBED SLOT (2026-09-02). The climb reaches the person's
        // own main account now, so the registration made on the HIDDEN
        // placement account records its 2,500 a second time, on the main.
        //
        // ONE, not two: the other registration was made on the main itself,
        // which is the top of the tree — there is nothing above it to climb to.
        //
        // That extra row is exactly what this test is about: the combined line
        // has to carry everything the person is owed across every account they
        // hold, so it must show up here rather than being dropped.
        $onTheMain = (float) $settings->commission_per_student;

        // No thousands separator — matches bankDepositRows()'s own
        // number_format($x, 2, '.', '') convention (same as the existing
        // per-account $data mapping above it).
        $expectedTotal = number_format(($perRegistration * 2) + $onTheMain, 2, '.', '');

        $response->assertSeeText($expectedTotal);
    }

    /** Read the one worksheet out of an .xlsx body. */
    private function sheetXml(string $xlsx): string
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($path, $xlsx);

        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($path) === true, 'the download is a real .xlsx (zip) file');
        $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        @unlink($path);

        return $sheet;
    }

    /**
     * ⚠️ THE PDF'S LIST AS AN EXCEL FILE (2026-09-17): "PDF eke tikama Excel ekat
     * enn one". Same rows, same columns — one line per person, NIC and bank.
     */
    public function test_the_bank_deposit_list_downloads_as_an_excel_file(): void
    {
        $admin = User::where('email', 'admin@etec.lk')->firstOrFail();
        $consultant = $this->makeConsultantWithBankDetails();
        $hidden = $consultant->hiddenAccounts()->first();

        $this->registerAndVerify($consultant, 1);
        $this->registerAndVerify($hidden, 1);
        $this->forceApproveEveryCommission();
        $consultant->refresh();
        $hidden->refresh();

        $response = $this->actingAs($admin)->get(route('reports.payments', ['export' => 'bank-excel']));

        $response->assertOk();
        $this->assertStringContainsString('spreadsheetml', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('.xlsx', (string) $response->headers->get('Content-Disposition'));

        $sheet = $this->sheetXml($response->getContent());

        foreach (['Marketer Code', 'NIC', 'Account Number', $consultant->scn_code, 'Nimal Perera', 'Sampath Bank', 'Colombo 07'] as $text) {
            $this->assertStringContainsString($text, $sheet);
        }

        $this->assertStringNotContainsString($hidden->scn_code, $sheet, 'one line per person, as in the PDF');

        // ⚠️ Stored as TEXT, so Excel never shows 1.99E+11 or drops a leading 0.
        $this->assertStringContainsString('<is><t>199012345678</t></is>', $sheet);
        $this->assertStringContainsString('<is><t>1234567890</t></is>', $sheet);
    }

    public function test_the_pdf_page_offers_the_excel_download(): void
    {
        $this->actingAs(User::where('email', 'admin@etec.lk')->firstOrFail())
            ->get(route('reports.payments', ['export' => 'pdf']))
            ->assertOk()
            ->assertSee('Download Excel')
            ->assertSee('export=bank-excel', false);
    }

    public function test_a_consultant_excel_holds_only_their_own_line(): void
    {
        $mine = $this->makeConsultantWithBankDetails();
        $this->registerAndVerify($mine, 1);

        $otherUser = User::factory()->create(['name' => 'Someone Else']);
        $otherUser->assignRole('consultant');
        $other = Consultant::create([
            'user_id' => $otherUser->id, 'consultant_code' => 'CON-'.Str::random(6),
            'referral_code' => strtoupper(Str::random(8)), 'status' => 'active',
            'nic' => '888888888V',
        ]);
        $this->registerAndVerify($other, 1);
        $this->forceApproveEveryCommission();

        $this->grantConsultantPages($mine->user, ['payment_summary']); // a grant since 2026-09-22

        $sheet = $this->sheetXml($this->actingAs($mine->user)
            ->get(route('reports.payments', ['export' => 'bank-excel']))
            ->assertOk()
            ->getContent());

        $this->assertStringContainsString('199012345678', $sheet);
        $this->assertStringNotContainsString('888888888V', $sheet, 'another consultant\'s NIC must never leak');
    }

    public function test_the_on_screen_table_and_csv_stay_grouped_per_account(): void
    {
        // The rollup is PDF-only — admin auditing still needs the per-account
        // breakdown, which is why this is a separate code path rather than a
        // change to the underlying $commissions query.
        $admin = User::where('email', 'admin@etec.lk')->firstOrFail();
        $consultant = $this->makeConsultantWithBankDetails();
        $hidden = $consultant->hiddenAccounts()->first();

        $this->registerAndVerify($consultant, 1);
        $this->registerAndVerify($hidden, 1);
        $this->forceApproveEveryCommission();

        $consultant->refresh();
        $hidden->refresh();

        $screen = $this->actingAs($admin)->get(route('reports.payments'));
        $screen->assertOk();
        $screen->assertSeeText($consultant->scn_code);
        $screen->assertSeeText($hidden->scn_code);

        $csv = $this->actingAs($admin)->get(route('reports.payments', ['export' => 'csv']));
        $csv->assertOk();
        $body = $csv->streamedContent();
        $this->assertStringContainsString($consultant->scn_code, $body);
        $this->assertStringContainsString($hidden->scn_code, $body);
    }
}
