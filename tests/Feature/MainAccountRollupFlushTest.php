<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\Consultant;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\User;
use App\Services\ServiceGroupFlush;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A person's MAIN account flushes their whole Service Group.
 *
 * Its own two legs are almost always empty — climbing income lands on the
 * specific placement account (the 2026-08-21 rule) — so the main never
 * balanced and never appeared in a System Run, while the page above it read
 * "LKR 7,500 on the leg, not yet flushed". Owner, 2026-08-24:
 *
 *   "main eke 7500 wadi gank thiyeno eth me system run ekata giyam en na...
 *    main eketh 7500 balance wenn, 1to1 2to2 3to3 ewa calculate wenn one."
 *
 * The placement accounts still flush their own internal legs as well, which is
 * what the de-duplication in preview() exists for.
 */
class MainAccountRollupFlushTest extends TestCase
{
    use RefreshDatabase;

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

    /** Put $count unflushed slot rows of 2,500 on $account's internal leg $tier. */
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
                'position' => ($tier * 1000) + $i + random_int(1, 900),
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
                'amount' => 2500,
                'status' => 'pending',
            ])->forceFill(['created_at' => now()->subDays(3)])->save();
        }
    }

    /**
     * Open an account that the 12-student unlock chain would otherwise gate.
     *
     * ⚠️ Needed since 2026-08-24: the main rollup honours each account own
     * lock, so seeding a locked account and expecting the main to flush it
     * would be testing the bug that fix removed.
     */
    private function open(Consultant $account): Consultant
    {
        $account->forceFill(["manual_lock_override" => Consultant::LOCK_FORCED_OPEN])->save();

        return $account->fresh();
    }

    private function stateOf(Consultant $account): array
    {
        $group = Group::where('consultant_id', $account->id)->orderBy('id')->firstOrFail();

        return app(ServiceGroupFlush::class)->state($account, $group, ServiceGroupFlush::defaultCutoff());
    }

    /**
     * ⚠️ THE REPORTED CASE. Money on the sub accounts, nothing on the main's own
     * legs — the main must still balance and flush.
     *
     * ⚠️⚠️ INVERTED TWICE. Read the dates before trusting either version.
     *
     *   08-24  the main FLUSHES its rolled-up Service Groups (original).
     *   08-27  inverted — the ACCOUNT became the settlement unit and the main
     *          settled nothing, after the rollup left a child one-sided
     *          (2,500/27,500 -> 0/27,500) and let an account with an empty side
     *          pay out (12,500/0 -> 10,000/0): "eka group ehek wath stage samana
     *          nathiw system run yann ba."
     *   08-28  RESTORED, because the main held 47,500 / 35,000 and never ran.
     *   08-30  REMOVED AGAIN, FINAL. Two days of the main settling produced five
     *          separate money faults in one run — SCN-00101 at 27,500/2,500 lost
     *          7,500 off one side only, SCN-00102 released with an empty side,
     *          SCN-00104 gave up 2,500 from one leg and nothing from the other,
     *          SCN-00109 lost 5,000 a side instead of 2,500.
     *
     *   09-02  THE ARGUMENT ITSELF WAS DISSOLVED, and this is the entry to read.
     *
     * ⚠️ THE ARITHMETIC THAT DROVE ALL SIX FLIPS: a main's "Service Group 1" was
     * not money of its own, it was the SUM of one child's two legs. So "the main
     * settles its Service Groups" and "no account moves unless its own two sides
     * balance" were the same rupees counted twice, and could not both hold. Every
     * flip was a choice of which half to break — settle, and a child is drained
     * one-sided; do not settle, and the main freezes showing a balance it cannot
     * move ("main account eke amount eka yanne na").
     *
     * ⚠️ THE PREMISE WAS THE BUG. The main was the ONE account in the model that
     * earned nothing, purely because the climb stopped one hop short of it. Give
     * it its own rows and there is no rollup, no double count, and no dilemma:
     * the main is an ordinary settlement unit that pairs its own two legs and
     * touches nothing belonging to a child. Chosen by the owner on 2026-09-02
     * when shown the cost of each option.
     *
     * So this test is now the opposite of its original self in BOTH halves — the
     * main reports its own money AND settles it — and the rollup it was named
     * for no longer exists.
     */
    public function test_the_main_settles_its_own_money_and_never_a_childs(): void
    {
        $owner = $this->owner();
        $accounts = $owner->hiddenAccounts()->orderBy('id')->get();

        // Money sitting on the two placement accounts, and nothing seeded on the
        // main. Under the old rollup this WAS the main's displayed balance.
        $this->seedRows($accounts[0], 1, 3);                // 7,500 on SCN-00101
        $this->seedRows($this->open($accounts[1]), 1, 4);   // 10,000 on SCN-00102

        $state = $this->stateOf($owner->fresh());

        $this->assertSame(0.0, $state['leg1_total'], "A child's money is not the main's, on any screen.");
        $this->assertSame(0.0, $state['leg2_total'], 'Not displayed...');
        $this->assertSame(0.0, $state['leg1'], '...and not settled either.');

        $result = app(ServiceGroupFlush::class)->flush(
            $owner->fresh(),
            Group::where('consultant_id', $owner->id)->orderBy('id')->firstOrFail(),
            ServiceGroupFlush::defaultCutoff(),
        );

        $this->assertSame(
            0.0,
            (float) $result['payout'],
            'The main has no rows of its own here, so it pays nothing — the children settle themselves.',
        );
    }

    /**
     * ⚠️ THE OTHER HALF, AND THE ONE THE OWNER KEPT REPORTING: once the main
     * holds its own money on both legs, the run must actually move it.
     *
     * "System run ekedi main account eke stage anuw amount eka balance wela
     * amount eka yanne na" — reported across eight rounds. Every previous fix
     * satisfied this by letting the main reach into its children, which broke
     * the test above. Both hold at once now, and that is the whole point of the
     * 2026-09-02 change: the two tests are a pair and must be read together.
     */
    public function test_the_main_runs_once_its_own_two_legs_balance(): void
    {
        $owner = $this->owner();

        $this->seedRows($owner, 1, 1);   // 2,500 on the main's own leg 1
        $this->seedRows($owner, 2, 1);   // 2,500 on the main's own leg 2

        $state = $this->stateOf($owner->fresh());

        $this->assertSame(2500.0, $state['leg1'], 'Its own leg 1...');
        $this->assertSame(2500.0, $state['leg2'], '...and its own leg 2.');
        $this->assertTrue($state['can_flush'], 'Balanced at 1-to-1, so the run must offer it.');

        $result = app(ServiceGroupFlush::class)->flush(
            $owner->fresh(),
            Group::where('consultant_id', $owner->id)->orderBy('id')->firstOrFail(),
            ServiceGroupFlush::defaultCutoff(),
        );

        $this->assertTrue($result['flushed'], 'The main account settles like any other account.');
        $this->assertSame(
            5000.0,
            (float) $result['payout'],
            '1-to-1 releases 2,500 from EACH side — the arithmetic the owner wrote out.',
        );
    }

    /** ⚠️ 1-to-1 → 2-to-2 → 3-to-3 run on the main, as asked. */
    /**
     * ⚠️ RE-AIMED AT THE ACCOUNT 2026-08-27. The ladder is unchanged — 1-to-1,
     * 2-to-2, 3-to-3 — but the thing that walks it is the ACCOUNT, not the
     * main's rolled-up group. See the inverted test above for why.
     *
     * Both legs are seeded now, because an account only settles when its two
     * sides can pair. Seeding one leg used to be enough only because the main
     * paired two different ACCOUNTS against each other.
     */
    public function test_an_account_advances_through_the_stages(): void
    {
        $owner = $this->owner();
        $account = $this->open($owner->hiddenAccounts()->orderBy('id')->first());

        $this->seedRows($account, 1, 6);
        $this->seedRows($account, 2, 6);

        $group = Group::where('consultant_id', $account->id)->orderBy('id')->firstOrFail();
        $flusher = app(ServiceGroupFlush::class);
        $cutoff = ServiceGroupFlush::defaultCutoff();

        $first = $flusher->flush($account->fresh(), $group, $cutoff);
        $this->assertSame(5000.0, (float) $first['payout'], '1-to-1 → 2,500 a side.');

        $second = $flusher->flush($account->fresh(), $group, $cutoff);
        $this->assertSame(10000.0, (float) $second['payout'], '2-to-2 → 5,000 a side.');

        $third = $flusher->flush($account->fresh(), $group, $cutoff);
        $this->assertSame(15000.0, (float) $third['payout'], '3-to-3 → 7,500 a side.');
    }

    /**
     * ⚠️ THE DOUBLE-COUNT GUARD. The main and the account the money sits on both
     * flush, so the preview must not offer the same row under each of them.
     */
    public function test_the_preview_never_offers_the_same_row_twice(): void
    {
        $owner = $this->owner();
        $accounts = $owner->hiddenAccounts()->orderBy('id')->get();

        // Balanced INSIDE SCN-00152 as well, so both pairings can claim it.
        $this->seedRows($accounts[0], 1, 2);
        $this->seedRows($accounts[0], 2, 2);
        $this->seedRows($this->open($accounts[1]), 1, 2);

        $preview = app(ServiceGroupFlush::class)->preview();

        $result = app(ServiceGroupFlush::class)->runAll();

        $this->assertSame(
            (float) $preview['payout'],
            (float) $result['payout'],
            'What the screen promised is exactly what the run paid.',
        );
    }

    /** A placement account still flushes its own internal legs. */
    public function test_a_placement_account_still_flushes_on_its_own(): void
    {
        $owner = $this->owner();
        $sub = $owner->hiddenAccounts()->orderBy('id')->first();

        $this->seedRows($sub, 1, 1);
        $this->seedRows($sub, 2, 1);

        $state = $this->stateOf($sub->fresh());

        $this->assertSame(2500.0, $state['leg1'], "Its OWN internal leg 1, not the owner's group.");
        $this->assertSame(2500.0, $state['leg2']);
        $this->assertTrue($state['can_flush']);
    }

    /** An account placed in neither group contributes to neither rolled-up side. */
    public function test_an_unplaced_account_is_not_rolled_into_either_group(): void
    {
        $owner = $this->owner();

        // addAccountSet() with no payment behind it → placement unknown.
        app(\App\Services\HiddenAccountService::class)->addAccountSet($owner);
        $orphan = $owner->fresh()->hiddenAccounts()->orderBy('id')->get()[2];

        $this->seedRows($orphan, 1, 4);

        $state = $this->stateOf($owner->fresh());

        $this->assertSame(0.0, $state['leg1'], 'Unknown placement belongs to neither side.');
        $this->assertSame(0.0, $state['leg2']);
    }
}
