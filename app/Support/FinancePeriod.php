<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * One finance week: the recurring window a consultant's student quota is
 * measured against.
 *
 * ----- Why this carries its own timezone --------------------------------
 * The app runs on UTC (config/app.php) but the business is Sri Lankan. A week
 * boundary of "Tuesday midnight" computed in UTC actually flips at 5:30am on
 * the start day LOCAL time, so a student registered before dawn would land in
 * the wrong week through no fault of their own. Every boundary here is
 * therefore computed in Asia/Colombo and only converted to UTC at the point a
 * timestamp column is queried.
 *
 * ----- Boundaries — HALF-OPEN, not inclusive-inclusive -------------------
 * Internally this is `[start, end)`: start is inclusive at 00:00:00 local on
 * the configured weekday, end is EXCLUSIVE — the instant the next period
 * begins. Every query MUST use `>= startUtc() AND < endUtc()`, never
 * `whereDate()` (see FiltersByDateRange, which discards the time component —
 * exactly the bug this class exists to avoid) and never `<=` against a
 * human "23:59:59" value. `displayEnd()` provides that inclusive instant
 * purely for showing a person "Mon 23:59:59" on screen; it must never be used
 * in a query. Sri Lanka has observed no DST since 1996, so plain day
 * arithmetic is exact.
 */
final class FinancePeriod
{
    public const TIMEZONE = 'Asia/Colombo';

    private function __construct(
        public readonly CarbonImmutable $start,
        public readonly CarbonImmutable $end,
    ) {
    }

    /**
     * The period containing $at, for a week starting on $startDayIso
     * (ISO weekday: Monday = 1 … Sunday = 7; the business default is Tuesday = 2).
     */
    public static function containing(?CarbonInterface $at, int $startDayIso): self
    {
        $startDayIso = self::normaliseDay($startDayIso);

        $local = CarbonImmutable::instance($at ? $at->toDateTime() : now())
            ->setTimezone(self::TIMEZONE)
            ->startOfDay();

        // How many days back the most recent start-weekday is. Zero when $at
        // already falls on it, which is why this is not Carbon's previous(),
        // whose search is strictly backwards.
        $daysSinceStart = ($local->dayOfWeekIso - $startDayIso + 7) % 7;

        $start = $local->subDays($daysSinceStart);

        return new self($start, $start->addDays(7));
    }

    /**
     * An arbitrary half-open span. Used by ConsultantMaturityService to build
     * a 28-day "consultant month" out of four consecutive finance weeks —
     * every method below derives its arithmetic from `lengthInDays()` rather
     * than assuming 7, so a month steps by 28 days exactly as a week steps by 7.
     */
    public static function spanning(CarbonImmutable $start, CarbonImmutable $end): self
    {
        return new self($start, $end);
    }

    /** How many days this window spans — 7 for a finance week, 28 for a consultant month. */
    public function lengthInDays(): int
    {
        return (int) round($this->start->diffInDays($this->end));
    }

    /** The period $count places before this one (1 = the immediately previous one). */
    public function minus(int $count): self
    {
        $length = $this->lengthInDays();
        $start = $this->start->subDays($length * max(0, $count));

        return new self($start, $start->addDays($length));
    }

    /** The period immediately after this one. */
    public function next(): self
    {
        return new self($this->end, $this->end->addDays($this->lengthInDays()));
    }

    /**
     * A stable identifier for this window, e.g. "2026-08-04".
     *
     * Deliberately the local start DATE rather than an ISO week number: ISO
     * weeks always begin on a Monday, so they would misdescribe any other
     * configured start day. It also degrades safely when an admin changes the
     * start day — the key simply becomes a different string, which reads as
     * "not yet evaluated" and triggers one fresh evaluation.
     */
    public function key(): string
    {
        return $this->start->toDateString();
    }

    /** [start, end) — matches every query in this feature. */
    public function contains(CarbonInterface $at): bool
    {
        $local = CarbonImmutable::instance($at->toDateTime())->setTimezone(self::TIMEZONE);

        return $local->greaterThanOrEqualTo($this->start) && $local->lessThan($this->end);
    }

    /** True once this window has closed (i.e. `end` is in the past). */
    public function hasEnded(?CarbonInterface $at = null): bool
    {
        $now = CarbonImmutable::instance($at ? $at->toDateTime() : now())->setTimezone(self::TIMEZONE);

        return $now->greaterThanOrEqualTo($this->end);
    }

    /**
     * The bounds as UTC instants for querying timestamp columns — which this
     * app stores in UTC. HALF-OPEN: use `>= $from AND < $to`. Never `<=` — see
     * the class docblock.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function utcBounds(): array
    {
        return [$this->start->utc(), $this->end->utc()];
    }

    /** The last real instant in this period ("Mon 23:59:59"), for DISPLAY only — never for querying. */
    public function displayEnd(): CarbonImmutable
    {
        return $this->end->subSecond();
    }

    /** "Aug 04 – Aug 10, 2026" */
    public function label(): string
    {
        return $this->start->format('M d').' – '.$this->displayEnd()->format('M d, Y');
    }

    /** Guard against a mis-set weekday leaving the period undefined. */
    private static function normaliseDay(int $startDayIso): int
    {
        return $startDayIso >= 1 && $startDayIso <= 7 ? $startDayIso : 2; // default Tuesday
    }
}
