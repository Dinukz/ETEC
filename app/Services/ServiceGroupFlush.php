<?php

namespace App\Services;

use App\Models\Commission;
use App\Models\CommissionSetting;
use App\Models\CompanyRevenue;
use App\Models\Consultant;
use App\Models\Group;
use Illuminate\Support\Facades\DB;

/**
 * The manual FLUSH-OUT (owner-confirmed 2026-08-13, threshold fixed 2026-08-16).
 *
 * ----- The model ------------------------------------------------------------
 * Each account's two legs accumulate a BALANCE: the slot income (2,500 per
 * student) recorded on that leg that has not been flushed yet. The balance
 * only grows; nothing is payable until a flush happens.
 *
 * ⚠️⚠️ THE STAGED THRESHOLDS ARE BACK — NOW PER ACCOUNT (owner-confirmed
 * 2026-08-18). This reverses the 2026-08-16 "one fixed threshold" rule below.
 *
 * A flush needs BOTH legs to hold the threshold for THAT ACCOUNT'S OWN STAGE:
 *
 *   Stage 1  "1-to-1"   2,500 each side (1 student)   →  releases  5,000
 *   Stage 2  "2-to-2"   5,000 each side (2 students)  →  releases 10,000
 *   Stage 3  "3-to-3"   7,500 each side (3 students)  →  releases 15,000
 *                                                        ...and repeats forever
 *
 * An account walks 1 → 2 → 3 automatically as it flushes, then stays at 3.
 *
 * ----- Why this is safe NOW when it was not on 08-16 ------------------------
 * The staged discount was retired on 2026-08-16 because of a real bug, and
 * that bug was NOT the staging itself — it was that `lifetime_pair_stage` was
 * read from the OWNING PERSON, so all of one person's accounts shared a single
 * counter. `eligibleAccounts()` processes a System Run in id order, so
 * whichever account flushed FIRST consumed the cheap stage and silently raised
 * the threshold under a second account of the same person before it was even
 * checked — money that genuinely qualified got stuck on the accident of
 * processing order.
 *
 * The stage is now read from THE ACCOUNT ITSELF (`$account->lifetime_pair_stage`,
 * never `owningPerson()`), which each hidden placement account already has its
 * own column value for. Two accounts of one person can no longer influence one
 * another at all, so processing order cannot change any outcome — the property
 * `FixedFlushThresholdTest` pins by flushing the same two accounts in both
 * orders. That is what makes reinstating the staged amounts safe.
 *
 * Owner's own framing, with the numbers: "onama SCN group eheka service group
 * 1 and 2 depaththatama student la dennek add krahama auto depaththa balance
 * wela e account eken 5000 auto out wenwa... 2 to 2 ekedith e wagemi...
 * studentla 4k add karoth eyta auto 10000k... ita passe normal digatama
 * hambena 3 to 3 eka eke 15000k (2500 x 6 = 15000)."
 *
 * An admin can also SET an account's stage directly (Edit User → Service Group
 * accounts), because existing consultants who already used their cheap stages
 * must not be handed them again. The selector only ever moves FORWARD — a
 * consumed stage can never be re-granted.
 *
 * A student's own SLOT income can still be released before 7,500×2 by an
 * accountant manually approving that one row (the per-row "Approve" override,
 * kept 2026-08-15) — that is now the ONLY way a fresh consultant's first
 * couple of students pay out before their legs reach the full threshold. It
 * does not reduce the leg balance a flush later measures.
 *
 * When it fires:
 *   1. The oldest N rows on EACH leg (N = the stage's pair step) are released
 *      for payout — they become approvable, exactly as before. An accountant
 *      still approves them; a flush never moves money on its own.
 *   2. EVERY other unflushed row on BOTH legs is CANCELLED, and the total is
 *      booked to the company as `group_overflow` revenue.
 *   3. Both legs therefore read 0 afterwards.
 *
 * ----- Why the forfeit ------------------------------------------------------
 * This is the owner's explicit instruction, given with a worked example
 * against a real account carrying 435,000 on one leg and 5,000 on the other:
 * "meke 5000 thiyen ekata thaw 2500k hoyagen 7500 krala meyata flush out krann
 * puluwn ethkota 435000 n 7500k adu wela ithuru gana company eke revenue ekata
 * ekathu wenwa ita passe anith ewath 0 wenwa." Confirmed again when asked what
 * should happen to the individual 2,500 records: "E tika okkom company ekata
 * add wenwa company revenue widiyta ita passe eyalata 0 widiyta penwanawa."
 *
 * It makes placement strategic — the owner's own framing was "nikan chess game
 * ekak wage": a consultant chooses which of their accounts, and which leg, a
 * student lands on, because volume stranded on one side is lost at the next
 * flush rather than banked forever.
 *
 * ----- What a flush must NEVER touch ----------------------------------------
 *  - HELD rows (a restricted/suspended consultant's income). They are excluded
 *    from the balance AND from the forfeit, so a hold can never be turned into
 *    a permanent loss by someone clicking Flush while the consultant is
 *    restricted. They simply stay on the leg until the hold clears.
 *  - Already approved/paid rows. They left the leg when they were approved.
 *  - The direct-add bonus (`type = referral`). It belongs to no leg and is
 *    never staged, flushed or forfeited.
 *  - A LOCKED account (the 12-students-unlocks-the-next-account rule still
 *    applies — owner reconfirmed 2026-08-13 that it stays).
 */
class ServiceGroupFlush
{
    public function __construct(protected ServiceGroupStaging $staging)
    {
    }

    /**
     * Everything a screen needs to render the flush state of one account.
     *
     * @return array{
     *     ready: bool, locked: bool, unlock_needed: int,
     *     leg1: float, leg2: float, threshold: float,
     *     need_leg1: float, need_leg2: float,
     *     payout: float, releasable: float, already_paid: float,
     *     forfeit: float, label: string, can_flush: bool
     * }
     */
    public function state(Consultant $account, ?Group $group, ?\Illuminate\Support\Carbon $cutoff = null, array $exclude = []): array
    {
        $config = ServiceGroupStaging::config();
        $rate = (float) CommissionSetting::current()->commission_per_student;

        // This ACCOUNT's own stage — never the owning person's. See the class
        // docblock for why that distinction is the whole safety argument.
        $step = $this->stepForAccount($account, $config);
        $threshold = $step * $rate;

        $locked = ! $this->staging->isUnlocked($account, $config);
        $unlockNeeded = 0;
        $blockedBy = null;

        // An admin's manual lock, not the 12-students rule. Reported
        // separately because "the previous account needs 12 more students" is
        // an actively misleading thing to print at someone whose account was
        // shut by hand — no number of students will open it.
        $lockedManually = $locked && $account->lockOverride() === Consultant::LOCK_FORCED_SHUT;

        if ($locked && ! $lockedManually) {
            // Name the account that is actually holding this one shut. "The
            // previous account needs 5 more students" is unactionable when a
            // person owns three accounts and cannot tell which one is meant.
            $previous = $this->previousAccount($account);
            $unlockNeeded = max(0, $config['unlock'] - ($previous ? $this->staging->studentsOn($previous->id) : 0));
            $blockedBy = $previous?->scn_code ?? $previous?->consultant_code;
        }

        $ready = $group !== null && \App\Services\FinanceSchemaRepair::flushReady();

        // Two different balances, and conflating them is what caused the
        // reported contradiction (2026-08-14): the Service Groups page called
        // state() with NO cutoff and told the consultant "3-to-3 ready — LKR
        // 15,000 at the next System Run", while the System Run preview, which
        // DOES apply the cutoff, found nothing to flush. Both were right about
        // their own question. The students had been registered that same day,
        // so they belong to the NEXT run, not the one about to be performed.
        //
        //  - $leg*        : in scope for THIS cutoff. Drives readiness, the
        //                   payout and the forfeit, so those always describe
        //                   the run that is actually going to happen.
        //  - $legTotal*   : everything unflushed regardless of date. What the
        //                   consultant is accumulating.
        $plan1 = $ready ? $this->legPlan($account, $group, 1, $step, $cutoff, $exclude) : null;
        $plan2 = $ready ? $this->legPlan($account, $group, 2, $step, $cutoff, $exclude) : null;

        $leg1 = $plan1['balance'] ?? 0.0;
        $leg2 = $plan2['balance'] ?? 0.0;

        $legTotal1 = $cutoff === null ? $leg1 : ($ready ? $this->legBalance($account, $group, 1, null, $exclude) : 0.0);
        $legTotal2 = $cutoff === null ? $leg2 : ($ready ? $this->legBalance($account, $group, 2, null, $exclude) : 0.0);

        // ⚠️ THESE WERE A SECOND, DIFFERENT SCOPE — NOW THEY ARE THE SAME ONE.
        //
        // While the main displayed a rollup, "own rows" and "what this account
        // shows" were two different figures for the same account, and comparing
        // one against the other is precisely what told the main its entire
        // Service Group had been "registered after the cutoff" (the main's
        // in-scope leg came from its own — empty — rows, the total came from
        // the rollup, so the subtraction reported the whole balance as waiting).
        //
        // Since 2026-09-02 every account holds its own money and there is one
        // scope. The keys stay so the screens and preview() keep reading a name
        // that says what it means, but they are deliberately NOT a second query
        // any more — two names for one number cannot drift.
        $ownTotal1 = $legTotal1;
        $ownTotal2 = $legTotal2;

        // Mirrors flush(): BOTH sides at the stage amount, at EVERY stage —
        // see the note there (2026-08-27). This carried its own copy of the
        // 08-26 "repeating stage needs only one side" rule, so when flush()
        // was corrected this screen went on promising 7,500 for an account the
        // run then refused. Caught by
        // BothSidesRequiredAtEveryStageTest::test_the_preview_offers_nothing_either.
        //
        // ⚠️ Keep this expression identical to flush()'s $canFlushHere. Two
        // copies of one rule is how the two screens drifted apart in the first
        // place; if this needs changing again, change both in the same edit.
        $canFlush = $ready && ! $locked && SystemRunRules::decide($leg1, $leg2, $this->stageOf($account), $rate)['runs'];

        // What the run would ACTUALLY release, which is not always the nominal
        // threshold × 2: since 2026-08-17 a row an accountant already approved
        // by hand still holds its place in the threshold, but has nothing left
        // to pay. Printing the nominal figure would recreate exactly the class
        // of contradiction the 2026-08-14 cutoff fix removed — the page
        // promising "LKR 15,000 at the next System Run" and the run then
        // reporting 12,500 with no explanation of the difference.
        $releasable = $canFlush
            ? (float) $plan1['release']->sum('amount') + (float) $plan2['release']->sum('amount')
            : 0.0;

        $alreadyPaid = $canFlush
            ? (float) $plan1['swept']->sum('amount') + (float) $plan2['swept']->sum('amount')
            : 0.0;

        // Taken from the SAME plan flush() acts on, never re-derived as
        // "balance − threshold": an already-paid row past the threshold is
        // swept off the leg, not cancelled, so subtracting raw balances would
        // promise the company revenue the run is never going to book.
        $forfeit = $canFlush
            ? (float) $plan1['forfeit']->sum('amount') + (float) $plan2['forfeit']->sum('amount')
            : 0.0;

        return [
            'ready' => $ready,
            'locked' => $locked,
            'locked_manually' => $lockedManually,
            'unlock_needed' => $unlockNeeded,
            'leg1' => $leg1,
            'leg2' => $leg2,
            // Full balance including anything registered after the cutoff.
            'leg1_total' => $legTotal1,
            'leg2_total' => $legTotal2,
            // The part of each leg NOT already counted as earned income — see
            // legPendingBalance(). The screens must use this, never leg*_total,
            // anywhere they print a figure ALONGSIDE the earned total, or the
            // same money is shown twice and reads as double.
            'leg1_unapproved' => $ready ? $this->legPendingBalance($account, $group, 1) : 0.0,
            'leg2_unapproved' => $ready ? $this->legPendingBalance($account, $group, 2) : 0.0,
            // Own rows only — NOT what this account settles. Read the note
            // above before using it for anything except the waiting total.
            'leg1_own_total' => $ownTotal1,
            'leg2_own_total' => $ownTotal2,
            // Sitting on the legs but not in scope for this run — it settles at
            // a later one. Both sides of this subtraction are measured in the
            // SETTLEMENT scope, which is what makes the answer mean "after the
            // cutoff" rather than "on another account".
            'waiting_after_cutoff' => max(0.0, ($legTotal1 - $leg1) + ($legTotal2 - $leg2)),
            'threshold' => $threshold,
            'need_leg1' => max(0.0, $threshold - $leg1),
            'need_leg2' => max(0.0, $threshold - $leg2),
            // The rate, so a caller can turn a shortfall in rupees into the
            // number of STUDENTS it represents — which is the unit the person
            // reading it actually acts in.
            'rate' => $rate,
            'blocked_by' => $blockedBy,
            // The headline "this stage pays" figure — what a full threshold on
            // both sides is worth. Unchanged, and still what the screens lead
            // with, because it is the number the rule is stated in.
            'payout' => $threshold * 2,
            // ...and what would land THIS time, once rows already approved by
            // hand are taken out. Equal to 'payout' in the ordinary case.
            'releasable' => $releasable,
            'already_paid' => $alreadyPaid,
            // What would be forfeited to the company if flushed right now.
            // Deliberately derived from the CUTOFF plan: a registration after
            // the cutoff is not swept into this run's forfeit.
            'forfeit' => $forfeit,
            'label' => $step.'-to-'.$step,
            'can_flush' => $canFlush,
            // The commission ids a flush would STAMP — released, forfeited or
            // swept. preview() carries these forward so no row is offered twice
            // across the main's rollup and the account it actually sits on.
            // Pending overflow left on the leg by a ramp stage is not here,
            // because the run does not stamp it either.
            'claims' => $canFlush
                ? collect([$plan1, $plan2])
                    ->filter()
                    ->flatMap(fn ($p) => $p['release']->concat($p['forfeit'])->concat($p['swept'])->pluck('id'))
                    ->all()
                : [],
        ];
    }

