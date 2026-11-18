<?php

namespace App\Services;

use App\Models\Commission;
use App\Models\Consultant;
use App\Models\ConsultantFinancePeriod;
use App\Models\Student;
use App\Models\User;
use App\Support\FinancePeriod;
use App\Support\FinanceWeek;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\DB;

/**
 * The weekly student-quota compliance engine: active -> restricted ->
 * suspended -> (admin) -> active. Evaluated LAZILY on request paths — this
 * host has no working cron (routes/console.php), so there is no scheduled
 * command anywhere in this class.
 *
 * Hidden placement accounts never carry finance state of their own; every
 * method here rolls a placement account up to Consultant::owningPerson()
 * first, and every count is taken across the owner's accountIds() so
 * students seated on a hidden account still count toward the real person's
 * quota (see ReportController.php's existing "students live across ALL
 * accounts" pattern for the same class of bug in a different feature).
 */
class ConsultantFinanceService
{
    /**
     * Whether the weekly quota only counts students whose payment has been
     * verified, vs. raw registrations. TRUE means an accountant's approval
     * backlog can never cost a consultant their week — the student still
     * counts in the period they were REGISTERED, once verification lands,
     * even if that happens after the period has closed (see the "re-check
     * from the restricting period forward" logic in evaluate()).
     */
    private const REQUIRE_VERIFIED_PAYMENT = true;

    /** Hard cap on how far a catch-up walk goes, so a consultant who never logged in stays O(1) to evaluate. */
    private const MAX_CATCHUP_PERIODS = 52;

    // ----- The middleware entry point ---------------------------------------

    /**
     * Cheap, safe fast path for "is this consultant currently suspended".
     * Called on (almost) every request by EnforceConsultantFinanceStatus, so
     * this must cost as close to zero queries as possible in the common case
     * and must NEVER run schema DDL — see FinanceSchemaRepair::isReady() and
     * the "no active transaction" incident documented on CommissionService.
     *
     * @return string 'active'|'restricted'|'suspended'
     */
    public function statusFor(User $user, Session $session): string
    {
        if (! FinanceSchemaRepair::isReady()) {
            return 'active';
        }

        $consultant = $user->consultant;

        if (! $consultant || $consultant->is_hidden) {
            return 'active';
        }

        // ⚠️ THE OFF SWITCH (2026-09-15) — see FinanceWeek::quotaEnabled().
        // Nothing is evaluated and nothing is written while it is off, so a
        // parked system costs zero queries and cannot restrict anyone.
        if (! FinanceWeek::quotaEnabled()) {
            return 'active';
        }

        $currentKey = FinanceWeek::current()->key();
        $expectedKey = FinanceWeek::lastClosed()->key();

        // Fast path: already active, already evaluated through the last
        // closed period, and this session has already seen this period.
        // Zero additional queries beyond the consultant row already loaded.
        if ($consultant->finance_status === 'active'
            && $consultant->finance_period_key === $expectedKey
            && $session->get('fin.period') === $currentKey) {
            return 'active';
        }

        // Advance the 5-year maturity counter first, so the moment a
        // consultant qualifies they are exempt from the quota in the SAME
        // request rather than one request later. Only reached on the slow
        // path (the fast path above already returned), and the counter itself
        // no-ops unless a whole 28-day month has closed since last time.
        try {
            app(ConsultantMaturityService::class)->evaluate($consultant);
            $consultant->refresh();
        } catch (\Throwable $e) {
            report($e);
        }

        $status = $this->evaluate($consultant);

        if ($status === 'active') {
            $session->put('fin.period', $currentKey);
        } else {
            $session->forget('fin.period');
        }

        return $status;
    }

    // ----- Counting ----------------------------------------------------------

    /** How many students this owner (across all their accounts) registered within one period. */
    public function countFor(Consultant $owner, FinancePeriod $period): int
    {
        [$from, $to] = $period->utcBounds();

        return $this->baseCountQuery($owner, $from, $to)->count();
    }

