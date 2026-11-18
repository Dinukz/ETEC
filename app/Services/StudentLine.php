<?php

namespace App\Services;

use App\Models\Consultant;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * ⚠️ STUDENTS ADDED ON ONE ACCOUNT FORM A LINE (2026-10-01).
 *
 * The owner's example: SCN-112 adds students 1…10 on one Service Group. They
 * stand in a line in the order they were added — 5 is "under" 1-4 and "above"
 * 6-10 — "hariyt SCN downline ekak thiyen ekk wage". When #5 and later #8
 * become consultants and #8 registers students, the 2,500 must climb
 * 8 → 5 → SCN-112. 6 and 7 are students, so they have no accounts and earn
 * nothing; the climb simply passes them.
 *
 * Owner's answers, all three asked explicitly:
 *  - EACH SERVICE GROUP IS ITS OWN LINE. So the account above the line is
 *    credited on exactly the side it always was.
 *  - NO SPILLOVER. When 112 itself adds student 11 below #8, #5 and #8 earn
 *    nothing — only a consultant's OWN registrations climb through the line.
 *  - EXISTING PEOPLE ARE DISPLAY ONLY. Nobody already promoted is ever moved;
 *    the line is shown for everyone and every old record stays as it is.
 *
 * ⚠️ THE MONEY CHANGES ONLY AT PROMOTION, AND THE ENGINE IS UNTOUCHED.
 * attachmentFor() picks the new consultant's parent_consultant_id — the one
 * field the engine already climbs — and nothing else. It is called from
 * UserManagementController::syncProfile() only when the consultant row is
 * being CREATED, so no existing row ever changes, and a brand-new consultant
 * has no history climbing through them that Repair or an audit could re-pay.
 *
 * ⚠️ THE LINE IS READ FROM PAYMENTS, NOT students.consultant_id. A consultant
 * buying their own course re-points their student row (EnrollmentService), so
 * reading that column would drop them off their line the day they buy. The
 * first payment a person ever made on an account is their registration on it;
 * its requested_tier is the leg (fallback: their seat on that account). On the
 * 21 Sep data that leg is recorded for 3,116 of 3,348 students and never
 * disagrees with the seat.
 */
class StudentLine
{
    /** Settings switch for the promotion rule — on unless set to 0. */
    public const ATTACH_KEY = 'student_line_attach';

    /** Rows shown above and below a person in the popup, each way. */
    public const CAP = 100;

    /** The attach is refused if the new chain would come this close to MAX_UPLINE_DEPTH. */
    public const DEPTH_HEADROOM = 10;

    public static function attachEnabled(?callable $read = null): bool
    {
        try {
            $value = $read ? $read() : Setting::get(self::ATTACH_KEY);
        } catch (\Throwable) {
            $value = null;
        }

        return $value === null || $value === '' ? true : (bool) (int) $value;
    }

    /**
     * Everyone on line (account, leg), in the order they were added.
     *
     * @return array<int, array{user_id: int, payment_id: int}>
     */
    public static function members(int $accountId, int $leg): array
    {
        $owner = Consultant::find($accountId);

        if (! $owner) {
            return [];
        }

        // The account's own holder is never on its line (a self-purchase).
        $ownerUserId = (int) ($owner->is_hidden
            ? Consultant::whereKey($owner->owner_consultant_id)->value('user_id')
            : $owner->user_id);

        $first = DB::table('payments')
            ->where('consultant_id', $accountId)
            ->selectRaw('user_id, MIN(id) as first_id')
            ->groupBy('user_id');

        $rows = DB::table('payments as p')
            ->joinSub($first, 'f', fn ($join) => $join->on('p.id', '=', 'f.first_id'))
            ->select('p.id', 'p.user_id', 'p.requested_tier')
            ->selectSub(
                DB::table('group_members as gm')
                    ->join('groups as g', 'g.id', '=', 'gm.group_id')
                    ->whereColumn('gm.user_id', 'p.user_id')
                    ->where('g.consultant_id', $accountId)
                    ->orderBy('gm.id')
                    ->limit(1)
                    ->select('gm.tier'),
                'seat_tier',
            )
            ->orderBy('p.id')
            ->get();

        $members = [];

        foreach ($rows as $row) {
            if ((int) $row->user_id === $ownerUserId) {
                continue;
            }

            if (self::legOf($row->requested_tier, $row->seat_tier) === $leg) {
                $members[] = ['user_id' => (int) $row->user_id, 'payment_id' => (int) $row->id];
            }
        }

        return $members;
    }

    /** The recorded leg, else the seat, else none. */
    protected static function legOf(mixed $requested, mixed $seat): ?int
    {
        foreach ([$requested, $seat] as $value) {
            if (in_array((int) $value, [1, 2], true)) {
                return (int) $value;
            }
        }

        return null;
    }

    /**
     * Which line a person stands on: the account of their very first payment
     * (their registration) and its leg. Null when they are on none.
     *
     * @return array{account_id: int, leg: int}|null
     */
    public static function lineOf(int $userId): ?array
    {
        $payment = DB::table('payments')
            ->where('user_id', $userId)
            ->whereNotNull('consultant_id')
            ->orderBy('id')
            ->first(['consultant_id', 'requested_tier']);

        if (! $payment) {
            return null;
        }

        $seat = DB::table('group_members as gm')
            ->join('groups as g', 'g.id', '=', 'gm.group_id')
            ->where('gm.user_id', $userId)
            ->where('g.consultant_id', $payment->consultant_id)
            ->orderBy('gm.id')
            ->value('gm.tier');

        $leg = self::legOf($payment->requested_tier, $seat);

        return $leg === null ? null : ['account_id' => (int) $payment->consultant_id, 'leg' => $leg];
    }