    /**
     * The pair-step this ONE account must reach on each leg to flush.
     *
     * Read from the account row itself. Every consultant row — including each
     * hidden placement account — carries its own `lifetime_pair_stage`, so
     * "per account" needs no new column; it is purely a matter of NOT
     * resolving up to owningPerson() the way the pre-2026-08-16 code did.
     *
     * Degrades to the repeating step when the column does not exist yet, which
     * is the conservative direction: the account needs the FULL 7,500 rather
     * than being handed a cheap release by a half-applied schema.
     */
    protected function stepForAccount(Consultant $account, array $config): int
    {
        if (! \App\Services\FinanceSchemaRepair::stagingReady()) {
            return $config['repeat'];
        }

        return ServiceGroupStaging::stepFor($this->stageOf($account), $config);
    }

    /** How many one-time stages THIS ACCOUNT has already consumed (0, 1 or 2+). */
    public function stageOf(Consultant $account): int
    {
        return max(0, (int) ($account->lifetime_pair_stage ?? 0));
    }

    /** Safety stop for the repeat-until-dry loop; far above any real account. */
    private const MAX_FLUSH_ROUNDS = 200;

    /**
     * EVERYTHING one System Run would settle on this account — not just its
     * next stage.
     *
     * ⚠️ A RUN KEEPS GOING UNTIL THE ACCOUNT CANNOT BALANCE (2026-08-24).
     *
     * A flush settles ONE stage and advances it. So an account still on 1-to-1
     * holding 7,500 a side released only 2,500 + 2,500 and stopped, leaving
     * 5,000 a side untouched until somebody ran the whole thing again — three
     * separate runs to reach the steady state. That is what "flush wen na" kept
     * meaning: it did flush, just barely.
     *
     * The owner's arithmetic is the specification: "hama SCN account ekatam
     * ekathu wena amount eka 7500ta wadi nm apit puluwan depaththa 7500 gane
     * balance krala 15000k out kragann... wadi amount eka thiyeno nm eka
     * company ekata yanwa. system run ekedi nm meka aniwaaren wenn one."
     *
     * 7,500 a side now really does pay 15,000 in one run, by walking the stages
     * it was always going to walk:
     *
     *   1-to-1  releases 2,500 + 2,500  =  5,000   (2 rows gone, 5,000 a side left)
     *   2-to-2  releases 5,000 + 5,000  = 10,000   (leg empty)
     *   3-to-3  needs 7,500, has 0      -> stop
     *                                    ------
     *                                     15,000
     *
     * and an account already at 3-to-3 releases 7,500 a side and forfeits the
     * rest, exactly as before. The ramp still exists — it is simply no longer
     * spread across separate runs.
     *
     * Simulated in memory against a growing $claimed set rather than by
     * writing, so preview() can show the same figure runAll() will pay. Ramp
     * overflow is deliberately NOT claimed: the run leaves it on the leg, so
     * the next round must still see it — that is what makes 2-to-2 able to
     * spend what 1-to-1 left behind.
     *
     * @return array{payout: float, already_paid: float, forfeit: float, claims: array<int, int>, rounds: int, stages: array<int, string>}
     */
    public function fullPlan(Consultant $account, ?Group $group, ?\Illuminate\Support\Carbon $cutoff = null, array $exclude = []): array
    {
        $empty = ['payout' => 0.0, 'already_paid' => 0.0, 'forfeit' => 0.0, 'claims' => [], 'rounds' => 0, 'stages' => []];

        if (! $group || ! \App\Services\FinanceSchemaRepair::flushReady()) {
            return $empty;
        }

        $config = ServiceGroupStaging::config();

        if (! $this->staging->isUnlocked($account, $config)) {
            return $empty;
        }

        $rate = (float) CommissionSetting::current()->commission_per_student;
        $stage = $this->stageOf($account);

        $claims = $exclude;
        $payout = 0.0;
        $alreadyPaid = 0.0;
        $forfeit = 0.0;
        $stages = [];

        // ⚠️ EXACTLY ONE STAGE — see runAll() (2026-08-25).
        //
        // This used to walk the stages until the account could no longer
        // balance. The owner has capped a run at one stage per account, so the
        // simulation models one too, or the preview would promise several
        // stages' worth of money the run will not pay.
        for ($round = 0; $round < 1; $round++) {
            $step = \App\Services\FinanceSchemaRepair::stagingReady()
                ? ServiceGroupStaging::stepFor($stage, $config)
                : (int) $config['repeat'];

            $threshold = $step * $rate;

            $plan1 = $this->legPlan($account, $group, 1, $step, $cutoff, $claims);
            $plan2 = $this->legPlan($account, $group, 2, $step, $cutoff, $claims);

            // ⚠️⚠️ THE THIRD COPY OF THE BOTH-SIDES RULE (fixed 2026-08-28).
            //
            // flush() and state() were corrected on 08-27 when the owner
            // reversed the one-sided 3-to-3 rule; THIS copy was missed and was
            // left on the 08-26 version — "at the repeating stage a side takes
            // part if it holds anything". So the money the preview computed for
            // an account came from a different rule than the one deciding
            // whether the account ran at all, and a 3-to-3 account holding
            // 20,000 against 0 was still being handed a payout and a forfeit
            // here. It only stayed off the screen because preview() throws the
            // figure away when can_flush is false.
            //
            // One rule, three call sites, and this is the second time two of
            // them drifted. Keep the expression below IDENTICAL to flush()'s
            // $canFlushHere and state()'s $canFlush; if it ever changes again,
            // change all three in the same edit.
            if (! SystemRunRules::decide($plan1['balance'], $plan2['balance'], $stage, $rate)['runs']) {
                break;
            }

            foreach ([$plan1, $plan2] as $plan) {
                $payout += (float) $plan['release']->sum('amount');
                $alreadyPaid += (float) $plan['swept']->sum('amount');
                $forfeit += (float) $plan['forfeit']->sum('amount');

                // Only what the run STAMPS is spoken for.
                $claims = array_merge(
                    $claims,
                    $plan['release']->concat($plan['forfeit'])->concat($plan['swept'])->pluck('id')->all(),
                );
            }

            $stages[] = $step.'-to-'.$step;
        }

        return [
            'payout' => $payout,
            'already_paid' => $alreadyPaid,
            'forfeit' => $forfeit,
            'claims' => $claims,
            'rounds' => count($stages),
            'stages' => $stages,
        ];
    }

    /**
     * Unflushed slot income sitting on one leg, whatever its approval state.
     *
     * ⚠️ A `$rollup` flag used to select between the DISPLAY figure (for a
     * main, the whole Service Group) and what the account would actually
     * settle. There is only one answer now — every account, main included,
     * holds and settles its own rows — so callers can no longer accidentally
     * ask the question that produced two different numbers for one account.
     */
    public function legBalance(Consultant $account, Group $group, int $leg, ?\Illuminate\Support\Carbon $cutoff = null, array $exclude = []): float
    {
        return (float) $this->legScopeQuery($account, $group, $leg, $cutoff, $exclude)->sum('amount');
    }

