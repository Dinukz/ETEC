<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LessonViewRequest;
use App\Services\LessonViewWindow;
use App\Services\LmsSchemaRepair;
use App\Services\VideoLibrary;
use Illuminate\Http\Request;

/**
 * Serves a lesson video to somebody who is entitled to watch it.
 *
 * ⚠️ THIS IS THE ONLY WAY THE FILES ARE REACHABLE. They live in
 * `resources/Videos`, outside the document root, so there is no URL to guess
 * and nothing to leak — see VideoLibrary. Every byte passes the checks below.
 *
 * ⚠️ AND IT IS HONEST ABOUT WHAT IT CANNOT DO. The owner asked for screen
 * recording to be blocked. No website can do that — not this one, not YouTube,
 * not Netflix without paid hardware DRM, and not against a phone pointed at the
 * screen. What is here (chosen by the owner on 2026-09-10, "just block the easy
 * ways") stops a casual student: no download button, no context menu, no file
 * URL in the page source, a link that dies when the viewing ends (at most
 * LINK_HOURS — it was 15 minutes until the video began arriving in pieces), and
 * an E-TEC mark drawn over the player. It stops nobody who tries, and the code
 * should not pretend otherwise.
 */
class LessonVideoController extends Controller
{
    /**
     * The signed URL the player fetches immediately before it plays.
     *
     * Handed out by JSON rather than baked into the page so the file address is
     * never sitting in the HTML source, and so opening the lesson page does not
     * itself consume a viewing — only pressing play does.
     */
    public function ticket(Request $request, Course $course, Lesson $lesson)
    {
        // ⚠️ HEAL HERE, not on the lesson page render. This is the first moment
        // the viewing-window columns are actually needed, it is a POST with no
        // open transaction, and LmsSchemaRepair::ready() is cached afterwards so
        // it costs nothing on later plays. Without it the window fails OPEN and
        // "watch once" would quietly not be enforced at all until an admin
        // happened to visit the Course Videos screen.
        LmsSchemaRepair::ensure();

        [$enrollment, $progress] = $this->gate($course, $lesson);

        // ⚠️ A RESUME NEVER OPENS A WINDOW (2026-10-01). The player asks again by
        // itself when the network drops mid-lesson. open() would start a FRESH
        // window once the old one had closed — spending a viewing the office
        // granted by hand, without the student pressing anything. A resume is
        // only ever a new link for a window that is already open.
        //
        // ⚠️ EVERY OTHER CALL COUNTS ONE VIEWING (2026-11-11, strictly two) — see
        // LessonViewWindow::open(). Only this resume path is free.
        if ($request->boolean('resume')) {
            if (! LessonViewWindow::isOpen($progress)) {
                return response()->json([
                    'ok' => false,
                    'message' => LessonViewWindow::remaining($progress) > 0
                        ? 'This viewing has ended. Press the button again to start your next viewing.'
                        : 'You have used all your viewings of this lesson. Ask to watch it again.',
                    'remaining' => LessonViewWindow::remaining($progress),
                ], 403);
            }
        } elseif (! LessonViewWindow::open($progress)) {
            return response()->json([
                'ok' => false,
                'message' => 'You have watched this lesson '.LessonViewWindow::allowed($progress).' times. Ask to watch it again.',
                'remaining' => 0,
            ], 403);
        }

        $progress = $progress->fresh() ?? $progress;
        $expires = self::linkExpiry($progress);

        // ⚠️ PASS THE MODELS, NOT THEIR IDS. Course::getRouteKeyName() is
        // 'slug', so an id here builds a URL the router cannot bind and every
        // stream 404s. `q` is signed with the rest, so it cannot be edited.
        $sign = fn (array $extra = []) => \Illuminate\Support\Facades\URL::temporarySignedRoute(
            'student.lesson.video',
            $expires,
            ['course' => $course, 'lesson' => $lesson] + $extra,
        );

        $urls = ['hd' => $sign()];

        if (VideoLibrary::lightCopyOf((string) $lesson->video_path) !== null) {
            $urls['sd'] = $sign(['q' => 'sd']);
        }

        return response()->json([
            'ok' => true,
            // The light copy plays first when there is one — the whole point
            // of making it. The HD link is offered beside it.
            'url' => $urls['sd'] ?? $urls['hd'],
            'urls' => $urls,
            'quality' => isset($urls['sd']) ? 'sd' : 'hd',
            'expires_at' => optional($progress->window_expires_at)->toDayDateTimeString(),
            // Viewings left AFTER this one — the player shows it, and locks at 0
            // once this one ends.
            'remaining' => LessonViewWindow::remaining($progress),
        ]);
    }

