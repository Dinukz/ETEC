<?php

namespace App\Console\Commands;

use App\Models\Commission;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Payment;
use App\Services\CommissionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Re-runs the commission engine over payments that were ALREADY verified.
 *
 * PaymentService::verify() returns early once a payment is verified, so the
 * engine never sees it again. Any student verified before a commission rule
 * changed therefore keeps whatever (or nothing) was recorded at the time —
 * new code alone can never fix them. This walks the history in the order the
 * payments were actually verified and lets the engine record what is missing.
 *
 * Every step of the engine is idempotent (placement is guarded by payment_id,
 * the direct bonus by an existing referral row, and slot settlement by
 * group_members.commission_amount), so running this twice pays nothing twice.
 */
class BackfillCommissions extends Command
{
    protected $signature = 'commissions:backfill {--dry-run : Roll back at the end and only report what would change}';

    protected $description = 'Create commission rows missed by payments verified before the current rules existed.';

    public function handle(CommissionService $commissions): int
    {
        $payments = Payment::where('status', 'verified')
            ->whereNotNull('consultant_id')
            ->orderBy('verified_at')
            ->orderBy('id')
            ->get();

        if ($payments->isEmpty()) {
            $this->info('No verified payments with a consultant — nothing to backfill.');

            return self::SUCCESS;
        }

        // Genuinely before any transaction opens — see PaymentService::verify()
        // for why: an ALTER TABLE run inside a transaction silently auto-commits
        // it under MySQL, and the DB::commit()/rollBack() below would then throw
        // "There is no active transaction" instead of doing anything useful.
        \App\Services\CommissionSchemaRepair::ensure();

        $dryRun = (bool) $this->option('dry-run');
        $before = (float) Commission::sum('amount');

        $this->info(($dryRun ? '[DRY RUN] ' : '').'Replaying '.$payments->count().' verified payment(s)...');
        $this->newLine();

        DB::beginTransaction();

        $created = 0;

        try {
            foreach ($payments as $payment) {
                $rowsBefore = Commission::where('payment_id', $payment->id)->count();

                $commissions->handleVerifiedPayment($payment);

                $new = Commission::where('payment_id', $payment->id)->count() - $rowsBefore;

                if ($new > 0) {
                    $created += $new;
                    $amount = (float) Commission::where('payment_id', $payment->id)->sum('amount');
                    $this->line("  {$payment->payment_code}: +{$new} row(s), payment now totals LKR ".number_format($amount, 2));
                }
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('Backfill aborted, nothing was changed: '.$e->getMessage());

            return self::FAILURE;
        }

        $resynced = $this->resyncMemberAmounts();
        $regrouped = $this->resyncGroupTotals();
        $redated = $this->resyncEarnedAt();

        $after = (float) Commission::sum('amount');

        if ($dryRun) {
            DB::rollBack();
        } else {
            DB::commit();
        }

        $this->newLine();
        $this->info(($dryRun ? '[DRY RUN] ' : '').'Done.');
        $this->line('  Commission rows added : '.$created);
        $this->line('  Student amounts fixed : '.$resynced);
        $this->line('  Group totals fixed    : '.$regrouped);
        $this->line('  Income dates fixed    : '.$redated);
        $this->line('  Total before          : LKR '.number_format($before, 2));
        $this->line('  Total after           : LKR '.number_format($after, 2));
        $this->line('  Difference            : LKR '.number_format($after - $before, 2));

        if ($dryRun) {
            $this->warn('Dry run — everything above was rolled back. Re-run without --dry-run to apply.');
        }

        return self::SUCCESS;
    }

    /**
     * `group_members.commission_amount` is the per-student figure the Service
     * Group Details page prints next to each name. Seats placed under older
     * rules kept 0 there even once their slot income existed, so the page
     * showed "LKR 0" beside students who had genuinely earned. Re-point it at
     * the slot commission that actually exists for each seat.
     *
     * @return int how many student rows were corrected
     */
    protected function resyncMemberAmounts(): int
    {
        // Slot income only — the direct-add bonus belongs to the consultant for
        // bringing the student in, not to the student's seat, and adding it
        // here is what made one number look like two different things.
        // ⚠️ CANCELLED EXCLUDED (2026-09-06) — THIS IS A RESURRECTION VECTOR.
        //
        // Without the filter, a commission removed by hand (or forfeited by a
        // System Run) still contributed its amount here, so the next Repair
        // wrote group_members.commission_amount straight back to 2,500 — and
        // the Service Group Details page showed the removed money again. The
        // commission row itself is safe (a cancelled row still blocks
        // re-creation), but this counter is recomputed from scratch, so it
        // needed the filter explicitly. resyncGroupTotals() below already had
        // one via whereIn(['approved','paid']).
        $slotAmounts = Commission::where('type', 'bonus')
            ->where('status', '!=', 'cancelled')
            ->whereNotNull('group_member_id')
            ->pluck('amount', 'group_member_id');

        $fixed = 0;

        GroupMember::query()->chunkById(200, function ($members) use ($slotAmounts, &$fixed) {
            foreach ($members as $member) {
                $should = round((float) ($slotAmounts[$member->id] ?? 0), 2);

                if (round((float) $member->commission_amount, 2) !== $should) {
                    $member->update(['commission_amount' => $should]);
                    $fixed++;
                }
            }
        });

        return $fixed;
    }

    /**
     * `groups.total_commission_earned` drives the "Current Commission" bar on
     * the Service Group pages, which is measured against the SLOT cap
     * (first_tier_amount + second_tier_amount). It was being incremented with
     * every approved commission including direct bonuses, so a group that had
     * earned no slot income at all still showed a filled bar. Recompute it
     * from approved slot income alone.
     *
     * @return int how many groups were corrected
     */
    protected function resyncGroupTotals(): int
    {
        $slotTotals = Commission::where('type', 'bonus')
            ->whereIn('status', ['approved', 'paid'])
            ->selectRaw('group_id, SUM(amount) as total')
            ->groupBy('group_id')
            ->pluck('total', 'group_id');

        $fixed = 0;

        Group::query()->chunkById(200, function ($groups) use ($slotTotals, &$fixed) {
            foreach ($groups as $group) {
                $should = round((float) ($slotTotals[$group->id] ?? 0), 2);

                if (round((float) $group->total_commission_earned, 2) !== $should) {
                    $group->update(['total_commission_earned' => $should]);
                    $fixed++;
                }
            }
        });

        return $fixed;
    }

    /**
     * Date existing income by its payslip instead of by its verification.
     *
     * Reported 2026-09-10: a slip attached on the 1st and verified on the 8th
     * showed 8 Sep as its income date, because the commission row is only
     * inserted at verification. New rows now carry `earned_at` from the
     * payment (CommissionService::earnedAtFor()); this fills it in for
     * everything already on the books, which is the "Run Repair" answer the
     * owner asked for.
     *
     * ⚠️ ONLY EVER FILLS A NULL. Re-running this cannot move a date that has
     * already been set, so the button is safe to press twice.
     *
     * ⚠️ MONEY IS NOT TOUCHED. Pair matching, forfeits, staging rank and the
     * System Run all order by row id, and the run's cutoff reads
     * payments.created_at — none of them reads this column. Only display,
     * filtering, sorting and the report windows change.
     *
     * A correlated subquery rather than UPDATE ... JOIN: the latter is MySQL
     * only, and the test suite runs on SQLite.
     *
     * Hand-entered income has no payment_id, so it keeps a NULL and falls back
     * to created_at — the day it was typed in, which is the right date for it.
     *
     * @return int how many rows were dated
     */
    protected function resyncEarnedAt(): int
    {
        if (! \App\Services\FinanceSchemaRepair::earnedAtReady()) {
            return 0;
        }

        return DB::table('commissions')
            ->whereNull('earned_at')
            ->whereNotNull('payment_id')
            ->update([
                // coalesce, not created_at: earned_at follows the PAYMENT DATE
                // the consultant entered (2026-09-11), falling back to the row
                // insert time only for older payments that have no paid_at.
                'earned_at' => DB::raw('(select coalesce(p.paid_at, p.created_at) from payments p where p.id = commissions.payment_id)'),
            ]);
    }
}
