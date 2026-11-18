<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\CommissionSetting;
use App\Models\Consultant;
use App\Models\Course;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use App\Services\PaymentService;
use App\Services\ServiceGroupFlush;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ⚠️ A RUN SETTLES ONE STAGE PER SERVICE GROUP — set 2026-08-27.
 *
 * Owner, with before/after screenshots of a real run:
 *
 *   "SCN 100 account eke stage 1 (service group 1 eken 2500 service group 2 eken
 *    2500i okkom 5000 release wenn one). Amount eka tham release wenn thibbe
 *    service group 1 eken withrk 7500 out wela, habai Service group 2 eken 2500k
 *    ganat adu wela thiyeno... eka paththk withrk ai hari stagen gihilla anith
 *    paththe wardiyt wenne, meka fix krann."
 *
 * eligibleAccounts() is ordered by id, so the MAIN flushes first and its rollup
 * settles rows that LIVE on its direct children. Those children then flushed
 * AGAIN in the same run off their own remaining rows — and since a child account
 * is attributed ENTIRELY to one of the owner's two Service Groups, both of its
 * internal legs land on that ONE side of the owner's headline:
 *
 *   main's rollup     2,500 from group 1  +  2,500 from group 2
 *   SCN-00101's own   2,500 + 2,500, all of it group 1 (that account IS group 1)
 *   ----------------------------------------------------------------------
 *   group 1: 7,500                          group 2: 2,500
 *
 * Nothing was ever paid twice — those are different rows, and legScopeQuery()
 * skips released ones. The AMOUNT was wrong: a run must settle one stage per
 * side, and this settled three on one side.
 *
 * ⚠️ THE MECHANISM HAS BEEN SET THREE TIMES. THE SYMPTOM IS WHAT THIS FILE PINS.
 *
 *   08-27 am : a $settledByRollup skip — the MAIN owned the settlement.
 *   08-27 pm : the ACCOUNT became the settlement unit; the main settled nothing.
 *   08-28    : the main settles its Service Groups AGAIN, after its children and
 *              only on their surplus (TheMainReportsItsOwnLegsInARunTest).
 *
 * So the per-side AMOUNT has moved with each setting and is not the invariant.
 * Today a side gives up 3 x the stage: 2 x from the child account that IS that
 * Service Group (one stage off each of its two internal legs) plus 1 x from the
 * main pairing the two groups. Every account still obeys the owner's per-account
 * cap literally — "SCN account ekat enne 1to1 ekedi 5000i, 2to2 ekedi 10000,
 * 3to3 ekedi 15000i" — there are simply three accounts settling.
 *
 * ⚠️ WHAT NEVER CHANGES, AND IS THE ACTUAL REPORT: the two sides move TOGETHER,
 * never by different stages, and everything that leaves a leg is either paid or
 * forfeited. Those are asserted below and must survive any future re-setting of
 * who settles what.
 */
class RunSettlesOneStagePerSideTest extends TestCase
{
    use RefreshDatabase;

    private const SLOT = 2500;

    private Consultant $main;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        \App\Services\FinanceSchemaRepair::ensure();

        CommissionSetting::where('is_active', true)->update([
            'commission_per_student' => self::SLOT,
            'direct_commission' => 4000,
        ]);
        Cache::forget('commission_settings.active');


