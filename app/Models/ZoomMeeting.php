<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ZoomMeeting extends Model
{
    protected $fillable = [
        'course_id', 'lesson_id', 'lecturer_id', 'title', 'agenda', 'meeting_id',
        'passcode', 'join_url', 'start_url', 'start_at', 'duration_minutes', 'status', 'recording_url',
    ];

    protected function casts(): array
    {
        return ['start_at' => 'datetime'];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function lecturer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lecturer_id');
    }
}
