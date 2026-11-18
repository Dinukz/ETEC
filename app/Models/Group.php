<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Group extends Model
{
    use HasFactory;

    protected $fillable = [
        'group_code', 'consultant_id', 'name', 'capacity', 'filled_slots', 'tier1_filled', 'tier2_filled',
        'status', 'total_commission_earned', 'opened_at', 'completed_at', 'closed_at',
        // How many matched pairs on this account have been RELEASED for
        // payout. See ServiceGroupStaging — recorded income is not the same
        // as payable income.
        'released_depth',
    ];

    protected function casts(): array
    {
        return [
            'released_depth' => 'integer',
            'total_commission_earned' => 'decimal:2',
            'opened_at' => 'datetime',
            'completed_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function consultant(): BelongsTo
    {
        return $this->belongsTo(Consultant::class);
    }

    public function members(): HasMany
    {
        return $this->hasMany(GroupMember::class);
    }

    public function students(): HasMany
    {
        return $this->hasMany(Student::class);
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(Commission::class);
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    public function getRemainingSlotsAttribute(): int
    {
        return max(0, (int) $this->capacity - (int) $this->filled_slots);
    }
}
