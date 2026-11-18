<?php

namespace App\Services;

use App\Models\Commission;

/**
 * Income as the consultant Dashboard counts it: direct-add bonuses, plus
 * service group income a System Run has released.
 *
 * The rule is the owner's, for the Dashboard's "Total Earned" card — whole
 * releases and direct bonuses only. ConsultantDashboardController carries the
 * history of why. A slot row counts once a flush stamped `released_at`; the
 * direct bonus is never flushed, so it always counts.
 *
 * ⚠️ ONE CALCULATION FOR TWO SCREENS (2026-09-13). Service Income summed
 * consultants.total_earned / total_paid instead, which count every APPROVED
 * row — service group income approved before any run released it included — so
 * its "Total Income" and "Unpaid Approved Income" read above the Dashboard for
 * the same person. Owner: "Service Income eke loku gank penwanwa ... dashboard
 * eke thiyeno wage Total Earned and Pending balance". Both screens call this
 * now, so they cannot drift apart again.
 *
 * consultants.total_earned / total_paid themselves are untouched — the Service
 * Groups page and the admin queue read them as they are.
 */
class SettledIncome
{
    /**
     * @param  array<int>  $accountIds
     * @return array{earned: float, paid: float, pending: float}
     */
    public static function totals(array $accountIds): array
    {
        // Read as whole models rather than filtered in SQL: `released_at` comes
        // from FinanceSchemaRepair, and on a database that has not healed yet a
        // missing column must read as null here instead of failing the query.
        $rows = Commission::whereIn('consultant_id', $accountIds)
            ->whereIn('status', ['approved', 'paid'])
            ->get()
            ->reject(fn (Commission $c) => $c->type !== CommissionService::TYPE_DIRECT
                && $c->released_at === null);

        $earned = (float) $rows->sum('amount');
        $paid = (float) $rows->where('status', 'paid')->sum('amount');

        return [
            'earned' => $earned,
            'paid' => $paid,
            'pending' => $earned - $paid,
        ];
    }
}
