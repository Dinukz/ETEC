<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\CommissionSetting;
use App\Models\Consultant;
use App\Models\Course;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\Student;
use App\Models\User;
use App\Services\PaymentService;
use App\Services\ServiceGroupFlush;
use App\Services\SystemRunRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ⚠️ NOTHING TAKES AN ACCOUNT'S MONEY UNLESS THAT ACCOUNT BALANCED.
 *
 * Reported four days running, most recently 2026-08-31:
 *
 *   "Balance wen nathiw thama system run ekedi flush out wela thiyenne. Stage 3
 *    rule eka anuwa depaththem 7500k ho ita wadiyen — SG1 eke 7500+ = SG2 eke
 *    7500+ thiyenn one, ethkotati SCN 101 ekat adala group eka balance wela
 *    system run ekedi flush out wenn one. Me wagem thama SCN 102 account
 *    ekatath."
 *
 * The rule itself was already right and pinned (SystemRunCommonRuleTest). What
 * this file adds is the hunt for anything ELSE that can take money off a leg,
 * because the report kept coming back after the rule was correct — so the
 * question stopped being "is the rule right" and became "what else can empty an
 * account".
 *
 * Two things were found:
 *   1. FinanceSchemaRepair stamped released_at on every APPROVED row. Harmless
 *      when written (approval was a human step) and catastrophic since
 *      auto-approval: it would empty every leg in the database on the first
 *      request of a fresh environment.
 *   2. When a main IS settling its rollup, the accounts it empties are now
 *      named in the preview BEFORE the run, instead of being discovered after.
 */
