<?php

namespace App\Services;

use App\Models\Consultant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The network tree must never be cut silently.
 *
 * ⚠️ WHAT WENT WRONG, AND WHY THIS EXISTS (found 2026-09-11).
 *
 * `consultants.parent_consultant_id` carries a foreign key declared
 * ON DELETE SET NULL. So deleting one consultant does not fail, and does not
 * warn — it quietly empties the upline of every person directly below them.
 * Those people, and everyone under THEM, then vanish from their real sponsor's
 * screens, and no commission can climb past the gap, because every upline walk
 * is written `while ($node && $node->parent_consultant_id ...)`.
 *
 * It happened on 2026-09-07 05:45:05: a super admin deleted the user "kavindu
 * rukshan", whose accounts sat under SCN-00111. Anishka Prabath (SCN-00169)
 * hung off kavindu's Service Group 1 account. Anishka's upline became NULL,
 * and her 18 students and 2 consultants disappeared from SCN-00109's network
 * even though not one of those rows was touched.
 *
 * Two jobs here, and they are the same job at two points in time:
 *
 *   liftDownlineOf() — BEFORE a deletion, move the leavers' downline onto the
 *                      leaver's own sponsor. The tree closes over the gap
 *                      instead of losing a branch.
 *   orphans()        — AFTER the fact, find the branches already cut and work
 *                      out where each belongs, from the audit log, which still
 *                      remembers the link the foreign key erased.
 *
 * ⚠️ NOTHING HERE MOVES MONEY. It repairs who-sits-under-whom. Existing
 * commission rows are not recalculated, not created and not deleted; a
 * reconnection only changes where FUTURE income can climb, which is the thing
 * the deletion broke.
 */
class UplineContinuity
{
    /** Stop a corrupted chain turning a climb into a hang. */
    protected const MAX_CLIMB = 50;

    // ----- prevention --------------------------------------------------------

    /**
     * Move everyone sitting under $person onto $person's own sponsor.
     *
     * Called immediately before the person is deleted, so the branch is
     * re-hung one level up rather than being cut loose by the foreign key.
     * This is exactly what should have happened to Anishka on 7 September.
     *
     * ⚠️ Covers ALL of the person's accounts, not just their main one. A
     * downline consultant usually hangs off one of the two hidden service
     * group accounts (Anishka hung off SCN-00161, not SCN-00160), so looking
     * only at the main account would miss almost everybody.
     *
     * @return int how many consultants were moved
     */
    public static function liftDownlineOf(Consultant $person): int
    {
        $accounts = $person->accountIds();
        $sponsorId = $person->owningPerson()->parent_consultant_id;

        if ($sponsorId === null) {
            return 0;
        }

        return Consultant::whereIn('parent_consultant_id', $accounts)
            ->whereNotIn('id', $accounts)
            ->update(['parent_consultant_id' => $sponsorId]);
    }

    /**
     * How many people would lose their upline if $person were deleted now.
     *
     * Used to refuse a deletion that cannot be healed — when the leaver has a
     * downline but no sponsor of their own, there is nowhere to lift the
     * branch to, and going ahead would strand it exactly as before.
     */
    public static function wouldStrand(Consultant $person): int
    {
        if ($person->owningPerson()->parent_consultant_id !== null) {
            return 0;
        }

        $accounts = $person->accountIds();

        return Consultant::whereIn('parent_consultant_id', $accounts)
            ->whereNotIn('id', $accounts)
            ->count();
    }

    // ----- repair ------------------------------------------------------------