    /**
     * Bucketed count across [from.start, throughInclusive.end), in ONE query,
     * keyed by each period's key(). This is what keeps the catch-up walk for
     * a consultant who has not logged in for months to a single SELECT
     * instead of one query per missed week.
     *
     * @return array<string, int>
     */
    protected function countsByPeriod(Consultant $owner, FinancePeriod $from, FinancePeriod $throughInclusive): array
    {
        [$start] = $from->utcBounds();
        [, $end] = $throughInclusive->utcBounds();

        $timestamps = $this->baseCountQuery($owner, $start, $end)->pluck('created_at');

        $buckets = [];

        foreach ($timestamps as $createdAt) {
            $key = FinanceWeek::containing(CarbonImmutable::instance($createdAt))->key();
            $buckets[$key] = ($buckets[$key] ?? 0) + 1;
        }

        return $buckets;
    }

    /**
     * How many students EVERY real consultant registered within one period,
     * in a single grouped query — used by the admin Finance Report, which
     * would otherwise need one countFor() call per consultant. Keyed by
     * owner id (not account id): a student seated on a hidden placement
     * account is rolled up to whoever owns it, via COALESCE(owner_consultant_id, id),
     * the same rule accountIds() applies one consultant at a time.
     *
     * @return array<int, int>
     */
    public function countAllFor(FinancePeriod $period): array
    {
        [$from, $to] = $period->utcBounds();

        $query = DB::table('students as s')
            ->join('consultants as c', 'c.id', '=', 's.consultant_id')
            ->where('s.created_at', '>=', $from)
            ->where('s.created_at', '<', $to);

        if (self::REQUIRE_VERIFIED_PAYMENT) {
            $query->whereExists(function ($sub) {
                $sub->selectRaw('1')
                    ->from('payments')
                    ->whereColumn('payments.user_id', 's.user_id')
                    ->where('payments.status', 'verified');
            });
        }

        return $query
            ->selectRaw('COALESCE(c.owner_consultant_id, c.id) as owner_id, COUNT(*) as completed')
            ->groupBy('owner_id')
            ->pluck('completed', 'owner_id')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    protected function baseCountQuery(Consultant $owner, CarbonImmutable $fromUtc, CarbonImmutable $toUtc)
    {
        $query = Student::query()
            ->whereIn('consultant_id', $owner->accountIds())
            ->where('created_at', '>=', $fromUtc)
            ->where('created_at', '<', $toUtc); // half-open — see FinancePeriod

        if (self::REQUIRE_VERIFIED_PAYMENT) {
            $query->whereExists(function ($sub) {
                $sub->selectRaw('1')
                    ->from('payments')
                    ->whereColumn('payments.user_id', 'students.user_id')
                    ->where('payments.status', 'verified');
            });
        }

        return $query;
    }

    // ----- The state machine ------------------------------------------------

    /**
     * Walk this consultant forward from their last-evaluated period through
     * the last CLOSED one, applying the restrict/suspend/release rules for
     * each, then check the still-open current period for an immediate
     * release. Safe to call on every request: idempotent, and a cheap no-op
     * once already caught up.
     *
     * @return string 'active'|'restricted'|'suspended'
     */
    public function evaluate(Consultant $consultant, bool $force = false): string
    {
        // Hidden accounts are placement buckets, not people — evaluate the owner.
        if ($consultant->is_hidden) {
            $consultant = $consultant->owningPerson();
        }

        // ⚠️ THE OFF SWITCH (2026-09-15). Guarded here too, not only in
        // statusFor(), because evaluateAll() (the admin "re-evaluate everyone"
        // button) comes straight in through this method.
        if (! FinanceWeek::quotaEnabled()) {
            return 'active';
        }

        // A MATURE consultant (60 qualifying consultant months) is exempt from
        // the weekly quota permanently — they earn from their downline
        // instead, so there is nothing here to restrict them for. Checked
        // before any counting so a mature account costs zero queries.
        if (FinanceSchemaRepair::maturityReady() && $consultant->mature_at) {
            if (($consultant->finance_status ?? 'active') !== 'active') {
                $this->transition($consultant, 'active', null, FinanceWeek::lastClosed()->key());
            }

            return 'active';
        }

        $cfg = FinanceWeek::config();
        $current = FinanceWeek::current();
        $through = $current->minus(1); // the last CLOSED period

        $from = $consultant->finance_period_key
            ? FinanceWeek::fromKey($consultant->finance_period_key)->next()
            // Never punish the week a consultant joined in.
            : FinanceWeek::containing(CarbonImmutable::instance($consultant->created_at))->next();

        // While restricted, always re-check from the RESTRICTING period
        // forward — a payment verified late can retroactively turn a missed
        // period into a met one and release the consultant.
        if ($consultant->finance_restricted_period) {
            $restrictedFrom = FinanceWeek::fromKey($consultant->finance_restricted_period->toDateString());

            if ($restrictedFrom->start->lessThan($from->start)) {
                $from = $restrictedFrom;
            }
        }

        // Hard cap so a consultant absent for years is still cheap to catch up.
        $cap = FinanceWeek::containing($through->start->subWeeks(self::MAX_CATCHUP_PERIODS));

        if ($from->start->lessThan($cap->start)) {
            $from = $cap;
        }

        if ($from->start->greaterThan($through->start) && ! $force) {
            // Nothing new through the last closed period — but the OPEN
            // current period can still release a restricted consultant.
            return $this->checkOpenPeriodRelease($consultant, $current, $cfg);
        }

        $counts = $this->countsByPeriod($consultant, $from, $through);

        $status = $consultant->finance_status ?? 'active';
        $restrictedKey = $consultant->finance_restricted_period?->toDateString();
        $ledger = [];

        for ($p = $from; $p->start->lessThanOrEqualTo($through->start); $p = $p->next()) {
            $done = $counts[$p->key()] ?? 0;
            $met = $done >= $cfg['quota'];

            if ($met) {
                if ($status === 'restricted') {
                    $status = 'active';
                    $restrictedKey = null;
                }
                // 'suspended' + met stays suspended — only an admin reactivates.
            } elseif ($status === 'active') {
                $status = 'restricted';
                $restrictedKey = $p->key();
            } elseif ($status === 'restricted') {
                $streak = FinanceWeek::fromKey($restrictedKey)->start->diffInWeeks($p->start);

                if ($streak >= $cfg['grace_weeks']) {
                    $status = 'suspended';
                }
            }

            $ledger[] = [
                'period_start' => $p->start->toDateString(),
                'period_end' => $p->displayEnd()->toDateString(),
                'students_required' => $cfg['quota'],
                'students_completed' => $done,
                'result' => $met ? 'met' : 'missed',
                'outcome' => $status,
            ];
        }

        $this->persist($consultant, $status, $restrictedKey, $through->key(), $ledger);

        return $this->checkOpenPeriodRelease($consultant->fresh(), $current, $cfg);
    }

    /** The still-open current period can only ever RELEASE a restriction, never trigger one. */
    protected function checkOpenPeriodRelease(Consultant $consultant, FinancePeriod $current, array $cfg): string
    {
        if ($consultant->finance_status !== 'restricted') {
            return $consultant->finance_status ?? 'active';
        }

        if ($this->countFor($consultant, $current) < $cfg['quota']) {
            return $consultant->finance_status;
        }

        $this->transition($consultant, 'active', null, $consultant->finance_period_key);

        return 'active';
    }

    // ----- Writes --------------------------------------------------------------

    /**
     * Idempotent, concurrency-safe: a conditional UPDATE rather than
     * read-then-write, so two simultaneous requests for the same consultant
     * can never double-apply a transition, and no lockForUpdate()/transaction
     * is needed — this never moves money directly (hold()/release() do that
     * separately, also idempotently).
     */
    protected function persist(Consultant $consultant, string $status, ?string $restrictedKey, string $throughKey, array $ledger): void
    {
        $previousStatus = $consultant->finance_status ?? 'active';

        $applied = DB::table('consultants')
            ->where('id', $consultant->id)
            ->where(fn ($q) => $q->whereNull('finance_period_key')->orWhere('finance_period_key', '<', $throughKey))
            ->update([
                'finance_status' => $status,
                'finance_period_key' => $throughKey,
                'finance_restricted_period' => $restrictedKey,
                'finance_restricted_at' => $status === 'restricted' && $previousStatus !== 'restricted' ? now() : $consultant->finance_restricted_at,
                'finance_suspended_at' => $status === 'suspended' && $previousStatus !== 'suspended' ? now() : $consultant->finance_suspended_at,
                'updated_at' => now(),
            ]);

        if ($applied === 0) {
            // A concurrent request already evaluated this consultant through
            // this period.
            return;
        }

        foreach ($ledger as $row) {
            ConsultantFinancePeriod::updateOrCreate(
                ['consultant_id' => $consultant->id, 'period_start' => $row['period_start']],
                array_merge($row, ['evaluated_at' => now()]),
            );
        }

        if ($status !== $previousStatus) {
            $this->applySideEffects($consultant, $previousStatus, $status);
        }
    }

    /** A single explicit transition with no ledger write — used for open-period release and reactivation. */
    protected function transition(Consultant $consultant, string $status, ?string $restrictedKey, ?string $throughKey): void
    {
        $previousStatus = $consultant->finance_status ?? 'active';

        DB::table('consultants')->where('id', $consultant->id)->update([
            'finance_status' => $status,
            'finance_period_key' => $throughKey,
            'finance_restricted_period' => $restrictedKey,
            'updated_at' => now(),
        ]);

        if ($status !== $previousStatus) {
            $this->applySideEffects($consultant, $previousStatus, $status);
        }
    }

    protected function applySideEffects(Consultant $consultant, string $from, string $to): void
    {
        // ⚠️⚠️ THE WEEKLY QUOTA NO LONGER HOLDS INCOME (2026-09-15).
        //
        // Missing the quota used to stamp held_at on every pending/approved row
        // the person owned. legScopeQuery() excludes held rows, so at the
        // Tuesday period boundary a restricted consultant's Service Group legs
        // all read LKR 0 and it looked like their money had been wiped. Reported
        // as "hama finance week ekedim okkogem commission direct bonus okkom 0
        // ... meka monw unath 0 wen nathi wenn". On the live site that boundary
        // hit 4 consultants and hid LKR 912,500 of real balance.
        //
        // NOTHING IS LEAKED BY DROPPING IT. Both money-moving gates check the
        // live finance status themselves, never held_at:
        //   - approval — CommissionApproval, `! isFinanceActive()`
        //   - payout   — ManagesServiceIncome::payOne(), `isFinanceActive()`
        // So a restricted consultant still cannot have income approved and
        // still cannot be marked paid. The quota keeps its teeth; the money is
        // simply visible again, and settles in a System Run like anyone else's.
        //
        // release() now runs on EVERY transition, so a person carrying stamps
        // from before this change is cleaned up the moment their status moves.
        // Rows whose owner never transitions again are cleared in one press by
        // Commission Health -> "Put Back Held Income".
        //
        // hold() itself is deliberately left working for a direct caller; this
        // only stops the weekly quota from being that caller.
        $this->release($consultant);

        // Every suspension counts against the maturity track for life. The
        // 4th one wipes the 60-month counter — see ConsultantMaturityService.
        // Only on the TRANSITION into suspended, never on a re-evaluation
        // that finds them already suspended, or the counter would run away.
        if ($to === 'suspended' && $from !== 'suspended') {
            try {
                app(ConsultantMaturityService::class)->recordSuspension($consultant);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        $this->logTransition($consultant, $from, $to);
    }

    /**
     * A bulk query-builder update() (used throughout this class for
     * idempotency/concurrency-safety) fires no Eloquent events, so the
     * Auditable trait's automatic logging never sees these writes. Log one
     * explicit activity record per transition instead. Compliance bookkeeping
     * must never take the site down, so this never throws outward.
     */
    protected function logTransition(Consultant $consultant, string $from, string $to, ?User $causedBy = null): void
    {
        try {
            $log = activity()->performedOn($consultant)->withProperties(['from' => $from, 'to' => $to]);

            if ($causedBy) {
                $log = $log->causedBy($causedBy);
            }

            $log->log("finance.{$to}");
        } catch (\Throwable $e) {
            report($e);
        }
    }

    // ----- Commission hold / release -----------------------------------------

    /**
     * Hold every PENDING commission across every account this person owns.
     * Idempotent — whereNull makes a repeat affect 0 rows — and touches no
     * counter: a held row stays `pending`, so nothing here moves
     * consultants.total_earned or groups.total_commission_earned. Rows
     * already approved/paid are left alone; see
     * CommissionController::markPaid()'s own finance-status guard for how
     * already-approved money is stopped from actually being paid out.
     */
    public function hold(Consultant $owner): int
    {
        $owner = $owner->owningPerson();

        // ⚠️ APPROVED-BUT-UNPAID IS HELD TOO (2026-08-25).
        //
        // This was `status = pending`, which was the whole queue back when
        // income waited for a human to approve it. Since 2026-08-25 income is
        // approved the instant its payment is verified, so by the time anyone
        // is restricted there is nothing pending left to stamp — the hold
        // became a no-op, and a System Run could then FORFEIT that
        // consultant's income, which the flush is explicitly not allowed to do
        // ("held income must never be forfeited — the consultant can still
        // earn it back").
        //
        // PAID rows are excluded: that money has already left, and holding it
        // would only corrupt the totals. They are stopped by the payout gate in
        // ManagesServiceIncome::payOne() instead, which is where already-earned
        // money is prevented from leaving.
        return Commission::whereIn('consultant_id', $owner->accountIds())
            ->whereIn('status', ['pending', 'approved'])
            ->whereNull('held_at')
            ->update(['held_at' => now()]);
    }

    /** The mirror of hold() — also idempotent, also touches no counter. */
    public function release(Consultant $owner): int
    {
        $owner = $owner->owningPerson();

        return Commission::whereIn('consultant_id', $owner->accountIds())
            ->whereNotNull('held_at')
            ->update(['held_at' => null]);
    }

    // ----- Admin actions ---------------------------------------------------------

    /**
     * The only way back from 'suspended' — rule 6 says this is deliberately
     * NOT automatic. finance_period_key is advanced to the last closed
     * period so the very next evaluate() does not immediately re-suspend the
     * consultant on the same historical misses.
     */
    public function reactivate(Consultant $consultant, User $admin): void
    {
        $consultant = $consultant->owningPerson();
        $previousStatus = $consultant->finance_status ?? 'active';

        DB::table('consultants')->where('id', $consultant->id)->update([
            'finance_status' => 'active',
            'finance_period_key' => FinanceWeek::lastClosed()->key(),
            'finance_restricted_period' => null,
            'finance_reactivated_at' => now(),
            'finance_reactivated_by' => $admin->id,
            'updated_at' => now(),
        ]);

        $this->release($consultant);
        $this->logTransition($consultant, $previousStatus, 'active', $admin);
    }

    /**
     * Evaluate every real consultant in one pass — the admin lever required
     * because there is no cron to do this automatically for a consultant who
     * has not logged in. Chunked to keep memory bounded.
     */
    public function evaluateAll(): int
    {
        $count = 0;

        Consultant::real()->orderBy('id')->chunkById(200, function ($consultants) use (&$count) {
            foreach ($consultants as $consultant) {
                $this->evaluate($consultant, force: true);
                $count++;
            }
        });

        return $count;
    }
}
