<?php

namespace App\Services;

use App\Models\Commission;
use Illuminate\Support\Facades\DB;

/**
 * Apply the CURRENT auto-approval rules to income recorded before they existed.
 *
 * Since 2026-08-24 approval is not a human step for normal income: the direct
 * bonus, hidden-account slot income, and climbing upline 2,500 are approved the
 * moment they are recorded. System Run release is a separate step that clears
 * slot rows from the leg balance; it is not the approval step.
 *
 * ⚠️ Those rules run at CREATION time, so they do nothing for rows already in
 * the database. A consultant who earned before the change is left with income
 * that no path will ever approve — the queue's bulk buttons are unforced and
 * correctly refuse an unbalanced slot, and nobody is going to click through
 * hundreds of historical bonuses by hand. Reported 2026-08-24 as a Service
 * Groups page reading "LKR 0 · + LKR 52,000 pending review" with a System
 * Run that had nothing to flush: "parana data unata... hama ekatama".
 *
 * ⚠️ WHAT THIS DELIBERATELY DOES NOT TOUCH:
 *  - Own-main slot edge cases that are not direct/upline backfill candidates.
 *    These must stay with the live rule path or an explicit finance review.
 *  - Held or finance-suspended income. CommissionApproval's hold gate is not
 *    overridable and refuses those on its own; they are skipped before that so
 *    the reported count is honest.
 *  - Anything already approved, paid or cancelled.
 *
 * It moves no money by itself: an approved commission still has to be marked
 * paid by a human, which is the one manual step left.
 */
class AutoApprovalBackfill
{
    /**
     * @return array{approved: int, amount: float, direct: int, upline: int, skipped_held: int}
     */
    public function run(): array
    {
        $candidates = Commission::query()
            ->where('status', 'pending')
            ->whereNull('held_at')
            ->with(['payment', 'consultant.owner'])
            ->orderBy('id')
            ->get();

        $approved = 0;
        $amount = 0.0;
        $direct = 0;
        $upline = 0;
        $skippedHeld = 0;

        foreach ($candidates as $commission) {
            $isDirect = $commission->type === CommissionService::TYPE_DIRECT;
            $isUpline = ! $isDirect && $commission->isUplineCredit();

            // The registrant's own slot — left for the System Run, on purpose.
            if (! $isDirect && ! $isUpline) {
                continue;
            }

            // force: true skips ONLY the staged-release gate, which is exactly
            // what these two kinds skip at creation. The hold / finance-status
            // gate still refuses, and returns false, so a restricted
            // consultant's income is counted as skipped rather than approved.
            $ok = app(CommissionApproval::class)->approve($commission, force: true, actorId: null);

            if (! $ok) {
                $skippedHeld++;

                continue;
            }

            $approved++;
            $amount += (float) $commission->amount;
            $isDirect ? $direct++ : $upline++;
        }

        return [
            'approved' => $approved,
            'amount' => $amount,
            'direct' => $direct,
            'upline' => $upline,
            'skipped_held' => $skippedHeld,
        ];
    }

    /**
     * What run() would approve, without touching anything.
     *
     * Counted the same way, so the number an admin is shown before pressing the
     * button is the number they get — the preview/run mismatch on the System
     * Run screen was exactly this class of bug.
     *
     * @return array{count: int, amount: float}
     */
    public function pending(): array
    {
        $rows = Commission::query()
            ->where('status', 'pending')
            ->whereNull('held_at')
            ->with('payment')
            ->get()
            ->filter(fn (Commission $c) => $c->type === CommissionService::TYPE_DIRECT || $c->isUplineCredit());

        return [
            'count' => $rows->count(),
            'amount' => (float) $rows->sum('amount'),
        ];
    }
}
