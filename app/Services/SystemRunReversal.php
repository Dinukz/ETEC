<?php

namespace App\Services;

use App\Models\Commission;
use App\Models\CompanyRevenue;
use App\Models\Consultant;
use App\Models\Group;
use App\Models\SystemRun;
use Illuminate\Support\Facades\DB;

/**
 * Undo a System Run for ONE account.
 *
 * Requested 2026-08-25: "apit meka revers krannth option ekak hadann — wardila
 * giyanm ho wadiyen dila thiyeno, eka account wise revers krann puluwan
 * widiyta." A run is irreversible by nature: it stamps rows released, cancels
 * the overflow to company revenue, and moves the stage on. Getting one account
 * wrong used to mean living with it.
 *
 * It works off the snapshot each flush now leaves on the SystemRun record —
 * exactly which rows were released, which were forfeited and what status they
 * held before, which company-revenue row was booked, and what the stage was.
 * Nothing is inferred from timestamps, which is what makes it safe to run on
 * one account without disturbing the others in the same run.
 *
 * ⚠️ WHAT IT DELIBERATELY WILL NOT DO:
 *  - Reverse a row that has since been marked PAID. That money has left the
 *    company; un-releasing it would put it back on a leg to be paid twice. Such
 *    a row is reported as skipped and left exactly as it is.
 *  - Reverse the same account twice. The reversal is recorded on the run, and a
 *    second attempt refuses rather than crediting the totals again.
 */
class SystemRunReversal
{
    /**
     * @return array{ok: bool, message: string, restored: int, unforfeited: float, skipped_paid: int}
     */
    public function reverse(SystemRun $run, int $accountId): array
    {
        $entry = collect($run->settledAccounts())->first(fn ($a) => (int) ($a['account_id'] ?? 0) === $accountId);

        if (! $entry) {
            return $this->result(false, 'That account was not settled by this run, so there is nothing to reverse.');
        }

        if (! empty($entry['reversed_at'])) {
            return $this->result(false, 'That account has already been reversed on this run.');
        }

        $account = Consultant::find($accountId);

        if (! $account) {
            return $this->result(false, 'That account no longer exists.');
        }

        return DB::transaction(function () use ($run, $entry, $account, $accountId) {
            $restored = 0;
            $skippedPaid = 0;

            // 1. Put released rows back on their leg. Their status is untouched
            //    — releasing never changed it — so clearing the stamp is the
            //    whole undo. A row since marked PAID is left alone.
            $releasedIds = array_map('intval', $entry['released_ids'] ?? []);

            if ($releasedIds !== []) {
                $paid = Commission::whereIn('id', $releasedIds)->where('status', 'paid')->pluck('id')->all();
                $skippedPaid += count($paid);

                $restorable = array_values(array_diff($releasedIds, $paid));

                if ($restorable !== []) {
                    $restored += $this->notRemoved(Commission::whereIn('id', $restorable))->update(['released_at' => null]);
                }
            }

            // 2. Swept rows were only taken off the leg — same undo, and the
            //    same refusal for anything since paid.
            $sweptIds = array_map('intval', $entry['swept_ids'] ?? []);

            if ($sweptIds !== []) {
                $restorable = $this->notRemoved(Commission::whereIn('id', $sweptIds)->where('status', '!=', 'paid'))
                    ->pluck('id')->all();

                if ($restorable !== []) {
                    $restored += Commission::whereIn('id', $restorable)->update(['released_at' => null]);
                }
            }

            // 3. Un-forfeit: restore each row to the status it actually held,
            //    and re-credit the totals the forfeit decremented.
            $unforfeited = 0.0;

            foreach ($entry['forfeited_rows'] ?? [] as $row) {
                $commission = Commission::find($row['id'] ?? 0);

                if (! $commission || $commission->status !== 'cancelled') {
                    continue;
                }

                // ⚠️ AN ADMIN HAS SINCE REMOVED THIS ROW BY HAND (2026-09-06).
                // Un-forfeiting it would put its amount back on total_earned
                // from the SNAPSHOT while the row itself has been zeroed — the
                // account would gain money that no commission row accounts for.
                // The removal is the later, deliberate decision; a reversal of
                // an older run does not get to undo it.
                if ($commission->wasRemovedByHand()) {
                    continue;
                }

                $commission->update([
                    'status' => $row['status'] ?? 'approved',
                    'released_at' => null,
                ]);

                $unforfeited += (float) ($row['amount'] ?? 0);
                $restored++;

                // The forfeit only decremented the totals for rows that were
                // APPROVED at the time, so only those are put back.
                if (($row['status'] ?? null) === 'approved') {
                    Consultant::whereKey($row['consultant_id'])->increment('total_earned', (float) $row['amount']);

                    if (! empty($row['group_id']) && ($row['type'] ?? null) !== CommissionService::TYPE_DIRECT) {
                        Group::whereKey($row['group_id'])->increment('total_commission_earned', (float) $row['amount']);
                    }
                }
            }

            // 4. Take the company revenue back off the books.
            if (! empty($entry['revenue_id'])) {
                CompanyRevenue::whereKey($entry['revenue_id'])->delete();
            }

            // 5. ⚠️ THE STAGE IS LEFT WHERE IT IS (2026-09-14).
            //
            //    This put the stage back to what it was before the run. The
            //    owner has ruled that a stage only ever moves forward — "dana
            //    stage eka thama digtam thiyenne ... admin dana stage eke ho auto
            //    yana stage eke thama digatam thiyenne" — after stages were found
            //    back on 1-to-1. A reversal returns the money to the legs; the
            //    account carries on from the stage it has reached, and any stage
            //    an admin set since the run is kept. stage_before stays in the
            //    snapshot as a record. The only thing that moves a stage back is
            //    the 3rd suspension — ConsultantMaturityService::recordSuspension().

            $this->markReversed($run, $accountId);

            $code = $account->scn_code ?? $account->consultant_code;

            $message = sprintf(
                'Reversed %s — %d record(s) put back on their service groups%s.',
                $code,
                $restored,
                $unforfeited > 0 ? ', LKR '.number_format($unforfeited, 2).' taken back off company revenue' : '',
            );

            if ($skippedPaid > 0) {
                $message .= sprintf(' %d record(s) were left alone because they have already been marked paid.', $skippedPaid);
            }

            return $this->result(true, $message, $restored, $unforfeited, $skippedPaid);
        });
    }

    /**
     * Exclude rows an admin removed by hand from whatever this query is about
     * to restore. Degrades to "no such thing has ever happened" when the column
     * does not exist yet, which on that host is exactly true.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected function notRemoved($query)
    {
        return \App\Services\FinanceSchemaRepair::removalReady()
            ? $query->whereNull('removed_at')
            : $query;
    }

    /** Stamp the reversal onto the run's snapshot so it cannot be repeated. */
    protected function markReversed(SystemRun $run, int $accountId): void
    {
        $accounts = collect($run->settledAccounts())
            ->map(function ($a) use ($accountId) {
                if ((int) ($a['account_id'] ?? 0) === $accountId) {
                    $a['reversed_at'] = now()->toDateTimeString();
                }

                return $a;
            })
            ->all();

        $run->update(['accounts' => json_encode($accounts)]);
    }

    /** @return array{ok: bool, message: string, restored: int, unforfeited: float, skipped_paid: int} */
    protected function result(bool $ok, string $message, int $restored = 0, float $unforfeited = 0.0, int $skippedPaid = 0): array
    {
        return [
            'ok' => $ok,
            'message' => $message,
            'restored' => $restored,
            'unforfeited' => $unforfeited,
            'skipped_paid' => $skippedPaid,
        ];
    }
}
