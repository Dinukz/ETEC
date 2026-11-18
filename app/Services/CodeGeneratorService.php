<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Generates predictable sequential codes (e.g. "SRN-00001", "SCN-00100") for a
 * given table/column, picking up from the highest existing numeric suffix.
 * Callers are expected to run this inside their own DB::transaction() so the
 * lockForUpdate() below actually holds.
 */
class CodeGeneratorService
{
    public static function next(string $table, string $column, string $prefix, int $padLength, int $start): string
    {
        $offset = strlen($prefix) + 2; // skip "{PREFIX}-"

        $last = DB::table($table)
            ->where($column, 'like', $prefix.'-%')
            ->lockForUpdate()
            ->orderByRaw("CAST(SUBSTRING(`{$column}`, {$offset}) AS UNSIGNED) DESC")
            ->value($column);

        $next = $last ? ((int) substr($last, $offset - 1)) + 1 : $start;

        return $prefix.'-'.str_pad((string) $next, $padLength, '0', STR_PAD_LEFT);
    }
}
