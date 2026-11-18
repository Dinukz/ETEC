<?php

namespace App\Services;

use App\Models\Exam;
use App\Models\ExamResult;
use Illuminate\Support\Carbon;

/**
 * Marks an attempt, and decides when its time is up (2026-10-18).
 *
 * *"time ekak athule krana widiyt"* — a paper is sat within a time limit. The
 * countdown on the page is only a display: the deadline is enforced HERE, on
 * the server. Answers are saved as the student picks them (save()), so when
 * time runs out — the tab closed, the connection dropped, or a late submit —
 * the attempt is marked on what was saved before the deadline, never on
 * anything sent after it.
 *
 * One marking rule for every way an attempt can end: the Submit button, the
 * timer running out, or the student coming back after the deadline.
 */
class ExamGrader
{
    /** A submit this soon after the deadline still counts (slow phones, slow networks). */
    public const SUBMIT_GRACE_SECONDS = 120;

    /** Autosave is accepted this long after the deadline — the last click in flight. */
    public const SAVE_GRACE_SECONDS = 30;

    /** When this attempt's time runs out: its own duration, or the paper closing, whichever is first. */
    public static function deadline(Exam $exam, ExamResult $result): Carbon
    {
        $deadline = ($result->started_at ?? now())->copy()->addMinutes((int) $exam->duration_minutes);

        if ($exam->end_at && $exam->end_at->lessThan($deadline)) {
            $deadline = $exam->end_at->copy();
        }

        return $deadline;
    }

    public static function isOver(Exam $exam, ExamResult $result, int $graceSeconds = 0): bool
    {
        return now()->greaterThan(self::deadline($exam, $result)->addSeconds($graceSeconds));
    }

    /**
     * Only answers to this paper's own questions, as short strings.
     *
     * @param  array<mixed>  $answers
     * @return array<int, string>
     */
    public static function clean(Exam $exam, array $answers): array
    {
        $ids = $exam->questions()->pluck('id')->map(fn ($id) => (int) $id)->all();
        $clean = [];

        foreach ($answers as $questionId => $answer) {
            if (in_array((int) $questionId, $ids, true) && is_scalar($answer) && trim((string) $answer) !== '') {
                $clean[(int) $questionId] = mb_substr(trim((string) $answer), 0, 5000);
            }
        }

        return $clean;
    }

    /**
     * Mark and close the attempt.
     *
     * @param  array<int, string>  $answers  already passed through clean()
     */
    public static function finish(Exam $exam, ExamResult $result, array $answers): ExamResult
    {
        $marks = 0;
        $hasEssay = false;
        $saved = [];

        foreach ($exam->questions()->orderBy('sort_order')->get() as $question) {
            $answer = $answers[$question->id] ?? null;
            $saved[$question->id] = $answer;

            if ($question->type === 'essay') {
                $hasEssay = true;

                continue; // marked by the lecturer
            }

            if ($answer !== null && strcasecmp($answer, (string) $question->correct_answer) === 0) {
                $marks += (int) $question->marks;
            }
        }

        $total = max(1, (int) $exam->total_marks);
        $percentage = round(min(100, $marks / $total * 100), 2);

        $result->update([
            'marks_obtained' => $marks,
            'total_marks' => $exam->total_marks,
            'percentage' => $percentage,
            'grade' => ExamResult::gradeFor($percentage),
            'passed' => $marks >= $exam->pass_marks,
            'answers' => $saved,
            'submitted_at' => now(),
            // Marked at once unless a written answer needs the lecturer.
            'is_published' => ! $hasEssay,
        ]);

        return $result;
    }
}
