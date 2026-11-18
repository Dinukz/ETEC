<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\Certificate;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LessonProgress;
use Illuminate\Support\Str;

class LearnController extends Controller
{
    /** Resolve the caller's active enrollment for the course, or 403. */
    protected function enrollment(Course $course): Enrollment
    {
        $enrollment = Enrollment::where('user_id', auth()->id())
            ->where('course_id', $course->id)
            ->whereIn('status', ['active', 'completed'])
            ->first();

        abort_unless($enrollment, 403, 'You are not enrolled in this course, or your payment has not been verified yet.');

        return $enrollment;
    }

    /** Entry point: jump to the first incomplete lesson (or the first lesson). */
    public function index(Course $course)
    {
        $enrollment = $this->enrollment($course);

        // ⚠️ (sort_order, id) — the SAME tiebreaker the admin Course Videos
        // screen uses. Ordering by sort_order alone let the two disagree on
        // ties, so the order an admin dragged into place was not the order
        // students met (reported 2026-09-11).
        $lessons = $course->lessons()->where('status', 'published')->orderBy('sort_order')->orderBy('id')->get();

        // A course with no published lessons is the NORMAL state right after it
        // is created — Course Management's own success message says "Now add
        // modules and lessons". Aborting 404 here meant a student who had paid,
        // been verified and enrolled clicked "Continue learning" and hit a bare
        // "Page not found" with no explanation and no way back (reported
        // 2026-08-19 against a freshly created Diploma course). Send them back
        // to My Courses with the actual reason instead.
        if ($lessons->isEmpty()) {
            // 'status', not 'info' — components/flash.blade.php only renders
            // success / error / status, so any other key shows nothing at all.
            return redirect()->route('student.courses')->with(
                'status',
                "\"{$course->title}\" doesn't have any lessons published yet. You're enrolled — the content will appear here as soon as it is released.",
            );
        }

        $completedIds = $enrollment->lessonProgress()->where('is_completed', true)->pluck('lesson_id');
        $next = $lessons->first(fn ($l) => ! $completedIds->contains($l->id)) ?? $lessons->first();

        return redirect()->route('student.learn.lesson', [$course, $next]);
    }

    public function lesson(Course $course, Lesson $lesson)
    {
        abort_unless($lesson->course_id === $course->id && $lesson->status === 'published', 404);
        $enrollment = $this->enrollment($course);

        $course->load(['modules' => fn ($q) => $q->orderBy('sort_order'), 'modules.lessons' => fn ($q) => $q->where('status', 'published')->orderBy('sort_order')->orderBy('id')]);
        $unassigned = $course->lessons()->whereNull('module_id')->where('status', 'published')->orderBy('sort_order')->orderBy('id')->get();
        $lesson->load('materials');

        $completedIds = $enrollment->lessonProgress()->where('is_completed', true)->pluck('lesson_id');

        $ordered = $course->lessons()->where('status', 'published')->orderBy('sort_order')->orderBy('id')->get();
        $idx = $ordered->search(fn ($l) => $l->id === $lesson->id);
        $previous = $idx > 0 ? $ordered[$idx - 1] : null;
        $next = $idx !== false && $idx < $ordered->count() - 1 ? $ordered[$idx + 1] : null;

        // Viewings left on an uploaded video (strictly two, 2026-11-11), so a used-up
        // lesson opens on "ask to watch again" instead of a button that can only
        // refuse. Read, never created: opening the page spends nothing.
        $videoViewsLeft = \App\Services\LessonViewWindow::remaining(
            $enrollment->lessonProgress()->where('lesson_id', $lesson->id)->first(),
        );
        $videoRequestPending = \App\Services\LmsSchemaRepair::ready()
            && \App\Models\LessonViewRequest::where('user_id', auth()->id())
                ->where('lesson_id', $lesson->id)->where('status', 'pending')->exists();

        // ⚠️ $unlockedIds (2026-11-11 hotfix). The LIVE server's
        // student/_lesson-nav-item.blade.php carries a "lessons unlock in order"
        // feature that was added on the server and is NOT in this project; it reads
        // $unlockedIds, and uploading this controller without it 500'd every lesson
        // page ("Undefined variable $unlockedIds"). Null = no lesson locked — its own
        // check reads `$unlockedIds !== null && ...`. Replace with the real list once
        // that feature's files are merged in.
        $unlockedIds = null;

        return view('student.learn', compact('course', 'lesson', 'enrollment', 'unassigned', 'completedIds', 'previous', 'next', 'videoViewsLeft', 'videoRequestPending', 'unlockedIds'));
    }

    public function complete(Course $course, Lesson $lesson)
    {
        abort_unless($lesson->course_id === $course->id, 404);
        $enrollment = $this->enrollment($course);

        LessonProgress::updateOrCreate(
            ['enrollment_id' => $enrollment->id, 'lesson_id' => $lesson->id],
            ['is_completed' => true, 'completed_at' => now()],
        );

        $this->refreshProgress($enrollment, $course);

        $next = $course->lessons()
            ->where('status', 'published')
            ->where('sort_order', '>', $lesson->sort_order)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->first();

        return $next
            ? redirect()->route('student.learn.lesson', [$course, $next])->with('success', 'Lesson completed!')
            : redirect()->route('student.learn.lesson', [$course, $lesson])->with('success', 'Lesson completed!');
    }

    protected function refreshProgress(Enrollment $enrollment, Course $course): void
    {
        $total = $course->lessons()->where('status', 'published')->count();
        $done = $enrollment->lessonProgress()->where('is_completed', true)->count();
        $pct = $total > 0 ? round(min(100, $done / $total * 100), 2) : 0;

        $enrollment->update(['progress_percentage' => $pct]);

        if ($pct >= 100 && $enrollment->status !== 'completed') {
            $enrollment->update(['status' => 'completed', 'completed_at' => now()]);

            Certificate::firstOrCreate(
                ['user_id' => $enrollment->user_id, 'course_id' => $course->id],
                [
                    'certificate_number' => 'CERT-'.strtoupper(Str::random(8)),
                    'verification_code' => Str::upper(Str::random(12)),
                    'enrollment_id' => $enrollment->id,
                    'issued_at' => now(),
                    'status' => 'issued',
                ],
            );
        }
    }
}
