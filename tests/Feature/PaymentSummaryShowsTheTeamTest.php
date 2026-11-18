<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\Consultant;
use App\Models\Student;
use App\Models\SystemRun;
use App\Models\User;
use App\Services\HiddenAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Payment Summary for a consultant shows their WHOLE TEAM, with SRN, SCN and the
 * count of each amount (2026-10-25). Owner: "consultant ge eyta aithi downline
 * line ekem hamogem income tika penn habai eyage group eke withri penwann one
 * 2500 and 4000 and 5000 ewidiyt enn one SRN and SCN numbers ekk" — and chose
 * counts. Nobody outside their tree, and their bank deposit list stays their own.
 */
class PaymentSummaryShowsTheTeamTest extends TestCase
{
    use RefreshDatabase;

    private Consultant $me;

    private Consultant $downline;

    private Consultant $outsider;

    private string $today;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        \App\Services\FinanceSchemaRepair::ensure();

        // The live standard direct (the seed's default is 3,000).
        \App\Models\CommissionSetting::where('is_active', true)->update(['direct_commission' => 4000, 'commission_per_student' => 2500]);
        \Illuminate\Support\Facades\Cache::forget('commission_settings.active');

        $this->today =Carbon::now('Asia/Colombo')->toDateString();

        $meUser = User::where('email', 'consultant@etec.lk')->firstOrFail();
        $this->grantConsultantPages($meUser, ['payment_summary']);
        $this->me = $meUser->consultant;

        $this->downline = $this->person('SCN-40100', 'SRN-40100', $this->leg($this->me, 1));
        $this->outsider = $this->person('SCN-49900', 'SRN-49900', null);

        // Me: one 4,000.
        $this->direct($this->me, 4000);

        // The downline: 2 × 4,000 and 1 × 5,000 (Elliott Wave) on their main,
        // and a run that released 10,000 (4 × 2,500) on their Service Group 1.
        $this->direct($this->downline, 4000);
        $this->direct($this->downline, 4000);
        $this->direct($this->downline, 5000);
        $this->recordRun($this->leg($this->downline, 1), 10000);

