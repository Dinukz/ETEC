<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Shared "from / to" date filtering for the listing screens.
 *
 * Deliberately forgiving rather than validating: these are GET filter inputs,
 * so a bad value must quietly fall back to "no filter" instead of bouncing the
 * user back with a validation error on a page they were only browsing.
 */
trait FiltersByDateRange
{
    /**
     * The requested range as [from, to], either side possibly null.
     *
     * A backwards range (from later than to) is swapped rather than returning
     * nothing, since that is always a typo and an empty table looks like a bug.
     *
     * @return array{0: ?Carbon, 1: ?Carbon}
     */
    protected function dateRange(Request $request, string $fromKey = 'from', string $toKey = 'to'): array
    {
        $from = $this->parseFilterDate($request->input($fromKey));
        $to = $this->parseFilterDate($request->input($toKey));

        if ($from && $to && $from->gt($to)) {
            [$from, $to] = [$to, $from];
        }

        return [$from, $to];
    }

    /**
     * Apply the range to a query on the given column.
     *
     * Uses whereDate so both ends are INCLUSIVE — comparing a date against a
     * timestamp column with a plain `<=` silently drops everything recorded
     * after midnight on the final day.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     */
    protected function applyDateRange($query, Request $request, string $column, string $fromKey = 'from', string $toKey = 'to')
    {
        [$from, $to] = $this->dateRange($request, $fromKey, $toKey);

        return $query
            ->when($from, fn ($q) => $q->whereDate($column, '>=', $from))
            ->when($to, fn ($q) => $q->whereDate($column, '<=', $to));
    }

    /** A filter date, or null when absent or unparseable. */
    protected function parseFilterDate(mixed $value): ?Carbon
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }
}
