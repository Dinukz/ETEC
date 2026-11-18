<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A date-time column that holds SRI LANKA wall-clock time (2026-10-18).
 *
 * The app runs on UTC, but people type times in Sri Lanka time. An exam set to
 * open at "20:08" was saved as 20:08 UTC — 01:38 the next morning in Colombo —
 * so at 20:09 the student still read "Not open yet" (owner's report, the quiz
 * and the exam both). The same 5½ hours wrong at closing.
 *
 * So the column keeps exactly what was typed ("2026-10-18 20:08:00", Colombo),
 * and the model hands back a Carbon in Asia/Colombo: comparisons with now() are
 * between real instants (correct whatever the app's timezone), and ->format()
 * shows the time as typed. Times already saved were typed in Sri Lanka time
 * too, so they read correctly with no conversion of existing rows.
 */
class SriLankaWallClock implements CastsAttributes
{
    public const ZONE = 'Asia/Colombo';

    /**
     * Any moment shown as Sri Lanka time — for the ordinary UTC timestamps
     * (started, submitted) that sit beside these on the same pages. '' for none.
     */
    public static function show(?\DateTimeInterface $moment, string $format): string
    {
        return $moment === null ? '' : Carbon::instance($moment)->setTimezone(self::ZONE)->format($format);
    }

    public function get(Model $model, string $key, mixed $value, array $attributes): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse((string) $value, self::ZONE)->setTimezone(self::ZONE);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        // A moment in time (now()->addHour(), another model's date): convert.
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->setTimezone(self::ZONE)->format('Y-m-d H:i:s');
        }

        // A form value ("2026-10-18T20:08") is Sri Lanka time, as typed. A string
        // that names its own offset is converted from that offset.
        return Carbon::parse((string) $value, self::ZONE)->setTimezone(self::ZONE)->format('Y-m-d H:i:s');
    }
}
