<?php

namespace App\Support;

use App\Models\Setting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Settings-aware entry point for finance-period calculations — turns the
 * admin-configured week-start-day / quota / grace-weeks into FinancePeriod
 * instances.
 *
 * Deliberately a THIN wrapper: all the boundary arithmetic lives in
 * FinancePeriod, which knows nothing about Settings and is unit-testable with
 * no database. This class exists only to read config once and hand it in.
 */
final class FinanceWeek
{
    public const CACHE_KEY = 'settings.finance.grouped';

    public const DEFAULT_START_DAY = 2;   // ISO weekday: Tuesday
    public const DEFAULT_QUOTA = 2;
    public const DEFAULT_GRACE_WEEKS = 5;

    /** Settings key for the on/off switch — see quotaEnabled(). */
    public const ENABLED_KEY = 'finance_quota_enabled';

    /** Per-request memo for quotaEnabled(); cleared by forget(). */
    private static ?bool $quotaEnabled = null;

    /**
     * One grouped cache entry, one query on a cache miss — mirrors
     * AppServiceProvider::applyMailSettingsFromDatabase(). Reading this on
     * every consultant request must never cost three separate Setting::get()
     * round trips.
     *
     * @return array{start_day: int, quota: int, grace_weeks: int}
     */
    public static function config(): array
    {
        $raw = Cache::rememberForever(
            self::CACHE_KEY,
            fn () => Setting::where('group', 'finance')->pluck('value', 'key'),
        );

        // Clamped at the read site: a 0/missing/out-of-range value must never
        // silently disable the quota or instantly suspend everyone.
        $startDay = (int) ($raw['finance_week_start_day'] ?? self::DEFAULT_START_DAY);
        $quota = (int) ($raw['finance_week_quota'] ?? self::DEFAULT_QUOTA);
        $grace = (int) ($raw['finance_grace_weeks'] ?? self::DEFAULT_GRACE_WEEKS);

        // ⚠️ THE WHOLE WEEKLY REQUIREMENT CAN BE SWITCHED OFF (2026-09-15).
        // Anything other than an explicit 0 means ON, so a missing key keeps
        // the existing behaviour. See quotaEnabled().
        $enabled = (int) ($raw[self::ENABLED_KEY] ?? 1) !== 0;

        return [
            'start_day' => $startDay >= 1 && $startDay <= 7 ? $startDay : self::DEFAULT_START_DAY,
            'quota' => max(0, min(100, $quota)),
            'grace_weeks' => max(0, min(52, $grace)),
            'enabled' => $enabled,
        ];
    }

    public static function forget(): void
    {
        self::$quotaEnabled = null;
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Is the weekly student requirement switched on at all?
     *
     * ⚠️ THE TEMPORARY OFF SWITCH (2026-09-15). The owner asked to be able to
     * park the whole restrict/suspend system while the user base is still
     * growing — "system stable wenakn ... meka disable krla denn, awshya
     * welwata meka enable krnn puluwn wenn one" — and turn it back on later.
     *
     * OFF means nobody is restricted or suspended FOR MONEY OR ACCESS: no
     * evaluation runs, no status is written, and Consultant::isFinanceActive()
     * reports true even for someone already stamped 'restricted'. The stored
     * statuses are deliberately left alone, so switching it back on resumes
     * exactly where it left off rather than forgiving everyone permanently.
     *
     * Memoised per request: isFinanceActive() is called once per commission row
     * in places like the accountant's payout queue, and that must not turn into
     * a cache round trip each time.
     *
     * ⚠️ FAILS ENABLED. A missing settings table or a cache error must never
     * silently switch the money gates off.
     */
    public static function quotaEnabled(): bool
    {
        if (self::$quotaEnabled !== null) {
            return self::$quotaEnabled;
        }

        try {
            return self::$quotaEnabled = (bool) self::config()['enabled'];
        } catch (\Throwable $e) {
            return self::$quotaEnabled = true;
        }
    }

    public static function containing(?CarbonImmutable $at = null): FinancePeriod
    {
        return FinancePeriod::containing($at ?? CarbonImmutable::now(), self::config()['start_day']);
    }

    public static function current(): FinancePeriod
    {
        return self::containing();
    }

    /** The most recently CLOSED period — the one restriction/suspension decisions are made on. */
    public static function lastClosed(): FinancePeriod
    {
        return self::current()->minus(1);
    }

    public static function fromKey(string $key): FinancePeriod
    {
        return self::containing(CarbonImmutable::parse($key, FinancePeriod::TIMEZONE)->startOfDay()->addHours(12));
    }

    /** The most recent $n periods, newest first, INCLUDING the current open one. */
    public static function lastN(int $n): array
    {
        $out = [];
        $p = self::current();

        for ($i = 0; $i < max(1, $n); $i++) {
            $out[] = $p;
            $p = $p->minus(1);
        }

        return $out;
    }
}
