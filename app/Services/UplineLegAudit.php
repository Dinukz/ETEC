<?php

namespace App\Services;

use App\Models\Commission;
use App\Models\Consultant;
use App\Models\Group;
use App\Models\GroupMember;
use Illuminate\Support\Facades\DB;

/**
 * Finds — and optionally corrects — upline commission rows sitting on the
 * WRONG Service Group of their ancestor.
 *
 * ----- Why this exists ------------------------------------------------------
 * Which leg an ancestor is credited on is named by the placement account the
 * downline hangs off: the owner's first placement account IS their Service
 * Group 1, the second IS Service Group 2, and later account SETS repeat that
 * shape (see CommissionService::placementLeg()).
 *
 * Between 2026-08-19 and 2026-08-20 that rule was overridden by
 * `consultants.placement_tier`, which is fed from `payments.requested_tier` —
 * the leg the student sat on INSIDE the placement account, an entirely
 * different quantity. Rows written in that window landed on the wrong side of
 * the ancestor. Separately, every account beyond the first two fell through to
 * the same seat-tier lookup for far longer.
 *
 * Fixing the engine does NOT move money already written: a 2,500 recorded
 * against tier 2 stays on tier 2 for ever. Hence this audit — the owner
 * reported the totals still looking wrong after the fix, which is exactly what
 * historical rows do.
 *
 * ----- What it will and will not touch --------------------------------------
 * ONLY upline-credited slot income (`type = bonus`, `Commission::isUplineCredit()`),
 * because that is the only kind whose leg is derived from the sponsor chain. A
 * consultant's own registration seats them on the leg they picked, which is not
 * this rule's business.
 *
 * It NEVER touches:
 *  - the direct-add bonus (belongs to no leg),
 *  - rows already released or cancelled by a System Run — that money has been
 *    settled and re-legging it would rewrite a completed run's arithmetic,
 *  - the AMOUNT of anything. Only `tier`, and the matching seat's `tier`, move.
 */
class UplineLegAudit
{
    public function __construct(protected CommissionService $commissions)
    {
    }

    /**
     * Every upline row whose recorded tier disagrees with the rule.
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    public function mismatches()
    {
        $rows = Commission::query()
            ->where('type', CommissionService::TYPE_SLOT)
            ->whereIn('status', ['pending', 'approved'])
            ->whereNull('released_at')
            ->with(['consultant.owner', 'payment.consultant.owner'])
            ->get();

        return $rows
            ->filter(fn (Commission $c) => $c->isUplineCredit())
            ->map(function (Commission $c) {
                $expected = $this->expectedLegFor($c);

                return [
                    'commission' => $c,
                    'recorded' => (int) $c->tier,
                    'expected' => $expected,
                ];
            })
            ->filter(fn ($m) => $m['expected'] !== null && $m['expected'] !== $m['recorded'])
            ->values();
    }

    /**
     * The leg this upline row SHOULD sit on, per the current rule.
     *
     * Walks from the account that actually registered the student up to the
     * account this row credits, and asks which of the recipient's legs that
     * branch hangs off — the same question CommissionService::sideUnderParent()
     * answers when the row is first written.
     */
    protected function expectedLegFor(Commission $commission): ?int
    {
        $registrant = $commission->payment?->consultant;
        $recipient = $commission->consultant;

        if (! $registrant || ! $recipient) {
            return null;
        }

        // ⚠️ REWRITTEN 2026-08-21 for the current routing rule.
        //
        // Upline income lands on the PLACEMENT ACCOUNT the downline hangs off,
        // and takes the leg that downline sits on INSIDE that account. So the
        // question is no longer "which leg of the owner does this account
        // represent" — it is "where does the branch below sit within THIS
        // account", which is a seat lookup in the recipient's own groups.
        //
        // Climb from the registrant until we reach the node sponsored directly
        // by the recipient account; that node's seat inside it names the leg.
        $node = $registrant->is_hidden ? $registrant->owner : $registrant;
        $seen = [];
        $depth = 0;

        while ($node && $node->parent_consultant_id && $depth < 50) {
            if (isset($seen[$node->id])) {
                return null; // cyclic data — refuse to guess
            }
            $seen[$node->id] = true;

            if ((int) $node->parent_consultant_id === (int) $recipient->id) {
                // ⚠️ THE RECORDED CHOICE BEATS THE SEAT — mirroring the engine
                // (CommissionService::sideUnderParent, 2026-09-01).
                //
                // This asked the seat and nothing else, while the engine asks
                // `placement_tier` FIRST whenever the recipient is a main. For
                // any consultant whose recorded placement differs from the seat
                // they happen to occupy, the two disagreed — so this tool
                // reported correct money as wrong, moved it, and the next
                // verification moved it back. A repair button that fights the
                // engine is worse than no repair button.
                if (! $recipient->is_hidden && \App\Services\FinanceSchemaRepair::placementTierReady()) {
                    $chosen = (int) ($node->placement_tier ?? 0);

                    if (in_array($chosen, [1, 2], true)) {
                        return $chosen;
                    }
                }

                return $this->seatLegOf($node, $recipient);
            }

            $sponsor = Consultant::with('owner')->find($node->parent_consultant_id);

            if (! $sponsor) {
                return null;
            }

            $node = $sponsor;
            $depth++;
        }

        return null;
    }

    /** Which leg of $account the consultant $node occupies, from their seat. */
    protected function seatLegOf(Consultant $node, Consultant $account): ?int
    {
        if (! $node->user_id) {
            return null;
        }

        $tier = GroupMember::where('user_id', $node->user_id)
            ->whereIn('group_id', Group::where('consultant_id', $account->id)->select('id'))
            ->value('tier');

        return in_array((int) $tier, [1, 2], true) ? (int) $tier : null;
    }

    /**
     * Move the mismatched rows — and their seats — onto the correct leg.
     *
     * The GroupMember moves with the Commission deliberately. The leg balance a
     * System Run measures is built from commission rows, but every screen that
     * shows "N students on this side" counts seats, so correcting one without
     * the other would leave the money on one leg and the student on the other.
     *
     * @return array{checked: int, corrected: int, details: array<int, string>}
     */
    public function repair(): array
    {
        $mismatches = $this->mismatches();
        $details = [];

        DB::transaction(function () use ($mismatches, &$details) {
            foreach ($mismatches as $m) {
                /** @var Commission $c */
                $c = $m['commission'];

                // ⚠️ THE SEAT NO LONGER MOVES WITH THE MONEY (2026-09-01).
                //
                // It used to, on the reasoning that "N students on this side"
                // counts seats and the two would otherwise disagree. That held
                // while a commission's leg was always a copy of its seat's.
                //
                // It is false now: an upline credit legitimately sits on a
                // different leg from the seat under it, because one seat can
                // carry credits for several accounts at different depths. This
                // update would have dragged a student's original registration
                // onto another leg to satisfy an upline row — rewriting where
                // that student sits, for money that is not even theirs.
                Commission::whereKey($c->id)->update(['tier' => $m['expected']]);

                $details[] = sprintf(
                    '%s: LKR %s moved from Service Group %d to %d',
                    $c->consultant?->scn_code ?? $c->consultant?->consultant_code ?? ('#'.$c->consultant_id),
                    number_format((float) $c->amount),
                    $m['recorded'],
                    $m['expected'],
                );
            }
        });

        return [
            'checked' => $mismatches->count(),
            'corrected' => count($details),
            'details' => $details,
        ];
    }
}
