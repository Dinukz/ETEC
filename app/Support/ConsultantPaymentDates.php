<?php

namespace App\Support;

use App\Models\SystemRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * The dates a consultant may put on a payment slip (2026-09-22).
 *
 * Owner: "system run wela thiyen daws saha future date walin date dekak withrk
 * pennala anith date block krala denn", then "Add Tomorrow". So yesterday, today
 * and tomorrow — Sri Lanka dates — minus any day a System Run has already
 * settled. A slip dated into a settled day would sit on a leg that run can
 * never have seen; this stops it being entered at all.
 *
 * Only the consultant's own forms use this. Admin screens are unchanged.
 */
class ConsultantPaymentDates
{
    /** The latest day any System Run has settled, as Y-m-d, or null. */
    public static function lastSettledDate(): ?string
    {
        return once(function () {
            if (! Schema::hasTable('system_runs')) {
                return null;
            }

            // MAX of the DATE, not the latest run: an earlier-dated run can be
            // taken after a later one. cutoff_at is stored as Sri Lanka
            // wall-clock (see SystemRun::cutoffDate()), so its date part is
            // already the Sri Lanka day.
            $latest = SystemRun::query()->max('cutoff_at');

            return $latest ? substr((string) $latest, 0, 10) : null;
        });
    }

    /**
     * Allowed dates, newest first (tomorrow, today, yesterday), minus any day a
     * System Run already settled. Can be empty when a run has covered tomorrow.
     *
     * @return list<string>
     */
    public static function allowed(): array
    {
        $today = Carbon::now('Asia/Colombo')->startOfDay();
        $settled = self::lastSettledDate();

        return array_values(array_filter(
            [$today->copy()->addDay()->toDateString(), $today->toDateString(), $today->copy()->subDay()->toDateString()],
            fn (string $date) => $settled === null || $date > $settled,
        ));
    }

    /** The validation rules for a consultant's payment_date field. */
    public static function rules(): array
    {
        return ['required', 'date_format:Y-m-d', \Illuminate\Validation\Rule::in(self::allowed())];
    }

    public static function message(): string
    {
        return self::allowed() === []
            ? 'No payment date is open right now — a System Run has already settled the days available. Ask an administrator.'
            : 'Choose yesterday, today or tomorrow — earlier days were already settled by a System Run.';
    }
}
