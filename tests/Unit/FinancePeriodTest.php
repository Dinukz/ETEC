<?php

namespace Tests\Unit;

use App\Support\FinancePeriod;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * The finance week a consultant's student quota is measured against.
 *
 * Confirmed with the owner: the week runs Tuesday 00:00:00 to Monday
 * 23:59:59, Sri Lankan local time. Internally represented as the half-open
 * interval [start, end) — see the class docblock for why.
 */
class FinancePeriodTest extends TestCase
{
    private const TUESDAY = 2;

    private function colombo(string $when): CarbonImmutable
    {
        return CarbonImmutable::parse($when, FinancePeriod::TIMEZONE);
    }

    public function test_a_week_runs_tuesday_midnight_to_monday_midnight_half_open(): void
    {
        $period = FinancePeriod::containing($this->colombo('2026-08-07 15:00:00'), self::TUESDAY);

        $this->assertSame('2026-08-04 00:00:00', $period->start->toDateTimeString());
        $this->assertSame('2026-08-11 00:00:00', $period->end->toDateTimeString(), 'end is EXCLUSIVE — the instant the next period begins.');
        $this->assertSame('2026-08-10 23:59:59', $period->displayEnd()->toDateTimeString(), 'the human-readable inclusive end, for display only.');
        $this->assertSame('Tue', $period->start->format('D'));

        // Exactly seven days — no gap or overlap between consecutive weeks.
        $this->assertSame(604800, $period->end->timestamp - $period->start->timestamp);
    }

    public function test_both_boundary_instants_land_in_the_expected_week(): void
    {
        $first = $this->colombo('2026-08-04 00:00:00');   // the very first second
        $last = $this->colombo('2026-08-10 23:59:59');    // the very last second
        $next = $this->colombo('2026-08-11 00:00:00');    // the exclusive boundary — belongs to the NEXT period

        $this->assertSame('2026-08-04', FinancePeriod::containing($first, self::TUESDAY)->key());
        $this->assertSame('2026-08-04', FinancePeriod::containing($last, self::TUESDAY)->key());
        $this->assertSame('2026-08-11', FinancePeriod::containing($next, self::TUESDAY)->key(), 'A new week must start exactly at Tuesday 00:00.');

        $period = FinancePeriod::containing($first, self::TUESDAY);

        $this->assertTrue($period->contains($last));
        $this->assertFalse($period->contains($next), 'The exclusive boundary belongs to the NEXT period, not this one.');
    }

    /**
     * The bug this class exists to prevent. The app stores timestamps in UTC
     * but the business is Sri Lankan (UTC+5:30). A week boundary computed in
     * UTC would cut over 5.5 hours early in local time, so students
     * registered in the small hours of the start day would be counted
     * against the wrong week and their consultant restricted unfairly.
     */
    public function test_a_registration_just_after_local_midnight_belongs_to_the_new_week_not_the_old_one(): void
    {
        // 00:15 Colombo on the start day is still 18:45 UTC on the PREVIOUS
        // calendar day — a naive UTC week boundary would misfile this.
        $justAfterMidnight = $this->colombo('2026-08-04 00:15:00');

        $this->assertSame('2026-08-03 18:45:00', $justAfterMidnight->utc()->toDateTimeString());
        $this->assertSame('2026-08-04', FinancePeriod::containing($justAfterMidnight, self::TUESDAY)->key());

        // And the previous evening, still Monday local, must NOT be pulled
        // forward into the new week either.
        $mondayEvening = $this->colombo('2026-08-03 20:00:00');
        $this->assertSame('2026-07-28', FinancePeriod::containing($mondayEvening, self::TUESDAY)->key());
    }

    public function test_utc_bounds_are_half_open_and_offset_for_querying_timestamp_columns(): void
    {
        [$from, $to] = FinancePeriod::containing($this->colombo('2026-08-07 12:00:00'), self::TUESDAY)->utcBounds();

        // Tuesday 00:00 Colombo == the previous day 18:30 UTC.
        $this->assertSame('2026-08-03 18:30:00', $from->toDateTimeString());
        $this->assertSame('2026-08-10 18:30:00', $to->toDateTimeString(), 'exclusive — a >= / < query, never <=.');
    }

    public function test_the_start_day_is_configurable(): void
    {
        $at = $this->colombo('2026-08-07 12:00:00'); // a Friday

        $monday = FinancePeriod::containing($at, 1);
        $this->assertSame('Mon', $monday->start->format('D'));
        $this->assertSame('2026-08-03', $monday->key());

        $sunday = FinancePeriod::containing($at, 7);
        $this->assertSame('Sun', $sunday->start->format('D'));
        $this->assertSame('2026-08-02', $sunday->key());
    }

    public function test_an_out_of_range_start_day_falls_back_to_tuesday_rather_than_breaking(): void
    {
        $at = $this->colombo('2026-08-07 12:00:00');

        foreach ([0, 8, -3] as $nonsense) {
            $this->assertSame('Tue', FinancePeriod::containing($at, $nonsense)->start->format('D'));
        }
    }

    public function test_walking_back_gives_consecutive_earlier_weeks(): void
    {
        $period = FinancePeriod::containing($this->colombo('2026-08-07 12:00:00'), self::TUESDAY);

        $this->assertSame('2026-07-28', $period->minus(1)->key());
        $this->assertSame('2026-06-30', $period->minus(5)->key(), 'The five-week grace window reaches back to 2026-06-30.');
        $this->assertSame($period->key(), $period->minus(0)->key());

        // Each earlier week's end must equal exactly the timestamp this week starts at.
        $previous = $period->minus(1);
        $this->assertSame($period->start->timestamp, $previous->end->timestamp);
        $this->assertSame($period->key(), $previous->next()->key());
    }

    public function test_a_period_knows_when_it_has_closed(): void
    {
        $period = FinancePeriod::containing($this->colombo('2026-08-07 12:00:00'), self::TUESDAY);

        $this->assertFalse($period->hasEnded($this->colombo('2026-08-10 23:59:59')));
        $this->assertTrue($period->hasEnded($this->colombo('2026-08-11 00:00:00')));
    }
}
