<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExamResult extends Model
{
    protected $fillable = [
        'exam_id', 'user_id', 'marks_obtained', 'total_marks', 'percentage', 'grade',
        'passed', 'answers', 'started_at', 'submitted_at', 'is_published', 'evaluated_by',
    ];

    protected function casts(): array
    {
        return [
            'percentage' => 'decimal:2',
            'passed' => 'boolean',
            'answers' => 'array',
            'started_at' => 'datetime',
            'submitted_at' => 'datetime',
            'is_published' => 'boolean',
        ];
    }

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function evaluator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'evaluated_by');
    }

    /** Letter grade for a percentage score. */
    public static function gradeFor(float $percentage): string
    {
        return match (true) {
            $percentage >= 90 => 'A+',
            $percentage >= 75 => 'A',
            $percentage >= 65 => 'B',
            $percentage >= 55 => 'C',
            $percentage >= 40 => 'S',
            default => 'F',
        };
    }
}
