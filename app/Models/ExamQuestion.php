<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExamQuestion extends Model
{
    protected $fillable = [
        'exam_id', 'question', 'type', 'options', 'correct_answer', 'marks', 'sort_order',
        // Added by ExamSchemaRepair (2026-10-18) — an explanation per option, one
        // for the question, and a hint.
        'explanation', 'option_explanations', 'hint',
    ];

    protected function casts(): array
    {
        return ['options' => 'array', 'option_explanations' => 'array'];
    }

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    /** Which option (0-based) an answer is — answers are stored as the option's text. */
    public function optionIndex(?string $answer): ?int
    {
        if ($answer === null) {
            return null;
        }

        foreach ((array) $this->options as $i => $option) {
            if (strcasecmp((string) $option, $answer) === 0) {
                return $i;
            }
        }

        return null;
    }

    public function correctIndex(): ?int
    {
        return $this->optionIndex($this->correct_answer);
    }

    /** "A", "B", … for the option at $index. */
    public static function letter(int $index): string
    {
        return chr(ord('A') + $index);
    }
}
