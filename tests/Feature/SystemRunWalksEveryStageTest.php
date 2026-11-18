<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\CompanyRevenue;
use App\Models\Consultant;
use App\Models\GroupMember;
use App\Models\User;
use App\Services\ServiceGroupFlush;
use App\Services\ServiceGroupStaging;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ONE STAGE PER RUN, CAPPED.
 *
 * ⚠️ This file asserted the OPPOSITE for one day (2026-08-24): a run walked
 * 1-to-1 → 2-to-2 → 3-to-3 in one pass and could pay 30,000. The owner reversed
 * it the next day, with the amounts written out (2026-08-25):
 *
 *   "system run ekedi service group walin SCN account ekat enne 1to1 ekedi
 *    5000i, 2to2 ekedi 10000, 3to3 ekedi 15000i — ita wadiyen release wen na...
 *    1to1 ho 2to2 nm amount eka withrk aduwela ithuru tika thiyann, 3to3 nm
 *    depaththenm 7500 gane adu wela wadi nm ithiru tika company ekat add wenwa."
 *
 * So each account settles its CURRENT stage once and waits for the next run.
 */
class SystemRunWalksEveryStageTest extends TestCase
{
    use RefreshDatabase;

    private const SLOT = 2500.0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        \App\Services\FinanceSchemaRepair::ensure();
    }

    private function owner(): Consultant
    {
        return User::where('email', 'consultant@etec.lk')->firstOrFail()->consultant;
    }

    /** $count unflushed slot rows of 2,500 on $account's internal leg $tier. */
    private function seedRows(Consultant $account, int $tier, int $count): void
    {
        $group = app(\App\Services\HiddenAccountService::class)->ensureGroup($account);

        for ($i = 0; $i < $count; $i++) {
            $student = User::factory()->create();
            $student->assignRole('student');

            $seat = GroupMember::create([
                'group_id' => $group->id,
                'user_id' => $student->id,
                'tier' => $tier,
                // ⚠️ COUNTED, NOT RANDOM. This was `random_int(1, 9000)`, and
                // (group_id, position) is UNIQUE — with a few dozen seats in one
                // group the birthday paradox made this file fail a few times in
                // a hundred runs with a constraint violation that looked like a
                // System Run bug. Caught 2026-09-24 in a full-suite run.
                'position' => ($tier * 10000) + 1 + GroupMember::where('group_id', $group->id)
                    ->where('tier', $tier)
                    ->count(),
                'commission_amount' => 0,
                'joined_at' => now()->subDays(3),
            ]);

            Commission::create([
                'consultant_id' => $account->id,
                'user_id' => $student->id,
                'group_id' => $group->id,
                'group_member_id' => $seat->id,
                'type' => 'bonus',
                'tier' => $tier,
                'amount' => self::SLOT,
                'status' => 'pending',
            ])->forceFill(['created_at' => now()->subDays(3)])->save();
        }
    }

    /** An isolated consultant, so one account's run cannot be read as another's. */
    private function loneAccount(): Consultant
    {
        $user = User::factory()->create();
        $user->assignRole('consultant');

        $account = Consultant::create([
            'user_id' => $user->id,
            'consultant_code' => 'CON'.Str::random(8),
            'referral_code' => strtoupper(Str::random(10)),
            'scn_code' => 'SCN-6'.random_int(1000, 9999),
            'status' => 'active',
        ]);

        app(\App\Services\HiddenAccountService::class)->ensureGroup($account);

        return $account->fresh();
    }

    private function atRepeatingStage(): Consultant
    {
        $account = $this->loneAccount();
        $account->forceFill(['lifetime_pair_stage' => ServiceGroupStaging::ONE_TIME_STAGES])->save();

        return $account->fresh();
    }

    /** ⚠️ 1-to-1 pays 5,000 and NO MORE, however much is sitting there. */
    public function test_the_first_stage_pays_five_thousand_and_leaves_the_rest(): void
    {
        $account = $this->loneAccount();

        $this->seedRows($account, 1, 3);   // 7,500
        $this->seedRows($account, 2, 3);   // 7,500

        $result = app(ServiceGroupFlush::class)->runAll();

        $this->assertSame(5000.0, (float) $result['payout'], '2,500 a side — one stage only.');
        $this->assertSame(0.0, (float) $result['forfeit'], 'The ramp keeps its remainder.');
        $this->assertSame(
            10000.0,
            (float) Commission::where('consultant_id', $account->id)
                ->whereNull('released_at')->where('status', '!=', 'cancelled')->sum('amount'),
            'The other 5,000 a side waits for the next run.',
        );
        $this->assertSame(1, (int) $account->fresh()->lifetime_pair_stage, 'And it moves up one stage.');
    }

    /** 2-to-2 pays 10,000 and no more. */
    public function test_the_second_stage_pays_ten_thousand(): void
    {
        $account = $this->loneAccount();
        $account->forceFill(['lifetime_pair_stage' => 1])->save();

        $this->seedRows($account, 1, 4);   // 10,000
        $this->seedRows($account, 2, 4);   // 10,000

        $result = app(ServiceGroupFlush::class)->runAll();

        $this->assertSame(10000.0, (float) $result['payout'], '5,000 a side.');
        $this->assertSame(0.0, (float) $result['forfeit'], 'Still a ramp stage — nothing is lost.');
    }

    /**
     * ⚠️ THE OWNER'S WORKED EXAMPLE. 17,500 a side at 3-to-3 pays 15,000, and
     * the remaining 10,000 a side is closed off to the company.
     */
    public function test_the_repeating_stage_pays_fifteen_thousand_and_forfeits_the_rest(): void
    {
        $account = $this->atRepeatingStage();

        $this->seedRows($account, 1, 7);   // 17,500
        $this->seedRows($account, 2, 7);   // 17,500

        $revenueBefore = (float) CompanyRevenue::sum('amount');

        $result = app(ServiceGroupFlush::class)->runAll();

        $this->assertSame(15000.0, (float) $result['payout'], '7,500 a side, and not a rupee more.');
        $this->assertSame(20000.0, (float) $result['forfeit'], '10,000 left on each side goes to the company.');
        $this->assertSame($revenueBefore + 20000.0, (float) CompanyRevenue::sum('amount'));
    }

    /** ⚠️ The preview must promise exactly what that run pays. */
    public function test_the_preview_matches_the_capped_run(): void
    {
        $account = $this->atRepeatingStage();

        $this->seedRows($account, 1, 7);
        $this->seedRows($account, 2, 7);

        $preview = app(ServiceGroupFlush::class)->preview();
        $row = collect($preview['accounts'])->firstWhere('id', $account->id);

        $this->assertNotNull($row);
        $this->assertSame(15000.0, (float) $row['payout']);
        $this->assertSame(20000.0, (float) $row['forfeit']);

        $result = app(ServiceGroupFlush::class)->runAll();

        $this->assertSame((float) $preview['payout'], (float) $result['payout'], 'Promise and payment agree.');
        $this->assertSame((float) $preview['forfeit'], (float) $result['forfeit']);
    }

    /** Below the first threshold nothing happens at all. */
    public function test_an_account_under_the_first_threshold_flushes_nothing(): void
    {
        $account = $this->loneAccount();

        $this->seedRows($account, 1, 1);   // 2,500, leg 2 empty

        $result = app(ServiceGroupFlush::class)->runAll();

        $this->assertSame(0.0, (float) $result['payout']);
        $this->assertSame(0.0, (float) $result['forfeit'], 'And nothing is forfeited from an account that never paired.');
    }

    /** ⚠️ A ramping account never loses its remainder, however lopsided. */
    public function test_a_ramping_account_never_loses_its_remainder(): void
    {
        $account = $this->loneAccount();

        $this->seedRows($account, 1, 4);   // 10,000
        $this->seedRows($account, 2, 1);   // 2,500

        $result = app(ServiceGroupFlush::class)->runAll();

        $this->assertSame(5000.0, (float) $result['payout'], 'The 1-to-1 pair.');
        $this->assertSame(0.0, (float) $result['forfeit']);
        $this->assertSame(
            7500.0,
            (float) Commission::where('consultant_id', $account->id)
                ->whereNull('released_at')->where('status', '!=', 'cancelled')->sum('amount'),
        );
    }

    /** Every rupee is paid once, forfeited once, or still waiting. */
    public function test_the_run_never_double_releases(): void
    {
        $account = $this->loneAccount();

        $this->seedRows($account, 1, 7);
        $this->seedRows($account, 2, 4);

        $total = (float) Commission::where('consultant_id', $account->id)->sum('amount');

        $paid = 0.0;
        $forfeited = 0.0;

        for ($i = 0; $i < 4; $i++) {
            $r = app(ServiceGroupFlush::class)->runAll();
            $paid += (float) $r['payout'];
            $forfeited += (float) $r['forfeit'];
        }

        $waiting = (float) Commission::where('consultant_id', $account->id)
            ->whereNull('released_at')
            ->where('status', '!=', 'cancelled')
            ->sum('amount');

        $this->assertSame($total, round($paid + $forfeited + $waiting, 2));
    }

    /**
     * ⚠️ THE OWNER'S WORKED EXAMPLE, ON SCN-00100'S REAL BALANCES (2026-08-26).
     *
     * Pairing no longer gates payout at 3-to-3, and a side is always emptied:
     * one holding the stage amount releases it, one holding less releases what
     * it has, and only a SURPLUS goes to the company. "depaththenm 0 wenwa."
     */
    /**
     * ⚠️⚠️ INVERTED 2026-08-27 — THIRD SETTING OF THIS RULE.
     *
     * This asserted the 08-26 rule: at 3-to-3 a side settles ALONE, and a side
     * holding less than 7,500 is emptied anyway ("7500 samani ho adui nm 0
     * wenwa depaththenm"). Shown what that did on a real run — SCN-00103 held
     * 37,500 against 0, released 7,500 and closed 30,000 off to the company —
     * the owner reversed it: "meke paththaka hari 7500ta wada adui nam run wen
     * na... account eka load wenn wath one na."
     *
     * So a short side now BLOCKS the whole account instead of being emptied,
     * and the money stays put. See BothSidesRequiredAtEveryStageTest.
     */
    public function test_at_the_repeating_stage_a_short_side_blocks_the_account(): void
    {
        $account = $this->atRepeatingStage();

        $this->seedRows($account, 1, 12);  // 30,000 — well past the threshold
        $this->seedRows($account, 2, 2);   // 5,000 — under it

        $revenueBefore = (float) CompanyRevenue::sum('amount');

        $result = app(ServiceGroupFlush::class)->runAll();

        $this->assertSame(0.0, (float) $result['payout'], 'Service Group 2 is short, so nothing settles.');
        $this->assertSame(0.0, (float) $result['forfeit'], 'and nothing is closed off to the company.');
        $this->assertSame($revenueBefore, (float) CompanyRevenue::sum('amount'));

        // ⚠️ The point of the rule now: the money is NOT lost, it waits.
        $this->assertSame(
            35000.0,
            (float) Commission::where('consultant_id', $account->id)
                ->whereNull('released_at')->where('status', '!=', 'cancelled')->sum('amount'),
            'Every rupee stays on the legs until the short side catches up.',
        );
    }

    /** Once both sides reach 7,500, it settles and the surplus is closed off. */
    public function test_the_repeating_stage_settles_once_both_sides_reach_it(): void
    {
        $account = $this->atRepeatingStage();

        $this->seedRows($account, 1, 12);  // 30,000
        $this->seedRows($account, 2, 3);   // 7,500 — exactly the threshold

        $revenueBefore = (float) CompanyRevenue::sum('amount');

        $result = app(ServiceGroupFlush::class)->runAll();

        $this->assertSame(15000.0, (float) $result['payout'], '7,500 a side.');
        $this->assertSame(22500.0, (float) $result['forfeit'], 'and the surplus on the heavy side goes to the company.');
        $this->assertSame($revenueBefore + 22500.0, (float) CompanyRevenue::sum('amount'));
    }

    /** ⚠️ The RAMP is unchanged — it still takes both sides to get off it. */
    public function test_the_ramp_still_requires_both_sides(): void
    {
        $account = $this->loneAccount();   // stage 0 = 1-to-1

        $this->seedRows($account, 1, 12);  // 30,000
        // Service Group 2 empty.

        $result = app(ServiceGroupFlush::class)->runAll();

        $this->assertSame(0.0, (float) $result['payout'], 'One leg alone cannot start the ramp.');
        $this->assertSame(0.0, (float) $result['forfeit'], 'And nothing is taken from it.');
        $this->assertSame(0, (int) $account->fresh()->lifetime_pair_stage);
    }

    /** The preview agrees when only one side qualifies. */
    /**
     * ⚠️ Renamed 2026-08-27: a one-sided release no longer exists — a short
     * side blocks the account. What still matters, and what this asserts, is
     * that the preview and the run agree about that refusal, since the two used
     * to carry separate copies of the readiness rule and drifted apart.
     */
    public function test_the_preview_agrees_when_a_short_side_blocks_the_account(): void
    {
        $account = $this->atRepeatingStage();

        $this->seedRows($account, 1, 12);
        $this->seedRows($account, 2, 2);

        $preview = app(ServiceGroupFlush::class)->preview();
        $result = app(ServiceGroupFlush::class)->runAll();

        $this->assertSame((float) $preview['payout'], (float) $result['payout']);
        $this->assertSame((float) $preview['forfeit'], (float) $result['forfeit']);
    }

    /**
     * ⚠️ THE WHOLE POINT, END TO END: after a 3-to-3 run the account reads ZERO
     * on the consultant's own page.
     *
     * "system run eken passe SCN account eka 0 wenn one." Both halves have to
     * hold — the engine must empty the legs, AND the page must lead with the
     * figure that empties, not with lifetime earned which never drops.
     */
    public function test_the_service_groups_page_reads_zero_after_the_run(): void
    {
        $owner = $this->owner();
        $account = $owner->hiddenAccounts()->orderBy('id')->first();

        // ⚠️ THE STAGE NOW LIVES ON THE ACCOUNT (2026-08-27).
        // This set it on the OWNER, back when the main settled the whole group.
        // The ACCOUNT is the settlement unit now, so it is the account that has
        // to be at the repeating stage for a side to be emptied — otherwise a
        // run correctly takes only one 1-to-1 and leaves the remainder.
        $account->forceFill(['lifetime_pair_stage' => ServiceGroupStaging::ONE_TIME_STAGES])->save();

        // ⚠️ BOTH sides at 7,500 (2026-08-27). This was 3 + 2, which emptied
        // the group only under the 08-26 rule where a short side was flushed
        // anyway. A short side now BLOCKS the account, so 5,000 on leg 2 would
        // leave all 12,500 sitting there and the page would never read zero.
        $this->seedRows($account, 1, 3);   // 7,500 in the owner's Service Group 1
        $this->seedRows($account, 2, 3);   // 7,500

        $before = $this->actingAs($owner->user)->get(route('consultant.groups.index'));
        $before->assertOk();

        $mainBefore = collect($before->viewData('accountRows'))->firstWhere('is_owner', true);
        $this->assertGreaterThan(0.0, (float) $mainBefore['flush']['leg1_total'], 'Precondition: money is on the group.');

        app(ServiceGroupFlush::class)->runAll(ServiceGroupFlush::defaultCutoff());

        $after = $this->actingAs($owner->user)->get(route('consultant.groups.index'));
        $after->assertOk();

        $mainAfter = collect($after->viewData('accountRows'))->firstWhere('is_owner', true);

        $this->assertSame(0.0, (float) $mainAfter['flush']['leg1_total'], 'Service Group 1 reads zero.');
        $this->assertSame(0.0, (float) $mainAfter['flush']['leg2_total'], 'And so does Service Group 2.');

        // The headline the consultant actually reads agrees.
        $this->assertSame(0.0, (float) $after->viewData('availableLeg1'));
        $this->assertSame(0.0, (float) $after->viewData('availableLeg2'));

        // Lifetime earned is unchanged — it is history, not a balance.
        $this->assertGreaterThan(0.0, (float) ($after->viewData('tierTotals')[1]->total ?? 0));
    }
}
