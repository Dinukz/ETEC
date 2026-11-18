<?php

namespace App\Services;

use App\Models\Commission;
use App\Models\Consultant;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * ⚠️ MOSTLY HISTORICAL as of 2026-08-16 — the live release rule now lives in
 * ServiceGroupFlush, with ONE fixed threshold (7,500 each side, always). Read
 * that class first. What remains ALIVE here:
 *
 *  - config() — the shared settings reader (rates, unlock threshold). Still
 *    used by ServiceGroupFlush.
 *  - isUnlocked() / previousAccountId() / studentsOn() — the 12-students-
 *    unlocks-the-next-account rule. UNCHANGED, still enforced by
 *    ServiceGroupFlush::flush()/state().
 *  - isReleased() — the pre-flush-schema FALLBACK inside
 *    CommissionApproval::isReleased(), for a database where `released_at`
 *    does not exist yet. Never reached once FinanceSchemaRepair::flushReady()
 *    is true, which is every live install.
 *  - advance() — a documented no-op once flushReady() is true (see its own
 *    docblock). Kept only because CommissionService::placeInto() still calls
 *    it and the pre-flush fallback above still needs `released_depth`.
 *
 * DEAD in the live path: stepFor(), ONE_TIME_STAGES, and the staged
 * 1-to-1 (5,000) / 2-to-2 (10,000) one-time-per-person discount described
 * below. Retired 2026-08-16 after a real bug it caused: a person's accounts
 * are processed by id within one System Run and shared ONE
 * `lifetime_pair_stage`, so whichever account flushed FIRST consumed the
 * cheap stage and a SECOND account of the same person — sitting on a balance
 * that would have qualified moments earlier — could be refused because the
 * threshold had already moved up under it. See ServiceGroupFlush's docblock
 * for the full account. `lifetime_pair_stage` itself is left on the
 * `consultants` table rather than dropped (see FORWARD ONLY in
 * commission-rules-2026-08.md) — it simply stops being read for this purpose.
 *
 * ----- The rest of this docblock describes the RETIRED rule, for history ----
 *
 * Slot income used to become payable once a whole STAGE of matched pairs
 * balanced — one student on Service Group 1 against one on Service Group 2:
 *
 *   1-to-1   1 pair    →  5,000 payable    ONCE per consultant, lifetime
 *   2-to-2   2 pairs   → 10,000 payable    ONCE per consultant, lifetime
 *   3-to-3   3 pairs   → 15,000 payable    repeats forever
 *
 * So one account walked 1 → 3 → 6 → 9 … matched pairs, and a SECOND account
 * belonging to a person who had already used both one-time stages started
 * straight at 3-to-3 (3 → 6 → 9 …). Superseded by the fixed threshold above.
 *
 * ----- How "payable" WAS stored ----------------------------------------------
 * `groups.released_depth` is how many pairs deep this account has been
 * released. A member is payable when its rank on its own leg is within that
 * depth. The counter only ever moves FORWARD, and advance() is idempotent, so
 * it is safe to call from any path — including a read path for a group that
 * predates this feature and still has depth 0, which is what stops already
 * earned money from being stranded by the upgrade.
 */
class ServiceGroupStaging
{
    /** Defaults, all admin-overridable from Settings → Service Groups & Income. */
    public const DEFAULT_FIRST_STAGE_PAIRS = 1;

    public const DEFAULT_SECOND_STAGE_PAIRS = 2;

    public const DEFAULT_REPEAT_STAGE_PAIRS = 3;

    public const DEFAULT_UNLOCK_STUDENTS = 12;

    public const CACHE_KEY = 'settings.staging.grouped';

    /** How many one-time stages exist before the repeating one takes over. */
    public const ONE_TIME_STAGES = 2;

