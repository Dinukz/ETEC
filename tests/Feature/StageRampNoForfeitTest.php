<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\CompanyRevenue;
use App\Models\Consultant;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\User;
use App\Services\ServiceGroupFlush;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 1-to-1 and 2-to-2 are a RAMP, not a cut-off.
 *
 * Overflow used to be forfeited to the company at every stage, so a consultant
 * still on their first or second stage lost everything past 2,500 / 5,000 a
 * side the first time their legs balanced. Owner, 2026-08-23:
 *
 *   "1to1, 2to2 stage wala inn ayage withrk system run eken passe eyage withrk
 *    wadi amount eka company ekata add wen na, amount eka e widiytama thiyeno.
 *    habi 3 to 3 ekak nm aniwaren amount eka 7500 balance wela, 7500 wadi nm
 *    depaththama 7500 adu wela anith tika company eka revenue ekata add wenn
 *    one... eka day eketh e wage week eketh e wage."
 */
class StageRampNoForfeitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        \App\Services\FinanceSchemaRepair::ensure();
    }

    /**
     * An account carrying $perLeg slot rows of 2,500 on each leg, sitting at
     * lifetime stage $stage (0 = 1-to-1, 1 = 2-to-2, 2 = 3-to-3).
     */
    private function account(int $perLeg, int $stage): Consultant
    {
        $user = User::factory()->create();
        $user->assignRole('consultant');

        $account = Consultant::create([
            'user_id' => $user->id,
            'consultant_code' => 'CON'.Str::random(8),
            'referral_code' => strtoupper(Str::random(10)),
            'scn_code' => 'SCN-6'.random_int(1000, 9999),
            'status' => 'active',
            'lifetime_pair_stage' => $stage,
        ]);

        $group = app(\App\Services\HiddenAccountService::class)->ensureGroup($account);

        foreach ([1, 2] as $leg) {
            for ($i = 0; $i < $perLeg; $i++) {
                $student = User::factory()->create();
                $student->assignRole('student');

                $seat = GroupMember::create([
                    'group_id' => $group->id,
                    'user_id' => $student->id,
                    'tier' => $leg,
                    'position' => ($leg * 100) + $i,
                    'commission_amount' => 0,
                    'joined_at' => now()->subDays(3),
                ]);

                Commission::create([
                    'consultant_id' => $account->id,
                    'user_id' => $student->id,
                    'group_id' => $group->id,
                    'group_member_id' => $seat->id,
                    'type' => 'bonus',
                    'tier' => $leg,
                    'amount' => 2500,
                    'status' => 'pending',
                ])->forceFill(['created_at' => now()->subDays(3)])->save();
            }
        }

        return $account->fresh();
    }

    private function flush(Consultant $account): array
    {
        $group = Group::where('consultant_id', $account->id)->orderBy('id')->firstOrFail();

        return app(ServiceGroupFlush::class)->flush($account, $group, ServiceGroupFlush::defaultCutoff());
    }

    private function legBalance(Consultant $account): float
    {
        return (float) Commission::where('consultant_id', $account->id)
            ->where('status', '!=', 'cancelled')
            ->whereNull('released_at')
            ->sum('amount');
    }

    /** ⚠️ Stage 1: 3 a side, threshold 2,500 — the other 5,000 a side STAYS. */
    public function test_one_to_one_forfeits_nothing(): void
    {
        $account = $this->account(perLeg: 3, stage: 0);

        $result = $this->flush($account);

        $this->assertTrue($result['flushed']);
        $this->assertSame(5000.0, (float) $result['payout'], 'One row a side releases: 2,500 x 2.');
        $this->assertSame(0.0, (float) $result['forfeit'], 'Nothing is taken to the company.');
        $this->assertSame(0, $result['cancelled']);

        $this->assertSame(
            10000.0,
            $this->legBalance($account),
            'The other two rows a side are still sitting on the legs, ready for 2-to-2.',
        );
        $this->assertSame(0, CompanyRevenue::where('source_type', 'group_overflow')->count());
    }

    /** ⚠️ Stage 2: same — 5,000 a side releases, the rest waits. */
    public function test_two_to_two_forfeits_nothing(): void
    {
        $account = $this->account(perLeg: 4, stage: 1);

        $result = $this->flush($account);

        $this->assertSame(10000.0, (float) $result['payout'], 'Two rows a side: 5,000 x 2.');
        $this->assertSame(0.0, (float) $result['forfeit']);
        $this->assertSame(10000.0, $this->legBalance($account), 'Two rows a side remain.');
    }

    /** ⚠️ Stage 3 is unchanged — 7,500 a side pays, the rest is forfeited. */
    public function test_three_to_three_still_forfeits_the_overflow(): void
    {
        $account = $this->account(perLeg: 4, stage: 2);

        $result = $this->flush($account);

        $this->assertSame(15000.0, (float) $result['payout'], '7,500 on each side.');
        $this->assertSame(5000.0, (float) $result['forfeit'], 'The 4th row on each side goes to the company.');
        $this->assertSame(2, $result['cancelled']);

        $this->assertSame(0.0, $this->legBalance($account), 'The legs are cleared — "7500ta wadi nm withrk 0 wenwa".');
        $this->assertSame(5000.0, (float) CompanyRevenue::where('source_type', 'group_overflow')->sum('amount'));
    }

    /** Exactly on the threshold at 3-to-3: everything pays, nothing forfeits. */
    public function test_three_to_three_on_the_nose_forfeits_nothing(): void
    {
        $account = $this->account(perLeg: 3, stage: 2);

        $result = $this->flush($account);

        $this->assertSame(15000.0, (float) $result['payout']);
        $this->assertSame(0.0, (float) $result['forfeit']);
        $this->assertSame(0.0, $this->legBalance($account));
    }

    /** The ramp stages still advance, so a third flush lands on 3-to-3. */
    public function test_the_stage_still_advances_through_the_ramp(): void
    {
        $account = $this->account(perLeg: 3, stage: 0);

        $this->flush($account);
        $this->assertSame(1, (int) $account->fresh()->lifetime_pair_stage, '1-to-1 → 2-to-2.');

        $this->flush($account->fresh());
        $this->assertSame(2, (int) $account->fresh()->lifetime_pair_stage, '2-to-2 → 3-to-3.');
    }

    /** ⚠️ The preview must promise the same zero forfeit the run performs. */
    public function test_the_preview_reports_no_forfeit_on_a_ramp_stage(): void
    {
        $account = $this->account(perLeg: 3, stage: 0);

        $preview = app(ServiceGroupFlush::class)->preview();
        $row = collect($preview['accounts'])->firstWhere('id', $account->id);

        $this->assertNotNull($row);
        $this->assertSame(0.0, (float) $row['forfeit']);
        $this->assertSame(0.0, (float) $preview['forfeit']);
    }
}