    /** The longest a video link lives, however long the viewing window is. */
    public const LINK_HOURS = 3;

    /**
     * When a video link stops working: the moment the viewing window closes, or
     * LINK_HOURS from now, whichever is sooner.
     *
     * ⚠️ IT WAS 15 MINUTES, AND THAT NO LONGER WORKS (2026-10-01). The video now
     * arrives in pieces (VideoStream::maxSpan), each one a new request carrying
     * this link — so a 15-minute link stopped every lesson at minute 15. Nothing
     * else is loosened: every piece still checks login, enrollment and the open
     * window, so a copied link is useless to anyone else.
     */
    public static function linkExpiry(\App\Models\LessonProgress $progress): \Illuminate\Support\Carbon
    {
        $cap = now()->addHours(self::LINK_HOURS);
        $window = LmsSchemaRepair::ready() ? $progress->window_expires_at : null;

        return $window !== null && $window->lessThan($cap) ? $window->copy() : $cap;
    }

    /**
     * Stream the file, honouring HTTP Range.
     *
     * ⚠️ RANGE IS NOT OPTIONAL. Without a 206 the browser cannot seek at all,
     * and every scrub re-downloads from byte 0 — on a 2 GB lesson that is the
     * difference between a working player and an unusable one.
     */
    public function show(Request $request, Course $course, Lesson $lesson): \Symfony\Component\HttpFoundation\Response
    {
        abort_unless($request->hasValidSignature(), 403, 'This video link has expired. Reload the lesson and press play again.');

        [$enrollment, $progress] = $this->gate($course, $lesson);

        abort_unless(LessonViewWindow::isOpen($progress), 403, 'Your viewing window for this lesson has closed.');

        // The light copy, when the signed link asked for it. If it has since
        // been removed from the server, the original plays instead.
        $relative = (string) $lesson->video_path;

        if ($request->query('q') === 'sd') {
            $relative = VideoLibrary::lightCopyOf($relative) ?? $relative;
        }

        $path = VideoLibrary::absolutePath($relative);

        abort_unless($path !== null && is_file($path), 404, 'The video file for this lesson is missing.');

        // ⚠️ THE BYTES ARE PUSHED BY A SHARED SERVICE, THE RULES STAY HERE.
        // The admin preview on the Course Videos screen streams the same files
        // under completely different rules (a manage role, no enrollment, no
        // viewing window consumed). Only the Range handling is common, and
        // having two copies of it is how one door quietly loses seeking.
        return \App\Services\VideoStream::respond(
            $path,
            $request,
            VideoLibrary::mimeFor($relative),
        );
    }

    /** A student asks to watch a lesson again. */
    public function requestAgain(Request $request, Course $course, Lesson $lesson)
    {
        LmsSchemaRepair::ensure();

        [$enrollment, $progress] = $this->gate($course, $lesson);

        $data = $request->validate(['reason' => ['nullable', 'string', 'max:300']]);

        $already = LessonViewRequest::where('user_id', auth()->id())
            ->where('lesson_id', $lesson->id)
            ->where('status', 'pending')
            ->exists();

        if ($already) {
            return back()->with('status', 'Your request is already with the office — you will be told when it is approved.');
        }

        LessonViewRequest::create([
            'user_id' => auth()->id(),
            'lesson_id' => $lesson->id,
            'status' => 'pending',
            'reason' => $data['reason'] ?? null,
        ]);

        return back()->with('success', 'Your request has been sent. You will be able to watch this lesson again once it is approved.');
    }

    /**
     * The shared gate: enrolled, verified, and the lesson belongs to the course.
     *
     * ⚠️ THE SAME RULE Student\LearnController APPLIES, deliberately. An
     * enrollment must be 'active' or 'completed' — a pending (unpaid)
     * registration can see the course listed in My Courses but must never reach
     * its content. A consultant needs no special case: a consultant who bought
     * the course has an enrollment like anybody else.
     *
     * @return array{0: Enrollment, 1: \App\Models\LessonProgress}
     */
    protected function gate(Course $course, Lesson $lesson): array
    {
        abort_unless($lesson->course_id === $course->id && $lesson->status === 'published', 404);

        $enrollment = Enrollment::where('user_id', auth()->id())
            ->where('course_id', $course->id)
            ->whereIn('status', ['active', 'completed'])
            ->first();

        abort_unless($enrollment, 403, 'You are not enrolled in this course, or your payment has not been verified yet.');

        return [$enrollment, LessonViewWindow::progressFor($enrollment, $lesson)];
    }
}