class NothingIsEmptiedWithoutBalancingTest extends TestCase
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
            'status' => 'active', 'enrolled_at' => now()->subDays(10),
        ]);

        $payment = Payment::create([
            'payment_code' => 'PYM'.Str::random(8), 'user_id' => $user->id,
            'course_id' => Course::where('status', 'published')->firstOrFail()->id,
            'consultant_id' => $account->id, 'requested_tier' => $leg,
            'amount' => 39000, 'currency' => 'LKR', 'method' => 'bank_transfer',
            'payment_type' => 'full', 'status' => 'pending', 'paid_at' => now()->subDays(10),
        ]);

        $payment->forceFill(['created_at' => now()->subDays(10)])->save();

        app(PaymentService::class)->verify($payment, User::where('email', 'accountant@etec.lk')->firstOrFail());
    }

    /** @return array{0: Consultant, 1: Consultant} */
    private function pair(): array
    {
        return $this->main->hiddenAccounts()->orderBy('id')->take(2)->get()->all();
    }

    private function onLeg(Consultant $account): float
    {
        return (float) Commission::where('consultant_id', $account->id)
            ->where('type', 'bonus')
            ->where('status', '!=', 'cancelled')
            ->whereNull('released_at')
            ->sum('amount');
    }

    /**
     * The backfill under test, invoked directly.
     *
     * It is protected and ensure() only calls it at the moment released_at is
     * created. On a database where that column already exists — which is every
     * test, and every live site — going through ensure() runs NOTHING, so a
     * test written that way passes without touching the code it names.
     */
    private function runBackfill(): int
    {
        $method = new \ReflectionMethod(\App\Services\FinanceSchemaRepair::class, "backfillReleasedAt");
        $method->setAccessible(true);

        return (int) $method->invoke(null);
    }

    // ---- The schema repair must not empty a leg ----------------------------

    /**
     * ⚠️ THE LANDMINE. This stamped released_at on every APPROVED row, and
     * since 2026-08-25 every slot row is approved the instant it is recorded —
     * so on a fresh environment it would take every rupee off every leg on the
     * first request, with no System Run and nothing to show it happened.
     */
    public function test_the_schema_repair_never_releases_approved_income(): void
    {
        [$a101] = $this->pair();

        $this->student($a101, 1);
        $this->student($a101, 2);

        $this->assertSame(2 * self::SLOT, (int) $this->onLeg($a101), 'guard: 5,000 sitting on the legs');

        // ⚠️ CALLED DIRECTLY. ensure() only runs this when it CREATES the
        // released_at column, which already exists here — so going through
        // ensure() would exercise nothing and pass for the wrong reason.
        $this->runBackfill();

        $this->assertSame(
            2 * self::SLOT,
            (int) $this->onLeg($a101),
            'auto-approved income is ordinary money still waiting for its pair — not released',
        );
    }

    /** PAID money is different: it really has left, so it must leave the leg. */
    public function test_it_still_takes_paid_money_off_the_leg(): void
    {
        [$a101] = $this->pair();
        $this->student($a101, 1);

        Commission::where('consultant_id', $a101->id)
            ->where('type', 'bonus')
            ->update(['status' => 'paid', 'released_at' => null]);

        $this->runBackfill();

        $this->assertSame(
            0.0,
            $this->onLeg($a101),
            'paid money must never sit on a leg and be forfeited a second time',
        );
    }

    // ---- The run itself -----------------------------------------------------

    /** The reported case, under the rule in force. */
    public function test_an_unbalanced_account_at_3_to_3_survives_a_run(): void
    {
        [$a101, $a102] = $this->pair();

        foreach ([$a101, $a102] as $account) {
            $account->forceFill(['lifetime_pair_stage' => 2])->save();
        }

        for ($i = 0; $i < 12; $i++) {
            $this->student($a101, 1);       // 30,000 on one side
        }
        $this->student($a101, 2);           // 2,500 on the other — short of 7,500

        $before = $this->onLeg($a101->fresh());

        app(ServiceGroupFlush::class)->runAll(now()->addDay());

        $this->assertSame($before, $this->onLeg($a101->fresh()), 'SCN-00101 keeps every rupee');
    }

    // ---- The shape the pre-run warning used to catch -------------------------

    /**
     * ⚠️ THE EXACT SHAPE THAT USED TO DRAIN THEM.
     *
     * Both children one-sided so neither can pair, while the MAIN's two Service
     * Groups each clear 7,500 — the main would settle and take theirs. There
     * was a pre-run warning listing who was about to be emptied; it is gone
     * because it can no longer happen. This asserts the thing itself instead of
     * the warning about it.
     */
    public function test_a_balanced_main_cannot_touch_its_unbalanced_children(): void
    {
        [$a101, $a102] = $this->pair();

        foreach ([$this->main, $a101, $a102] as $account) {
            $account->forceFill(['lifetime_pair_stage' => 2])->save();
        }

        for ($i = 0; $i < 12; $i++) {
            $this->student($a101, 1);       // 30,000 / 0
        }
        for ($i = 0; $i < 6; $i++) {
            $this->student($a102, 1);       // 15,000 / 0
        }

        $before = [$this->onLeg($a101->fresh()), $this->onLeg($a102->fresh())];
        $this->assertSame([30000.0, 15000.0], $before, 'guard: 30,000 on one child and 15,000 on the other');

        $result = app(ServiceGroupFlush::class)->runAll(now()->addDay());

        // ⚠️ THE HALF THAT MUST NEVER CHANGE, AND HAS NOT.
        $this->assertSame(
            $before,
            [$this->onLeg($a101->fresh()), $this->onLeg($a102->fresh())],
            'neither child balanced, so neither loses a rupee — whatever the main holds',
        );

        // ⚠️⚠️ AND THE OTHER HALF, LIVE FOR THE FIRST TIME (2026-09-02).
        //
        // For eight reports these two were treated as an either/or, because a
        // main's "Service Group" was its children's legs added up: letting it
        // settle spent their money, and stopping it left the main frozen. Both
        // hold at once now, because the climb credited the main its OWN 2,500
        // for each of these students — 30,000 on its leg 1 and 15,000 on its
        // leg 2, distinct rows from the children's.
        //
        // The main is on the repeating 3-to-3, so it releases 7,500 a side and
        // closes the surplus to the company inside the same round — the owner's
        // own worked arithmetic: "eken 7500k adu flush out wela eka SCN account
        // ekat gihilla, ithuru ... company revenue ekt yanawa".
        //
        //   leg 1: 30,000 -> 7,500 released, 22,500 forfeited
        //   leg 2: 15,000 -> 7,500 released,  7,500 forfeited
        $main = collect($result['accounts'])->firstWhere('code', 'SCN-00100');

        $this->assertNotNull($main, 'the main settles its own balance, which it could never do before');
        $this->assertSame(15000.0, (float) $main['released'], '7,500 off each of its own two legs');
        $this->assertSame(30000.0, (float) $main['forfeited'], 'and the surplus is booked against the main itself');
        $this->assertSame(30000.0, (float) $result['forfeit'], 'and its own surplus — nobody else\'s — goes to the company');
    }

    /**
     * ⚠️ The screen has to state the rule the run actually applies. It said
     * "main account settles nothing" until 2026-09-02; now the main holds and
     * settles its own money like every other account, so the sentence that
     * matters is the per-account one — and, above all, that an unbalanced
     * account cannot be touched by anything.
     */
    public function test_the_run_screen_states_the_per_account_rule(): void
    {
        $this->actingAs(User::role('super_admin')->firstOrFail())
            ->get(route('system-run.index'))
            ->assertOk()
            ->assertSee('each SCN account on its own', false)
            ->assertSee('cannot be touched by anything', false);
    }
}
