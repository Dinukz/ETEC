<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class CommissionSetting extends Model
{
    protected $fillable = [
        'course_price', 'commission_per_student', 'direct_commission', 'max_commission', 'group_size',
        'first_tier_students', 'first_tier_amount', 'second_tier_students',
        'second_tier_amount', 'is_active', 'effective_from',
    ];

    protected function casts(): array
    {
        return [
            'course_price' => 'decimal:2',
            'commission_per_student' => 'decimal:2',
            'direct_commission' => 'decimal:2',
            'max_commission' => 'decimal:2',
            'first_tier_amount' => 'decimal:2',
            'second_tier_amount' => 'decimal:2',
            'is_active' => 'boolean',
            'effective_from' => 'datetime',
        ];
    }

    /** Columns the engine reads whose absence would silently read back as 0. */
    private const REQUIRED_RATES = ['commission_per_student', 'direct_commission'];

    /**
     * The active commission configuration (cached). Falls back to a fresh
     * default instance so callers never receive null.
     */
    public static function current(): self
    {
        $settings = Cache::remember('commission_settings.active', 3600, fn () => static::resolveActive());

        // A cached instance can predate a column being ADDED — this database
        // genuinely had `direct_commission` missing for a while — and a model
        // serialised back then carries no such attribute at all, so it reads
        // as null for the full hour of the cache's life. The engine treats
        // that null as "the rate is 0" and silently skips the direct-add
        // bonus, while `commission_per_student` on the very same stale object
        // keeps working. That is exactly the "2,500 arrived but 4,000 did not"
        // symptom. Refresh once rather than serve a value known to be missing.
        foreach (self::REQUIRED_RATES as $column) {
            if (! array_key_exists($column, $settings->getAttributes())) {
                Cache::forget('commission_settings.active');

                return Cache::remember('commission_settings.active', 3600, fn () => static::resolveActive());
            }
        }

        return $settings;
    }

    /** The live active row, or a default instance when none is configured yet. */
    protected static function resolveActive(): self
    {
        return static::query()->where('is_active', true)->latest('effective_from')->first()
            ?? new static([
                'course_price' => 28000,
                'commission_per_student' => 2500,
                'direct_commission' => 3000,
                'max_commission' => 15000,
                'group_size' => 6,
                'first_tier_students' => 3,
                'first_tier_amount' => 7500,
                'second_tier_students' => 3,
                'second_tier_amount' => 7500,
                'is_active' => true,
            ]);
    }

    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget('commission_settings.active'));
        static::deleted(fn () => Cache::forget('commission_settings.active'));
    }
}
