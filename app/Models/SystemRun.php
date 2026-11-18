<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One execution of the daily System Run (ServiceGroupFlush::runAll()).
 *
 * A run permanently forfeits unmatched service-group income across every
 * account in the network, so it is recorded rather than left implicit: who ran
 * it, the cutoff it settled up to, and the two totals it moved. Nothing else
 * in this codebase can reconstruct that after the fact — the cancelled
 * commissions carry no run identifier of their own.
 */
class SystemRun extends Model
{
    protected $fillable = [
        'run_by', 'cutoff_at', 'accounts_flushed', 'total_released', 'total_forfeited', 'accounts', 'failures',
    ];

    protected function casts(): array
    {
        return [
            'cutoff_at' => 'datetime',
            'total_released' => 'decimal:2',
            'total_forfeited' => 'decimal:2',
        ];
    }

    /**
     * The accounts this run actually settled.
     *
     * Stored as JSON rather than a relation because it is a SNAPSHOT: the SCN
     * code and the amounts must keep reading the way they did on the day, even
     * if the account is later renumbered or closed. Returns an empty list on a
     * run recorded before this column existed.
     *
     * @return array<int, array<string, mixed>>
     */
    public function settledAccounts(): array
    {
        if (! $this->accounts) {
            return [];
        }

        $decoded = json_decode($this->accounts, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * The moment this run settled up to, in UTC — ready to compare with
     * payments.created_at.
     *
     * ⚠️ cutoff_at IS STORED AS SRI LANKA WALL-CLOCK TIME, NOT UTC.
     * SystemRunController::run() saves a Colombo Carbon, and Eloquent writes a
     * Carbon with format() alone, so the column holds "2026-09-09 23:59:59"
     * beside a UTC created_at. The `datetime` cast then reads that string back
     * AS UTC, which is 5h30m late. Anything comparing a cutoff with a stored
     * timestamp must come through here, or a payment entered at 01:39 on 10 Sep
     * in Sri Lanka (20:09 UTC on the 9th) is wrongly treated as inside a
     * 9 Sep run — the PYM-01275 case from 2026-09-13.
     */
    public function cutoffUtc(): Carbon
    {
        return Carbon::parse((string) $this->getRawOriginal('cutoff_at'), 'Asia/Colombo')->utc();
    }

    /** The settled-up-to date as people read it, e.g. "Wed 9 Sep". */
    public function cutoffLabel(): string
    {
        return Carbon::parse((string) $this->getRawOriginal('cutoff_at'), 'Asia/Colombo')->format('D j M');
    }

    /**
     * The settled-up-to date as a plain Sri Lanka date string, e.g. "2026-09-09".
     *
     * cutoff_at is stored as Sri Lanka wall-clock time (see cutoffUtc()), so its
     * raw date part IS the Colombo date the run settled up to — the same shape
     * a payment's DATE(paid_at) is compared against in
     * ServiceGroupFlush::legScopeQuery().
     */
    public function cutoffDate(): string
    {
        return substr((string) $this->getRawOriginal('cutoff_at'), 0, 10);
    }

    public function runBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'run_by');
    }
}
