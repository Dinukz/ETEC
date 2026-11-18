<?php

namespace App\Services;

use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LessonProgress;
use Illuminate\Support\Facades\DB;

/**
 * "Watch it twice; to watch it again you have to ask."
 *
 * ⚠️ STRICTLY TWO VIEWINGS, COUNTED PER PRESS (2026-11-11). The owner:
 * "dn mt eka one nathiw two time balann hadala denn meka stickly two time balann
 * ... 3 park itath wada play krla balann puluwn una refresh krath puluwn My
 * courses page eken ain wela awath onatharm park balann puluwn" — make it strictly
 * two; after that the student asks, as before.
 *
 * Until now ONE viewing was a 48-hour WINDOW (asked for 2026-09-10 as "eka parai"):
 * the first play opened it and inside it the lesson replayed freely — refresh,
 * leave and come back, as often as liked. That is exactly what the owner saw.
 *
 * Now every press of "Learn with video" is one viewing, whether it follows a
 * refresh, a new visit or the end of the video. Inside ONE viewing pause and
 * seek still work, and a dropped connection still carries on (the player's
 * resume asks for a fresh link for the viewing already open, and never counts
 * — LessonVideoController::ticket()). A viewing's link lives WINDOW_HOURS.
 *
 * FREE_VIEWS each; every approved request adds one (extra_views_granted).
 */
class LessonViewWindow
{
    /** Viewings every learner gets before they must ask. */
    public const FREE_VIEWS = 2;

    /** How long one viewing's link stays usable once it is opened (pause, seek, resume). */
    public const WINDOW_HOURS = 3;

    /**
     * The progress row for this person on this lesson, created if absent.
     *
     * Keyed on enrollment_id + lesson_id, which the table already enforces as
     * unique — so this is one row per learner per lesson, never per play.
     */
    public static function progressFor(Enrollment $enrollment, Lesson $lesson): LessonProgress
    {
        return LessonProgress::firstOrCreate(
            ['enrollment_id' => $enrollment->id, 'lesson_id' => $lesson->id],
        );
    }

    /** Is a viewing open right now (its link may stream and be resumed)? */
    public static function isOpen(LessonProgress $progress): bool
    {
        if (! LmsSchemaRepair::ready()) {
            // The columns do not exist yet, so no viewing has ever been counted.
            // Fail OPEN: a half-healed schema must not lock every student out
            // of every lesson.
            return true;
        }

        return $progress->window_expires_at !== null && $progress->window_expires_at->isFuture();
    }

    /** Viewings given in all: the free ones plus every approved request. */
    public static function allowed(?LessonProgress $progress): int
    {
        return self::FREE_VIEWS + (int) ($progress?->extra_views_granted ?? 0);
    }

    /** Viewings not used yet. A learner who has never pressed play has FREE_VIEWS. */
    public static function remaining(?LessonProgress $progress): int
    {
        if (! LmsSchemaRepair::ready()) {
            return self::FREE_VIEWS;
        }

        return max(0, self::allowed($progress) - (int) ($progress?->view_count ?? 0));
    }

    /**
     * Count one viewing and open its link — or refuse when none are left.
     * Called the moment the student presses play, never on page load (opening a
     * lesson by mistake must not spend one), and never for a resume.
     *
     * ⚠️ EVERY CALL COUNTS, even while a viewing is still open. That is the
     * point: a refresh or a second visit used to slip back into the open window
     * for free.
     *
     * @return bool whether playback may proceed
     */
    public static function open(LessonProgress $progress): bool
    {
        if (! LmsSchemaRepair::ready()) {
            return true;
        }

        // Locked so two tabs pressing together cannot both spend the last one.
        return (bool) DB::transaction(function () use ($progress) {
            $fresh = LessonProgress::whereKey($progress->id)->lockForUpdate()->first();

            if (! $fresh || self::remaining($fresh) <= 0) {
                return false;
            }

            $fresh->update([
                'view_count' => (int) $fresh->view_count + 1,
                'window_expires_at' => now()->addHours(self::WINDOW_HOURS),
                'last_viewed_at' => now(),
            ]);

            $progress->setRawAttributes($fresh->getAttributes(), true);

            return true;
        });
    }

    /** Grant one more viewing — what approving a request does. */
    public static function grantExtra(LessonProgress $progress): void
    {
        if (! LmsSchemaRepair::ready()) {
            return;
        }

        $progress->increment('extra_views_granted');
    }
}