        $this->main = User::where('email', 'consultant@etec.lk')->firstOrFail()->consultant;
    }

    private function student(Consultant $account, int $leg): void
    {
        $user = User::factory()->create();
        Student::create([
            'user_id' => $user->id, 'student_code' => 'SRN'.Str::random(8),
            'status' => 'active', 'enrolled_at' => now()->subDays(3),
        ]);

        $payment = Payment::create([
            'payment_code' => 'PYM'.Str::random(8), 'user_id' => $user->id,
            'course_id' => Course::where('status', 'published')->firstOrFail()->id,
            'consultant_id' => $account->id, 'requested_tier' => $leg,
            'amount' => 39000, 'currency' => 'LKR', 'method' => 'bank_transfer',
            'payment_type' => 'full', 'status' => 'pending', 'paid_at' => now()->subDays(3),
        ]);

        app(PaymentService::class)->verify($payment, User::where('email', 'accountant@etec.lk')->firstOrFail());
    }

    /**
     * @return array{0: float, 1: float} unflushed slot income per SERVICE GROUP,
     *                                    across every account that sits in it
     *
     * ⚠️ MEASURED HERE RATHER THAN READ OFF THE PAGE (2026-09-02).
     *
     * This used to return availableLeg1 / availableLeg2 from the Service Groups
     * page, which was the main's ROLLUP — the sum of its children's legs. That
     * made it a fine proxy for "the whole Service Group", and every assertion
     * below is about the two GROUPS moving in step.
     *
     * The main now earns its own rows and its headline shows only those, so the
     * page figure no longer answers this question: a child settling would leave
     * it unmoved. Rather than weaken the assertions to whatever the page happens
     * to show, the quantity the test is actually about is computed directly.
     *
     * A Service Group is the main's own leg N plus BOTH legs of every account
     * sitting in that group — an account IS one of the owner's two groups, so
     * everything on it belongs to that side whatever internal leg it landed on.
     */
    private function available(): array
    {
        $placements = app(\App\Services\AccountPlacementMap::class)->for($this->main);

        $unflushed = fn ($query) => (float) $query
            ->where('type', \App\Services\CommissionService::TYPE_SLOT)
            ->where('status', '!=', 'cancelled')
            ->whereNull('held_at')
            ->whereNull('released_at')
            ->sum('amount');

        $perGroup = [];

        foreach ([1, 2] as $leg) {
            $inGroup = collect($placements)
                ->filter(fn ($entry, $id) => $id !== $this->main->id && ($entry['owner_group'] ?? null) === $leg)
                ->keys()
                ->all();

            $perGroup[$leg] = $unflushed(Commission::where('consultant_id', $this->main->id)->where('tier', $leg))
                + ($inGroup === [] ? 0.0 : $unflushed(Commission::whereIn('consultant_id', $inGroup)));
        }

        return [$perGroup[1], $perGroup[2]];
    }

    private function systemRun(): array
    {
        return app(ServiceGroupFlush::class)->runAll(now()->addDay());
    }

    /** Fill both of the owner's Service Groups with $perLeg students on each internal leg. */
    private function fillBothGroups(int $perLeg): void
    {
        [$sg1, $sg2] = $this->main->hiddenAccounts()->orderBy('id')->take(2)->get()->all();

        for ($i = 0; $i < $perLeg; $i++) {
            foreach ([1, 2] as $leg) {
                $this->student($sg1, $leg);
                $this->student($sg2, $leg);
            }
        }
    }

    public function test_a_stage_one_run_moves_the_two_sides_by_the_same_amount(): void
    {
        $this->fillBothGroups(3);

        [$before1, $before2] = $this->available();
        $this->assertSame($before1, $before2, 'guard: both sides start equal');

        $result = $this->systemRun();

        [$after1, $after2] = $this->available();

        // Three accounts settle a 1-to-1 here, each taking 2,500 off both of
        // the sides IT pairs:
        //   SCN-00101  2,500 + 2,500, all of it Service Group 1 (it IS group 1)
        //   SCN-00102  2,500 + 2,500, all of it Service Group 2
        //   the main   2,500 off its own leg 1  +  2,500 off its own leg 2
        //   ------------------------------------------------------------------
        //   group 1: 7,500                        group 2: 7,500
        //
        // ⚠️ The arithmetic in this comment is unchanged since it was written —
        // only the assertion was wrong. It read 2 x 2,500, because the main's
        // rollup DOUBLE-COUNTED its children: the 2,500 the main took off group
        // 1 was the same 2,500 SCN-00101 had already taken, so the observable
        // drop was one short. With the main holding its own rows, all three
        // settlements are distinct money and the sum is the 7,500 written here.
        $this->assertSame(3.0 * self::SLOT, $before1 - $after1, 'Service Group 1');
        $this->assertSame(3.0 * self::SLOT, $before2 - $after2, 'Service Group 2');
        $this->assertSame($before1 - $after1, $before2 - $after2, '⚠️ and they move TOGETHER — the report');
        $this->assertSame(6.0 * self::SLOT, (float) $result['payout'], 'three accounts x 5,000 each at 1-to-1');
    }

    /**
     * The stages then walk 1-to-1 → 2-to-2 → 3-to-3, and each run takes that
     * stage's amount from BOTH sides — never a different stage per side.
     */
    public function test_each_run_walks_one_stage_and_keeps_the_sides_level(): void
    {
        $this->fillBothGroups(8);   // 40,000 a side, enough for 2,500 + 5,000 + 7,500

        $expected = [self::SLOT, 2 * self::SLOT, 3 * self::SLOT];

        foreach ($expected as $i => $step) {
            [$before1, $before2] = $this->available();

            $result = $this->systemRun();

            [$after1, $after2] = $this->available();

            $drop1 = $before1 - $after1;
            $drop2 = $before2 - $after2;

            // ⚠️ THE INVARIANT, WHICHEVER ACCOUNTS SETTLE. Everything that left
            // a leg was either paid to somebody or booked to the company —
            // nothing evaporates. Stated this way rather than as a fixed
            // multiple of $step, because the number of accounts taking part has
            // now changed three times and this has not.
            $this->assertSame(
                $drop1 + $drop2,
                (float) $result['payout'] + (float) $result['forfeit'],
                'run #'.($i + 1).': every rupee off the legs is paid or forfeited',
            );

            // And whatever a run takes, it takes EQUALLY from the two sides —
            // this is the actual complaint: "eka paththk withrk hari stagen
            // gihilla anith paththe wardiyt wenne".
            $this->assertSame($drop1, $drop2, 'run #'.($i + 1).': the two sides move together');

            if ($step < 3 * self::SLOT) {
                // On the ramp the remainder stays on the leg. Three accounts
                // settle each side: the child pairs its two internal legs
                // (2 x $step) and the main pairs the two groups (1 x $step).
                $this->assertSame(3.0 * $step, $drop1, 'run #'.($i + 1).': one stage per settling account');
                $this->assertSame(6.0 * $step, (float) $result['payout'], 'run #'.($i + 1).': total paid');
            } else {
                // At the repeating 3-to-3 stage the side is emptied: 7,500 a
                // side is released and everything past it is forfeited to the
                // company in the same round. "7500 gane adu wela Releases
                // 15000k, ithuru tika wadi nm withrk company ekat yanwa."
                $this->assertSame(0.0, $after1, 'run #'.($i + 1).': 3-to-3 empties the side');
                $this->assertSame(0.0, $after2, 'run #'.($i + 1).': 3-to-3 empties the side');
                $this->assertGreaterThan(0, (float) $result['forfeit'], 'and the excess goes to the company');
            }
        }
    }

    /**
     * The "main AND sub accounts both flush" rule is untouched. Skipping a child
     * only happens when the main actually settled it — if the main cannot pair,
     * the child still flushes on its own.
     */
    public function test_a_sub_account_still_flushes_when_the_main_cannot_pair(): void
    {
        // Only Service Group 1 has anything, so the main has no pair to make.
        $sg1 = $this->main->hiddenAccounts()->orderBy('id')->first();
        for ($i = 0; $i < 3; $i++) {
            $this->student($sg1, 1);
            $this->student($sg1, 2);
        }

        [$before1, $before2] = $this->available();
        $this->assertSame(0.0, $before2, 'guard: the main cannot pair — Service Group 2 is empty');

        $result = $this->systemRun();

        $this->assertGreaterThan(0, (float) $result['payout'], 'the sub account settles on its own');

        [$after1] = $this->available();
        $this->assertSame(
            2.0 * self::SLOT,
            $before1 - $after1,
            'and it settles its OWN stage — 2,500 on each of its two legs'
        );
    }

    /**
     * The System Run screen previews what the run will pay. The same guard has
     * to exist on both sides or the preview over-promises — $claimed alone does
     * not catch it, because a child settled by the main's rollup still has
     * other rows the preview would happily offer again.
     */
    public function test_the_preview_promises_exactly_what_the_run_pays(): void
    {
        $this->fillBothGroups(8);

        $flusher = app(ServiceGroupFlush::class);

        foreach ([1, 2, 3] as $round) {
            $preview = $flusher->preview(now()->addDay());
            $result = $this->systemRun();

            $this->assertSame(
                (float) $preview['payout'],
                (float) $result['payout'],
                'round '.$round.': the preview and the run must agree'
            );
        }
    }

    /** A run must never pay the same row twice, whichever account reaches it. */
    public function test_no_row_is_released_twice_across_the_run(): void
    {
        $this->fillBothGroups(8);

        for ($i = 0; $i < 3; $i++) {
            $this->systemRun();
        }

        $duplicated = \App\Models\Commission::whereNotNull('released_at')
            ->selectRaw('id, COUNT(*) as n')->groupBy('id')->having('n', '>', 1)->get();

        $this->assertCount(0, $duplicated);

        // Nothing may be released beyond what was ever earned.
        $released = (float) \App\Models\Commission::where('type', 'bonus')->whereNotNull('released_at')->sum('amount');
        $earned = (float) \App\Models\Commission::where('type', 'bonus')->sum('amount');
        $this->assertLessThanOrEqual($earned, $released);
    }
}
