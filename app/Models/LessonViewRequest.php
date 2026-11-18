<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A student asking to watch a lesson again after their viewing window closed.
 *
 * See LessonViewWindow for what a "viewing" means and why it is a window rather
 * than a single play.
 */
class LessonViewRequest extends Model
{
    protected $fillable = [
        'user_id', 'lesson_id', 'status', 'reason', 'decided_by', 'decided_at',
    ];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * The Course Videos requests search (2026-11-04) — "Course video walat ena
     * request search krnn option ekak". The student's name, email, phone, SRN
     * (or SCN once promoted), the lesson's title or the course's.
     *
     * ⚠️ Wrapped in ONE where() group, so no orWhereHas() escapes the pending()
     * filter beside it and brings decided requests back. Laravel also groups a
     * scope's OR on its own; this keeps the rule visible. Same shape as
     * Payment::scopeSearch().
     */
    public function scopeSearch($query, string $term)
    {
        $term = trim($term);

        if ($term === '') {
            return $query;
        }

        $like = "%{$term}%";

        return $query->where(fn ($q) => $q
            ->whereHas('user', fn ($u) => $u
                ->where('name', 'like', $like)
                ->orWhere('email', 'like', $like)
                ->orWhere('phone', 'like', $like)
                ->orWhereHas('student', fn ($s) => $s->where('student_code', 'like', $like))
                ->orWhereHas('consultant', fn ($c) => $c->where('scn_code', 'like', $like)))
            ->orWhereHas('lesson', fn ($l) => $l
                ->where('title', 'like', $like)
                ->orWhereHas('course', fn ($c) => $c->where('title', 'like', $like))));
    }

    /**
     * Requests ASKED from one day to another, both ends inclusive (2026-11-04,
     * "date wise filter krla student search krnn"). $from / $to are Y-m-d, either
     * may be null.
     *
     * ⚠️ SRI LANKA DAYS. created_at is stored in UTC (the app timezone), so each
     * day is a Colombo midnight turned into UTC — a request made at 1:30 AM
     * belongs to that Sri Lanka day, not to the UTC day before it.
     */
    public function scopeAskedBetween($query, ?string $from, ?string $to)
    {
        $tz = 'Asia/Colombo';

        return $query
            ->when($from, fn ($q) => $q->where('created_at', '>=', CarbonImmutable::parse($from, $tz)->startOfDay()->utc()))
            ->when($to, fn ($q) => $q->where('created_at', '<', CarbonImmutable::parse($to, $tz)->startOfDay()->addDay()->utc()));
    }
}
