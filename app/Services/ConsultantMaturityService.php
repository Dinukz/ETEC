<?php

namespace App\Services;

use App\Models\Consultant;
use App\Support\FinancePeriod;
use App\Support\FinanceWeek;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The 5-year "mature consultant" track.
 *
 * ----- The rule (owner-confirmed 2026-08-09) -------------------------------
 * A CONSULTANT MONTH is 28 days — exactly 4 finance weeks, so it always starts
 * on the same weekday the finance week does and never drifts against it. A
 * month COUNTS toward maturity when the consultant both:
 *   - registered at least `MONTH_QUOTA` (2) students inside it, and
 *   - was not suspended during it.
 * 60 counted months (60 x 28 = 1,680 days, the owner's "awrudu 5k") makes the
 * consultant MATURE, permanently.
 *
 * A missed month PAUSES the counter, it does not reset it. Only the 4th
 * lifetime suspension resets it to zero ("suspend 3ta wada wen ba") — and
 * those suspensions need not be consecutive.
 *
 * Separately, the 3rd lifetime suspension and every one after it put all of
 * the person's SCN accounts back on 1-to-1 (2026-09-14) — see recordSuspension().
 *
 * ----- What maturity actually changes --------------------------------------
 * Exactly one thing: a mature consultant is exempt from the weekly student
 * quota forever, so ConsultantFinanceService can never restrict or suspend
 * them again. They keep earning normally — in particular the upline income
 * from their downline's registrations, which is what the owner meant by
 * "company eken gewanwa". It is NOT a salary and creates no new payment kind.
 *
 * ----- Why lazy, like everything else here ---------------------------------
 * This host has no working cron (routes/console.php), so nothing advances the
 * counter on a schedule. It is evaluated on request paths alongside the weekly
 * finance check, and is cheap: one bucketed query, only when a 28-day boundary
 * has actually been crossed since the last evaluation.
 */
class ConsultantMaturityService
{
    /** Weeks per consultant month. 4 x 7 = the 28 days the owner specified. */
    public const WEEKS_PER_MONTH = 4;

    /** Students needed inside one consultant month for it to count. */
    public const MONTH_QUOTA = 2;

    /** Counted months needed to mature. 60 x 28 days = 1,680 days. */
    public const MONTHS_TO_MATURE = 60;

    /**
     * How many lifetime suspensions are survivable. The NEXT one after this
     * resets the counter — "suspend 3ta wada wen ba" means 3 is fine and the
     * 4th is not.
     */
    public const SUSPENSIONS_ALLOWED = 3;

    /**
     * The lifetime suspension that puts every one of the person's SCN accounts
     * back on 1-to-1 — and every suspension after it (owner, 2026-09-14): "only
     * stage change after 3 time suspend only".
     *
     * ⚠️ NOT SUSPENSIONS_ALLOWED. The maturity counter resets on the 4th, the
     * stages on the 3rd; the owner was shown both and chose the 3rd for stages.
     */
    public const STAGE_RESET_SUSPENSION = 3;

    /** Same hard cap rationale as ConsultantFinanceService: an absent consultant stays O(1). */
    private const MAX_CATCHUP_MONTHS = 120;

    public function __construct(protected ConsultantFinanceService $finance)
    {
    }

    /**
     * Advance the maturity counter for one consultant, if any whole consultant
     * month has closed since it was last evaluated.
     *
     * Idempotent and safe to call on every request. Returns true when the
     * consultant is mature afterwards.
     */
    public function evaluate(Consultant $consultant): bool
    {
        $consultant = $consultant->owningPerson();

        if (! FinanceSchemaRepair::isReady() || ! FinanceSchemaRepair::maturityReady()) {
            return false;
        }

        // Already mature is a terminal state — nothing further to compute.
        if ($consultant->mature_at) {
            return true;
        }

        $current = $this->monthContaining(CarbonImmutable::now());
        $from = $consultant->mature_month_key
            ? $this->monthFromKey($consultant->mature_month_key)->next()
            // Never count the partial month a consultant joined in.
            : $this->monthContaining(CarbonImmutable::instance($consultant->created_at))->next();

        $cap = $this->monthContaining($current->start->subWeeks(self::MAX_CATCHUP_MONTHS * self::WEEKS_PER_MONTH));

        if ($from->start->lessThan($cap->start)) {
            $from = $cap;
        }

        // The month currently in progress is not countable yet.
        $through = $current->minus(1);

        if ($from->start->greaterThan($through->start)) {
            return false;
        }

        $counted = (int) ($consultant->mature_months ?? 0);
        $lastKey = $consultant->mature_month_key;

        for ($month = $from; $month->start->lessThanOrEqualTo($through->start); $month = $month->next()) {
            if ($this->monthQualifies($consultant, $month)) {
                $counted++;
            }

            $lastKey = $month->key();
        }

        $matured = $counted >= self::MONTHS_TO_MATURE;

        $this->persist($consultant, $counted, $lastKey, $matured);

        return $matured;
    }

    /**
     * Did this consultant clear the bar for one closed month?
     *
     * The suspension test is deliberately "are they suspended NOW" rather than
     * a historical lookup: a consultant who is suspended today has, by
     * definition, been failing the weekly quota for the whole grace window
     * that closed these months, and there is no per-month suspension history
     * table to consult. Erring this way costs a suspended consultant months
     * they were arguably owed, but never grants months to someone who was
     * locked out — which is the safer direction for a 5-year benefit.
     */
    protected function monthQualifies(Consultant $consultant, FinancePeriod $month): bool
    {
        if ($consultant->isFinanceSuspended()) {
            return false;
        }

        return $this->finance->countFor($consultant, $month) >= self::MONTH_QUOTA;
    }

    /**
     * Record one lifetime suspension. Called by ConsultantFinanceService the
     * moment a consultant transitions INTO 'suspended'.
     *
     * Past the allowance, the whole 60-month counter goes back to zero — the
     * owner was explicit that this is the penalty and that the suspensions do
     * not have to be consecutive.
     *
     * From the 3rd suspension on, every one of the person's SCN accounts is
     * also put back on 1-to-1 — the one thing allowed to move a stage backwards.
     */
    public function recordSuspension(Consultant $consultant): void
    {
        $consultant = $consultant->owningPerson();

        if (! FinanceSchemaRepair::maturityReady()) {
            return;
        }

        // Increment first, then read back — two concurrent requests must not
        // both read the old count and both decide it is still under the limit.
        Consultant::whereKey($consultant->id)->increment('suspension_count');

        $count = (int) DB::table('consultants')->where('id', $consultant->id)->value('suspension_count');

        if ($count > self::SUSPENSIONS_ALLOWED) {
            DB::table('consultants')->where('id', $consultant->id)->update([
                'mature_months' => 0,
                'mature_month_key' => null,
                'updated_at' => now(),
            ]);
        }

        // ⚠️⚠️ THE 3RD SUSPENSION PUTS EVERY ACCOUNT BACK ON 1-TO-1 (2026-09-14).
        //
        // The ONE thing that moves a stage backwards. Admin-set stages had been
        // found back on 1-to-1 after a repair button and a System Run reversal,
        // and the owner ruled that a stage only ever moves forward — "only stage
        // change after 3 time suspend only" — then, asked which suspension,
        // chose the 3rd, and every one after it.
        //
        // Every account the person holds, main and placements alike: a
        // suspension belongs to the person, while a stage lives on each account.
        if ($count >= self::STAGE_RESET_SUSPENSION && FinanceSchemaRepair::stagingReady()) {
            Consultant::query()
                ->where(fn ($q) => $q->whereKey($consultant->id)->orWhere('owner_consultant_id', $consultant->id))
                ->update(['lifetime_pair_stage' => 0]);

            activity()
                ->performedOn($consultant)
                ->withProperties(['suspension' => $count])
                ->log('Suspended '.$count.' times — every SCN account back on 1-to-1');
        }
    }

    /** Progress for the dashboard / finance report. */
    public function progressFor(Consultant $consultant): array
    {
        $consultant = $consultant->owningPerson();

        $counted = (int) ($consultant->mature_months ?? 0);

        return [
            'mature' => $consultant->mature_at !== null,
            'mature_at' => $consultant->mature_at,
            'months' => $counted,
            'required' => self::MONTHS_TO_MATURE,
            'remaining' => max(0, self::MONTHS_TO_MATURE - $counted),
            'suspensions' => (int) ($consultant->suspension_count ?? 0),
            'suspensions_allowed' => self::SUSPENSIONS_ALLOWED,
        ];
    }

    // ----- Month arithmetic, built on the finance week ----------------------

    /**
     * The 28-day consultant month containing $at.
     *
     * Anchored to the SAME weekday the finance week starts on, and counted in
     * whole 4-week blocks from a fixed epoch, so month boundaries always land
     * on a week boundary and the two systems can never drift apart.
     */
    public function monthContaining(CarbonImmutable $at): FinancePeriod
    {
        $week = FinanceWeek::containing($at);

        // Which of the four weeks in the grid this one is. Signed diff plus a
        // double-modulo so a date BEFORE the epoch still yields 0..3 rather
        // than a negative offset that would shift the month forwards.
        $days = (int) round($this->epoch()->diffInDays($week->start, false));
        $weeksSinceEpoch = intdiv($days, 7);
        $offset = (($weeksSinceEpoch % self::WEEKS_PER_MONTH) + self::WEEKS_PER_MONTH) % self::WEEKS_PER_MONTH;

        return $this->monthStartingAt($week->minus($offset));
    }

    public function monthFromKey(string $key): FinancePeriod
    {
        return $this->monthContaining(
            CarbonImmutable::parse($key, FinancePeriod::TIMEZONE)->startOfDay()->addHours(12),
        );
    }

    /** A 28-day period beginning at the given week's start. */
    protected function monthStartingAt(FinancePeriod $week): FinancePeriod
    {
        // FinancePeriod is a 7-day value object, so a month is expressed as
        // the span from its first week's start to its fourth week's end.
        return FinancePeriod::spanning($week->start, $week->start->addWeeks(self::WEEKS_PER_MONTH));
    }

    /**
     * A fixed, timezone-stable anchor for the 4-week grid. Any date works as
     * long as it never changes — this one is a Tuesday, the business default
     * week start, well before the platform existed.
     */
    protected function epoch(): CarbonImmutable
    {
        return CarbonImmutable::parse('2024-01-02 00:00:00', FinancePeriod::TIMEZONE);
    }

    protected function persist(Consultant $consultant, int $months, ?string $lastKey, bool $matured): void
    {
        $update = [
            'mature_months' => $months,
            'mature_month_key' => $lastKey,
            'updated_at' => now(),
        ];

        if ($matured && ! $consultant->mature_at) {
            $update['mature_at'] = now();
        }

        DB::table('consultants')->where('id', $consultant->id)->update($update);

        if ($matured && ! $consultant->mature_at) {
            try {
                activity()->performedOn($consultant)
                    ->withProperties(['months' => $months])
                    ->log('finance.matured');
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }
}