    /**
     * Consultants with no upline, each annotated with what the audit log says.
     *
     * The network's own root legitimately has no sponsor, so "had a parent
     * when created" is what separates a cut branch from the top of the tree.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function orphans(): Collection
    {
        return Consultant::query()
            ->where('is_hidden', false)
            ->whereNull('parent_consultant_id')
            ->with('user:id,name,email')
            ->orderBy('id')
            ->get(['id', 'user_id', 'scn_code', 'consultant_code', 'created_at'])
            ->map(function (Consultant $orphan) {
                $born = static::bornWith($orphan->id);
                $hadParent = isset($born['parent_consultant_id']) && $born['parent_consultant_id'] !== null;

                [$suggestion, $path] = $hadParent
                    ? static::climbToSurvivor((int) $born['parent_consultant_id'])
                    : [null, []];

                return [
                    'id' => $orphan->id,
                    'code' => $orphan->scn_code ?: $orphan->consultant_code,
                    'name' => $orphan->user?->name ?? '—',
                    'email' => $orphan->user?->email,
                    // False = the top of the network, not damage.
                    'lost_an_upline' => $hadParent,
                    'original_parent_id' => $hadParent ? (int) $born['parent_consultant_id'] : null,
                    'suggested_id' => $suggestion,
                    'suggested_code' => $suggestion ? static::codeOf($suggestion) : null,
                    'path' => $path,
                    'downline_size' => count($orphan->downlineIds()),
                ];
            });
    }

    /**
     * Reattach an orphan to a sponsor.
     *
     * ⚠️ REFUSES A CYCLE. Hanging someone off their own downline would make
     * every upline walk in the app loop until it hits its depth cap, and the
     * commission engine would pay the same person twice on the way round.
     */
    public static function reconnect(Consultant $orphan, Consultant $sponsor): void
    {
        abort_if($orphan->is_hidden, 422, 'A placement account has no upline of its own.');
        abort_if($orphan->parent_consultant_id !== null, 422, 'That consultant already has an upline.');

        $forbidden = array_merge($orphan->accountIds(), $orphan->downlineIds());

        abort_if(
            in_array((int) $sponsor->id, array_map('intval', $forbidden), true),
            422,
            'That account is inside this consultant\'s own network, which would make a loop.',
        );

        $orphan->forceFill(['parent_consultant_id' => $sponsor->id])->save();
    }

    // ----- reading the audit log ---------------------------------------------

    /**
     * Climb from a deleted account until a consultant that still exists.
     *
     * The rows are gone, but `activity_log` kept the attributes each was
     * created with, so the chain the foreign key erased can still be read:
     *
     *   Anishka -> #765 (deleted, a placement account of #764)
     *           -> #764 (deleted, its sponsor was #748)
     *           -> #748  still exists  =>  reconnect here
     *
     * A hidden account steps to the person who owns it; a main account steps
     * to its own sponsor.
     *
     * @return array{0: ?int, 1: array<int, string>} the survivor, and the steps taken
     */
    protected static function climbToSurvivor(int $startId): array
    {
        $path = [];
        $seen = [];
        $node = $startId;
        $hops = 0;

        while ($node !== null && ! isset($seen[$node]) && $hops++ < self::MAX_CLIMB) {
            $seen[$node] = true;

            if (Consultant::whereKey($node)->exists()) {
                $path[] = static::codeOf($node).' — still here';

                return [$node, $path];
            }

            $born = static::bornWith($node);

            if ($born === null) {
                $path[] = "#{$node} was deleted, and nothing records what it hung off";

                return [null, $path];
            }

            $step = isset($born['owner_consultant_id']) && $born['owner_consultant_id'] !== null
                ? (int) $born['owner_consultant_id']
                : (isset($born['parent_consultant_id']) && $born['parent_consultant_id'] !== null
                    ? (int) $born['parent_consultant_id']
                    : null);

            $path[] = "#{$node} was deleted".($step === null ? ', and nothing sat above it' : " — it hung off #{$step}");
            $node = $step;
        }

        return [null, $path];
    }

    /**
     * The attributes a consultant row was created with.
     *
     * ⚠️ Query builder, not the Activity model — this runs on screens that may
     * load before the package's own table is guaranteed, and a missing
     * activity_log must degrade to "no suggestion" rather than throw.
     *
     * @return array<string, mixed>|null
     */
    protected static function bornWith(int $consultantId): ?array
    {
        try {
            $properties = DB::table('activity_log')
                ->where('subject_type', Consultant::class)
                ->where('subject_id', $consultantId)
                ->where('event', 'created')
                ->orderBy('id')
                ->value('properties');
        } catch (\Throwable) {
            return null;
        }

        if (! $properties) {
            return null;
        }

        $decoded = json_decode((string) $properties, true);

        return $decoded['attributes'] ?? null;
    }

    protected static function codeOf(int $id): string
    {
        $row = Consultant::whereKey($id)->first(['scn_code', 'consultant_code']);

        return $row ? ($row->scn_code ?: $row->consultant_code) : ('#'.$id);
    }
}