    /**
     * The part of a leg the given run could NOT have settled, because it was
     * not there to settle.
     *
     * Reported 2026-09-13: after run #39 three accounts still read 10,000 /
     * 30,000 and the owner called the run broken. It was not. Every rupee left
     * was either ENTERED after that run's cutoff, or its slip was VERIFIED after
     * the run finished — and the page said neither, because it measures
     * everything against the end of today and so never has anything "after".
     *
     * Two conditions, deliberately OR'd:
     *   - the payment is DATED after the run's cutoff (the run's own scope rule,
     *     legScopeQuery(), keys on DATE(paid_at) since 2026-09-14), or
     *   - the income row itself was recorded after the run (a slip dated before
     *     the cutoff but verified late).
     *
     * ⚠️ WHAT THIS MUST NOT COUNT: a row that WAS in scope and present at run
     * time but is still on the leg — left by the 1-to-1 / 2-to-2 ramp, or by an
     * unbalanced other side. Timing is not why that money is there, so calling
     * it "came in after the run" would be a false explanation.
     *
     * Built on legScopeQuery() with no cutoff, so it is always a slice of the
     * exact rupees the leg figure shows — never a second, drifting scope.
     */
    public function arrivedAfterRun(Consultant $account, Group $group, int $leg, \App\Models\SystemRun $run): float
    {
        // The run's settled-up-to Colombo date, matched the same way the run's
        // own scope matches a payment — DATE(paid_at) since 2026-09-14, so the
        // "came in after" line and the run agree on what a payment's date is.
        $runCutoffDate = $run->cutoffDate();
        $ranAt = $run->created_at;

        return (float) $this->legScopeQuery($account, $group, $leg)
            ->where(fn ($q) => $q
                ->whereIn('payment_id', \App\Models\Payment::whereRaw('date(coalesce(paid_at, created_at)) > ?', [$runCutoffDate])->select('id'))
                ->orWhere('commissions.created_at', '>', $ranAt))
            ->sum('amount');
    }

