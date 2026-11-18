<?php

namespace App\Http\Controllers\Consultant;

use App\Concerns\ResolvesOwnedStudents;
use App\Http\Controllers\Controller;
use App\Models\Commission;
use App\Models\CommissionSetting;
use App\Models\Consultant;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Http\Request;

class GroupController extends Controller
{
    use ResolvesOwnedStudents;

    /** How many members to render per leg before asking the user to narrow down. */
    private const MEMBERS_PER_LEG = 50;

    /** How many downline people each tab renders before it says "and N more". */
    private const TEAM_PEOPLE_MAX = 100;

    /** And how many students under each of them. */
    private const TEAM_STUDENTS_PER_PERSON = 50;

    /** Matches CommissionService::MAX_UPLINE_DEPTH — the codebase's walk-depth convention. */
    private const TEAM_MAX_DEPTH = 50;

    /** Breadth ceiling on the walk, so corrupt data degrades instead of exhausting memory. */
    private const TEAM_MAX_PEOPLE_WALKED = 5000;

    /** Student rows fetched for the tree; the tab count stays honest via a separate COUNT(*). */
    private const TEAM_STUDENT_ROWS_MAX = 5000;

    protected function consultant()
    {
        $consultant = auth()->user()->consultant;
        abort_if(! $consultant, 403, 'No consultant profile found for your account.');

        return $consultant;
    }

