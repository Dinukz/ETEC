<?php

namespace App\Services;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * Self-healing schema for exam papers with explanations and hints
 * (2026-10-18).
 *
 * The owner's papers ("Introduction to AI & Prompting — Lesson 02", "Digital
 * Marketing Fundamentals — Lesson 01") come with an explanation for every
 * option, a hint for every question, and an answer sheet. The exam tables had
 * no place for any of that.
 *
 * Same pattern and reason as LmsSchemaRepair: production has no CLI, so the
 * columns are added by the first request that needs them, guarded by
 * hasColumn, and the answer is memoised.
 *
 * ⚠️ NEVER CALL ensure() INSIDE AN OPEN TRANSACTION — ALTER auto-commits under
 * MySQL.
 */
class ExamSchemaRepair
{
    private static ?bool $memo = null;

    public static function forgetMemo(): void
    {
        self::$memo = null;
        Cache::forget('exams.schema.ready');
    }

    public static function ready(): bool
    {
        if (self::$memo !== null) {
            return self::$memo;
        }

        try {
            return self::$memo = (bool) Cache::remember(
                'exams.schema.ready',
                now()->addMinutes(10),
                fn () => Schema::hasColumn('exam_questions', 'hint')
                    && Schema::hasColumn('exams', 'show_answers'),
            );
        } catch (\Throwable) {
            return self::$memo = false;
        }
    }

    /** The cheap call for every page: repair only when not known to be ready. */
    public static function heal(): void
    {
        if (! self::ready()) {
            self::ensure();
        }
    }

    /**
     * Always looks at the real columns (never trusts the memo), then resets it.
     *
     * @return array<int, string> one line per change
     */
    public static function ensure(): array
    {
        $log = [];

        try {
            if (Schema::hasTable('exam_questions') && ! Schema::hasColumn('exam_questions', 'hint')) {
                Schema::table('exam_questions', function (Blueprint $table) {
                    // Why the right answer is right — shown after submitting.
                    $table->text('explanation')->nullable();
                    // One note per option, in the same order as `options`.
                    $table->json('option_explanations')->nullable();
                    // A nudge the student may open while answering.
                    $table->text('hint')->nullable();
                });

                $log[] = 'exam_questions: added explanation, option_explanations and hint.';
            }

            if (Schema::hasTable('exams') && ! Schema::hasColumn('exams', 'show_answers')) {
                Schema::table('exams', function (Blueprint $table) {
                    // After submitting, may the student see the right answers
                    // and the explanations? On by default — the owner's papers
                    // are study papers with an answer sheet.
                    $table->boolean('show_answers')->default(true);
                });

                $log[] = 'exams: added show_answers.';
            }
        } catch (\Throwable $e) {
            report($e);
            $log[] = 'exam schema: could not be repaired — '.$e->getMessage();
        }

        self::forgetMemo();

        return $log;
    }
}
