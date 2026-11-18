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
use App\Services\ServiceGroupStaging;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ⚠️ A LOCKED ACCOUNT STILL FEEDS THE MAIN — set 2026-08-27.
 *
 * Owner: "course buy krla 9th account eka buy krla eke service group eke student
 * la add kraddi main account eka 2500 add wen na." Then, shown exactly what the
 * lock was doing and asked whether the rule should change:
 *
 *   "Meke lock eka hari eth e account walin hamben ewa mekat add krnn e widiyt
 *    hadann. main eka flush out wenn one system run ekedi."
 *   = the lock itself is right, BUT what those accounts earn must still be added
 *     to the main, and the MAIN must flush it out at the System Run.
 *
 * So the two locks deliberately mean different things now:
 *   - the automatic 12-STUDENT CHAIN gates the ACCOUNT's own payout only. Its
 *     income still rolls up, and the main settles it.
 *   - an ADMIN FORCE-SHUT drops the money from the rollup as well, so a closure
 *     cannot be routed around through the main.
 *
 * SECOND setting of the rollup rule — 08-24 made the main honour every lock.
 * Do not flip it back without the same explicit, numbers-based confirmation.
 */
class LockedGroupIsStillVisibleTest extends TestCase
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

    private function verify(User $buyer, Consultant $account, ?int $leg): void
    {
        $payment = Payment::create([
            'payment_code' => 'PYM'.Str::random(8), 'user_id' => $buyer->id,
            'course_id' => Course::where('status', 'published')->firstOrFail()->id,
            'consultant_id' => $account->id, 'requested_tier' => $leg,
            'amount' => 39000, 'currency' => 'LKR', 'method' => 'bank_transfer',
            'payment_type' => 'full', 'status' => 'pending', 'paid_at' => now(),
        ]);

        app(PaymentService::class)->verify($payment, User::where('email', 'accountant@etec.lk')->firstOrFail());
    }

    private function student(Consultant $account, ?int $leg): void
    {
        $user = User::factory()->create();
        Student::create([
            'user_id' => $user->id, 'student_code' => 'SRN'.Str::random(8),
            'status' => 'active', 'enrolled_at' => now(),
        ]);

        $this->verify($user, $account, $leg);
    }

    /** @return \Illuminate\Support\Collection<int, Consultant> the main, then every account it owns */
    private function accounts(): \Illuminate\Support\Collection
    {
        return Consultant::with('owner')
            ->where('id', $this->main->id)
            ->orWhere('owner_consultant_id', $this->main->id)
            ->orderBy('id')->get();
    }

    /** Buy two courses, so accounts 4..9 exist and the 9th is a bought set-leg. */
    private function buyUpToTheNinthAccount(): Consultant
    {
        $hidden = fn () => $this->main->fresh()->hiddenAccounts()->orderBy('id')->get();

        $this->verify($this->main->user, $hidden()[0], 1);   // set 00103/00104/00105
        $this->verify($this->main->user, $hidden()[1], 2);   // set 00106/00107/00108

        $ninth = $this->accounts()[8];
        $this->assertSame('SCN-00108', $ninth->scn_code, 'guard: the 9th account is the one under test');

        return $ninth;
    }

    private function page(): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->main->user)->get(route('consultant.groups.index'));
    }

    private function sg2(): Consultant
    {
        return Consultant::with('owner')->where('scn_code', 'SCN-00102')->firstOrFail();
    }

    public function test_a_students_2500_on_the_ninth_account_reaches_the_owners_service_group(): void
    {
        $ninth = $this->buyUpToTheNinthAccount();
        Commission::query()->delete();

        $this->student($ninth, 1);

        $this->assertSame(
            (float) self::SLOT,
            (float) Commission::where('consultant_id', $this->sg2()->id)->where('type', 'bonus')->sum('amount'),
            'the climb out of the bought account reaches SCN-00102, which IS Service Group 2'
        );
    }

    /** The headline figure the owner reads must include it, even though the account is locked. */
    public function test_the_main_counts_a_locked_accounts_income(): void
    {
        $ninth = $this->buyUpToTheNinthAccount();
        $this->student($ninth, 1);
        $this->student($ninth, 2);

        $this->assertFalse(
            app(ServiceGroupStaging::class)->isUnlocked($this->sg2()),
            'guard: SCN-00102 is locked — that is the whole point of this test'
        );

        $page = $this->page();

        $this->assertGreaterThan(
            0,
            (float) $page->viewData('availableLeg2'),
            'a locked account still feeds the main: "e account walin hamben ewa mekat add krnn"'
        );

        $this->assertSame(
            0.0,
            (float) $page->viewData('lockedLeg2'),
            'and nothing is reported as held, because nothing was dropped from the figure'
        );
    }

    /**
     * The account is still REPORTED as chain-locked — "meke lock eka hari".
     *
     * ⚠️ Renamed 2026-08-27: since the settlement unit became the account, the
     * chain lock no longer withholds a payout (only an admin force-shut does),
     * so this checks what the lock still means — the state the screens show and
     * the account it names as the blocker — not that money is refused.
     */
    public function test_the_account_still_reports_itself_as_chain_locked(): void
    {
        $ninth = $this->buyUpToTheNinthAccount();
        $this->student($ninth, 1);
        $this->student($ninth, 2);

        $account = $this->sg2();
        $group = \App\Models\Group::where('consultant_id', $account->id)->orderBy('id')->first();
        $state = app(ServiceGroupFlush::class)->state($account, $group);

        $this->assertTrue($state['locked'], 'the 12-student chain still gates the account itself');
        $this->assertSame('SCN-00101', $state['blocked_by']);
    }

    /**
     * ⚠️ REVERSED ON 2026-09-25 — A CLOSED ACCOUNT NO LONGER STARVES ITS MAIN.
     *
     * From 2026-09-02 this asserted the opposite: that a force-shut direct child
     * stopped the climb dead, so the main never earned its own row for a student
     * placed below it. The reasoning was that money on an account somebody
     * deliberately closed "must not reach a payout by ANY route".
     *
     * The owner reported what that does in practice, from his own screen:
     * SCN-00748 reading "SG1 LKR 7,500 · SG2 LKR 0 · LKR 2,500 held on
     * SCN-00750 — closed by an administrator". A main with one side at zero can
     * never balance, so closing ONE leg froze the 7,500 on the other leg too:
     * *"lock nisa point cal wen nathiw hira wela, eka wardi."* On the 21 Sep
     * data, 70 accounts are force-shut and 9 mains were missing LKR 42,500 in
     * rows that were never written.
     *
     * So the lock keeps its real meaning — the shut account cannot flush its own
     * money — and stops meaning something it was never asked to mean, which is
     * that everyone above it is punished too.
     */
    public function test_a_force_shut_account_no_longer_blocks_the_climb(): void
    {
        $ninth = $this->buyUpToTheNinthAccount();

        // Not fillable — an admin's override is set through the dashboard, never
        // by mass assignment.
        $this->sg2()->forceFill(['manual_lock_override' => Consultant::LOCK_FORCED_SHUT])->save();

        $this->student($ninth, 1);
        $this->student($ninth, 2);

        $page = $this->page();

        // ⚠️ THE REVERSAL, IN ONE ASSERTION. The main earns its own rows again.
        $this->assertGreaterThan(
            0.0,
            (float) $page->viewData('availableLeg2'),
            'the climb passes through a closed account instead of dying at it',
        );

        // And the account that was closed still holds its own money: the page
        // names it rather than letting it read as lost.
        $this->assertGreaterThan(0.0, (float) $page->viewData('lockedLeg2'));
        $this->assertSame('SCN-00102', $page->viewData('lockedReason')[2]['code']);
        $page->assertSee('closed by an administrator', false);
    }

    /**
     * ⚠️ AND THE LOCK STILL LOCKS. Closing an account has to keep stopping THAT
     * account from settling, or the override is decoration — which is the one
     * thing the 2026-09-02 rule was right about.
     */
    public function test_a_force_shut_account_still_cannot_flush_itself(): void
    {
        $this->buyUpToTheNinthAccount();

        $this->sg2()->forceFill(['manual_lock_override' => Consultant::LOCK_FORCED_SHUT])->save();

        $this->assertFalse(
            app(ServiceGroupStaging::class)->isUnlocked($this->sg2()->fresh()),
            'a closed account is never unlocked, whatever its students do',
        );

        $flush = app(\App\Services\ServiceGroupFlush::class);
        $group = \App\Models\Group::where('consultant_id', $this->sg2()->id)->orderBy('id')->first();
        $state = $flush->state($this->sg2()->fresh(), $group);

        $this->assertTrue((bool) $state['locked'], 'and the run still refuses it');
        $this->assertTrue((bool) $state['locked_manually'], 'for the admin reason, not the 12-student one');
    }

    /**
     * ⚠️ THE OTHER HALF OF THE SAME RULE — the 12-student chain lock must NOT
     * stop the climb, and that distinction is the whole point of the two locks
     * meaning different things.
     *
     * Written 2026-09-02 alongside the force-shut guard above, because that
     * guard sits in the climb where a slip would silently starve every
     * chain-locked account of its route to the main — the exact bug reported as
     * "9th account eka buy krla eke service group eke student la add kraddi main
     * account eka 2500 add wen na". Nothing else pins the two apart at the point
     * of enforcement.
     */
    public function test_the_twelve_student_chain_lock_does_not_stop_the_climb(): void
    {
        $ninth = $this->buyUpToTheNinthAccount();

        $this->assertFalse(
            app(ServiceGroupStaging::class)->isUnlocked($this->sg2()),
            'guard: SCN-00102 is chain-locked, with no admin override in sight',
        );

        $this->student($ninth, 1);

        $this->assertSame(
            (float) self::SLOT,
            (float) $this->page()->viewData('availableLeg2'),
            'a chain lock gates the ACCOUNT\'s own payout, never what it sends up',
        );
    }

    /**
     * "main eka flush out wenn one system run ekedi" — the half of the owner's
     * instruction that actually moves money. Earning it is not enough; the run
     * has to settle it.
     *
     * ⚠️ RE-AIMED 2026-08-27. The main no longer settles other accounts (the
     * owner made the ACCOUNT the settlement unit), so the requirement is met a
     * different way: the 12-student chain no longer BLOCKS a flush, and the
     * locked account releases its own income. Both sides are seeded now,
     * because — like every account — it only settles when its two sides pair.
     *
     * ⚠️ RE-AIMED AGAIN 2026-08-28. The main settles its Service Groups once
     * more, so BOTH routes now run in the same pass and the chain-locked
     * account gives up three stages, not two: its own 1-to-1 (2,500 off each of
     * its two internal legs) and then 2,500 more when the main pairs the two
     * groups. Both halves of the owner's instruction are live at once, which is
     * what "main paran logic e widiytam thiyala" asked for.
     */
    public function test_the_system_run_settles_a_chain_locked_accounts_income(): void
    {
        $ninth = $this->buyUpToTheNinthAccount();

        // Climbed income arrives on SCN-00102 through the bought set, and the
        // other side is filled directly so the account can actually pair.
        $this->student($ninth, 1);
        $this->student($this->sg2(), 1);
        $this->student($this->sg2(), 2);

        $unreleased = fn () => (float) Commission::where('consultant_id', $this->sg2()->id)
            ->where('type', 'bonus')->whereNull('released_at')->sum('amount');

        $before = $unreleased();
        $this->assertGreaterThan(0, $before, 'guard: the locked account is holding unreleased income');

        app(ServiceGroupFlush::class)->runAll(now()->addDay());

        // One stage per settling ACCOUNT, and never everything it is holding:
        //   2 x 2,500  SCN-00102's own 1-to-1, one stage off each of its legs
        //   1 x 2,500  the main pairing Service Group 2 (which is this account)
        $this->assertSame(
            $before - (2 * self::SLOT),
            $unreleased(),
            'a 1-to-1 comes off both sides of SCN-00102, even though it is chain-locked'
        );

        $this->assertGreaterThan(
            0,
            (float) Commission::where('consultant_id', $this->sg2()->id)
                ->where('type', 'bonus')->whereNotNull('released_at')->sum('amount'),
            'and that money is released by the run — "system run ekedi"'
        );
    }

    /** The same rupees must not be released twice once the lock later lifts. */
    public function test_the_account_cannot_pay_the_same_money_again_after_it_unlocks(): void
    {
        $ninth = $this->buyUpToTheNinthAccount();

        // Both sides seeded — an account settles only when its two legs pair.
        $this->student($ninth, 1);
        $this->student($this->sg2(), 1);
        $this->student($this->sg2(), 2);

        app(ServiceGroupFlush::class)->runAll(now()->addDay());

        $releasedAfterMainRun = Commission::where('consultant_id', $this->sg2()->id)
            ->whereNotNull('released_at')->pluck('id')->sort()->values()->all();
        $this->assertNotEmpty($releasedAfterMainRun);

        // Lift the lock the honest way, then run again.
        $first = $this->main->fresh()->hiddenAccounts()->orderBy('id')->first();
        for ($i = 0; $i < 12; $i++) {
            $this->student($first, $i % 2 ? 2 : 1);
        }
        $this->assertTrue(app(ServiceGroupStaging::class)->isUnlocked($this->sg2()));

        app(ServiceGroupFlush::class)->runAll(now()->addDay());

        $this->assertSame(
            $releasedAfterMainRun,
            Commission::where('consultant_id', $this->sg2()->id)
                ->whereIn('id', $releasedAfterMainRun)
                ->whereNotNull('released_at')->pluck('id')->sort()->values()->all(),
            'the rows the main already settled are still settled exactly once — no second payout'
        );
    }

    /**
     * The headline figure is scoped to DIRECT CHILDREN, like the rollup. Deeper
     * accounts each hold their own copy of the same climbed 2,500, so counting
     * them would report one student's money three times.
     */
    public function test_one_students_money_is_counted_only_once(): void
    {
        $ninth = $this->buyUpToTheNinthAccount();
        Commission::query()->delete();

        $this->student($ninth, 1);

        $this->assertSame(
            (float) self::SLOT,
            (float) $this->page()->viewData('availableLeg2'),
            'one student on the 9th account is 2,500 — not 7,500 for the three accounts it climbed through'
        );
    }
}