    public function index(Request $request)
    {
        $consultant = $this->consultant();
        $settings = CommissionSetting::current();

        // Everything the VIEWER may inspect: their own accounts plus their
        // whole downline's. This is the security boundary and is always the
        // signed-in consultant's, never the subject's.
        $reachableIds = $consultant->reachableAccountIds();

        // Whose service groups are on screen. A consultant sees their own by
        // default, and may switch to anyone below them in their network —
        // placement accounts are excluded, they are buckets, not people.
        $viewable = Consultant::whereIn('id', array_merge([$consultant->id], $consultant->downlineIds()))
            ->where('is_hidden', false)
            ->with('user:id,name')
            ->orderBy('scn_code')
            ->get();

        $subject = $viewable->firstWhere('id', $request->integer('consultant')) ?? $consultant;

        // The subject's own accounts (their SCN + placement accounts).
        $accountIds = $subject->accountIds();

        $stats = [
            'accounts' => count($accountIds),
            'members' => GroupMember::whereIn('group_id', Group::whereIn('consultant_id', $accountIds)->select('id'))->count(),
            'downline' => count($subject->downlineIds()),
        ];

        // Only approved (or already paid) income counts as the consultant's —
        // a record still awaiting the accountant's approval is not theirs yet.
        // "Service Group 1/2" here means slot income only (type = bonus) — the
        // direct-add bonus (type = referral) is a separate figure below, not
        // mixed into the per-leg totals.
        $earnedIncome = fn () => Commission::whereIn('consultant_id', $accountIds)
            ->whereIn('status', ['approved', 'paid']);

        $directIncome = (float) $earnedIncome()->where('type', 'referral')->sum('amount');

        // Headline Service Group 1 / 2 income across the consultant's own accounts.
        //
        // ⚠️ ATTRIBUTED BY ACCOUNT, NOT BY EACH ROW'S OWN TIER (2026-08-21).
        //
        // A placement account IS one of the owner's legs — SCN-00128 IS Service
        // Group 1, SCN-00129 IS Service Group 2 — so everything an account
        // earns belongs to the leg that account represents, whatever tier it
        // sits on INSIDE that account. Owner: "SCN 129 service group 1 dammath
        // 2 dammath ynn one SCN 127 eke service group 2 ekata, ekata hethuw
        // SCN 127 service group 2 eka yatathe thama SCN 129 thiyenne."
        //
        // Grouping by the raw `tier` column instead put a tier-1 row earned on
        // SCN-00129 into the owner's Service Group 1 — reported 2026-08-21 as
        // the Main row showing SG1 2,500 / SG2 0 when the only earnings were on
        // SCN-00129, which IS Service Group 2.
        //
        // Only a MAIN account's own rows keep their own tier: a main account
        // represents no leg, so its rows are already on the leg they belong to.
        //
        // ⚠️ RESOLVED BY PLACEMENT, NOT BY ORDINAL (2026-08-23).
        //
        // This read Consultant::serviceGroupLeg(), which numbers accounts by
        // POSITION (#4 -> 1, #5 -> 2, #7 -> 1, #8 -> 2). For the opening pair
        // that is correct — they really do sit directly under the main. For a
        // BOUGHT set it is not: a set bought into Service Group 1 belongs to
        // Service Group 1 entirely, all three accounts, whichever internal leg
        // a student lands on. The ordinal rule filed the set's second leg under
        // the owner's Service Group 2 instead, so half of every bought set's
        // income crossed to the opposite side of the headline on the way up —
        // and buying into Service Group 2 mirrored it. Reported 2026-08-23:
        // "2500 cross wela wadinwa udata ynakota... ohom cross wenn ba".
        //
        // AccountPlacementMap climbs the real placement chain instead. See its
        // withOwnerGroups() for why the two answers must not be conflated.
        $placements = app(\App\Services\AccountPlacementMap::class)->for($subject);

        $legOfAccount = collect($accountIds)
            ->mapWithKeys(fn (int $id) => [$id => $placements[$id]['owner_group'] ?? null]);

        // ⚠️⚠️ THE MAIN'S OWN ROWS, AND NOTHING ELSE (2026-09-02).
        //
        // THE RULE HAS NOT CHANGED — ITS ANSWER HAS. Slot income CLIMBS, so the
        // same student is recorded once on every account they pass through, and
        // this headline must count them exactly ONCE: at the HIGHEST point their
        // money reached. Anything lower is a copy.
        //
        // That rule was written on 2026-08-25, when the climb stopped one hop
        // short of the main, so the highest point was the account hanging
        // directly under it and this list was "the main plus its direct
        // children". The owner's screen at the time: SCN-00101 held 10,000,
        // SCN-00106 (under 00101) 2,500 and SCN-00107 (under 00106) 5,000, and
        // the headline read 17,500 — "me amount tika duplicate wela... methanat
        // enn one amount eka 10000".
        //
        // Since 2026-09-02 the climb runs all the way to the main, which now
        // earns a row of its own for every student in the tree. The highest
        // point IS the main, so including the direct children counts every
        // student twice over — one 2,500 student reported 5,000. Caught by
        // MainRollupCountsEachStudentOnceTest, which is the test that has
        // guarded this arithmetic through both versions of the rule.
        //
        // ⚠️ This is also what makes the headline and the System Run agree by
        // construction rather than by coincidence: both now read exactly the
        // rows written against this one account.
        //
        // Two things deliberately unaffected:
        //  - the DIRECT BONUS still sums every account. It never climbs, so it
        //    is only ever recorded in one place and there is no copy to drop.
        //  - money whose climb never REACHED the main (an account whose
        //    placement was never recorded, so it sits in neither Service Group)
        //    is not here and never was — $pendingUngrouped below names it
        //    rather than letting it read as lost.
        $headlineAccountIds = [(int) $subject->id];

        // Still needed further down, for the force-shut reporting only — those
        // accounts are named BECAUSE their money is not in the headline.
        $directChildIds = collect($accountIds)
            ->filter(fn (int $id) => $id !== (int) $subject->id
                && (int) ($placements[$id]['under_id'] ?? 0) === (int) $subject->id)
            ->values()
            ->all();

        $slotRows = $earnedIncome()
            ->where('type', 'bonus')
            ->whereIn('consultant_id', $headlineAccountIds)
            ->selectRaw('consultant_id, tier, SUM(amount) as total, COUNT(*) as members')
            ->groupBy('consultant_id', 'tier')
            ->get();

        // A null-leg account keeps its rows' own tier. That covers two cases:
        // a person's own MAIN (documented above), and a bought set's set-main,
        // whose money genuinely sits on its own internal leg 1 / leg 2.
        //
        // ⚠️ The result MUST land in bucket 1 or 2 (2026-08-22). The view only
        // renders $tierTotals[1] and [2], so a stray tier — a null casting to
        // 0 — built a phantom bucket that no card reads and the money simply
        // vanished from the headline while still sitting in the ledger.
        $attributedLeg = function ($row) use ($legOfAccount) {
            $leg = $legOfAccount[$row->consultant_id] ?? null;

            if ($leg === null) {
                $leg = (int) $row->tier;
            }

            return in_array($leg, [1, 2], true) ? $leg : 1;
        };

        $tierTotals = $slotRows
            ->groupBy($attributedLeg)
            ->map(fn ($rows, $leg) => (object) [
                'tier' => (int) $leg,
                'total' => (float) $rows->sum('total'),
                'members' => (int) $rows->sum('members'),
            ]);

        // Per-account table: each SCN with its own Service Group 1 / 2 income.
        $perAccountTiers = $earnedIncome()
            ->where('type', 'bonus')
            ->selectRaw('consultant_id, tier, SUM(amount) as total')
            ->groupBy('consultant_id', 'tier')
            ->get()
            ->groupBy('consultant_id');

        // Per-account Direct Add Bonus — this account's own registrants'
        // bonuses, so "Account Total" can be SG1 + SG2 + Direct rather than
        // silently leaving the direct bonus out (that was reported as
        // "direct income not calculating" — it WAS calculating, into the
        // headline $directIncome figure above, just never folded into any
        // one account's own row).
        $perAccountDirect = $earnedIncome()
            ->where('type', 'referral')
            ->selectRaw('consultant_id, SUM(amount) as total')
            ->groupBy('consultant_id')
            ->pluck('total', 'consultant_id');

        // The direct-add bonus ALREADY RECORDED but still pending review.
        //
        // The 4,000 is earned the instant a payment is verified — it never
        // waits for a pair and never waits for a flush (awardDirectBonus()).
        // But every figure above deliberately counts approved money only, and
        // nothing on this page showed the recorded-but-pending side, so a
        // consultant who had just registered students saw "Direct Add Bonus
        // LKR 0" on every row and reasonably concluded the 4,000 was not being
        // calculated at all (reported 2026-08-17: "meke 4000 auto cal wenn
        // one"). It WAS being calculated — it was simply invisible while held
        // or otherwise pending review.
        //
        // This is the same answer the owner already chose for the slot income
        // ("meke 2500 penwann one eth payout krnn thama group balance eka wenn
        // one"): SHOW it as soon as it is earned, and let approval keep
        // governing the payout. So the approved figures above keep their exact
        // meaning — and the approval gate they encode is untouched — while the
        // recorded amount is now displayed beside them.
        $directPendingQuery = fn () => Commission::whereIn('consultant_id', $accountIds)
            ->where('type', 'referral')
            ->where('status', 'pending');

        $directPending = (float) $directPendingQuery()->sum('amount');

        $perAccountDirectPending = $directPendingQuery()
            ->selectRaw('consultant_id, SUM(amount) as total')
            ->groupBy('consultant_id')
            ->pluck('total', 'consultant_id');

        // Students seated on each leg of each account, so every one of the two
        // service groups on every account can show its own progress rather than
        // just a money figure.
        $perAccountLegCounts = GroupMember::query()
            ->join('groups', 'groups.id', '=', 'group_members.group_id')
            ->whereIn('groups.consultant_id', $accountIds)
            ->selectRaw('groups.consultant_id, group_members.tier, COUNT(*) as members')
            ->groupBy('groups.consultant_id', 'group_members.tier')
            ->get()
            ->groupBy('consultant_id');

        // How each leg's seated students break down, so the money figure above
        // them is never an unexplained number. Four states, and only some of
        // them are a GAP between "students seated here" and "waiting to flush":
        //
        //   paid_on_leg — approved/paid by hand but NOT yet swept by a System
        //                 Run. Since 2026-08-17 these still hold their place in
        //                 the 7,500 threshold, so they are part of the balance,
        //                 not missing from it. Called out anyway because "LKR
        //                 2,500 waiting to flush" over a row already marked
        //                 Paid is exactly the combination that was reported as
        //                 unexplainable (SCN-00110, 2026-08-15).
        //   held        — a restricted consultant's income. Excluded from the
        //                 balance and from any forfeit until the hold clears.
        //   swept       — settled by an earlier System Run; off the leg.
        //   forfeited   — cancelled by an earlier run, booked to the company.
        //   removed     — struck off by an admin (2026-09-06). Also cancelled,
        //                 but the money did NOT go to the company; a wrong
        //                 figure was corrected. Reported separately because
        //                 telling a consultant the company took their 2,500
        //                 when nobody did is worse than saying nothing.
        //
        // The seat and its commission are always created for the SAME
        // consultant (placeInto()/awardSlot()), so there is no code path where
        // a seated student's money lands on a different account — every
        // difference is one of these, which is what the page now says instead
        // of leaving a silent gap that reads as a routing bug.
        //
        // Gated on held_at/released_at actually existing — same guard every
        // other flush-era query uses (FinanceSchemaRepair::ensure() adds these
        // lazily), so an install mid-migration gets an empty breakdown instead
        // of a fatal "no such column".
        $perAccountLegStatus = (\App\Services\FinanceSchemaRepair::isReady() && \App\Services\FinanceSchemaRepair::flushReady())
            ? Commission::query()
                ->join('group_members', 'group_members.id', '=', 'commissions.group_member_id')
                ->join('groups', 'groups.id', '=', 'group_members.group_id')
                ->whereIn('groups.consultant_id', $accountIds)
                ->where('commissions.type', \App\Services\CommissionService::TYPE_SLOT)
                ->selectRaw("groups.consultant_id, group_members.tier,
                    SUM(CASE WHEN commissions.status IN ('approved', 'paid') AND commissions.released_at IS NULL AND commissions.held_at IS NULL THEN 1 ELSE 0 END) as paid_on_leg,
                    SUM(CASE WHEN commissions.held_at IS NOT NULL AND commissions.status != 'cancelled' AND commissions.released_at IS NULL THEN 1 ELSE 0 END) as held,
                    SUM(CASE WHEN commissions.released_at IS NOT NULL AND commissions.status != 'cancelled' THEN 1 ELSE 0 END) as swept,
                    ".$this->cancelledBreakdownSql())
                ->groupBy('groups.consultant_id', 'group_members.tier')
                ->get()
                ->groupBy('consultant_id')
            : collect();

        // Staged release: income is recorded per student and shown straight
        // away, but only becomes payable once both legs balance a whole stage.
        // Each row carries its own "how many more students" figure so the
        // consultant can see exactly what is standing between them and a payout.
        $staging = app(\App\Services\ServiceGroupStaging::class);
        $flusher = app(\App\Services\ServiceGroupFlush::class);
        $stagingReady = \App\Services\FinanceSchemaRepair::stagingReady();

        // Only the signed-in consultant may flush, and only their own
        // accounts — see flush(). Viewing a downline's groups is read-only.
        $canFlushHere = $subject->id === $consultant->id;

        // The last System Run, so each leg can say how much of its balance came
        // in after that run and is simply waiting for the next one. Without it
        // a correct run reads as a failed one — see
        // ServiceGroupFlush::arrivedAfterRun() for the 2026-09-13 report.
        $lastRun = \App\Services\ServiceGroupFlush::lastRun();

        // $placements — where each account hangs, and which of the owner's two
        // Service Groups it ultimately sits in — was already resolved above for
        // the headline totals. Reused rather than rebuilt: it is four queries.
        $accountRows = Consultant::with('owner')->whereIn('id', $accountIds)->orderBy('id')->get()
            ->map(function ($acc) use ($perAccountTiers, $perAccountLegCounts, $perAccountLegStatus, $perAccountDirect, $perAccountDirectPending, $subject, $staging, $flusher, $stagingReady, $tierTotals, $directIncome, $directPending, $canFlushHere, $placements, $lastRun) {
                $group = $stagingReady ? Group::where('consultant_id', $acc->id)->orderBy('id')->first() : null;
                $isMain = $acc->id === $subject->id;

                // Why a leg's seat count and its flush balance don't match,
                // per leg — see $perAccountLegStatus above. Read off the raw
                // model attributes rather than casting the model to an array
                // (which would leak internal Eloquent properties instead of
                // the selected columns).
                $legStatus = function (int $tier) use ($perAccountLegStatus, $acc) {
                    $row = optional($perAccountLegStatus[$acc->id] ?? collect())->firstWhere('tier', $tier);

                    return [
                        'paid_on_leg' => (int) ($row->paid_on_leg ?? 0),
                        'held' => (int) ($row->held ?? 0),
                        'swept' => (int) ($row->swept ?? 0),
                        'forfeited' => (int) ($row->forfeited ?? 0),
                        'removed' => (int) ($row->removed ?? 0),
                    ];
                };

                return [
                    'id' => $acc->id,
                    'code' => $acc->scn_code ?? $acc->consultant_code,
                    'is_owner' => $isMain,
                    // The Main row shows the PERSON's combined total across
                    // every account they own (matching the headline figures
                    // above) — placement accounts each show only their own.
                    // Requested 2026-08-11: "main ekata... e widiyt meke
                    // hadann" — the main row should roll up what the other
                    // service-group accounts earned, the same way the
                    // headline already does.
                    'sg1' => $isMain ? (float) ($tierTotals[1]->total ?? 0) : (float) optional($perAccountTiers[$acc->id] ?? collect())->firstWhere('tier', 1)?->total,
                    'sg2' => $isMain ? (float) ($tierTotals[2]->total ?? 0) : (float) optional($perAccountTiers[$acc->id] ?? collect())->firstWhere('tier', 2)?->total,
                    'direct' => $isMain ? $directIncome : (float) ($perAccountDirect[$acc->id] ?? 0),
                    // ⚠️ THIS ACCOUNT'S OWN direct bonus, never the person-wide
                    // rollup above. The account picker adds it up per account,
                    // and 'direct' on the Main row is every account's combined —
                    // so using that one there would count each placement
                    // account's bonus twice and the chips would not sum to what
                    // the person has actually earned.
                    'direct_own' => (float) ($perAccountDirect[$acc->id] ?? 0),
                    // Closed accounts are not offered in the picker.
                    'status' => (string) $acc->status,
                    // Earned on registration, still awaiting an approval click.
                    'direct_pending' => $isMain ? $directPending : (float) ($perAccountDirectPending[$acc->id] ?? 0),
                    'sg1_members' => (int) optional($perAccountLegCounts[$acc->id] ?? collect())->firstWhere('tier', 1)?->members,
                    'sg2_members' => (int) optional($perAccountLegCounts[$acc->id] ?? collect())->firstWhere('tier', 2)?->members,
                    'sg1_status' => $legStatus(1),
                    'sg2_status' => $legStatus(2),
                    // WHICH of the owner's legs this account IS (null for the
                    // main account and for a set-main), and what that whole leg
                    // has earned.
                    //
                    // A downline's 2,500 lands on the owner's MAIN account
                    // (2026-08-12 rule), so a placement account's own row read
                    // empty even though the money arrived through its branch —
                    // reported 2026-08-21: "101 penwan na, eka main eke
                    // penwanne... dekem penwnn one". This is the same money the
                    // Main row already shows, surfaced on the account that IS
                    // that leg; the view labels it so, rather than presenting it
                    // as a second, separate amount.
                    // Provenance: which account this one was placed under, and
                    // on which of that account's Service Groups. Asked for
                    // 2026-08-22 — nine bare SCN codes said nothing about how
                    // they relate, so a consultant could not tell which purchase
                    // produced which accounts.
                    'placement' => $placements[$acc->id] ?? null,
                    // Which of the OWNER's Service Groups this account sits in —
                    // by placement, not by ordinal. See $legOfAccount above.
                    'represents_leg' => $placements[$acc->id]['owner_group'] ?? null,
                    'represents_leg_total' => (float) ($tierTotals[$placements[$acc->id]['owner_group'] ?? null]->total ?? 0),
                    'progress' => $group ? $staging->progressFor($acc, $group) : null,
                    // Live flush state: what is sitting on each leg right now,
                    // what the next flush would pay, and what it would forfeit.
                    // ⚠️ The Main row gets a REAL flush state of its own since
                    // 2026-09-02 — it holds its own money now, so it settles
                    // like every other account. (It used to be a rollup of the
                    // accounts beneath it and could settle nothing.)
                    // Judged against the SAME cutoff the next System Run will
                    // use. Without it this page said "ready — LKR 15,000 at
                    // the next System Run" for students registered today,
                    // which that run does not settle (it settles yesterday) —
                    // the contradiction reported 2026-08-14.
                    'flush' => $flusher->state($acc, $group, \App\Services\ServiceGroupFlush::defaultCutoff()),
                    // How much of each leg the LAST run could not have settled,
                    // because it was entered after that run's cutoff or verified
                    // after the run. Zero before any run exists.
                    'after_last_run' => ($lastRun && $group) ? [
                        1 => $flusher->arrivedAfterRun($acc, $group, 1, $lastRun),
                        2 => $flusher->arrivedAfterRun($acc, $group, 2, $lastRun),
                    ] : [1 => 0.0, 2 => 0.0],
                    'can_flush' => $canFlushHere,
                    // The Main row's money is a ROLLUP, so its own account's
                    // "N more needed" progress line (which describes only
                    // ITS OWN, usually-empty group) would sit next to a
                    // rolled-up figure and look contradictory — suppressed
                    // in the view via this flag, the accompanying money still
                    // shows normally.
                    'is_rollup' => $isMain,
                ];
            })
            ->sortByDesc('is_owner')
            ->values();

        // Unflushed slot income across EVERY account the subject owns, split
        // by leg — the headline-card equivalent of the "waiting to flush" line
        // already shown on each account row below.
        //
        // Without this, the top-of-page Service Group 1 / 2 figures counted
        // approved money only, same as tierTotals, so a downline's 2,500 —
        // correctly seated on the Main account the instant it is registered,
        // per the 2026-08-12 upline-routing rule — was invisible up here until
        // a System Run released it. Reported
        // 2026-08-17: "group wala wagema, downline eken yana 2500 add wenne
        // næ" — it WAS adding (onto the Main account's own leg balance,
        // visible on that row further down), just not reflected in this
        // headline number, which is exactly the "recorded but not shown" gap
        // already fixed for the Direct Add Bonus card below.
        //
        // ⚠️ TAKEN FROM THE MAIN ROW, NOT SUMMED (2026-08-24).
        //
        // This used to add every row's leg total together, which was right
        // while each row was one account's own balance. It stopped being right
        // the moment the main started flushing the whole person: its leg totals
        // ARE the rollup of every account in that Service Group, so adding the
        // placement accounts on top counted the same rows twice and the
        // headline drifted away from what the System Run reported for the same
        // person.
        //
        // The main's rollup is already the answer, and it is the same figure
        // the run settles — which is the point: these two screens must never
        // disagree about how much is waiting.
        $mainRow = $accountRows->firstWhere('is_owner', true);

        // ⚠️ UNAPPROVED ONLY, NOT THE WHOLE LEG (2026-08-25).
        //
        // These print as "+ LKR X on the leg, not yet flushed" directly beneath
        // the earned figure, so they must be the part that is NOT in it. Using
        // leg*_total counted approved-but-unflushed money in BOTH, and the page
        // read as double the moment anything was approved — reported as "kalin
        // eka hariyt watila thibba, ita passe approve krahama duplicate wela".
        //
        // Both figures were individually correct; presenting them with a "+"
        // was what made the same money look like two amounts.
        // ⚠️ WHAT A RUN CAN ACTUALLY SETTLE, for the headline (2026-08-26).
        //
        // The page led with LIFETIME EARNED, and every question the reader
        // brings to it — can this flush? how much will I get? — needs the
        // unflushed balance instead. That mismatch has been reported five
        // separate times as "7500ta wada thiyeno ne, ai system run eka krann
        // den naththe": Service Group 2 showed 12,500 earned while only
        // 5,000 was still on the leg, the other 7,500 having been settled by
        // an earlier run. So the run correctly refused, and the screen
        // correctly showed 12,500, and the two could not be reconciled by
        // looking at it.
        //
        // The headline now leads with this and keeps the earned figure as
        // context beneath it.
        $availableLeg1 = (float) ($mainRow['flush']['leg1_total'] ?? 0);
        $availableLeg2 = (float) ($mainRow['flush']['leg2_total'] ?? 0);
        $flushThreshold = (float) ($mainRow['flush']['threshold'] ?? 0);
        $flushLabel = (string) ($mainRow['flush']['label'] ?? '');

        $pendingLeg1 = (float) ($mainRow['flush']['leg1_unapproved'] ?? 0);
        $pendingLeg2 = (float) ($mainRow['flush']['leg2_unapproved'] ?? 0);

        // The headline legs ARE the main row's legs, so the "came in after the
        // last run" figure comes from the same row — the two can never disagree.
        $afterRunLeg1 = (float) ($mainRow['after_last_run'][1] ?? 0);
        $afterRunLeg2 = (float) ($mainRow['after_last_run'][2] ?? 0);

        // ⚠️ MONEY THAT IS IN NEITHER SERVICE GROUP MUST NOT VANISH.
        //
        // The rollup above only reaches accounts with a KNOWN owner_group. A
        // set whose placement was never recorded (opened one-at-a-time by an
        // admin, or predating the payment marker) sits in neither group, so its
        // unflushed income appears in neither headline figure — it is still
        // visible on that account's own row, and still flushable there, but the
        // number at the top would quietly be short.
        //
        // Reporting it separately is the same answer this page already gives
        // for held and awaiting-approval money: show it, name it, and let the
        // consultant act on it, rather than leaving a silent gap that reads as
        // lost income.
        $groupedIds = collect($accountRows)
            ->filter(fn ($r) => ! ($r['is_owner'] ?? false) && ($r['placement']['owner_group'] ?? null) !== null)
            ->pluck('id')
            ->push($subject->id)
            ->all();

        $pendingUngrouped = (float) collect($accountRows)
            ->reject(fn ($r) => in_array($r['id'], $groupedIds, true))
            ->sum(fn ($r) => (float) ($r['flush']['leg1_total'] ?? 0) + (float) ($r['flush']['leg2_total'] ?? 0));

        // ⚠️ MONEY AN ADMIN HAS SHUT AWAY MUST NOT READ AS ZERO (2026-08-27).
        //
        // Same principle as $pendingUngrouped directly above, for the other way
        // this headline could quietly be short.
        //
        // The two figures the headline prints are scoped DIFFERENTLY: the EARNED
        // total ($directChildIds, above) applies no lock filter at all, while
        // the AVAILABLE total comes from the rollup. So whatever the rollup
        // drops shows up as "LKR 0 on this group now — of LKR 7,500 earned to
        // date", with no way to tell which of the two numbers is lying. Since
        // 08-26 AVAILABLE is the one in bold, so the zero is what is read first.
        //
        // ⚠️ ONLY THE ADMIN FORCE-SHUT STILL DROPS ANYTHING (2026-08-27).
        //
        // The automatic 12-student lock USED to drop money here too, which is
        // what produced "9th account eka buy krla eke service group eke student
        // la add kraddi main account eka 2500 add wen na" — the 2,500 climbed
        // to SCN-00102 correctly and then vanished from the headline. The owner
        // ruled that such money must roll up and be settled by the main's run
        // ("main eka flush out wenn one system run ekedi"), so
        // ServiceGroupFlush::accountsInOwnerGroup() no longer drops it and it is
        // now part of $availableLeg*. Counting it here as well would print the
        // same rupees twice on one card.
        //
        // ⚠️ WHAT THIS FIGURE MEANS CHANGED ON 2026-09-25. It used to be money
        // MISSING from the headline: a force-shut account blocked the climb, so
        // the main never earned its row and the rupees were named here instead.
        // The climb now passes through a closed account (see
        // CommissionService::creditOwnAccountAncestors) because closing one leg
        // also froze the other — a main with a zero side can never balance.
        //
        // So the money above is NOT missing any more, and this is no longer a
        // warning about the headline. It is a statement about the closed
        // account itself: that account still cannot settle its OWN money, which
        // is what the lock is for. The wording in the view says exactly that —
        // do not put "held" back, it reads as income the person has lost.
        //
        // Scoped to DIRECT CHILDREN, exactly like the rollup it is compensating
        // for. Deeper accounts each hold their own copy of the same climbed
        // 2,500, so summing them here would report one student's money several
        // times over — the duplication the 08-25 narrowing exists to prevent.
        $lockedLegs = [1 => 0.0, 2 => 0.0];
        $lockedReason = [1 => null, 2 => null];

        foreach ($accountRows as $row) {
            $flush = $row['flush'] ?? null;
            $leg = $row['represents_leg'] ?? null;

            if (($row['is_owner'] ?? false)
                || ! is_array($flush)
                || ! ($flush['locked_manually'] ?? false)
                || ! in_array($row['id'], $directChildIds, true)
                || ! in_array($leg, [1, 2], true)) {
                continue;
            }

            // The account IS one of the owner's two Service Groups, so BOTH of
            // its own legs are that one group's money — the same attribution
            // accountsInOwnerGroup() makes for every account it does count.
            $lockedLegs[$leg] += (float) ($flush['leg1_total'] ?? 0) + (float) ($flush['leg2_total'] ?? 0);

            // First blocker per group is enough to act on; naming every one of
            // them would be a wall of text for a single fix.
            $lockedReason[$leg] ??= ['code' => $row['code']];
        }

        $lockedLeg1 = $lockedLegs[1];
        $lockedLeg2 = $lockedLegs[2];

        // What one full leg pays, so each row can be shown against its own cap.
        $legMax = [
            1 => (float) $settings->first_tier_students * (float) $settings->commission_per_student,
            2 => (float) $settings->second_tier_students * (float) $settings->commission_per_student,
        ];

        // ----- "View members for" -------------------------------------------
        // Accepts an exact SCN (with or without the prefix) or an account id.
        $selected = $this->resolveSelectedAccount($request, $reachableIds) ?? $subject;

        $searchError = $request->filled('scn') && $this->resolveSelectedAccount($request, $reachableIds) === null
            ? 'No account in your network matches "'.$request->string('scn').'". Enter a full SCN such as 101 or SCN-00101.'
            : null;

        $selectedGroupIds = Group::where('consultant_id', $selected->id)->pluck('id');

        // 'payment.consultant.user' / '.owner.user' load display_name() for
        // the "via <downline>" attribution below — the registering account is
        // often a downline several levels down the sponsor chain whose own
        // placement climbed up to seat here, not necessarily $selected itself.
        $legQuery = fn (int $tier) => GroupMember::whereIn('group_id', $selectedGroupIds)
            ->where('tier', $tier)
            ->with(['user.student', 'payment.consultant.user', 'payment.consultant.owner.user'])
            ->orderBy('id');

        $legCounts = [
            1 => (clone $legQuery(1))->count(),
            2 => (clone $legQuery(2))->count(),
        ];

        $legs = [
            1 => $legQuery(1)->limit(self::MEMBERS_PER_LEG)->get(),
            2 => $legQuery(2)->limit(self::MEMBERS_PER_LEG)->get(),
        ];

        // The picker: own accounts first, then the downline's.
        $pickable = Consultant::whereIn('id', $reachableIds)
            ->with(['user:id,name', 'owner.user:id,name'])
            ->orderBy('scn_code')
            ->get()
            ->map(fn ($acc) => [
                'id' => $acc->id,
                'code' => $acc->scn_code ?? $acc->consultant_code,
                'label' => ($acc->scn_code ?? $acc->consultant_code).' — '.$acc->display_name
                    .' ('.($acc->is_hidden ? 'Placement Account' : 'Consultant').')',
                'mine' => in_array($acc->id, $accountIds, true),
            ])
            ->values();

        // ----- THE TEAM, SPLIT ACROSS THE THREE TABS -------------------------
        //
        // Asked for 2026-09-07, after the same data shipped as a fourth tab the
        // day before: "mt mekama one aluth tab ehekin newei, one thiyen SCN
        // account wala penwann one... Udara add krna student consultant la eyala
        // meke penwann one, e wagem eyage yatathe inna consultant la add krana
        // student and consultant la eyala okkom... Ilagata eyala aithi wena SCN
        // account widiyta SG1 and SG2 ewa wala."
        //
        // ⚠️ THESE TABS ARE NO LONGER SEAT LISTS, AND THAT IS THE POINT.
        //
        // They used to be GroupMember rows on $selected. A seat is written by
        // CommissionService::placeInto() only when a payment is VERIFIED and the
        // sponsor chain is walked, so four ordinary situations were invisible
        // there and always would be: a HALF payment seats nobody at all, a FREE
        // card seats only the registrant, an UNVERIFIED registration seats
        // nobody, and a downline whose sponsor link was never recorded seats no
        // ancestor. Widening a seat query cannot conjure a row that was never
        // written, so this reads students and payments, where a student exists
        // from the moment they are registered.
        //
        // The seat number has NOT been thrown away — $legCounts above is still
        // the honest "what counts toward the flush" figure, printed inside each
        // panel beside the team count. Both numbers, deliberately: one explains
        // the money, the other answers "who is under me".
        $teamRootIds = $selected->is_hidden ? [$selected->id] : $selected->accountIds();

        // ⚠️ WHICH LEG EACH ROOT ACCOUNT *IS*, and never how to derive it.
        //
        // AccountPlacementMap resolves this by CLIMBING — "which of the owner's
        // two Service Groups does this account ultimately sit in". Consultant::
        // serviceGroupLeg() answers a different question (the account's ordinal
        // within its own set) and substituting it here is precisely the cross
        // reported on 2026-08-23; the map's own docblock forbids the swap.
        //
        // Reused from the instance already built for the headline totals when the
        // selected account belongs to the subject — the dominant case, and zero
        // extra queries.
        $rootLegOf = [];

        if ($selected->is_hidden) {
            // A placement account is not a leg; it HAS two. Its own people are
            // filed by the leg they sit on INSIDE it, below.
            $rootLegOf[(int) $selected->id] = null;
        } else {
            $selectedPlacements = $selected->id === $subject->id
                ? $placements
                : app(\App\Services\AccountPlacementMap::class)->for($selected);

            foreach ($teamRootIds as $rootId) {
                $rootLegOf[(int) $rootId] = $selectedPlacements[$rootId]['owner_group'] ?? null;
            }
        }

        $branches = Consultant::downlineBranchesBelow(
            $teamRootIds,
            [$selected->id],
            self::TEAM_MAX_DEPTH,
            self::TEAM_MAX_PEOPLE_WALKED,
        );

        $teamPeopleIds = array_keys($branches);

        // Every seat sitting on the SELECTED ACCOUNT ITSELF, by student.
        //
        // ⚠️ SCOPED TO $selected->id ALONE — NEVER $selected->accountIds().
        // CommissionService::sideUnderParent() widens to the parent's whole
        // account set because it answers "which internal leg did the money land
        // on". Widening here would read a tier from INSIDE SCN-00102 and file
        // the person under Service Group 1 when SCN-00102 *is* Service Group 2 —
        // the 2026-08-18 "pathi deka maru wela" report and the 2026-08-23 cross,
        // in new clothes. $selectedGroupIds is already exactly this scope.
        $seatTierOf = GroupMember::whereIn('group_id', $selectedGroupIds)->pluck('tier', 'user_id');

        // The explicit choice, and ONLY under a main parent. `placement_tier` is
        // fed from payments.requested_tier — "which leg the student sat on
        // INSIDE the account" — which is a different quantity from the ordinal.
        // Reading it under a hidden parent is the 2026-08-19 bug (fixed 08-20).
        $placementTierOf = collect();

        if (! $selected->is_hidden && \App\Services\FinanceSchemaRepair::placementTierReady() && $teamPeopleIds !== []) {
            $placementTierOf = Consultant::whereIn('id', $teamPeopleIds)
                ->whereIn('placement_tier', [1, 2])
                ->pluck('placement_tier', 'id');
        }

        // ----- Which leg each PERSON belongs to ------------------------------
        // A level-1 person hangs off one of the root accounts. If that root IS a
        // leg, that is the answer. Otherwise they hang off the selected account
        // itself and the answer is the leg they occupy inside it.
        //
        // Everyone deeper INHERITS their branch. parent_consultant_id is
        // single-valued, so the placement graph is a tree and a branch cannot
        // fork: everything below a level-1 person is wholly inside that person's
        // leg. This is AccountPlacementMap's own climb, walked downward.
        $legOfPerson = [];

        foreach (collect($branches)->sortBy('level') as $personId => $branch) {
            $root = (int) $branch['root_account_id'];
            $leg = $rootLegOf[$root] ?? null;

            if ($leg === null && $branch['parent'] !== null) {
                // Deeper than level 1 — take the branch it came down.
                $leg = $legOfPerson[$branch['parent']] ?? null;
            }

            if ($leg === null && $branch['user_id'] !== null) {
                $leg = (int) ($placementTierOf[$personId] ?? $seatTierOf[$branch['user_id']] ?? 0) ?: null;
            }

            $legOfPerson[$personId] = in_array($leg, [1, 2], true) ? $leg : null;
        }

        // ----- Every account in the subtree, mapped back to its person --------
        // A student registered through a downline's placement account is filed
        // under the downline themself, not under an SCN bucket nobody knows.
        $teamAccountOwner = [];

        foreach ($teamRootIds as $rootId) {
            $teamAccountOwner[(int) $rootId] = null;   // null = the root row
        }

        if ($teamPeopleIds !== []) {
            foreach ($teamPeopleIds as $personId) {
                $teamAccountOwner[(int) $personId] = (int) $personId;
            }

            foreach (Consultant::whereIn('owner_consultant_id', $teamPeopleIds)
                ->where('is_hidden', true)
                ->get(['id', 'owner_consultant_id']) as $account) {
                $teamAccountOwner[(int) $account->id] = (int) $account->owner_consultant_id;
            }
        }

        $teamAccountIds = array_keys($teamAccountOwner);

        // Slim rows, newest first, hard-capped — this is the DEFAULT tab now, so
        // it runs on every page load rather than on a click. The uncapped count
        // below keeps the tab number honest when the fetch clips.
        $teamStudentsTotal = (int) $this->ownedStudents($teamAccountIds)->count();

        $teamStudentRows = $this->ownedStudents($teamAccountIds)
            ->orderByDesc('id')
            ->limit(self::TEAM_STUDENT_ROWS_MAX)
            ->get(['id', 'user_id', 'student_code', 'consultant_id']);

        // The payment names the account the money was placed under (so a student
        // whose students.consultant_id was never set can still be filed), carries
        // the status badge, and holds requested_tier — the ONLY leg fact a
        // half-paid or unverified student has, since they have no seat.
        $teamPayments = $teamAccountIds === [] || $teamStudentRows->isEmpty()
            ? collect()
            : Payment::whereIn('consultant_id', $teamAccountIds)
                ->whereIn('user_id', $teamStudentRows->pluck('user_id'))
                ->orderBy('id')
                ->get(['id', 'user_id', 'consultant_id', 'status', 'payment_type', 'requested_tier'])
                ->keyBy('user_id');

        // ----- Students, filed under a person and a leg -----------------------
        $studentsOf = [];          // personId|0 (root) => rows
        $studentLegOf = [];        // student row index => 1|2|null

        foreach ($teamStudentRows as $row) {
            $payment = $teamPayments[$row->user_id] ?? null;
            $accountId = (int) ($row->consultant_id ?? $payment?->consultant_id ?? 0);

            if (! array_key_exists($accountId, $teamAccountOwner)) {
                // Reached this list through the other half of ownedStudents()
                // and belongs to somebody else's branch. Skip rather than guess.
                continue;
            }

            $personId = $teamAccountOwner[$accountId];

            $leg = $personId === null
                // On a root account: the leg that account IS, else the leg this
                // student sits on inside the selected account, else the leg they
                // were registered into.
                ? ($rootLegOf[$accountId] ?? (int) ($seatTierOf[$row->user_id] ?? $payment?->requested_tier ?? 0) ?: null)
                : ($legOfPerson[$personId] ?? null);

            $studentsOf[$personId ?? 0][] = [
                'user_id' => $row->user_id,
                'code' => $row->student_code ?? '—',
                'payment' => $payment,
                'leg' => in_array($leg, [1, 2], true) ? $leg : null,
            ];
        }

        // ----- Names, one query for everything that will be printed -----------
        $teamPeople = $teamPeopleIds === [] ? collect() : Consultant::whereIn('id', $teamPeopleIds)
            ->with(['user:id,name', 'owner.user:id,name'])
            ->get()
            ->keyBy('id');

        $teamNames = User::whereIn(
            'id',
            collect($studentsOf)->flatten(1)->pluck('user_id')->unique()->values(),
        )->pluck('name', 'id');

        // ----- The three panels ----------------------------------------------
        $childrenOf = [];

        foreach ($branches as $personId => $branch) {
            $childrenOf[$branch['parent'] ?? 0][] = $personId;
        }

        $team = [
            'root' => [
                'code' => $selected->scn_code ?? $selected->consultant_code,
                'is_main' => ! $selected->is_hidden,
            ],
            'unattributed' => collect($legOfPerson)->filter(fn ($leg) => $leg === null)->count(),
            'truncated' => $teamStudentsTotal > $teamStudentRows->count(),
            'legs' => [],
        ];

        foreach (['all', 1, 2] as $tab) {
            $team['legs'][$tab] = $this->teamPanel(
                $tab,
                $branches,
                $childrenOf,
                $legOfPerson,
                $studentsOf,
                $teamPeople,
                $teamNames,
                $legCounts,
                $selected,
            );
        }

        // Courses that pay their own direct bonus (2026-10-22: Elliott Wave
        // pays 5,000), named next to the standard rate on the Direct Add Bonus tile.
        $courseDirectRates = \App\Support\CourseMoney::coursesWithOwnDirect();

        return view('consultant.groups.index', compact(
            'consultant', 'subject', 'viewable', 'settings', 'stats', 'tierTotals', 'directIncome', 'directPending', 'accountRows',
            'pendingLeg1', 'pendingLeg2', 'availableLeg1', 'availableLeg2', 'afterRunLeg1', 'afterRunLeg2', 'lastRun', 'lockedLeg1', 'lockedLeg2', 'lockedReason', 'flushThreshold', 'flushLabel', 'pendingUngrouped', 'selected', 'legs', 'legCounts', 'legMax', 'pickable', 'searchError', 'team',
            'courseDirectRates',
        ));
    }

    /**
     * One tab's worth of the team: the selected account's own registrations as a
     * level-0 row, then everyone below it, nested.
     *
     * ⚠️ DEPTH-FIRST, THEN TRUNCATED — NEVER TRUNCATED THEN NESTED. Capping a
     * nested list by count is only safe if a parent is always emitted before its
     * children, or a kept row whose parent was dropped floats at the wrong
     * indent and the tree silently lies about who sponsored whom. Siblings are
     * ordered busiest-first so the cap falls on the quiet end of the list.
     *
     * Filtering by leg keeps whole subtrees intact rather than shredding them:
     * a branch cannot fork (see Consultant::downlineBranchesBelow()), so every
     * descendant of a Service Group 1 person is also Service Group 1.
     *
     * @param  'all'|int  $tab
     * @param  array<int, array{level: int, parent: ?int, root_account_id: int, user_id: ?int}>  $branches
     * @param  array<int, array<int>>  $childrenOf  parent person id (0 = the selected account) => children
     * @param  array<int, ?int>  $legOfPerson
     * @param  array<int, array<int, array<string, mixed>>>  $studentsOf  person id (0 = the root row) => students
     * @param  array<int, int>  $legCounts  the SEAT counts — what actually pays
     */
    protected function teamPanel(
        string|int $tab,
        array $branches,
        array $childrenOf,
        array $legOfPerson,
        array $studentsOf,
        \Illuminate\Support\Collection $teamPeople,
        \Illuminate\Support\Collection $teamNames,
        array $legCounts,
        Consultant $selected,
    ): array {
        $wanted = fn (int $personId): bool => $tab === 'all' || ($legOfPerson[$personId] ?? null) === $tab;

        $studentsFor = function (int $key) use ($tab, $studentsOf, $teamNames): array {
            return collect($studentsOf[$key] ?? [])
                ->filter(fn (array $s) => $tab === 'all' || $s['leg'] === $tab)
                ->map(fn (array $s) => [
                    'name' => $teamNames[$s['user_id']] ?? 'Unknown',
                    'code' => $s['code'],
                    'payment' => $s['payment'],
                ])
                ->values()
                ->all();
        };

        // Busiest first, so what the cap drops is the quiet tail.
        $order = fn (array $ids): array => collect($ids)
            ->filter(fn ($id) => $wanted((int) $id))
            ->sortByDesc(fn ($id) => count($studentsOf[$id] ?? []))
            ->values()
            ->all();

        $rootStudents = $studentsFor(0);
        $topLevel = $order($childrenOf[0] ?? []);

        $rows = [[
            'id' => 0,
            'level' => 0,
            'is_root' => true,
            'code' => $selected->scn_code ?? $selected->consultant_code,
            'name' => 'Registered directly on this account',
            'count' => count($rootStudents),
            'more' => max(0, count($rootStudents) - self::TEAM_STUDENTS_PER_PERSON),
            'children' => count($topLevel),
            'students' => array_slice($rootStudents, 0, self::TEAM_STUDENTS_PER_PERSON),
        ]];

        $studentsTotal = count($rootStudents);
        $peopleTotal = 0;

        // Reversed on push so array_pop() yields the busiest child next — that
        // is what makes this depth-first rather than breadth-first.
        $stack = array_reverse($topLevel);
        $shown = 0;

        while ($stack !== []) {
            $personId = (int) array_pop($stack);
            $person = $teamPeople[$personId] ?? null;

            if ($person === null) {
                continue;
            }

            $peopleTotal++;

            $students = $studentsFor($personId);
            $studentsTotal += count($students);

            $children = $order($childrenOf[$personId] ?? []);

            if ($shown < self::TEAM_PEOPLE_MAX) {
                $rows[] = [
                    'id' => $personId,
                    'level' => (int) ($branches[$personId]['level'] ?? 1),
                    'is_root' => false,
                    'code' => $person->scn_code ?? $person->consultant_code,
                    'name' => $person->display_name,
                    'count' => count($students),
                    'more' => max(0, count($students) - self::TEAM_STUDENTS_PER_PERSON),
                    'children' => count($children),
                    'students' => array_slice($students, 0, self::TEAM_STUDENTS_PER_PERSON),
                ];

                $shown++;
            }

            foreach (array_reverse($children) as $child) {
                $stack[] = $child;
            }
        }

        return [
            'label' => $tab === 'all' ? 'All Students' : 'Service Group '.$tab,
            'people_total' => $peopleTotal,
            'students_total' => $studentsTotal,
            // ⚠️ THE SEAT COUNT, UNTOUCHED — what actually fills toward the flush
            // threshold. Printed beside the team count so the two can never be
            // mistaken for each other. This is the only figure on this panel
            // that money depends on.
            'seated' => $tab === 'all' ? ($legCounts[1] + $legCounts[2]) : ($legCounts[$tab] ?? 0),
            'people_shown' => $shown,
            'rows' => $rows,
        ];
    }

    /**
     * The two ways a slot row can be `cancelled`, counted apart.
     *
     * A System Run FORFEIT books the money to the company; an admin REMOVAL
     * (2026-09-06) corrects a figure that should never have existed and books
     * it nowhere. The leg breakdown reports both as a reason the seat count and
     * the flush balance differ, but it must not tell a consultant the company
     * took income when it did not.
     *
     * Degrades to the old single count when `removed_at` has not been created
     * yet — on such a host nothing has ever been removed, so `removed` is
     * genuinely 0 rather than merely unknown. Selected as a literal so the
     * result shape never changes and the caller needs no second branch.
     */
    protected function cancelledBreakdownSql(): string
    {
        if (! \App\Services\FinanceSchemaRepair::removalReady()) {
            return "SUM(CASE WHEN commissions.status = 'cancelled' THEN 1 ELSE 0 END) as forfeited,
                    0 as removed";
        }

        return "SUM(CASE WHEN commissions.status = 'cancelled' AND commissions.removed_at IS NULL THEN 1 ELSE 0 END) as forfeited,
                SUM(CASE WHEN commissions.status = 'cancelled' AND commissions.removed_at IS NOT NULL THEN 1 ELSE 0 END) as removed";
    }

    /**
     * Resolve the account being inspected from either the dropdown (`account`)
     * or the exact-SCN search box (`scn`). Only accounts inside the viewer's own
     * network are allowed.
     */
    protected function resolveSelectedAccount(Request $request, array $reachableIds): ?Consultant
    {
        if ($request->filled('account')) {
            return Consultant::whereIn('id', $reachableIds)
                // display_name() reads user->name, falling back to owner->user->name
                // for a hidden account, and the view prints it. preventLazyLoading()
                // turns a missing eager load into a 500, not a slow page.
                ->with(['user:id,name', 'owner.user:id,name'])
                ->find($request->integer('account'));
        }

        if ($request->filled('scn')) {
            $term = strtoupper(trim((string) $request->string('scn')));
            $digits = preg_replace('/\D/', '', $term);

            // Exact match only, but "101", "SCN101" and "SCN-00101" all resolve
            // to the same account.
            $candidates = [$term];

            if ($digits !== '') {
                $candidates[] = 'SCN-'.str_pad($digits, 5, '0', STR_PAD_LEFT);
                $candidates[] = 'SCN-'.$digits;
            }

            return Consultant::whereIn('id', $reachableIds)
                ->where(fn ($q) => $q->whereIn('scn_code', $candidates)->orWhereIn('consultant_code', $candidates))
                ->first();
        }

        return null;
    }

    /*
     * REMOVED 2026-08-14: flush() lived here for one day.
     *
     * A consultant could settle their own two legs whenever they liked. The
     * owner replaced that with the accounts unit's once-daily System Run
     * ("manual newei... eka sampuranen ain krala denn"), so flushing is now
     * network-wide and belongs to Dashboard\SystemRunController. The route is
     * gone too; this page is read-only again.
     */

    public function show(Group $group)
    {
        $consultant = $this->consultant();
        abort_if(! in_array((int) $group->consultant_id, $consultant->reachableAccountIds(), true), 403);

        // 'consultant' joins the load because the heading now names the SCN
        // account rather than the internal GRP code — reading it unloaded would
        // 500 the page outside production (preventLazyLoading), not fire a
        // quiet second query.
        $group->load(['consultant', 'members.user.student', 'members.user.consultant', 'members.payment.consultant', 'commissions']);

        return view('consultant.groups.show', compact('group'));
    }
}
