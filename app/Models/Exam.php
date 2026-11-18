<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Exam extends Model
{
    protected $fillable = [
        'course_id', 'lecturer_id', 'title', 'description', 'type', 'total_marks',
        'pass_marks', 'duration_minutes', 'start_at', 'end_at', 'is_published',
        // After submitting, show the right answers and explanations (ExamSchemaRepair, 2026-10-18).
        'show_answers',
    ];

    protected function casts(): array
    {
        return [
            // Sri Lanka time, as typed — see SriLankaWallClock (2026-10-18).
            'start_at' => \App\Casts\SriLankaWallClock::class,
            'end_at' => \App\Casts\SriLankaWallClock::class,
            'is_published' => 'boolean',
            'show_answers' => 'boolean',
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function lecturer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lecturer_id');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(ExamQuestion::class);
    }

    public function results(): HasMany
    {
        return $this->hasMany(ExamResult::class);
    }
}
