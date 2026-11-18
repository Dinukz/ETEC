<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One frozen row per consultant per CLOSED finance week — see the migration
 * docblock. Never written for the current open period.
 */
class ConsultantFinancePeriod extends Model
{
    protected $fillable = [
        'consultant_id', 'period_start', 'period_end',
        'students_required', 'students_completed', 'result', 'outcome', 'evaluated_at',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'evaluated_at' => 'datetime',
        ];
    }

    public function consultant(): BelongsTo
    {
        return $this->belongsTo(Consultant::class);
    }
}