    /**
     * The nearest person ABOVE $userId on line (account, leg) who is a
     * consultant — their main account — or null.
     *
     * A person whose role was taken away keeps their consultant row, so the
     * role is checked too; a SUSPENDED consultant still counts (suspension is
     * temporary, and the engine's own hold rules already deal with it).
     */
    public static function nearestConsultantAbove(int $userId, int $accountId, int $leg): ?Consultant
    {
        $members = self::members($accountId, $leg);
        $index = array_search($userId, array_column($members, 'user_id'), true);

        if ($index === false) {
            return null;
        }

        for ($i = $index - 1; $i >= 0; $i--) {
            $consultant = Consultant::where('user_id', $members[$i]['user_id'])->where('is_hidden', false)->first();

            if ($consultant && User::find($members[$i]['user_id'])?->hasRole('consultant')) {
                return $consultant;
            }
        }

        return null;
    }

    /**
     * ⚠️ WHO A STUDENT BEING PROMOTED HANGS UNDER — or null for "as before".
     *
     * $sponsorId / $placementTier are the values syncProfile() would write
     * today. The attachment is used only when it provably changes nothing for
     * the account the line belongs to, or for anyone above it:
     *
     *  1. L is the ENGINE'S OWN answer for "which side of $sponsorId would this
     *     person's money land on" (CommissionService::sideUnderParent on an
     *     unsaved stand-in) — not a second copy of its rules. Unknown → as before.
     *  2. P = the nearest consultant above them on line ($sponsorId, L).
     *  3. P's chain must reach $sponsorId on side L, every hop being either
     *     "$sponsorId itself, on side L" or "another consultant of this line's
     *     main, placement_tier L". Anything else (P moved by hand, sitting on the
     *     other side, a deletion having lifted them) → as before.
     *  4. The longer chain must stay well inside MAX_UPLINE_DEPTH, or the people
     *     at the very top would stop earning.
     *
     * @return array{parent_consultant_id: int, placement_tier: int, line_consultant: Consultant}|null
     */
    public static function attachmentFor(int $userId, ?int $sponsorId, ?int $placementTier): ?array
    {
        if (! $sponsorId || ! self::attachEnabled() || ! FinanceSchemaRepair::placementTierReady()) {
            return null;
        }

        $engine = app(CommissionService::class);

        $probe = (new Consultant)->forceFill([
            'user_id' => $userId,
            'parent_consultant_id' => $sponsorId,
            'placement_tier' => $placementTier,
            'is_hidden' => false,
        ]);

        $leg = $engine->sideUnderParent($probe);

        if (! in_array($leg, [1, 2], true)) {
            return null;
        }

        $above = self::nearestConsultantAbove($userId, $sponsorId, $leg);

        if (! $above || (int) $above->user_id === $userId) {
            return null;
        }

        if (! self::climbsTo($above, $sponsorId, $leg, $engine)) {
            return null;
        }

        if (self::depthOf($above) + 1 > CommissionService::MAX_UPLINE_DEPTH - self::DEPTH_HEADROOM) {
            return null;
        }

        return [
            'parent_consultant_id' => (int) $above->id,
            'placement_tier' => $leg,
            'line_consultant' => $above,
        ];
    }

    /** Does $from's chain reach $accountId, on side $leg at every hop? */
    protected static function climbsTo(Consultant $from, int $accountId, int $leg, CommissionService $engine): bool
    {
        $lineMains = null;
        $node = $from;

        for ($i = 0; $i < CommissionService::MAX_UPLINE_DEPTH; $i++) {
            $parentId = (int) $node->parent_consultant_id;

            if ($parentId === 0) {
                return false;
            }

            if ($parentId === $accountId) {
                return $engine->sideUnderParent($node) === $leg;
            }

            // Otherwise the hop must be one this rule made: onto the main of
            // another consultant on the same line, on the same side.
            $lineMains ??= Consultant::whereIn('user_id', array_column(self::members($accountId, $leg), 'user_id'))
                ->where('is_hidden', false)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            if (! in_array($parentId, $lineMains, true) || (int) $node->placement_tier !== $leg) {
                return false;
            }

            $node = Consultant::find($parentId);

            if (! $node) {
                return false;
            }
        }

        return false;
    }

    /** How many sponsor hops sit above this person — the count MAX_UPLINE_DEPTH caps. */
    protected static function depthOf(Consultant $person): int
    {
        $depth = 0;
        $seen = [];
        $node = $person;

        while ($node && $node->parent_consultant_id && $depth < CommissionService::MAX_UPLINE_DEPTH + 5) {
            if (isset($seen[$node->id])) {
                break;
            }

            $seen[$node->id] = true;
            $sponsor = Consultant::find($node->parent_consultant_id);

            if (! $sponsor) {
                break;
            }

            $node = $sponsor->is_hidden ? Consultant::find($sponsor->owner_consultant_id) : $sponsor;
            $depth++;
        }

        return $depth;
    }
}
