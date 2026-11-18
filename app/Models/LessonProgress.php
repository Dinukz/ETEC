<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LessonProgress extends Model
{
    protected $table = 'lesson_progress';

    protected $fillable = [
        'enrollment_id', 'lesson_id', 'is_completed', 'completed_at', 'watch_seconds',
        // The viewing window — see App\Services\LessonViewWindow. Added by
        // LmsSchemaRepair, so absent on a host that has not healed yet.
        'view_count', 'window_expires_at', 'extra_views_granted', 'last_viewed_at',
    ];

    protected function casts(): array
    {
        return [
            'is_completed' => 'boolean',
            'completed_at' => 'datetime',
            'window_expires_at' => 'datetime',
            'last_viewed_at' => 'datetime',
            'view_count' => 'integer',
            'extra_views_granted' => 'integer',
        ];
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }
}
