<?php

namespace App\Services;

use App\Models\Commission;
use App\Models\Consultant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * ⚠️ COMPLETES HAND ENTRIES THAT STOPPED SHORT OF A MAIN ACCOUNT.
 *
 * Until 2026-09-27 ManualCommissionEntry::chainFrom() skipped every MAIN
 * account on the way up — the registrant's own and each sponsor's. The owner
 * found it on Udara Dilshantha's screen: a 2,500 entered for Lakshan Maduranga
 * reached SCN-00113 and never reached SCN-00112. *"anith ayageth mehem
 * aththe, meka fix krla denn."*
 *
 * The tool is fixed for new entries. This finds the OLD ones and adds exactly
 * the rows the fixed walk says they are missing — nothing else.
 *
 * ⚠️ THIS MINTS MONEY, SO IT IS NARROW ON PURPOSE:
 *
 *   1. PREVIEW FIRST, NEVER BLIND. The page lists every row it would add. An
 *      admin who already made up a missing main by hand — with a different
 *      student, which this cannot connect — sees it before pressing.
 *
 *   2. ONLY UPLINE ENTRIES. One add() call writes all its rows in one
 *      transaction, so an entry is its rows sharing a student and a created_at
 *      second. A single-row entry may have been the "this account only" scope
 *      on purpose; it is never touched, only counted.
 *
 *   3. A ROW REMOVED BY HAND IS NEVER PUT BACK, and nothing is added above it —
 *      the engine's own rule (CommissionService::removedByHand, 2026-09-16).
 *      An entry whose every row was removed was withdrawn, and is left alone.
 *
 *   4. IDEMPOTENT. Each added row takes its entry's created_at, so it joins that
 *      entry — pressing again finds the entry complete and adds nothing.
 *
 * Slot income only. The direct bonus never climbs, so it can never be short.
 */
class ManualEntryMainRepair
{
    public function __construct(protected ManualCommissionEntry $entry) {}

    /**
     * @return array{
     *     entries: Collection<int, array{
     *         user_id: int, student: string, bottom: string, when: string,
     *         amount: float, earned_at: ?\Illuminate\Support\Carbon, created_at: \Illuminate\Support\Carbon,
     *         missing: array<int, array{account: Consultant, code: string, leg: int}>
     *     }>,
     *     singles: int
     * }
     */
    public function preview(): array
    {
        $columns = ['id', 'consultant_id', 'user_id', 'tier', 'amount', 'status', 'created_at'];

        if (FinanceSchemaRepair::earnedAtReady()) {
            $columns[] = 'earned_at';
        }

        if (FinanceSchemaRepair::removalReady()) {
            $columns[] = 'removed_at';
        }

        // EVERY status, cancelled included: a removed row still proves the entry
        // reached that account, and rule 3 needs to see it.
        $rows = Commission::query()
            ->whereNull('payment_id')
            ->where('type', CommissionService::TYPE_SLOT)
            ->orderBy('id')
            ->get($columns);

        $groups = $rows->groupBy(fn ($r) => $r->user_id.'|'.$r->created_at?->format('Y-m-d H:i:s'));

        $entries = collect();
        $singles = 0;

        foreach ($groups as $group) {
            if ($group->count() < 2) {
                $singles++;

                continue;
            }

            // Withdrawn in full — rule 3.
            if ($group->every(fn ($r) => $r->status === 'cancelled')) {
                continue;
            }

            $found = $this->placeEntry($group);

            if ($found === null) {
                continue;
            }

            [$bottom, $chain] = $found;

            $missing = $this->missingFrom($chain, $group);

            if ($missing === []) {
                continue;
            }

            $person = \App\Models\User::find($bottom->user_id);
            $bottomAccount = $chain->first()['account'];

            $entries->push([
                'user_id' => (int) $bottom->user_id,
                'student' => $person?->name ?? ('user #'.$bottom->user_id),
                'bottom' => $bottomAccount->scn_code ?: $bottomAccount->consultant_code,
                'when' => $bottom->created_at->format('M d, Y H:i'),
                'amount' => (float) $bottom->amount,
                'earned_at' => $bottom->earned_at ?? null,
                'created_at' => $bottom->created_at,
                'missing' => $missing,
            ]);
        }

        return ['entries' => $entries, 'singles' => $singles];
    }

    /**
     * Add every row the preview lists, in one transaction.
     *
     * @return array{created: int, amount: float}
     */
    public function apply(?int $actorId = null): array
    {
        $preview = $this->preview();
        $created = 0;
        $amount = 0.0;

        DB::transaction(function () use ($preview, $actorId, &$created, &$amount) {
            foreach ($preview['entries'] as $entry) {
                $extra = $entry['earned_at'] !== null && FinanceSchemaRepair::earnedAtReady()
                    ? ['earned_at' => $entry['earned_at']]
                    : [];

                foreach ($entry['missing'] as $gap) {
                    $row = $this->entry->writeRow(
                        $gap['account'],
                        $entry['user_id'],
                        CommissionService::TYPE_SLOT,
                        $gap['leg'],
                        $entry['amount'],
                        $actorId,
                        $extra,
                    );

                    // Rule 4: join the original entry, so it reads complete next
                    // time. It is also, honestly, the moment this row was owed.
                    $row->forceFill(['created_at' => $entry['created_at']])->save();

                    $created++;
                    $amount += $entry['amount'];
                }
            }
        });

        return ['created' => $created, 'amount' => $amount];
    }

    /**
     * Which row of this entry is the account the admin named, and the walk
     * from it.
     *
     * The named account is the bottom of the climb, so it is the one row whose
     * walk reaches every other account in the entry. Its tier is the leg the
     * form was given.
     *
     * @return array{0: Commission, 1: Collection<int, array{account: Consultant, leg: int}>}|null
     */
    protected function placeEntry(Collection $group): ?array
    {
        $ids = $group->pluck('consultant_id')->map(fn ($id) => (int) $id)->unique()->values();
        $accounts = Consultant::with('owner')->whereIn('id', $ids)->get()->keyBy('id');

        foreach ($group as $row) {
            $account = $accounts[(int) $row->consultant_id] ?? null;

            if (! $account) {
                continue;
            }

            $chain = $this->entry->chainFrom($account, in_array((int) $row->tier, [1, 2], true) ? (int) $row->tier : 1);
            $reached = $chain->map(fn ($hop) => (int) $hop['account']->id);

            if ($ids->diff($reached)->isEmpty()) {
                return [$row, $chain];
            }
        }

        // No row's walk covers the others: the tree has moved since, and there
        // is no honest answer to what this entry should have reached.
        return null;
    }

    /**
     * The hops this entry never wrote — stopping at a row removed by hand.
     *
     * @return array<int, array{account: Consultant, code: string, leg: int}>
     */
    protected function missingFrom(Collection $chain, Collection $group): array
    {
        $byAccount = $group->keyBy(fn ($r) => (int) $r->consultant_id);
        $missing = [];

        foreach ($chain as $hop) {
            $id = (int) $hop['account']->id;
            $row = $byAccount[$id] ?? null;

            if ($row !== null) {
                // Rule 3: a row an admin struck off stops the climb above it,
                // exactly as it stops the engine's.
                if ($row->status === 'cancelled' && ($row->removed_at ?? null) !== null) {
                    break;
                }

                continue;
            }

            $missing[] = [
                'account' => $hop['account'],
                'code' => $hop['account']->scn_code ?: $hop['account']->consultant_code,
                'leg' => (int) $hop['leg'],
            ];
        }

        return $missing;
    }
}