    /** The most recent System Run on record, or null before the first one. */
    public static function lastRun(): ?\App\Models\SystemRun
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('system_runs')) {
            return null;
        }

        return \App\Models\SystemRun::query()->latest('id')->first();
    }

    /**
     * ⚠️ MONEY THAT ARRIVED TOO LATE FOR ITS OWN RUN — grouped by payment date.
     *
     * Reported 2026-09-24: *"21 damm payslip eka 22 verify krala … 21 system run
     * eka yawanakota penwan na, eth 22 system run eke penwanawa. Eka wardi —
     * eka penwann one 21 system run eke."*
     *
     * A run settles DATE(paid_at) <= its cutoff, so a slip banked on the 21st
     * but VERIFIED on the 22nd could not exist when the 21st run was performed.
     * It then sits on the leg until the next run, and that run — the 22nd —
     * sweeps it up and stamps it as its own. The money is never lost; it lands
     * on the wrong day, and until now there was no way to know it was there.
     *
     * ⚠️ THIS IS NOT "EVERY UNRELEASED ROW". Most unflushed money is simply
     * waiting for the other leg to fill (LKR 9,077,500 network-wide on the
     * 21 Sep data) and belongs exactly where it is. The narrow set that matters
     * is: dated ON OR BEFORE what the last run already settled, but WRITTEN
     * after that run finished. That is the same two-sided test arrivedAfterRun()
     * applies per leg, asked network-wide instead.
     *
     * ⚠️ READ-ONLY. Nothing here releases, pairs, forfeits or stamps anything.
     *
     * @return \Illuminate\Support\Collection<int, array{date: string, amount: float, rows: int, payments: int, late_slip: int, repaired: int, hand: int}>
     *                                          oldest payment date first
     */
    public static function lateArrivals(?\App\Models\SystemRun $lastRun = null): \Illuminate\Support\Collection
    {
        $lastRun ??= self::lastRun();

        if (! $lastRun || ! \App\Services\FinanceSchemaRepair::flushReady()) {
            return collect();
        }

        $unsettled = Commission::query()
            ->where('type', CommissionService::TYPE_SLOT)
            ->where('status', '!=', 'cancelled')
            ->whereNull('released_at')
            ->whereNull('held_at')
            // Written after the run finished — that is what made it miss.
            ->where('commissions.created_at', '>', $lastRun->created_at)
            ->get(['id', 'payment_id', 'amount', 'created_at']);

        if ($unsettled->isEmpty()) {
            return collect();
        }

        // The payment's own date, which is the day the run scopes on — and when
        // it was verified, which is what tells a late slip from a repair.
        $payments = \App\Models\Payment::whereIn('id', $unsettled->pluck('payment_id')->filter()->unique())
            ->get(['id', 'paid_at', 'created_at', 'verified_at'])
            ->keyBy('id');

        $cutoffDate = $lastRun->cutoffDate();
        $ranAt = $lastRun->created_at;

        return $unsettled
            ->map(function ($row) use ($payments, $ranAt) {
                $payment = $row->payment_id !== null ? ($payments[(int) $row->payment_id] ?? null) : null;

                // A hand-entered row has no payment; it is dated by its own
                // earned_at, exactly as legScopeQuery() dates it.
                $date = $row->payment_id === null
                    ? $row->incomeDate()?->setTimezone('Asia/Colombo')->toDateString()
                    : ($payment?->paid_at ?? $payment?->created_at)?->setTimezone('Asia/Colombo')->toDateString();

                // ⚠️ WHY IT MISSED THE RUN (2026-10-01). Not every row written
                // after a run is a late SLIP. The owner saw "52 payments dated
                // 9 Sep … verified after Run #59" — three-week-old slips cannot
                // all have been verified that late; rows Commission Health →
                // Repair writes for OLD payments land here too, because the
                // row is new even though the payment is not. The page has to
                // say which, or it reports a repair as a pile of late slips.
                $why = match (true) {
                    $row->payment_id === null => 'hand',
                    $payment?->verified_at !== null && $payment->verified_at->greaterThan($ranAt) => 'late_slip',
                    default => 'repaired',
                };

                return [
                    'date' => $date,
                    'amount' => (float) $row->amount,
                    'payment_id' => $row->payment_id !== null ? (int) $row->payment_id : null,
                    'why' => $why,
                ];
            })
            // Only money the last run WOULD have settled had it existed then.
            ->filter(fn ($r) => $r['date'] !== null && $r['date'] <= $cutoffDate)
            ->groupBy('date')
            ->map(fn ($rows, $date) => [
                'date' => $date,
                'amount' => round((float) $rows->sum('amount'), 2),
                // Commission ROWS — one payment climbs onto several accounts,
                // so this is never the number of payments. It was printed as
                // "52 payments" when it meant 52 rows.
                'rows' => $rows->count(),
                'payments' => $rows->pluck('payment_id')->filter()->unique()->count(),
                'late_slip' => $rows->where('why', 'late_slip')->pluck('payment_id')->unique()->count(),
                'repaired' => $rows->where('why', 'repaired')->pluck('payment_id')->unique()->count(),
                'hand' => $rows->where('why', 'hand')->count(),
            ])
            ->sortKeys()
            ->values();
    }

    /**
     * The part of a leg that is NOT yet counted as earned income.
     *
     * ⚠️ THE "DUPLICATE" REPORTED ON 2026-08-25.
     *
     * Two figures on the Service Groups page overlap. "LKR 12,500 earned"
     * counts approved + paid rows; "+ LKR 12,500 on the leg, not yet flushed"
     * counts everything unflushed REGARDLESS of approval. An approved-but-
     * unflushed row therefore sits in both, and the page reads as 25,000 —
     * exactly once somebody approves it:
     *
     *   "kalin eka hariyt watila thibba, ita passe approve krahama duplicate
     *    wela" — it had landed correctly, then approving duplicated it.
     *
     * The two are not additive and never were; the "+" prefix just made it look
     * that way. This is what the "+" line should show: the money genuinely not
     * in the earned figure yet.
     */
    public function legPendingBalance(Consultant $account, Group $group, int $leg): float
    {
        return (float) $this->legScopeQuery($account, $group, $leg)
            ->where('status', 'pending')
            ->sum('amount');
    }

    /**
     * What a flush of ONE leg would do right now, in one query.
     *
     * The rows are ordered oldest-first and split at the threshold: the first
     * $step are what the threshold measured and are KEPT; everything past them
     * is overflow. Each half is then split again by status, because an
     * already-approved row must be treated completely differently from a
     * pending one:
     *
     *   release : pending, within the threshold  → stamped, becomes approvable.
     *   forfeit : pending, past the threshold    → cancelled, booked to company.
     *   swept   : approved/paid, either half     → stamped ONLY.
     *
     * ⚠️ `swept` is the whole point of this method. A row an accountant already
     * pushed through with the per-row override still counts toward the
     * threshold (commission-rules-2026-08.md: "a force-approved row never
     * reduces the leg balance — the flush is what does that"), so it has to be
     * carried here rather than filtered out. But its money has ALREADY been
     * paid, so the run must neither pay it again nor cancel it — cancelling it
     * would claw back a real payout and book it to the company as revenue.
     * Stamping `released_at` is all that is left to do: it takes the row off
     * the leg so the next run does not count it a second time.
     *
     * @return array{rows: \Illuminate\Support\Collection, release: \Illuminate\Support\Collection, forfeit: \Illuminate\Support\Collection, swept: \Illuminate\Support\Collection, balance: float}
     */
    protected function legPlan(Consultant $account, Group $group, int $leg, int $step, ?\Illuminate\Support\Carbon $cutoff = null, array $exclude = []): array
    {
        // ⚠️⚠️ AN ACCOUNT SETTLES ITS OWN ROWS. ALWAYS. NO EXCEPTIONS.
        //
        // This was a `rollup` boolean, flipped six times in eight days, then a
        // saved setting, then removed. Every time it was ON the same thing
        // happened: a main's "Service Group" was one CHILD's two legs added
        // together, so the main settling spent that child's money while the
        // child was nowhere near balanced. Reported with real figures four
        // separate times — SCN-00101 at 70,000/2,500 and SCN-00102 at
        // 2,500/75,000 both emptied at 3-to-3 without either ever reaching
        // 7,500 a side.
        //
        // Every time it was OFF, the main had nothing of its own to settle and
        // sat frozen showing a balance it could not move.
        //
        // ⚠️ THE FLAG IS GONE, AND SO IS THE DILEMMA (2026-09-02). The main now
        // earns its own rows like every other account, so "its own rows" and
        // "what it displays" are the same rupees. legScopeQuery() has one
        // branch; there is no longer a question to get wrong here.
        $rows = $this->legScopeQuery($account, $group, $leg, $cutoff, $exclude)
            ->orderBy('id')
            ->get(['id', 'amount', 'status', 'consultant_id', 'tier']);

        // ⚠️ "RELEASED" MEANS NOT-YET-PAID, NOT PENDING (2026-08-25).
        //
        // This was `status = pending`. Since income is approved the moment its
        // payment is verified, nothing reaching a run is pending any more — so
        // every row fell into `swept`, `payout` was always 0.00, and the System
        // Run screen reported "Released to consultants: LKR 0" for a run that
        // had just settled 15,000.
        //
        // What a run actually does now is decide which money is PAYABLE, so
        // that is what it reports: everything within the threshold that has not
        // already been paid out. A genuinely PAID row is swept — it is off the
        // leg, but it is not this run's payout.
        $pending = fn ($c) => $c->whereIn('status', ['pending', 'approved']);

        $over = $rows->slice($step);

        // ⚠️ ONLY THE REPEATING STAGE FORFEITS (2026-08-23).
        //
        // Overflow used to be cancelled to the company at EVERY stage, so a
        // consultant still on 1-to-1 or 2-to-2 lost everything past 2,500 or
        // 5,000 a side the first time they balanced. The owner has scrapped
        // that: those two stages are a ramp, not a cut-off.
        //
        //   1-to-1 / 2-to-2 : release what the threshold measured, LEAVE the
        //                     rest sitting on the leg — "wadi amount eka
        //                     company ekata add wen na, amount eka e widiytama
        //                     thiyeno". It counts toward the next stage.
        //   3-to-3          : unchanged — 7,500 releases on each side and
        //                     anything beyond it is forfeited, "anith tika
        //                     company eka revenue ekata add wenn one".
        //
        // Same at every cutoff, daily or weekly: "eka day eketh e wage week
        // eketh e wage". Below the threshold nothing happens at all, because
        // flush() refuses before it ever gets here.
        // ⚠️ DELEGATED — SystemRunRules owns this now (2026-08-30). It used to
        // be re-derived here, in flush(), in state() and in fullPlan(), and
        // those four copies drifting is what made the run need correcting stage
        // by stage. Do not inline it again.
        //
        // Every row this method can see already belongs to $account —
        // legScopeQuery() is scoped to rows written against it, with no rollup
        // branch left — so "forfeit the surplus" cannot reach another account's
        // money.
        $isRepeatingStage = SystemRunRules::isRepeating($this->stageOf($account))
            && SystemRunRules::repeatingForfeitsSurplus();

        // ⚠️ FORFEIT REACHES APPROVED ROWS TOO (2026-08-25).
        //
        // This was $pending($over). That was right while a slot stayed pending
        // until a run released it — but since income is approved at
        // verification, nothing is ever pending by the time a run sees it, so
        // the excess-past-7,500 rule would have quietly stopped working the day
        // that shipped and the company would have collected nothing.
        //
        // PAID rows are still never touched: that money has left, and clawing
        // it back is not what "wadi amount eka company ekata yanwa" means.
        // flush() unwinds the earned totals for any approved row it cancels.
        $forfeitable = fn ($c) => $c->whereIn('status', ['pending', 'approved']);

        // ⚠️⚠️ AT 3-TO-3 THE ACCOUNT ENDS AT ZERO, WHATEVER DATE THE RUN IS FOR
        // (2026-09-14).
        //
        // A run for an old date settles from payments entered up to that date —
        // that is what the date is for: "parana date walat system run eka". But
        // the forfeit only ever reached THOSE rows, so a 3-to-3 account kept
        // everything entered after the date. The owner re-ran 9 Sep on SCN-00421
        // (SG1 17,500 / SG2 32,500): the consultant was paid, "eth ... stage
        // 3to3 eka adalawa 0 wela na". The rule, with the numbers written out:
        // SG1 → 7,500 to the consultant and 10,000 to the company; SG2 → 7,500
        // and 25,000.
        //
        // So once an account settles at the repeating stage, the rows past the
        // date go to the company with the rest. The date still decides who
        // QUALIFIES and which rows are RELEASED ($rows, 'balance'); only the
        // surplus reaches further. Held rows stay out — legScopeQuery() never
        // returns them — and the ramp stages keep later money exactly as before.
        //
        // For one evening (2026-09-13) the run ignored the date altogether to get
        // the zero. The owner needs runs for old dates, so it is done here
        // instead. A date that has not ended yet (a run for today) has nothing
        // past it, so the query is skipped — the Service Groups page calls this
        // for every account on every visit.
        $later = collect();

        if ($isRepeatingStage && $cutoff !== null && $cutoff->lessThan(now())) {
            $later = $this->legScopeQuery($account, $group, $leg, null, $exclude)
                ->whereNotIn('id', $rows->pluck('id')->all())
                ->orderBy('id')
                ->get(['id', 'amount', 'status', 'consultant_id', 'tier']);
        }

        return [
            'rows' => $rows,
            'release' => $pending($rows->take($step)),
            // ⚠️ FORFEITED IN THIS ROUND — ONE STAGE, THEN STOP (2026-08-25).
            //
            // For a few hours this deferred the forfeit so a run could walk
            // 1-to-1 → 2-to-2 → 3-to-3 and pay 30,000. The owner has set the
            // rule the other way, with the amounts spelled out: "1to1 ekedi
            // 5000i, 2to2 ekedi 10000, 3to3 ekedi 15000i — ita wadiyen release
            // wen na." One stage per run, and at the repeating stage
            // everything past 7,500 a side is closed off to the company in that
            // same round.
            'forfeit' => $isRepeatingStage ? $forfeitable($over)->concat($forfeitable($later))->values() : collect(),
            // ⚠️ SWEEPING IS NOT CONDITIONAL. Only the FORFEIT is.
            //
            // An already-approved row has been paid; taking it off the leg
            // costs the consultant nothing and is not "adding it to the
            // company" — it is cancelling that a run books as revenue, and
            // that is the part the ramp now skips. Leaving paid rows on the
            // leg instead would let them keep holding their place toward the
            // NEXT threshold, so a leg could reach 5,000 on money already
            // paid out and release again with no new students behind it.
            // Only genuinely PAID money is swept now — see the note on
            // $pending above. An approved row is this run's payout, not
            // something it merely tidies off the leg.
            'swept' => $rows->where('status', 'paid')->concat($later->where('status', 'paid'))->values(),
            'balance' => (float) $rows->sum('amount'),
        ];
    }

    /**
     * Perform the flush.
     *
     * @return array{flushed: bool, reason: string, payout: float, forfeit: float, released: int, cancelled: int}
     */
    public function flush(Consultant $account, Group $group, ?\Illuminate\Support\Carbon $cutoff = null): array
    {
        $refuse = fn (string $reason) => [
            'flushed' => false, 'reason' => $reason,
            'payout' => 0.0, 'forfeit' => 0.0, 'released' => 0, 'cancelled' => 0,
        ];

        if (! \App\Services\FinanceSchemaRepair::flushReady()) {
            return $refuse('The flush feature is not available on this database yet.');
        }

        return DB::transaction(function () use ($account, $group, $refuse, $cutoff) {
            // THE ACCOUNT ROW is what gets locked, not the owning person.
            //
            // Two things now depend on it: two flushes racing on the same
            // account must not both select and cancel the same rows, and the
            // account's own stage has to be read and advanced atomically (it
            // decides the threshold this very flush is judged against). The
            // lock used to be taken on owningPerson() because the stage lived
            // there; moving the stage onto the account moves the lock with it.
            $fresh = Consultant::whereKey($account->id)->lockForUpdate()->first();

            if (! $fresh) {
                return $refuse('That account no longer exists.');
            }

            $config = ServiceGroupStaging::config();
            $rate = (float) CommissionSetting::current()->commission_per_student;
            // This ACCOUNT's own stage — see the class docblock. Because it is
            // per-account and never per-person, no other account of the same
            // person can move this threshold, so order within a run is still
            // irrelevant even though the staged amounts are back. Read off the
            // LOCKED row so a concurrent flush cannot have moved it underneath.
            $step = $this->stepForAccount($fresh, $config);
            $threshold = $step * $rate;

            // Whether this account has finished the ramp. Decides both what is
            // forfeited (legPlan) and, since 2026-08-26, whether the two sides
            // still have to pair at all.
            $isRepeatingStage = $step >= (int) ($config['repeat'] ?? $step);

            // ⚠️ THE 12-STUDENT CHAIN NO LONGER BLOCKS SETTLEMENT (2026-08-27).
            //
            // On 08-26 the owner ruled that a chain-locked account's income
            // must still be settled — "meke lock eka hari eth e account walin
            // hamben ewa mekat add krnn... main eka flush out wenn one system
            // run ekedi" — and that was implemented by letting the MAIN release
            // those rows on the account's behalf.
            //
            // The main no longer settles other accounts (see legScopeQuery),
            // because doing so left children one-sided. So if this guard still
            // refused, a chain-locked account's income would never be released
            // by anybody, silently reversing the 08-26 decision. The account
            // now settles itself instead — same money, same run, and it still
            // obeys the balanced two-sided rule like every other account.
            //
            // An ADMIN FORCE-SHUT still stops dead: that is a deliberate
            // closure, and it must not be paid out by any route.
            if ($account->lockOverride() === Consultant::LOCK_FORCED_SHUT) {
                return $refuse('This account is closed by an administrator — it earns no group income until that is changed.');
            }

            $plan1 = $this->legPlan($account, $group, 1, $step, $cutoff);
            $plan2 = $this->legPlan($account, $group, 2, $step, $cutoff);

            $leg1 = $plan1['balance'];
            $leg2 = $plan2['balance'];

            // ⚠️⚠️ EVERY STAGE NEEDS BOTH SIDES AT THE FULL AMOUNT (2026-08-27).
            //
            // THIRD setting of this rule. It required both sides until 08-26,
            // when the owner asked for a side to settle alone at 3-to-3
            // ("7500 samani ho adui nm 0 wenwa depaththenm"). Shown what that
            // actually did — SCN-00103 holding 37,500 against 0 released 7,500
            // and closed 30,000 off to the company — they reversed it and
            // restated the whole ladder, stage by stage:
            //
            //   "Stage 1 eke depaththem 2500k aniwaren thiyenn one... naththn
            //    run wen na, account eka load wenn wath one na."
            //   "Stage 2 eke depaththem 5000k aniwaren thiyenn one..."
            //   "Stage 3... eke depaththem 7500k aniwaren thiyenn one, service
            //    group 1 eke 7500i service group 2 eke 7500i ho ita wadi wenn
            //    one, naththn run wen na... meke paththaka hari 7500ta wada
            //    adui nam run wen na."
            //
            // So there is now ONE rule for all three stages: both sides at the
            // stage amount, or the account does not take part at all.
            //
            // What still differs at 3-to-3 is only what happens to the SURPLUS
            // once the account does qualify: on the ramp the remainder stays on
            // the leg for next time, while at the repeating stage everything
            // past 7,500 a side is closed off to the company — "wadi amount
            // tika company ekat yanwa meka stage 3 nisa". That is handled in
            // legPlan(), not here.
            $decision = SystemRunRules::decide($leg1, $leg2, $this->stageOf($fresh), $rate);

            if (! $decision['runs']) {
                return $refuse(sprintf(
                    'Both service groups need at least LKR %s before this can be flushed (Service Group 1 has %s, Service Group 2 has %s).',
                    number_format($threshold), number_format($leg1), number_format($leg2),
                ));
            }

            // BOTH sides take part, always — the decision above already refused
            // every case where one of them was short, so there is no such thing
            // as a one-sided flush. This used to filter on a per-side flag and
            // that is how "SG1 eken 7500k adu wela SG2 eke adu wela na" kept
            // happening.
            $active = collect([$plan1, $plan2]);

            // 1. Release the matched rows on each leg — oldest first, so the
            //    students who have waited longest are the ones paid. Only rows
            //    that are still PENDING can be released; one already approved
            //    by hand occupies its place in the threshold but has nothing
            //    left to pay (see legPlan()).
            // Only the sides that reached the threshold — see $active.
            $release = $active->flatMap(fn ($p) => $p['release']);
            $releasedIds = $release->pluck('id')->all();
            $payout = (float) $release->sum('amount');

            Commission::whereIn('id', $releasedIds)->update(['released_at' => now()]);

            // ⚠️ A RELEASED ROW IS APPROVED BY THE RUN ITSELF (2026-08-24).
            //
            // "1to1, 2to2, 3to3 thiyeno nm aniwaren flush wenn one auto, e tika
            // approve wela ynn one, mark paid withri manual denn one." Anything
            // still pending at this point is pending only because the
            // consultant's income was held when it was recorded; approve()
            // refuses those on the hold gate, which nothing overrides, so they
            // stay put rather than slipping through the run.
            foreach (Commission::whereIn('id', $releasedIds)->where('status', 'pending')->get() as $row) {
                app(\App\Services\CommissionApproval::class)->approve($row, force: true, actorId: null);
            }

            // 2. Money past the threshold on either leg is forfeited.
            $forfeitRows = $active->flatMap(fn ($p) => $p['forfeit']);
            $forfeitIds = $forfeitRows->pluck('id')->all();
            $forfeit = (float) $forfeitRows->sum('amount');

            $forfeitedSnapshot = [];
            $revenueId = null;

            if ($forfeitIds !== []) {
                // Captured BEFORE the status changes, so a reversal can put each
                // row back exactly as it was rather than guessing.
                $forfeitedSnapshot = Commission::whereIn('id', $forfeitIds)
                    ->get(['id', 'status', 'amount', 'consultant_id', 'group_id', 'type'])
                    ->map(fn ($r) => [
                        'id' => (int) $r->id,
                        'status' => (string) $r->status,
                        'amount' => (float) $r->amount,
                        'consultant_id' => (int) $r->consultant_id,
                        'group_id' => $r->group_id ? (int) $r->group_id : null,
                        'type' => (string) $r->type,
                    ])
                    ->all();

                // ⚠️ UNWIND WHAT APPROVAL ALREADY CREDITED (2026-08-25).
                //
                // Since income is approved at verification, a forfeited row has
                // almost always been counted into consultants.total_earned and
                // groups.total_commission_earned already. Cancelling it without
                // taking that back would leave both figures permanently
                // overstated — the consultant would keep seeing money the
                // company had reclaimed.
                //
                // Done BEFORE the status changes, so the rows can still be told
                // apart by it. Paid rows are never in this set (see legPlan).
                $approvedForfeits = Commission::whereIn('id', $forfeitIds)
                    ->where('status', 'approved')
                    ->get(['id', 'consultant_id', 'group_id', 'amount', 'type']);

                foreach ($approvedForfeits as $row) {
                    Consultant::whereKey($row->consultant_id)->decrement('total_earned', (float) $row->amount);

                    if ($row->group_id && $row->type !== CommissionService::TYPE_DIRECT) {
                        \App\Models\Group::whereKey($row->group_id)->decrement('total_commission_earned', (float) $row->amount);
                    }
                }

                Commission::whereIn('id', $forfeitIds)->update([
                    'status' => 'cancelled',
                    // Stamped so a cancelled row can never be picked up as
                    // "still sitting on its leg" by a later flush.
                    'released_at' => now(),
                ]);

                $revenueId = CompanyRevenue::create([
                    'source_type' => 'group_overflow',
                    'amount' => $forfeit,
                    'description' => 'Flush-out overflow from service group '
                        .($account->scn_code ?? $account->consultant_code)
                        .' ('.count($forfeitIds).' unmatched record(s))',
                    'recorded_at' => now(),
                ])->id;
            }

            // 3. Sweep rows already approved before this run off the leg. No
            //    payment status changes here; without this stamp they would sit
            //    on the leg forever and inflate every future run's balance. NOT
            //    cancelled and NOT booked to the company: that would claw back
            //    a real payout.
            $sweptIds = $active->flatMap(fn ($p) => $p['swept'])->pluck('id')->all();

            if ($sweptIds !== []) {
                Commission::whereIn('id', $sweptIds)->update(['released_at' => now()]);
            }

            // 4. Advance THIS account's stage, so its next flush is judged
            //    against the next threshold up: 1-to-1 → 2-to-2 → 3-to-3, then
            //    it stays on 3-to-3 forever. Written against the locked row and
            //    capped, so it is monotonic and cannot run past the repeating
            //    stage however many times the account flushes.
            $stage = $this->stageOf($fresh);

            if (\App\Services\FinanceSchemaRepair::stagingReady() && $stage < ServiceGroupStaging::ONE_TIME_STAGES) {
                Consultant::whereKey($account->id)->update(['lifetime_pair_stage' => $stage + 1]);
            }

            // 5. ⚠️ MOVED OUT OF HERE (2026-08-25, same day).
            //
            // Advancing the DRAINED accounts' stages used to happen right here.
            // It raised their threshold in the middle of the run, so an account
            // the main had partly emptied could no longer flush the remainder
            // it was still holding — and the preview, which cannot see a bump
            // that has not happened yet, promised money the run then refused to
            // pay (10,000 previewed, 5,000 paid).
            //
            // A run is ONE event, so the ladder moves once, at the end of it.
            // See runAll()'s closing step.
            //
            // (it advanced every contributing account by one stage)

            return [
                'flushed' => true,
                'reason' => '',
                'payout' => $payout,
                'forfeit' => $forfeit,
                'released' => count($releasedIds),
                'cancelled' => count($forfeitIds),
                // Already-paid rows this run only took off the leg. Reported so
                // a run that settles an account whose income was all manually
                // approved does not look like it did nothing.
                'swept' => count($sweptIds),

                // ⚠️ EXACTLY WHAT THIS FLUSH TOUCHED, so it can be undone
                // account by account (2026-08-25): "apit meka revers krannth
                // option ekak hadann, wardila giyanm ho wadiyen dila thiyeno
                // eka account wise revers krann puluwan widiyta".
                //
                // Recorded as a snapshot on the SystemRun rather than as a new
                // column on commissions: the run row already stores its
                // accounts as JSON for the same reason, and a schema change
                // needs the self-healing repair on a host with no CLI.
                //
                // Forfeited rows carry their PRIOR status — restoring a held
                // row as 'approved' would quietly release income that was never
                // approvable.
                'released_ids' => $releasedIds,
                'swept_ids' => $sweptIds,
                'forfeited_rows' => $forfeitedSnapshot,
                'revenue_id' => $revenueId,
                'stage_before' => $stage,
            ];
        });
    }

    /**
     * Every account in the system that WOULD flush right now, without changing
     * anything. Drives the confirmation screen the accountant sees before
     * committing a System Run.
     *
     * Read-only by construction: it only ever calls state(), which performs no
     * writes. The accountant is being asked to authorise a permanent forfeit
     * across the whole network, so they must be able to see the exact figure
     * first.
     *
     * It also reports the accounts that are NOT flushing but are holding money,
     * each with the reason. Without that, an account with a balance simply
     * vanished from the screen and there was no way to tell a correct "1
     * account" from a bug — which is exactly what happened on 2026-08-14, when
     * the admin queue showed "LKR 25,000 waiting on a stage" for a consultant
     * the run did not touch. Silence about a skipped account is the one thing a
     * preview must never do.
     *
     * `payout` is what the run will REALLY release, matching runAll() exactly —
     * see the note on the per-account figure below.
     *
     * @return array{cutoff: \Illuminate\Support\Carbon, accounts: array<int, array<string, mixed>>, skipped: array<int, array<string, mixed>>, payout: float, already_paid: float, forfeit: float, count: int, waiting_total: float}
     */
    public function preview(?\Illuminate\Support\Carbon $cutoff = null, bool $includeSkipped = true): array
    {
        $cutoff ??= self::defaultCutoff();

        $accounts = [];
        $skipped = [];
        $payout = 0.0;
        $alreadyPaid = 0.0;
        $forfeit = 0.0;
        $waitingTotal = 0.0;

        // ⚠️ ROWS ARE CLAIMED ONCE, IN RUN ORDER (2026-08-24).
        //
        // Since a person's main flushes their whole Service Group AND each
        // placement account still flushes its own two legs, one commission row
        // can satisfy two different pairings. The run resolves that by itself —
        // the first flush stamps released_at and the second query cannot see
        // the row — but only because it writes as it goes. The preview writes
        // nothing, so it has to simulate the same thing: same account order as
        // runAll() (both iterate eligibleAccounts(), ordered by id, so a main
        // is always reached before the accounts it owns), carrying forward
        // every row an earlier account would have taken.
        //
        // Without this the same 2,500 appears under the main AND under the
        // account it sits on, and "Released to consultants" promises double
        // what the run can pay.
        $claimed = [];

        // ⚠️ NO PER-GROUP SKIP ANY MORE (2026-08-27).
        // A $settledByRollup guard lived here and in runAll() for a few hours
        // this morning, to stop the main and its children draining the same
        // Service Group twice in one run. It is unnecessary now that the main
        // settles only its OWN rows (see legScopeQuery): every account is its
        // own settlement unit, so every one of them must still be offered.
        // $claimed still carries forward the rows an earlier account took.
        // ⚠️ ONLY ACCOUNTS THAT HOLD SOMETHING ARE LOOKED AT (2026-09-14) — see
        // accountsWithSlotRows(). An account with two empty legs can neither
        // flush nor be listed below; on the live site that was 1,224 of the
        // 1,521 active accounts, each costing a dozen queries, and the page
        // took long enough to hit the proxy's 504.
        //
        // ⚠️ AND WHEN THE "HOLDING MONEY" LIST IS NOT ASKED FOR, ONLY THE
        // ACCOUNTS THAT CAN FLUSH (2026-09-14). That list walks every account
        // holding a slot row — 297 on live — while the run itself needs only the
        // 46 with a row on BOTH legs. So the page opens on the flush list alone
        // (bothLegs) and the diagnostic list loads behind a button. The flush
        // list is IDENTICAL either way: an account that does not flush never
        // claims a row (see the can_flush branch), so narrowing the walk to
        // potential flushers cannot change what the flushers pay.
        $candidates = $this->eligibleAccounts($this->accountsWithSlotRows(bothLegs: ! $includeSkipped));
        $groups = $this->firstGroupsFor($candidates);

        foreach ($candidates as $account) {
            $group = $groups->get($account->id);

            if (! $group) {
                continue;
            }

            $state = $this->state($account, $group, $cutoff, $claimed);

            if (! $state['can_flush']) {
                if (! $includeSkipped) {
                    // The diagnostic list was not asked for — this account is
                    // only counted, below, not measured row by row.
                    continue;
                }

                // Only worth reporting if it is actually holding something —
                // otherwise every empty account in the network is listed. A
                // main appears here on its OWN balance now, like any other
                // account; it used to appear on its rollup so that a consultant
                // looking for their own SCN code would still find it.
                $holding = $state['leg1_total'] + $state['leg2_total'];

                if ($holding > 0) {
                    // ⚠️ ADD THE OWN ROWS (2026-08-28). While a main displayed
                    // its children's money AND every child was listed here in
                    // its own right, summing the main's Service Group totals
                    // counted the whole network twice — the screenshot reading
                    // "23 account(s), LKR 427,500" was roughly double what was
                    // actually waiting.
                    //
                    // Since 2026-09-02 leg*_own_total and leg*_total are the
                    // same figure (there is no rollup left to differ from), so
                    // this is now merely explicit rather than load-bearing. Kept
                    // named this way because "own rows" is the property that
                    // makes the sum correct, whatever the scope is called.
                    $waitingTotal += $state['leg1_own_total'] + $state['leg2_own_total'];

                    $skipped[] = [
                        'id' => $account->id,
                        'code' => $account->scn_code ?? $account->consultant_code,
                        'name' => $account->display_name,
                        'leg1' => $state['leg1'],
                        'leg2' => $state['leg2'],
                        'leg1_total' => $state['leg1_total'],
                        'leg2_total' => $state['leg2_total'],
                        'threshold' => $state['threshold'],
                        'reason' => $this->skipReason($state),
                    ];
                }

                continue;
            }

            // ⚠️ ONE STAGE, MIRRORING THE RUN (comment corrected 2026-08-27).
            //
            // This used to read "the whole run, not one stage", from 08-24 when
            // runAll() looped flush() until an account could no longer balance.
            // That rule was reversed on 08-25 — one flush per account per run,
            // capped, at the owner's instruction — and fullPlan() was changed to
            // loop exactly once to match. Only this comment was left behind,
            // describing the opposite of what both sides now do.
            //
            // Worked out only for an account that will flush (2026-09-14): the
            // plan of any other account was never read, and it is four queries.
            $plan = $this->fullPlan($account, $group, $cutoff, $claimed);

            // Everything this account's flush would stamp is now spoken for.
            // Pending overflow left on the leg by a ramp stage is NOT stamped,
            // so fullPlan() deliberately does not claim it either.
            $claimed = $plan['claims'];

            $accounts[] = [
                'id' => $account->id,
                'code' => $account->scn_code ?? $account->consultant_code,
                'name' => $account->display_name,
                'leg1' => $state['leg1'],
                'leg2' => $state['leg2'],
                // ⚠️ WHAT THE RUN WILL ACTUALLY PAY, not the nominal stage
                // amount (2026-08-22).
                //
                // This reported $state['payout'] — threshold × 2, what the
                // stage is WORTH — while runAll() records what flush() really
                // released. Those differ by every row an accountant (or the
                // auto-approval on upline income) already approved: such a row
                // holds its place in the threshold but has nothing left to pay,
                // so it is swept, not released.
                //
                // With upline income auto-approved, that is now the COMMON
                // case, and the two screens flatly contradicted each other:
                // reported 2026-08-22 — the preview promised LKR 5,000 on
                // SCN-00143, the run then logged "released LKR 0" even though
                // the consultant's totals had gone up correctly all along.
                //
                // Cumulative across every stage this run will walk — see
                // fullPlan(). $state['releasable'] is the first stage only.
                'payout' => $plan['payout'],
                // The nominal figure is still carried so the screen can explain
                // the difference rather than silently showing a smaller number.
                'stage_value' => $state['payout'],
                'already_paid' => $plan['already_paid'],
                'forfeit' => $plan['forfeit'],
                // "1-to-1 → 2-to-2" when a run walks more than one, so the
                // accountant can see why an account pays more than its current
                // stage is worth.
                'label' => $plan['rounds'] > 1 ? implode(' → ', $plan['stages']) : $state['label'],
                'rounds' => $plan['rounds'],
            ];

            $payout += $plan['payout'];
            $alreadyPaid += $plan['already_paid'];
            $forfeit += $plan['forfeit'];
        }

        // How many accounts hold money but will not flush — cheap, so the button
        // can name a number without walking each one. Every account holding a
        // slot row, less the ones that flushed. Null (schema not ready / rate 0)
        // means "cannot tell", shown as 0.
        $holdingCount = 0;

        if (! $includeSkipped) {
            $moneyHolding = $this->accountsWithSlotRows(bothLegs: false);
            $holdingCount = $moneyHolding === null ? 0 : max(0, count($moneyHolding) - count($accounts));
        }

        return [
            'cutoff' => $cutoff,
            'accounts' => $accounts,
            'skipped' => $skipped,
            // The "Holding money" list was left uncomputed for speed; the view
            // shows a button that reloads with it. See index().
            'skipped_hidden' => ! $includeSkipped,
            'holding_count' => $holdingCount,
            'payout' => $payout,
            // Money this run takes OFF the legs that was already paid out when
            // it was approved. Not a new payout and not a loss — but without it
            // on screen, an account settling entirely pre-approved income looks
            // like a run that did nothing.
            'already_paid' => $alreadyPaid,
            'forfeit' => $forfeit,
            'count' => count($accounts),
            'waiting_total' => $waitingTotal,
        ];
    }

    /**
     * Why an account holding money is not being flushed by this run.
     *
     * Ordered by what actually blocks it first: a locked account can never
     * flush whatever its legs hold, and a leg short of the threshold is the
     * normal case. The cutoff note comes last because it only explains part of
     * a shortfall, never the whole reason on its own.
     */
    protected function skipReason(array $state): string
    {
        if ($state['locked_manually'] ?? false) {
            return 'Account locked by an admin. Its balance is fine — no number of students will open it; '
                .'an admin must set it back to Automatic or Unlocked from the consultant\'s user page.';
        }

        if ($state['locked']) {
            return 'Account locked — '.($state['blocked_by'] ? $state['blocked_by'].' ' : 'the previous account ')
                .'needs '.$state['unlock_needed'].' more student(s) before this account can release anything. '
                .'Its balance is fine; the lock is what is stopping it.';
        }

        // Rupees are what the legs hold, but students are what someone can go
        // and do something about, so say both.
        $rate = max(1.0, (float) ($state['rate'] ?? 2500));
        $asStudents = fn (float $amount) => (int) ceil($amount / $rate);

        $short = [];

        foreach ([1, 2] as $leg) {
            $needed = $state['need_leg'.$leg];

            if ($needed > 0) {
                $students = $asStudents($needed);
                $short[] = $students.' more student'.($students === 1 ? '' : 's')
                    .' on Service Group '.$leg.' (LKR '.number_format($needed).')';
            }
        }

        $reason = $short === []
            ? 'Not eligible for this run.'
            : 'Needs '.implode(' and ', $short).' — this account settles at LKR '
                .number_format($state['threshold']).' on each side ('.$state['label'].').';

        if ($state['waiting_after_cutoff'] > 0) {
            $reason .= ' LKR '.number_format($state['waiting_after_cutoff'])
                .' on these legs was registered after the cutoff — move the date forward to include it.';
        }

        return $reason;
    }

    /**
     * The System Run itself: flush EVERY eligible account in one pass.
     *
     * Each account is flushed in its own transaction (flush() opens one), so a
     * failure on one account cannot roll back the accounts already settled
     * before it — the alternative, one giant transaction across every
     * consultant in the network, would hold locks on the whole `consultants`
     * table for the duration and turn one bad row into a total failure.
     *
     * @return array{cutoff: \Illuminate\Support\Carbon, flushed: int, payout: float, forfeit: float, failures: array<int, string>}
     */
    public function runAll(?\Illuminate\Support\Carbon $cutoff = null): array
    {
        $cutoff ??= self::defaultCutoff();

        // Stamped before anything moves, so the closing step can find exactly
        // the rows THIS run settled. See the stage bump at the end.
        $runStartedAt = now();

        $flushed = 0;
        $payout = 0.0;
        $forfeit = 0.0;
        $settled = [];
        $failures = [];
        $flushedAccountIds = [];

        // ⚠️ EVERY ACCOUNT IS OFFERED, IN eligibleAccounts() ORDER.
        //
        // A "$settledByRollup" guard lived here on 08-27, skipping a child once
        // its main had settled that Service Group. It was written when the main
        // ran FIRST, and its job was to stop a stage-1 run draining one side by
        // 7,500 and the other by 2,500 — reported with before/after screenshots:
        // "stage 1 (service group 1 eken 2500 service group 2 eken 2500i okkom
        // 5000 release wenn one)... eka paththk withrk ai hari stagen gihilla
        // anith paththe wardiyt wenne".
        //
        // The order is now the other way round (children first, mains last), so
        // that cannot arise: a child takes its own balanced pair, and the main
        // then pairs whatever surplus is left across the two Service Groups. A
        // released row is invisible to every later query, so the same money can
        // never be settled twice — the guard is unnecessary and would now be
        // silencing the account that should run.
        // ⚠️ ONLY ACCOUNTS WITH ROWS ON BOTH SIDES ARE OFFERED (2026-09-14) —
        // see accountsWithSlotRows(). flush() refuses anything short of the
        // stage amount on BOTH legs, so an account with an empty leg was locked,
        // measured and refused for nothing; on the live site 46 of the 1,521
        // active accounts could run at all. Same accounts, same order, same
        // flushes — only the refusals are no longer paid for.
        $candidates = $this->eligibleAccounts($this->accountsWithSlotRows(bothLegs: true));
        $groups = $this->firstGroupsFor($candidates);

        foreach ($candidates as $account) {
            $group = $groups->get($account->id);

            if (! $group) {
                continue;
            }

            // ⚠️ ONE STAGE PER RUN, CAPPED (2026-08-25).
            //
            // For one day this looped flush() until the account could no longer
            // balance, so a run could walk 1-to-1 → 2-to-2 → 3-to-3 and pay an
            // account 30,000. The owner has set the rule the other way, with
            // the amounts written out: "system run ekedi service group walin
            // SCN account ekat enne 1to1 ekedi 5000i, 2to2 ekedi 10000, 3to3
            // ekedi 15000i — ita wadiyen release wen na."
            //
            // So each account gets exactly one flush per run: it settles its
            // CURRENT stage, advances one step, and waits for the next run. At
            // the repeating stage everything past 7,500 a side is closed off to
            // the company inside that same round (legPlan()); on the ramp the
            // remainder stays on the leg for next time.
            $accountPayout = 0.0;
            $accountForfeit = 0.0;
            $accountReleased = 0;
            $accountCancelled = 0;

            try {
                $result = $this->flush($account->fresh() ?? $account, $group, $cutoff);
            } catch (\Throwable $e) {
                report($e);
                $failures[] = ($account->scn_code ?? $account->consultant_code).': '.$e->getMessage();

                continue;
            }

            // A refusal is the normal case for most accounts (not balanced
            // yet) — only count and report the ones that actually moved.
            if (! $result['flushed']) {
                continue;
            }

            $rounds = 1;
            $accountPayout = (float) $result['payout'];
            $accountForfeit = (float) $result['forfeit'];
            $accountReleased = (int) $result['released'];
            $accountCancelled = (int) $result['cancelled'];


            // Counts ACCOUNTS, not rounds — "2 accounts flushed" is what the
            // screen and the SystemRun record have always meant.
            $flushed++;
            $flushedAccountIds[] = $account->id;
            $payout += $accountPayout;
            $forfeit += $accountForfeit;

            // Named, not just counted. "1 account" with no way to find out
            // which one is not an audit trail.
            //
            // Read straight off the accumulators rather than the loop's last
            // $result, which holds only the FINAL round — an account that
            // walked 1-to-1 then 2-to-2 would otherwise be recorded as having
            // released just the 10,000.
            $settled[] = [
                'code' => $account->scn_code ?? $account->consultant_code,
                'name' => $account->display_name,
                'released' => $accountPayout,
                'forfeited' => $accountForfeit,
                'records' => $accountReleased,
                'cancelled' => $accountCancelled,
                // How many stages this account walked in this one run.
                'rounds' => $rounds,
                // ⚠️ The undo record. See flush()'s note — a reversal needs to
                // know exactly which rows moved and what they were before.
                'account_id' => $account->id,
                'released_ids' => $result['released_ids'] ?? [],
                'swept_ids' => $result['swept_ids'] ?? [],
                'forfeited_rows' => $result['forfeited_rows'] ?? [],
                'revenue_id' => $result['revenue_id'] ?? null,
                'stage_before' => $result['stage_before'] ?? null,
            ];
        }

        // ⚠️⚠️ REMOVED 2026-08-29 — A STAGE IS EARNED BY PAIRING, NOTHING ELSE.
        //
        // A block here used to bump `lifetime_pair_stage` for every account whose
        // rows had been released by SOMEBODY ELSE'S flush — i.e. drained by the
        // main's rollup. It was written on 08-25 while the main ran FIRST, when a
        // child could be emptied before it ever got a turn and would otherwise
        // "sit frozen on 1-to-1 forever, still advertising a pairing that can
        // never happen because the main reaches their money first every time".
        //
        // That premise is gone: eligibleAccounts() puts placement accounts FIRST
        // (2026-08-28), so every account gets its own chance to pair before a
        // main touches it. An account that did not pair genuinely did not pair,
        // and bumping it RAISED the threshold it must reach for a pairing it
        // never made — 1-to-1 to 2-to-2 to 3-to-3 on somebody else's payout.
        //
        // That is the second half of the 08-29 report ("hariyt stage ekata
        // calculation eka un na"): SCN-00101 and SCN-00102 were advertising
        // 3-to-3 while holding almost nothing and having settled almost nothing
        // themselves, so they needed 7,500 a side and could never run again.
        //
        // Accounts that flush advance inside flush(), which is the only place a
        // stage moves now. Pinned by
        // AMainNeverForfeitsItsAccountsMoneyTest::test_being_drained_by_the_main_does_not_advance_an_accounts_stage.

        return [
            'cutoff' => $cutoff,
            'flushed' => $flushed,
            'payout' => $payout,
            'forfeit' => $forfeit,
            'accounts' => $settled,
            'failures' => $failures,
        ];
    }

    /**
     * Every account a run should consider: real consultants and their hidden
     * placement accounts alike, since each one carries its own two legs.
     *
     * `owner` is eager-loaded because owningPerson() is reached on each of
     * these (via isUnlocked/previousAccountId), and `user` / `owner.user`
     * because preview() reads display_name — a hidden placement account has no
     * user of its own and borrows its owner's name. Model::preventLazyLoading()
     * is active outside production, so each of those would throw.
     *
     * @return \Illuminate\Support\Collection<int, Consultant>
     */
    protected function eligibleAccounts(?array $only = null)
    {
        return Consultant::with(['owner', 'user', 'owner.user'])
            ->where('status', 'active')
            // Narrowed to the accounts that hold something — see
            // accountsWithSlotRows(). null means every active account.
            ->when($only !== null, fn ($q) => $q->whereIn('id', array_keys($only)))
            ->orderBy('id')
            ->get()
            // ⚠️ PLACEMENT ACCOUNTS FIRST, MAINS LAST (2026-08-28).
            //
            // Both settle the same rows: a main pairs the Service Groups its
            // children physically hold. Whoever runs first takes them, so the
            // order decides who gets stranded — and it must be the children who
            // go first, because a child can only ever pair its own two legs
            // while a main can pair anything left over across the group.
            //
            // Main-first (the pre-08-27 order) let the main lift rows out from
            // under a child that was one student away from balancing.
            // Child-first gives every account its own guaranteed pair and hands
            // the main the surplus — which is the only reading under which
            // "main paran logic e widiytam thiyala" and "parana logic walata
            // gataluwk wen nathi wenn" can both hold.
            //
            // preview() walks this same list in this same order, which is what
            // keeps its $claimed simulation matching what the run really pays.
            ->sortBy(fn (Consultant $account) => sprintf('%d-%012d', $account->is_hidden ? 0 : 1, $account->id))
            ->values();
    }

    /**
     * The accounts a run, or its preview, can have anything to say about.
     *
     * ⚠️ SPEED, NOT RULES (2026-09-14). Reported: "System run eka godk slow ...
     * 504 wage error enwa". Both the page and the run walked every active
     * account — 1,521 on the live site — and each one cost a dozen queries,
     * although 1,224 held no slot row at all and only 46 held one on both
     * sides. This finds, in one query, the accounts that do.
     *
     * ⚠️ A SUPERSET, NEVER A GUESS. Every row legScopeQuery() can return is an
     * unreleased, uncancelled slot row on leg 1 or 2 of the account; this asks
     * for exactly that and nothing narrower. Held rows, group membership and the
     * date are deliberately left out — they only ever take rows AWAY from what
     * legScopeQuery() returns — so leaving them out can keep an extra account
     * in, but can never drop one that could flush or be listed.
     *
     *   $bothLegs = false : an account holding nothing is not listed by preview().
     *   $bothLegs = true  : flush() needs the stage amount on BOTH legs, so an
     *                       account with an empty leg is refused whatever it has.
     *
     * Returns null — do not filter — whenever that reasoning would not hold:
     * without released_at nothing can be judged, and at a commission rate of 0
     * the stage amount is 0, so even an empty account would "qualify".
     *
     * @return array<int, true>|null account ids as keys
     */
    protected function accountsWithSlotRows(bool $bothLegs): ?array
    {
        if (! \App\Services\FinanceSchemaRepair::flushReady()
            || (float) CommissionSetting::current()->commission_per_student <= 0) {
            return null;
        }

        $query = Commission::query()
            ->where('type', CommissionService::TYPE_SLOT)
            ->where('status', '!=', 'cancelled')
            ->whereNull('released_at')
            ->whereIn('tier', [1, 2])
            ->groupBy('consultant_id')
            ->select('consultant_id');

        if ($bothLegs) {
            $query->havingRaw('COUNT(DISTINCT tier) = 2');
        }

        return array_fill_keys(
            $query->pluck('consultant_id')->map(fn ($id) => (int) $id)->all(),
            true,
        );
    }

    /**
     * Each account's first Service Group, in one query — the same group
     * `Group::where('consultant_id', …)->orderBy('id')->first()` returns, which
     * the loops used to ask for once per account.
     *
     * @param  \Illuminate\Support\Collection<int, Consultant>  $accounts
     * @return \Illuminate\Support\Collection<int, Group> keyed by account id
     */
    protected function firstGroupsFor($accounts)
    {
        return Group::whereIn('consultant_id', $accounts->pluck('id')->all())
            ->orderBy('id')
            ->get()
            ->unique('consultant_id')
            ->keyBy('consultant_id');
    }

    /**
     * Slot income still sitting on one leg: recorded, not yet released by a
     * flush, and not held.
     *
     * ⚠️ APPROVAL STATE IS DELIBERATELY NOT FILTERED (fixed 2026-08-17).
     *
     * This query used to require `status = 'pending'`, which meant the per-row
     * manual "Approve" override silently REMOVED that student from their own
     * leg. The consultant then had to find three more students on that side to
     * reach 7,500, having lost all credit for the one that was paid — and
     * because the account's own row on the Service Groups page showed only
     * this balance, their 2,500 appeared to vanish from their account and
     * "move" to the Main rollup, which is how it was reported (SCN-00107,
     * 2026-08-17: "eka SCN 107 ain wela eka main ekat add wenwa... dekenm 2500
     * penwann one, eka 0 wenn one system run ekedi").
     *
     * It also directly contradicted the documented rule, which has said since
     * 2026-08-15 that "a force-approved row never reduces the leg balance —
     * the flush is what does that" (commission-rules-2026-08.md). The override
     * is meant to be a one-off early payout, NOT a discount that also resets
     * the leg. So the leg now holds every unflushed, unheld, uncancelled row
     * regardless of approval state, and ONLY a System Run empties it.
     *
     * `cancelled` rows are excluded because a previous flush already forfeited
     * them; `held_at` rows because a restricted consultant's income is neither
     * payable nor forfeitable (see the class docblock).
     *
     * See legPlan() for how the two states are then treated differently at
     * flush time — an already-approved row is swept off the leg but is never
     * paid twice and never forfeited.
     *
     * $cutoff, when given, restricts the run to students REGISTERED on or
     * before that moment — deliberately keyed to `payments.created_at` (the
     * moment the consultant registered the student) rather than to the
     * commission's own timestamp or the payment's verified_at. The owner was
     * explicit about this: a student added yesterday whose pending/balance
     * payment only clears today still belongs to YESTERDAY's run — "iye dawse
     * damm student lage pending hari blanace payment ekak ada dammoth eka iye
     * dawse damm complte payment ekak widiyta yano". Keying it to the
     * commission row instead would push that student into the next day's run,
     * which is exactly what the owner said must not happen.
     */
    protected function legScopeQuery(Consultant $account, Group $group, int $leg, ?\Illuminate\Support\Carbon $cutoff = null, array $exclude = [])
    {
        $query = Commission::query()
            ->where('type', CommissionService::TYPE_SLOT)
            ->where('status', '!=', 'cancelled')
            ->whereNull('held_at')
            ->whereNull('released_at');

        // ⚠️⚠️ THE ROLLUP IS GONE — ONE SCOPE FOR EVERY ACCOUNT (2026-09-02).
        //
        // A `$rollup` branch lived here. For a main it read every unflushed row
        // on every account hanging directly beneath it, so the main DISPLAYED
        // its children's money; legPlan() then passed rollup: false, so it
        // SETTLED only its own rows — of which it had none, because the climb
        // stopped one hop short of it. Displaying one set of rupees and
        // settling a different one IS the eight-report bug: the screen read
        // "SG1 LKR 5,000 · SG2 LKR 12,500" while the run read LKR 0 / LKR 0 on
        // the same account in the same minute, and each attempt to reconcile
        // them broke the other half — settling the rollup drained children that
        // had not balanced, settling own rows left the main frozen.
        //
        // The main now EARNS its own rows (CommissionService::creditOwnAccountAncestors),
        // so there is nothing left to roll up: one scope answers both
        // questions, and the number on the screen is by construction the number
        // the run settles. ⚠️ The two halves are ONE decision — restoring
        // either on its own puts the contradiction straight back.
        //
        // The old branch is preserved verbatim below for the reasoning it
        // carries, which is still the best record of why the main must never
        // reach into a child:
        //
        // > Why: the main paired account-101 against account-102 and drew from
        // > whichever leg inside them happened to hold money, which left the
        // > child itself unbalanced. Owner, off a real run: 101 went
        // > 2,500/27,500 -> 0/27,500 (one side only) and 102 went 12,500/0 ->
        // > 10,000/0 (its other side empty) — "eka group ehek wath stage samana
        // > nathiw system run yann ba".
        //
        // ⚠️ THE SEAT'S TIER IS NOT PART OF THIS TEST (2026-09-01).
        //
        // It read `->where('tier', $leg)` on the commission AND on the SEAT.
        // Those were always the same value while awardSlot() copied the seat's
        // tier onto the row — but that copy IS the cross fixed on 2026-09-01,
        // so an upline credit now legitimately sits on a different leg from the
        // seat beneath it. Left in, the two conditions contradict: a row
        // carrying leg 2 with a leg-1 seat is returned by NEITHER leg, and the
        // money vanishes from both sides of the account.
        //
        // The GROUP scoping stays: a row still has to belong to a seat in this
        // account's own group. Only the leg is read from the row itself, which
        // is where the leg now lives.
        // ⚠️⚠️ A HAND-ENTERED ROW HAS NO SEAT (2026-09-15).
        //
        // `whereIn('group_member_id', …)` alone can never match a NULL, so a
        // commission added by hand — which has no student, and therefore no seat
        // — was written, counted in the consultant's Service Group totals, and
        // then INVISIBLE to every leg query. The page printed the 2,500 while a
        // System Run read LKR 0 on the same account in the same minute, and the
        // money could never settle: "add a commission by hand eke add kran
        // commission add wen na".
        //
        // Such a row still has to belong to THIS account's group, which is what
        // group_id records, so the two branches together assert exactly what the
        // seat branch always asserted: the money is on this account's leg.
        $query->where('consultant_id', $account->id)
            ->where('tier', $leg)
            ->where(fn ($q) => $q
                ->whereIn('group_member_id', \App\Models\GroupMember::where('group_id', $group->id)
                    ->select('id'))
                ->orWhere(fn ($w) => $w
                    ->whereNull('group_member_id')
                    ->where('group_id', $group->id)));


        // ⚠️ PREVIEW DE-DUPLICATION. The main and the sub accounts both flush,
        // so one commission row can be claimed by two different pairings — the
        // main's Service Group pairing and its own account's internal pairing.
        // The RUN is naturally safe: whichever flushes first stamps
        // released_at, and the next query cannot see it. The PREVIEW has no
        // such stamp, so preview() walks the accounts in the same order the run
        // does and passes on what has already been claimed. Without it the
        // screen promises the same money twice.
        if ($exclude !== []) {
            $query->whereNotIn('id', $exclude);
        }

        if ($cutoff) {
            // ⚠️⚠️ SCOPED BY THE SELECTED PAYMENT DATE (2026-09-14).
            //
            // This keyed on payments.created_at — the moment the row was
            // inserted. The owner's rule is now the DATE the consultant picks on
            // the form: "payment eka kawada dammath ... select krana Payment Date
            // e widiyt en one", with the example of a payment dated 11 Sep,
            // verified 14 Sep, that must settle in an 11 Sep run.
            //
            // That date is payments.paid_at. It is built as
            // Carbon::parse($payment_date)->setTimeFrom(now()) (StudentController),
            // so its DATE part is exactly the day the consultant chose and its
            // time is merely when they submitted — which is why the comparison is
            // on DATE(paid_at), not the timestamp: the time carries no meaning and
            // reading it against a Colombo end-of-day boundary would push a
            // backdated payment submitted after 6 am Colombo onto the wrong day.
            //
            // date() over a Colombo date string, so no timezone conversion is
            // needed: DATE(paid_at) already IS the chosen Colombo day, and the
            // cutoff's own Colombo date is what a run settles up to. coalesce to
            // created_at for the rare row with no paid_at (0 of 2,034 verified on
            // the live site, but free cards and old data can lack it).
            //
            // ⚠️ ONLY THE DATE IN SCOPE CHANGED — no stage, pairing, threshold or
            // order-by rule is touched ("stage wise balance ... logic wens krnn
            // epa"). Release order is still by id.
            $cutoffDate = $cutoff->copy()->setTimezone('Asia/Colombo')->toDateString();

            // ⚠️ A ROW WITH NO PAYMENT IS DATED BY ITS OWN `earned_at` (2026-09-15).
            //
            // Hand-entered income and orphaned rows (their payment was deleted —
            // both FKs are nullOnDelete) both carry a NULL payment_id, and a NULL
            // can never match an IN-list. They used to be waved through on ANY
            // date, which left no way to say which run should settle a hand
            // entry — "system run ekedi me add kran point adal day ekat add
            // kragnn on nisa methanat date ekak dala denn".
            //
            // incomeDateSql() is COALESCE(earned_at, created_at), guarded for a
            // database where that column has not been added yet — so an orphan
            // carrying no earned_at still settles by the day it was written
            // rather than being stranded on the leg for ever.
            $incomeDate = \App\Services\FinanceSchemaRepair::incomeDateSql();

            $query->where(fn ($q) => $q
                ->where(fn ($w) => $w
                    ->whereNull('payment_id')
                    ->whereRaw("date({$incomeDate}) <= ?", [$cutoffDate]))
                ->orWhereIn('payment_id', \App\Models\Payment::whereRaw('date(coalesce(paid_at, created_at)) <= ?', [$cutoffDate])->select('id')));
        }

        return $query;
    }

    /**
     * The cutoff a System Run uses by default: the end of TODAY in Asia/Colombo.
     *
     * ⚠️ MOVED FROM YESTERDAY TO TODAY (2026-09-02), at the owner's choice.
     *
     * It was the end of YESTERDAY, on the reasoning that a run is done the day
     * AFTER the activations it settles ("iye (13) dawse system run eka kranne
     * ilaga dawse"), so anything registered today waits for tomorrow.
     *
     * That is defensible as a routine and indefensible as a default, because it
     * makes a correct run look like a broken one. Everything registered today
     * is real, approved, and visible on the Service Groups page — and the run
     * then reported LKR 0 on those same accounts with "LKR 10,000 on these legs
     * was registered after the cutoff". Two screens, same minute, same account,
     * different answers. The owner read that as the run refusing to work and
     * reported it as a bug ("system run eka krann den na, amount eka 0
     * penwanawa"), which is the correct reading of what it showed them.
     *
     * The date picker still goes back as far as they like, so the
     * settle-yesterday routine is one click away and nothing about what a
     * cutoff MEANS has changed. Only which day is pre-filled.
     *
     * Skipping a day strands nothing either way — the test is "on or before",
     * not "on", so a later run still sweeps in whatever an earlier one missed.
     *
     * config('app.timezone') is UTC on this install while the business runs on
     * UTC+5:30, so the zone is named explicitly rather than inherited.
     *
     * Returned in COLOMBO time deliberately — screens print this date and
     * "11:59 pm" must read as the local end of day. legScopeQuery() converts it
     * to the storage timezone at the point of comparison; do not convert it
     * here or every label shifts.
     */
    public static function defaultCutoff(): \Illuminate\Support\Carbon
    {
        return \Illuminate\Support\Carbon::now('Asia/Colombo')->endOfDay();
    }

    /**
     * The furthest the accountant may push the cutoff: the end of TODAY.
     *
     * Distinct from defaultCutoff() on purpose. Yesterday is the routine;
     * today is allowed because a same-day settlement is sometimes genuinely
     * wanted, and the first build's ceiling of "yesterday" made it impossible
     * rather than merely non-default. Beyond today is refused — nothing can be
     * registered in the future, so it would settle nothing extra and only
     * misstate what the run covers.
     */
    public static function latestSelectableCutoff(): \Illuminate\Support\Carbon
    {
        return \Illuminate\Support\Carbon::now('Asia/Colombo')->endOfDay();
    }

    /**
     * ⚠️⚠️ isRollupFlusher() AND accountsInOwnerGroup() WERE DELETED HERE (2026-09-02).
     *
     * They existed only to answer "which of my children's rows does this main
     * display?". A main has its own rows now, so the question has no meaning:
     * legScopeQuery() has a single branch and every account — main or placement
     * — is scoped to the rows physically written against it.
     *
     * ⚠️ ONE RULE THEY CARRIED HAS MOVED, NOT VANISHED. accountsInOwnerGroup()
     * ended with `->reject(lockOverride() === LOCK_FORCED_SHUT)`: an account an
     * admin deliberately closed must not reach a payout by ANY route, including
     * up through the main. That guard now lives at the top of the climb, in
     * CommissionService::creditOwnAccountAncestors() — a force-shut DIRECT CHILD
     * stops the climb before the main is credited, so the row is never written
     * rather than written and then filtered out. Same scope (direct children
     * only), same reason, enforced one step earlier.
     *
     * The 12-student chain lock deliberately did NOT filter here and still does
     * not: it gates an account's own payout, while its income keeps climbing to
     * the owner ("e account walin hamben ewa mekat add krnn... main eka flush
     * out wenn one system run ekedi", 2026-08-27). Pinned by
     * LockedGroupIsStillVisibleTest.
     */

    /**
     * The account that gates this one, for the locked message.
     *
     * ⚠️ DELEGATES — DO NOT REIMPLEMENT (2026-08-22).
     *
     * This held its own copy of the chain, walking every placement account in
     * id order. ServiceGroupStaging::previousAccountId() — which is what
     * actually DECIDES whether the account is locked — skips set-mains, since
     * nothing can ever be placed into one. So the two drifted: isUnlocked()
     * gated on #2 while this message named #3, telling a consultant to go and
     * fill an account that can never take a student.
     *
     * That is the same failure mode as the leg mapping, which was duplicated
     * three times and disagreed with itself until it was consolidated into
     * Consultant::serviceGroupLeg(). One implementation, read from both places.
     */
    protected function previousAccount(Consultant $account): ?Consultant
    {
        $previousId = $this->staging->previousAccountId($account);

        return $previousId ? Consultant::find($previousId) : null;
    }
}
