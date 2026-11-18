<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\Consultant;
use App\Models\Group;
use App\Models\User;
use App\Services\ServiceGroupFlush;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ⚠️ THE PREVIEW MUST PROMISE EXACTLY WHAT THE RUN PAYS.
 *
 * preview() reported $state['payout'] — threshold × 2, what the STAGE is worth
 * — while runAll() records what flush() actually released. Those differ by
 * every row that was already approved: such a row holds its place in the
 * threshold but has nothing left to pay, so the run sweeps it rather than
 * releasing it.
 *
 * Upline income is auto-approved, so that is now the ordinary case, and the two
 * screens contradicted each other outright. Reported 2026-08-22: the preview
 * promised LKR 5,000 on SCN-00143, and Recent Runs then logged "released LKR 0"
 * — while the consultant's own totals had gone up correctly all along.
 */
class SystemRunPreviewMatchesRunTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        \App\Services\FinanceSchemaRepair::ensure();
    }

    /** An account with $amount of slot income on each leg, at whatever status. */
    private function accountWithBalancedLegs(string $status, float $amount = 2500): Consultant
    {
        $user = User::factory()->create();
        $user->assignRole('consultant');

        $account = Consultant::create([
            'user_id' => $user->id,
            'consultant_code' => 'CON'.Str::random(8),
            'referral_code' => strtoupper(Str::random(10)),
            'scn_code' => 'SCN-8'.random_int(1000, 9999),
            'status' => 'active',
        ]);

        $group = app(\App\Services\HiddenAccountService::class)->ensureGroup($account);

        foreach ([1, 2] as $leg) {
            $student = User::factory()->create();
            $student->assignRole('student');

            // Slot income only counts toward a leg through its SEAT — see
            // ServiceGroupFlush::legScopeQuery(), which matches on
            // group_member_id. A commission with no seat sits on no leg.
            $seat = \App\Models\GroupMember::create([
                'group_id' => $group->id,
                'user_id' => $student->id,
                'tier' => $leg,
                'position' => $leg,
                'commission_amount' => 0,
                'joined_at' => now()->subDays(3),
            ]);

            // Dated before the cutoff — a run settles yesterday, not today.
            Commission::create([
                'consultant_id' => $account->id,
                'user_id' => $student->id,
                'group_id' => $group->id,
                'group_member_id' => $seat->id,
                'type' => 'bonus',
                'tier' => $leg,
                'amount' => $amount,
                'status' => $status,
            ])->forceFill(['created_at' => now()->subDays(3)])->save();
        }

        return $account->fresh();
    }

    private function previewFor(Consultant $account): ?array
    {
        $preview = app(ServiceGroupFlush::class)->preview();

        return collect($preview['accounts'])->firstWhere('id', $account->id);
    }

    /**
     * The reported case: everything on the legs was already approved, so the run
     * releases nothing new. The preview must say so rather than promising 5,000.
     */
    public function test_pre_approved_legs_are_previewed_as_paying_nothing_new(): void
    {
        $account = $this->accountWithBalancedLegs('approved');

        $row = $this->previewFor($account);

        $this->assertNotNull($row, 'The account is ready to flush.');
        $this->assertSame(0.0, (float) $row['payout'], 'Nothing left to release — it was paid on approval.');
        $this->assertSame(5000.0, (float) $row['stage_value'], 'The stage is still worth 5,000; the screen explains the difference.');
        $this->assertSame(5000.0, (float) $row['already_paid']);

        // ...and the run agrees.
        $result = app(ServiceGroupFlush::class)->runAll();
        $settled = collect($result['accounts'])->firstWhere('code', $account->scn_code);

        $this->assertNotNull($settled, 'It still flushes — the rows come off the legs.');
        $this->assertSame(0.0, (float) $settled['released']);
    }

    /** Ordinary case: unapproved income really is released, and the figures match. */
    public function test_pending_legs_preview_and_pay_the_same_amount(): void
    {
        $account = $this->accountWithBalancedLegs('pending');

        $previewed = (float) $this->previewFor($account)['payout'];

        $result = app(ServiceGroupFlush::class)->runAll();
        $settled = collect($result['accounts'])->firstWhere('code', $account->scn_code);

        $this->assertSame(5000.0, $previewed);
        $this->assertSame($previewed, (float) $settled['released'], 'Preview and run must never disagree.');
    }

    /** ⚠️ The headline total is the sum the run will actually pay out. */
    public function test_the_headline_total_matches_what_the_run_releases(): void
    {
        $this->accountWithBalancedLegs('pending');
        $this->accountWithBalancedLegs('approved');
        $this->accountWithBalancedLegs('pending');

        $preview = app(ServiceGroupFlush::class)->preview();
        $promised = (float) $preview['payout'];

        $result = app(ServiceGroupFlush::class)->runAll();

        // ⚠️ 15,000, NOT 10,000 (2026-08-27). Three accounts now each settle
        // their own two legs — 5,000 apiece at 1-to-1 — where the main used to
        // pair the whole group and cap the run at its own single stage.
        //
        // The pre-approved account is NOT being paid twice: approved-but-unpaid
        // rows have been releasable since 08-17 ("a force-approved row never
        // reduces the leg balance — the flush is what does that"), and the
        // at-most-once guarantees still hold (MoneySafetyInvariantsTest::
        // test_a_full_run_releases_every_row_at_most_once).
        //
        // What this test is actually for — the preview promising exactly what
        // the run pays — is asserted on the next line and is unchanged.
        $this->assertSame(15000.0, $promised, 'Three accounts, each settling 5,000 of its own.');
        $this->assertSame($promised, (float) $result['payout']);
        $this->assertSame(5000.0, (float) $preview['already_paid'], 'Reported separately so the run does not look like it did nothing.');
    }
}
