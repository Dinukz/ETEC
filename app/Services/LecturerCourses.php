<?php

namespace App\Services;

use App\Models\Course;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ⚠️ THE COURSES A LECTURER TEACHES (2026-10-25).
 *
 * Owner: "Lecturer exam penwan na ... okkom exam tika penwnn ba ne lecturer add
 * krnakot saha eka user management eken edit kranakot eyta course type eka dann
 * e course type ekata adala exam and assignement admin ho wena lecturer kenk
 * dammath penwann one course type eke thiyen ayata."
 *
 * A course names ONE lecturer (courses.lecturer_id), and the exam and
 * assignment screens showed a lecturer only those courses — so a lecturer
 * teaching a course an admin had created saw "No exams yet". User Management
 * now gives a lecturer any number of courses, and on those courses they see
 * and run every exam and assignment, whoever wrote it (an admin or another
 * lecturer). Course details, lessons, videos and Zoom stay with the course's
 * own lecturer, unchanged.
 *
 * The table is added by the first screen that needs it, like the other
 * *SchemaRepair classes (no CLI on live, no migration file).
 * ⚠️ NEVER CALL heal()/ensure() INSIDE AN OPEN TRANSACTION — ALTER/CREATE
 * auto-commits under MySQL.
 */
class LecturerCourses
{
    public const TABLE = 'course_lecturers';

    private static ?bool $memo = null;

    public static function forgetMemo(): void
    {
        self::$memo = null;
        Cache::forget('course_lecturers.ready');
    }

    public static function ready(): bool
    {
        if (self::$memo !== null) {
            return self::$memo;
        }

        try {
            return self::$memo = (bool) Cache::remember(
                'course_lecturers.ready',
                now()->addMinutes(10),
                fn () => Schema::hasTable(self::TABLE),
            );
        } catch (\Throwable) {
            return self::$memo = false;
        }
    }

    public static function heal(): void
    {
        if (! self::ready()) {
            self::ensure();
        }
    }

    /** @return array<int, string> one line per change */
    public static function ensure(): array
    {
        $log = [];

        try {
            if (! Schema::hasTable(self::TABLE)) {
                Schema::create(self::TABLE, function (Blueprint $table) {
                    $table->id();
                    $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
                    $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                    $table->unsignedBigInteger('assigned_by')->nullable();
                    $table->timestamps();
                    $table->unique(['course_id', 'user_id']);
                });

                $log[] = self::TABLE.': created.';
            }
        } catch (\Throwable $e) {
            report($e);
            $log[] = self::TABLE.': could not be created — '.$e->getMessage();
        }

        self::forgetMemo();

        return $log;
    }

    /** @return array<int, int> the courses assigned to this lecturer in User Management */
    public static function assignedIds(int $userId): array
    {
        self::heal();

        if (! self::ready()) {
            return [];
        }

        return DB::table(self::TABLE)->where('user_id', $userId)->pluck('course_id')
            ->map(fn ($id) => (int) $id)->all();
    }

    /** @return array<int, int> courses whose exams and assignments this lecturer runs: their own plus the assigned */
    public static function teachableIds(int $userId): array
    {
        $assigned = self::assignedIds($userId);

        return Course::query()
            ->where(fn ($q) => $q->where('lecturer_id', $userId)->orWhereIn('id', $assigned ?: [0]))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Replace this lecturer's assigned courses with exactly $courseIds.
     *
     * @param  array<int, int|string>  $courseIds
     * @return array<int, int> what is now assigned
     */
    public static function sync(int $userId, array $courseIds, ?int $actorId): array
    {
        self::heal();

        if (! self::ready()) {
            throw new \RuntimeException('The lecturer\'s courses could not be saved: the database could not be updated.');
        }

        $ids = array_values(array_unique(array_map('intval', $courseIds)));

        DB::transaction(function () use ($userId, $ids, $actorId) {
            DB::table(self::TABLE)->where('user_id', $userId)->whereNotIn('course_id', $ids ?: [0])->delete();

            foreach ($ids as $id) {
                // insertOrIgnore keeps an existing row (and when it was first given) as it is.
                DB::table(self::TABLE)->insertOrIgnore([
                    'course_id' => $id, 'user_id' => $userId, 'assigned_by' => $actorId,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });

        return $ids;
    }
}