        // Someone outside the tree.
        $this->direct($this->outsider, 4000);
    }

    private function person(string $scn, string $srn, ?Consultant $under): Consultant
    {
        $user = User::factory()->create(['name' => 'Person '.$scn]);
        $user->syncRoles(['consultant']);
        Student::create(['user_id' => $user->id, 'student_code' => $srn, 'status' => 'active', 'enrolled_at' => now()]);

        $consultant = Consultant::create([
            'user_id' => $user->id, 'parent_consultant_id' => $under?->id,
            'consultant_code' => 'CON'.Str::random(8), 'scn_code' => $scn,
            'referral_code' => strtoupper(Str::random(10)), 'status' => 'active',
        ]);

        app(HiddenAccountService::class)->ensureFor($consultant);

        return $consultant->fresh();
    }

    private function leg(Consultant $person, int $n): Consultant
    {
        return $person->hiddenAccounts()->orderBy('id')->get()[$n - 1];
    }

    private function direct(Consultant $account, float $amount): void
    {
        Commission::create([
            'consultant_id' => $account->id, 'user_id' => User::factory()->create()->id,
            'amount' => $amount, 'slots' => 1, 'tier' => 1, 'type' => 'referral', 'status' => 'approved',
            'approved_at' => now(), 'earned_at' => Carbon::parse($this->today.' 10:00', 'Asia/Colombo'),
        ]);
    }

    private function recordRun(Consultant $account, float $released): void
    {
        SystemRun::create([
            'run_by' => User::where('email', 'superadmin@etec.lk')->value('id'),
            'cutoff_at' => $this->today.' 23:59:59',
            'accounts_flushed' => 1, 'total_released' => $released, 'total_forfeited' => 0,
            'accounts' => json_encode([[
                'account_id' => $account->id, 'code' => $account->scn_code,
                'released' => $released, 'forfeited' => 0, 'released_ids' => [],
            ]]),
        ]);
    }

    private function report(User $viewer, array $query = [])
    {
        return $this->actingAs($viewer)->get(route('reports.payments', ['from' => $this->today, 'to' => $this->today] + $query));
    }

    private function row($rows, Consultant $account): ?object
    {
        return $rows->firstWhere('consultant_id', (int) $account->id);
    }

    public function test_a_consultant_sees_everyone_below_them_with_srn_scn_and_counts(): void
    {
        $response = $this->report($this->me->user)->assertOk();
        $rows = $response->viewData('commissions');

        $main = $this->row($rows, $this->downline);
        $this->assertNotNull($main, 'the downline is on the consultant\'s report');
        $this->assertSame('SRN-40100', $main->srn);
        $this->assertSame(2, $main->direct_counts['4000'] ?? 0, 'two 4,000s');
        $this->assertSame(1, $main->direct_counts['5000'] ?? 0, 'one 5,000');

        $this->assertEquals(4, $this->row($rows, $this->leg($this->downline, 1))->slot_count, '10,000 from the run = four 2,500s');

        $response->assertSee('SRN-40100')->assertSee('SCN-40100')
            ->assertSee('2,500 ×', false)->assertSee('4,000 ×', false)->assertSee('5,000 ×', false);
    }

    public function test_nobody_outside_their_tree(): void
    {
        $rows = $this->report($this->me->user)->viewData('commissions');

        $this->assertNull($this->row($rows, $this->outsider));
        $this->report($this->me->user)->assertDontSee('SCN-49900');
    }

    public function test_their_own_accounts_come_first_then_the_team(): void
    {
        $rows = $this->report($this->me->user)->viewData('commissions')->values();

        $this->assertSame((int) $this->me->id, (int) $rows->first()->consultant_id);
        $this->assertSame(0, $rows->first()->level);
        $this->assertSame(1, $this->row($rows, $this->downline)->level);
    }

    public function test_the_cards_split_their_own_income_from_the_team(): void
    {
        $totals = $this->report($this->me->user)->viewData('totals');

        $this->assertSame(4000.0, $totals['commissions'], 'their own: one 4,000');
        $this->assertSame(27000.0, $totals['team'], '4,000 + 8,000 + 5,000 + 10,000');
    }

    public function test_the_bank_deposit_list_is_still_only_their_own(): void
    {
        $this->report($this->me->user, ['export' => 'pdf'])
            ->assertOk()
            ->assertSee((string) $this->me->scn_code)
            ->assertDontSee('SCN-40100')
            ->assertDontSee('Person SCN-40100');
    }

    public function test_the_csv_carries_the_team_with_counts(): void
    {
        $csv = $this->report($this->me->user, ['export' => 'csv'])->streamedContent();

        $this->assertStringContainsString('SRN,"Marketer Code","Marketer Name","2,500 count","4,000 count","5,000 count"', $csv);
        $this->assertStringContainsString('SRN-40100,SCN-40100', $csv);
        $this->assertStringNotContainsString('SCN-49900', $csv);
    }

    public function test_the_downline_sees_only_their_own_tree_not_the_upline(): void
    {
        $this->grantConsultantPages($this->downline->user, ['payment_summary']);

        $rows = $this->report($this->downline->user)->viewData('commissions');

        $this->assertNotNull($this->row($rows, $this->downline));
        $this->assertNull($this->row($rows, $this->me), 'the upline is not below them');
    }

    public function test_staff_still_see_everyone(): void
    {
        $rows = $this->report(User::where('email', 'admin@etec.lk')->firstOrFail())->assertOk()->viewData('commissions');

        $this->assertNotNull($this->row($rows, $this->outsider));
        $this->assertNotNull($this->row($rows, $this->downline));
        $this->assertNull($this->report(User::where('email', 'admin@etec.lk')->firstOrFail())->viewData('totals')['team']);
    }
}