    /**
     * @return array{first: int, second: int, repeat: int, unlock: int}
     */
    public static function config(): array
    {
        $raw = Cache::rememberForever(
            self::CACHE_KEY,
            fn () => Setting::whereIn('key', [
                'stage_first_pairs', 'stage_second_pairs', 'stage_repeat_pairs', 'account_unlock_students',
            ])->pluck('value', 'key'),
        );

        // Clamped at the read site: a 0 or negative step would make the
        // "release when matched >= depth + step" test true forever and spin
        // advance()'s loop, so the floor is 1 for every step.
        return [
            'first' => max(1, (int) ($raw['stage_first_pairs'] ?? self::DEFAULT_FIRST_STAGE_PAIRS)),
            'second' => max(1, (int) ($raw['stage_second_pairs'] ?? self::DEFAULT_SECOND_STAGE_PAIRS)),
            'repeat' => max(1, (int) ($raw['stage_repeat_pairs'] ?? self::DEFAULT_REPEAT_STAGE_PAIRS)),
            'unlock' => max(1, (int) ($raw['account_unlock_students'] ?? self::DEFAULT_UNLOCK_STUDENTS)),
        ];
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /** The pair-step size for the next release, given how many one-time stages this person has used. */
    public static function stepFor(int $lifetimeStage, array $config): int
    {
        return match ($lifetimeStage) {
            0 => $config['first'],
            1 => $config['second'],
            default => $config['repeat'],
        };
    }

    // ----- Advancing the released depth ---------------------------------------

    /**
     * Release every stage this account's current balance allows, and consume
     * the person's one-time stages in the same breath. Returns the new depth.
     *
     * Idempotent and monotonic — it can only ever move the depth forward — so
     * it is safe to call from a placement, from an approval check, or from a
     * screen that just wants to show progress.
     */
    public function advance(Consultant $account, Group $group): int
    {
        // ⚠️ NO LONGER AUTO-RELEASES (2026-08-13).
        //
        // Release is now a deliberate act by the consultant — they flush their
        // two legs from the Service Groups page, which pays the matched rows
        // and forfeits the rest to the company (ServiceGroupFlush). Advancing
        // released_depth automatically here would hand out exactly the money
        // the flush is supposed to gate, straight from placeInto() on every
        // verification.
        //
        // Kept as a no-op rather than deleted: it is still called from
        // CommissionService::placeInto(), and the column still backs the
        // pre-flush fallback in CommissionApproval::isReleased() for a
        // database where `released_at` has not been created yet.
        if (FinanceSchemaRepair::flushReady()) {
            return (int) ($group->released_depth ?? 0);
        }

        if (! FinanceSchemaRepair::stagingReady()) {
            // Without the column there is nothing to gate on. Treating every
            // pair as released is the SAFE failure direction here: it falls
            // back to the pre-staging behaviour (everything approvable) rather
            // than silently freezing every consultant's income.
            return PHP_INT_MAX;
        }

        $config = self::config();

        if (! $this->isUnlocked($account, $config)) {
            return (int) ($group->released_depth ?? 0);
        }

        $owner = $account->owningPerson();
        $depth = (int) ($group->released_depth ?? 0);
        $stage = max(0, (int) ($owner->lifetime_pair_stage ?? 0));
        $matched = $this->matchedPairs($group);

        $consumed = 0;
        $start = $depth;

        while (true) {
            $step = self::stepFor($stage, $config);

            if ($matched < $depth + $step) {
                break;
            }

            $depth += $step;

            if ($stage < self::ONE_TIME_STAGES) {
                $stage++;
                $consumed++;
            }
        }

        if ($depth === $start) {
            return $depth;
        }

        // Conditional UPDATE rather than a save(): two concurrent verifications
        // on the same account must not walk the depth backwards, and the
        // counter is monotonic so the higher value always wins.
        DB::table('groups')
            ->where('id', $group->id)
            ->where(fn ($q) => $q->whereNull('released_depth')->orWhere('released_depth', '<', $depth))
            ->update(['released_depth' => $depth, 'updated_at' => now()]);

        $group->released_depth = $depth;

        // The one-time 1-to-1 / 2-to-2 stages belong to the PERSON, not the
        // account, so a second account starts straight at 3-to-3.
        if ($consumed > 0) {
            Consultant::whereKey($owner->id)->increment('lifetime_pair_stage', $consumed);
        }

        return $depth;
    }

    /**
     * Is this member's slot income payable yet?
     *
     * Called on the approval path, so it advances first — a group whose legs
     * balanced before this feature existed must not stay frozen at depth 0
     * just because no new student has been placed since.
     */
    public function isReleased(GroupMember $member): bool
    {
        if (! FinanceSchemaRepair::stagingReady()) {
            return true;
        }

        $group = Group::find($member->group_id);

        if (! $group) {
            return true;
        }

        $account = Consultant::with('owner')->find($group->consultant_id);

        if (! $account) {
            return true;
        }

        return $this->rankOnLeg($member) <= $this->advance($account, $group);
    }

    // ----- Progress, for the screens -------------------------------------------

    /**
     * What this account still needs before its next release — the figures the
     * owner asked to see ("thaw mechchrk add krnn one kiyla penwann one").
     *
     * @return array{
     *     locked: bool, unlock_needed: int,
     *     leg1: int, leg2: int, matched: int, released: int,
     *     next_target: int, need_leg1: int, need_leg2: int,
     *     next_amount: float, next_label: string,
     *     pending_amount: float, releasable_now: bool
     * }
     */
    public function progressFor(Consultant $account, Group $group): array
    {
        $config = self::config();
        $rate = (float) \App\Models\CommissionSetting::current()->commission_per_student;

        $leg1 = $this->countOnLeg($group, 1);
        $leg2 = $this->countOnLeg($group, 2);
        $matched = min($leg1, $leg2);

        $locked = ! $this->isUnlocked($account, $config);
        $unlockNeeded = 0;

        if ($locked) {
            $previousId = $this->previousAccountId($account);
            $unlockNeeded = max(0, $config['unlock'] - ($previousId ? $this->studentsOn($previousId) : 0));
        }

        $released = $locked ? (int) ($group->released_depth ?? 0) : $this->advance($account, $group);
        $released = $released === PHP_INT_MAX ? $matched : $released;

        $owner = $account->owningPerson();

        // Re-read rather than trusting the in-memory model: advance() above may
        // have just CONSUMED one or more one-time stages, and it does so with a
        // query-builder increment, which leaves $owner stale. Using the stale
        // value made the banner name the stage that had just been used up
        // ("Waiting for 1-to-1" when 1-to-1 was already released).
        $stage = max(0, (int) (Consultant::whereKey($owner->id)->value('lifetime_pair_stage') ?? 0));
        $step = self::stepFor($stage, $config);
        $target = $released + $step;

        // Everything recorded on this account that has NOT been paid out yet.
        $pending = (float) Commission::where('consultant_id', $account->id)
            ->where('type', CommissionService::TYPE_SLOT)
            ->whereIn('status', ['pending', 'approved'])
            ->sum('amount');

        // Money sitting on this account that IS released and still pending review.
        //
        // This replaces a `matched >= target` test that could never be true:
        // advance() runs a few lines above and consumes every pair the current
        // balance allows, and its own loop exits precisely when
        // `matched < depth + step` — so by the time the caller saw it, the
        // "balanced, ready to approve" state was unreachable and the button it
        // drove never appeared. That is why the owner reported never getting an
        // approve-all for a balanced stage.
        $releasableAmount = $locked ? 0.0 : $this->releasablePendingAmount($account, $group, $released);

        return [
            'locked' => $locked,
            'unlock_needed' => $unlockNeeded,
            'leg1' => $leg1,
            'leg2' => $leg2,
            'matched' => $matched,
            'released' => $released,
            'next_target' => $target,
            'need_leg1' => max(0, $target - $leg1),
            'need_leg2' => max(0, $target - $leg2),
            'next_amount' => $step * 2 * $rate,
            'next_label' => $step.'-to-'.$step,
            'pending_amount' => $pending,
            'releasable_amount' => $releasableAmount,
            'releasable_now' => $releasableAmount > 0,
        ];
    }

    /**
     * Total slot income on this account that is already released by the staged
     * rule but still waiting on an approval click.
     *
     * Held rows are excluded — they cannot be approved, so counting them would
     * put money on an "Approve" button that button cannot move.
     */
    protected function releasablePendingAmount(Consultant $account, Group $group, int $released): float
    {
        if ($released <= 0) {
            return 0.0;
        }

        // The first $released members on each leg are the ones within depth.
        $eligible = [];

        foreach ([1, 2] as $leg) {
            $eligible = array_merge($eligible, GroupMember::where('group_id', $group->id)
                ->where('tier', $leg)
                ->orderBy('id')
                ->limit($released)
                ->pluck('id')
                ->all());
        }

        if ($eligible === []) {
            return 0.0;
        }

        return (float) Commission::where('consultant_id', $account->id)
            ->where('type', CommissionService::TYPE_SLOT)
            ->where('status', 'pending')
            ->whereNull('held_at')
            ->whereIn('group_member_id', $eligible)
            ->sum('amount');
    }

    // ----- The account unlock ----------------------------------------------------

    /**
     * Is this account allowed to release group income yet?
     *
     * Accounts unlock in sequence: the first one a consultant owns is always
     * open, and each one after it stays locked until the PREVIOUS account has
     * reached `account_unlock_students` (12) students.
     *
     * An ADMIN MAY OVERRIDE THAT EITHER WAY (2026-08-17). The automatic rule
     * is the default and still governs every account nobody has touched, but
     * waiting for 12 students is not always what the business wants — the
     * owner asked to be able to open a placement account early, and to be able
     * to shut one that the rule would otherwise have opened. The override is
     * checked FIRST and short-circuits, so the student count stops mattering
     * for that account until the override is cleared back to Automatic.
     *
     * It is only ever set on hidden placement accounts — a real consultant's
     * own account is never gated by this rule in the first place (see
     * previousAccountId()) and the admin screen refuses to set it on one.
     */
    public function isUnlocked(Consultant $account, ?array $config = null): bool
    {
        $override = $account->lockOverride();

        if ($override === Consultant::LOCK_FORCED_OPEN) {
            return true;
        }

        if ($override === Consultant::LOCK_FORCED_SHUT) {
            return false;
        }

        $config ??= self::config();
        $previousId = $this->previousAccountId($account);

        // The very first account is always open.
        if ($previousId === null) {
            return true;
        }

        return $this->studentsOn($previousId) >= $config['unlock'];
    }

    /**
     * The account immediately before this one in unlock order, or null when
     * nothing gates it.
     *
     * The chain runs through the PLACEMENT accounts only. The owner's own
     * account is never gated and never gates anything — the owner described
     * their three accounts as "CON-00100 main, SCN-00101 and SCN-00102", with
     * placements starting at SCN-00101, and in real data the main account
     * routinely holds zero students. Chaining through it would leave every
     * placement account permanently locked and freeze all group income.
     */
    public function previousAccountId(Consultant $account): ?int
    {
        $owner = $account->owningPerson();

        // The owner's own account is always open.
        if ($account->id === $owner->id) {
            return null;
        }

        // ⚠️ SET-MAINS ARE SKIPPED IN THE CHAIN (2026-08-22).
        //
        // Accounts arrive in sets of three, and a set-main represents no
        // Service Group, so nothing can ever be placed into it — the picker
        // hides it deliberately. Leaving it in the unlock chain meant every
        // bought set's legs waited on an account that could never reach the
        // 12 students the rule demands, so a bought set could NEVER release
        // its own slot income. Verified: with a bought set #3/#4/#5, both #4
        // and #5 reported locked with blocked_by pointing at #3, and giving #3
        // 12 students was the only thing that freed them.
        //
        // The chain therefore runs #1 -> #2 -> #4 -> #5 -> #7 -> #8, skipping
        // #3, #6, #9. Loaded in one query rather than a find() per id.
        $placements = Consultant::where('owner_consultant_id', $owner->id)
            ->where('is_hidden', true)
            ->orderBy('id')
            ->get();

        // The leg read from each account's position in THIS list, which is the
        // very list serviceGroupLeg() queries for — not by calling it, which
        // re-ran that query once per sibling (2026-09-14, System Run speed).
        $ids = $placements
            ->values()
            ->filter(fn (Consultant $a, int $position) => Consultant::legForOrdinal($position + 1) !== null)
            ->pluck('id')
            ->values()
            ->all();

        $position = array_search($account->id, $ids, true);

        // Unknown account (including a set-main, which is never gated), or the
        // FIRST placement account — always open.
        if ($position === false || $position === 0) {
            return null;
        }

        return $ids[$position - 1];
    }

    /** How many students have been placed on one account (all legs, regardless of pay state). */
    public function studentsOn(int $accountId): int
    {
        return GroupMember::whereIn('group_id', Group::where('consultant_id', $accountId)->select('id'))->count();
    }

    // ----- Counting ----------------------------------------------------------------

    public function matchedPairs(Group $group): int
    {
        return min($this->countOnLeg($group, 1), $this->countOnLeg($group, 2));
    }

    protected function countOnLeg(Group $group, int $leg): int
    {
        return GroupMember::where('group_id', $group->id)->where('tier', $leg)->count();
    }

    /**
     * This member's position on its own leg, oldest first. Taken by id rather
     * than from a live counter so it answers correctly for an old member being
     * checked long after placement.
     */
    protected function rankOnLeg(GroupMember $member): int
    {
        return GroupMember::where('group_id', $member->group_id)
            ->where('tier', $member->tier)
            ->where('id', '<=', $member->id)
            ->count();
    }

    /**
     * Every member's rank on one leg in a single query, keyed by
     * group_member_id — for a caller that needs to mark several rows
     * released/unreleased at once (e.g. the admin queue's per-row Approve
     * button) without one query per row.
     *
     * @return array<int, int> group_member_id => rank (1-based, oldest first)
     */
    public function ranksOnLeg(Group $group, int $leg): array
    {
        return GroupMember::where('group_id', $group->id)
            ->where('tier', $leg)
            ->orderBy('id')
            ->pluck('id')
            ->flip()
            ->map(fn ($zeroBased) => $zeroBased + 1)
            ->all();
    }
}
