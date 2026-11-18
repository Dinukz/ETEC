<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\LessonViewRequest;
use App\Services\LessonViewWindow;
use App\Services\LmsSchemaRepair;
use App\Services\VideoLibrary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Attach the videos already on the server to the lessons that should play them,
 * and deal with students asking to watch something again.
 *
 * The files are 10.4 GB with a single 2.13 GB lesson — far beyond what any
 * shared-hosting PHP takes in one browser request. They live in
 * `resources/Videos/<folder>/`, placed by FTP, and this screen points a lesson
 * at one of them. See App\Services\VideoLibrary.
 *
 * ⚠️ SINCE 2026-10-10 THEY CAN ALSO BE UPLOADED HERE — in small pieces, then
 * web-optimised without re-encoding. That lives in CourseVideoUploadController.
 */
class CourseVideoController extends Controller
{
    use \App\Http\Controllers\Manage\Concerns\ManagesCourses;

    /** The library, the courses, and what is attached to what. */
    public function index(Request $request)
    {
        LmsSchemaRepair::ensure();

        $folders = VideoLibrary::folders()->map(fn (string $folder) => [
            'name' => $folder,
            'files' => VideoLibrary::filesIn($folder),
        ]);

        $selected = $request->string('folder')->toString();

        if ($selected === '' && $folders->isNotEmpty()) {
            // Open on a folder that actually has something in it — two of the
            // three are empty, and landing on an empty one reads as broken.
            $selected = $folders->firstWhere(fn ($f) => $f['files']->isNotEmpty())['name']
                ?? $folders->first()['name'];
        }

        $files = $folders->firstWhere('name', $selected)['files'] ?? collect();

        // ⚠️ WHICH FILES MAKE STUDENTS WAIT (2026-10-01). Length, average
        // bitrate and whether the index comes first — read from the MP4
        // headers only, cached per file — plus whether a light SD copy exists.
        // Only for the folder on screen; the others are not opened.
        $files = $files->map(function (array $file) {
            $absolute = VideoLibrary::absolutePath($file['path']);
            $probe = $absolute !== null && in_array(strtolower(pathinfo($file['path'], PATHINFO_EXTENSION)), ['mp4', 'm4v', 'mov'], true)
                ? \App\Support\Mp4Probe::inspect($absolute)
                : ['duration' => null, 'faststart' => null];
            $bps = \App\Support\Mp4Probe::bitrate((int) $file['size'], $probe['duration']);

            return $file + [
                'duration' => $probe['duration'],
                'faststart' => $probe['faststart'],
                'bps' => $bps,
                'heavy' => $bps !== null && $bps > \App\Support\Mp4Probe::HEAVY_BPS,
                'light' => VideoLibrary::lightCopyOf($file['path']),
            ];
        });

        // Which lesson (if any) already plays each file.
        $attached = Lesson::where('video_provider', 'upload')
            ->whereNotNull('video_path')
            ->with('course:id,title')
            ->get(['id', 'course_id', 'title', 'video_path'])
            ->keyBy('video_path');

        // The lessons of whichever course is being ordered, in their current
        // order. Separate from $files above: that lists the library, this lists
        // what students will actually meet, and only the second can be sorted.
        $orderCourseId = (int) $request->integer('order_course');

        $orderLessons = $orderCourseId
            ? Lesson::where('course_id', $orderCourseId)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(['id', 'title', 'video_path', 'sort_order', 'status'])
            : collect();

        // ⚠️ THE REQUESTS ARE PAGED (2026-10-10). 497 were waiting and all of
        // them loaded on every visit — "e section eka withrk 50 request load
        // krnn, ithuru tika page widiyt". The admin picks 50 / 100 / 200 / 300 /
        // 400 a page; anything else falls back to 50. Own query-string names
        // (req_page / req_per) so paging the requests never disturbs the folder
        // or the lesson-order picker on the same screen.
        $perPage = in_array((int) $request->integer('req_per'), self::REQUEST_PAGE_SIZES, true)
            ? (int) $request->integer('req_per')
            : self::REQUEST_PAGE_SIZES[0];

        // ⚠️ AND SEARCHABLE (2026-11-04) — "Course video walat ena request search
        // krnn option ekak". Its own query-string name (req_q), like req_page /
        // req_per, so it survives paging and the page size. See
        // LessonViewRequest::scopeSearch() for what it matches.
        $requestSearch = trim($request->string('req_q')->toString());

        // ...and by the Sri Lanka day it was asked (2026-11-04, part 2: "date wise
        // filter krla student search krnn puluwn wenn one"). A typed-in nonsense
        // date is dropped, and ends picked the wrong way round are swapped.
        $requestFrom = $this->requestDay($request->input('req_from'));
        $requestTo = $this->requestDay($request->input('req_to'));

        if ($requestFrom !== null && $requestTo !== null && $requestFrom > $requestTo) {
            [$requestFrom, $requestTo] = [$requestTo, $requestFrom];
        }

        // What every other form and link on the page must carry along.
        $requestFilters = array_filter(
            ['req_q' => $requestSearch, 'req_from' => $requestFrom, 'req_to' => $requestTo],
            fn ($value) => $value !== null && $value !== '',
        );

        $pendingRequests = LmsSchemaRepair::ready()
            ? LessonViewRequest::pending()
                ->search($requestSearch)
                ->askedBetween($requestFrom, $requestTo)
                ->with(['user:id,name,email', 'lesson:id,title,course_id', 'lesson.course:id,title'])
                ->latest()
                ->orderByDesc('id')
                ->paginate($perPage, ['*'], 'req_page')
                ->withQueryString()
                ->fragment('requests')
            : new \Illuminate\Pagination\LengthAwarePaginator([], 0, $perPage);

        // Allowing the last requests on the last page would otherwise land on
        // an empty page reading "Showing – of N" (review 2026-10-10).
        if ($pendingRequests->isEmpty() && $pendingRequests->currentPage() > 1 && $pendingRequests->total() > 0) {
            return redirect()->to($pendingRequests->url($pendingRequests->lastPage()));
        }

        // Everyone waiting, for the heading — a search narrows the list, not the
        // number the admin is working down. Same figure as before when not searching.
        $pendingTotal = $requestFilters === [] || ! LmsSchemaRepair::ready()
            ? $pendingRequests->total()
            : LessonViewRequest::pending()->count();

        // The upload form: a lecturer only ever sees their own courses (the
        // server refuses the rest anyway — VideoUploads::start()).
        $uploadCourses = $this->manageableCourses()->orderBy('title')->get(['id', 'title']);

        // ...and each of those courses' lessons, in order.
        $lessonsByCourse = Lesson::whereIn('course_id', $uploadCourses->pluck('id'))
            ->orderBy('sort_order')->orderBy('id')
            ->get(['id', 'course_id', 'title', 'video_path'])
            ->groupBy('course_id')
            ->map(fn ($lessons) => $lessons->map(fn ($l) => [
                'id' => $l->id,
                'title' => $l->title,
                'has_video' => filled($l->video_path),
            ])->values());

        return view('manage.course-videos.index', [
            'pendingRequests' => $pendingRequests,
            'pendingTotal' => $pendingTotal,
            'requestSearch' => $requestSearch,
            'requestFrom' => $requestFrom,
            'requestTo' => $requestTo,
            'requestFilters' => $requestFilters,
            'requestPageSizes' => self::REQUEST_PAGE_SIZES,
            'requestPerPage' => $perPage,
            'lessonsByCourse' => $lessonsByCourse,
            'uploadCourses' => $uploadCourses,
            'chunkBytes' => \App\Services\VideoUploads::chunkBytes(),
            'orderCourseId' => $orderCourseId,
            'orderLessons' => $orderLessons,
            'folders' => $folders,
            'selected' => $selected,
            'files' => $files,
            'attached' => $attached,
            'courses' => Course::orderBy('title')->get(['id', 'title']),
            'libraryExists' => VideoLibrary::exists(),
            'libraryPath' => VideoLibrary::root(),
        ]);
    }

    /** Requests per page the admin can choose from; the first is the default. */
    public const REQUEST_PAGE_SIZES = [50, 100, 200, 300, 400];

    /** A real Y-m-d day from the query string, or null — never an exception. */
    protected function requestDay(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        [$year, $month, $day] = array_map('intval', explode('-', $value));

        return checkdate($month, $day, $year) ? $value : null;
    }

    /**
     * Point a lesson at a file. Creates the lesson when the admin gives a title
     * rather than picking an existing one — the courses in the library have no
     * lessons yet, and making them one at a time on another screen first would
     * be 29 round trips for one course.
     */
    public function attach(Request $request)
    {
        $data = $request->validate([
            'video_path' => ['required', 'string', 'max:1000'],
            'course_id' => ['required', 'exists:courses,id'],
            'lesson_id' => ['nullable', 'exists:lessons,id'],
            'title' => ['required_without:lesson_id', 'nullable', 'string', 'max:180'],
        ]);

        // ⚠️ RE-CHECK THE PATH AGAINST THE LIBRARY. It arrives in a request, so
        // it is not trusted: VideoLibrary refuses anything that resolves outside
        // the root or is not a playable file.
        if (! VideoLibrary::isPlayable($data['video_path'])) {
            return back()->withInput()->withErrors([
                'video_path' => 'That file is not in the video library, or is not a video.',
            ]);
        }

        $course = Course::findOrFail($data['course_id']);

        // ?? null, not just a truthiness check: a nullable rule that the form
        // never submitted leaves the key ABSENT from the validated array, not
        // present-and-null.
        $lessonId = $data['lesson_id'] ?? null;

        $lesson = $lessonId
            ? Lesson::where('course_id', $course->id)->findOrFail($lessonId)
            : null;

        if (! $lesson) {
            $lesson = Lesson::create([
                'course_id' => $course->id,
                'title' => $data['title'],
                'slug' => $this->uniqueSlug($course, $data['title']),
                'status' => 'published',
                'sort_order' => (int) Lesson::where('course_id', $course->id)->max('sort_order') + 1,
            ]);
        }

        $lesson->update(array_filter([
            'video_provider' => 'upload',
            'video_path' => $data['video_path'],
            // ⚠️ THE TITLE WAS BEING DROPPED (fixed 2026-09-11). The button on
            // an already-attached row says "Rename" and posts a title, but this
            // update never assigned it, so renaming silently did nothing.
            // array_filter keeps a blank title from wiping the existing one.
            'title' => $data['title'] ?? null,
        ], fn ($v) => $v !== null && $v !== ''));

        return back()->with('success', "\"{$lesson->fresh()->title}\" now plays {$data['video_path']}.");
    }

    /**
     * Create a lesson for each of several files at once.
     *
     * ⚠️ WHY THIS EXISTS. One course in the real library is 29 files. Attaching
     * them one at a time — pick a course, type a name, submit, scroll back — is
     * 29 round trips for one course, and there are three courses. The owner's
     * words were "meka ikmnt fix krla denn".
     *
     * ⚠️ AND WHY IT IS A PICKED LIST, NOT "IMPORT EVERYTHING". That folder holds
     * the same lessons twice: an "All video" folder AND fifteen per-day folders.
     * Importing the lot would create 29 lessons for 15 real ones. The admin ticks
     * what they want and sees the count before pressing.
     */
    public function attachMany(Request $request)
    {
        $data = $request->validate([
            'course_id' => ['required', 'exists:courses,id'],
            'paths' => ['required', 'array', 'min:1'],
            'paths.*' => ['string', 'max:1000'],
        ]);

        $course = Course::findOrFail($data['course_id']);

        // Natural sort so "Day 2" lands before "Day 10" — the order lessons are
        // created in becomes the order students see them.
        $paths = collect($data['paths'])->unique()->values()->all();
        natcasesort($paths);

        $created = 0;
        $skipped = 0;
        $order = (int) Lesson::where('course_id', $course->id)->max('sort_order');

        foreach ($paths as $path) {
            if (! VideoLibrary::isPlayable($path)) {
                $skipped++;

                continue;
            }

            // Already attached somewhere — leave it alone rather than making a
            // second lesson pointing at the same file.
            if (Lesson::where('video_provider', 'upload')->where('video_path', $path)->exists()) {
                $skipped++;

                continue;
            }

            $title = VideoLibrary::suggestTitle($path);

            Lesson::create([
                'course_id' => $course->id,
                'title' => $title,
                'slug' => $this->uniqueSlug($course, $title),
                'status' => 'published',
                'sort_order' => ++$order,
                'video_provider' => 'upload',
                'video_path' => $path,
            ]);

            $created++;
        }

        $message = $created === 1
            ? "1 video lesson added to \"{$course->title}\"."
            : "{$created} video lessons added to \"{$course->title}\".";

        if ($skipped > 0) {
            $message .= " {$skipped} were skipped because they are already attached or missing.";
        }

        return back()->with($created > 0 ? 'success' : 'error', $created > 0 ? $message : 'Nothing was added — those files are already attached.');
    }

    /** Unhook a lesson from its file. The file itself is never touched. */
    public function detach(Request $request)
    {
        $data = $request->validate(['lesson_id' => ['required', 'exists:lessons,id']]);

        $lesson = Lesson::findOrFail($data['lesson_id']);
        $lesson->update(['video_provider' => null, 'video_path' => null]);

        return back()->with('success', "\"{$lesson->title}\" no longer has a video. The file is still on the server.");
    }

    /**
     * Delete a video outright: every lesson that plays it, and the file itself.
     *
     * Reported 2026-09-11: *"courses video remove krata student and consultant
     * lagen baluwaham remove wela na"*. Detach only nulls `video_path`, and the
     * student sidebar filters on `status` alone and never looks at the video —
     * so a detached lesson stayed on the list and played nothing. Removing it
     * for students means removing the LESSON.
     *
     * ⚠️ IRREVERSIBLE, ON BOTH SIDES. `Lesson` has no soft delete, and deleting
     * one cascades `lesson_progress`, `lesson_materials` and
     * `lesson_view_requests` — every student's watch history and any granted
     * re-watch window for it go too. The file is unlinked with no backup. The
     * `confirm` flag below is what stops a stray POST doing all that.
     *
     * ⚠️ ONE FILE CAN HAVE SEVERAL LESSONS. This library really does hold two
     * lessons named "Day 04" pointing at two different copies, and attachMany()
     * can point more than one lesson at a single path — so this deletes every
     * lesson matching the path, not just the first.
     */
    public function destroy(Request $request)
    {
        $data = $request->validate([
            'video_path' => ['required', 'string'],
            'confirm' => ['required', 'accepted'],
        ]);

        $path = $data['video_path'];

        abort_unless(VideoLibrary::isPlayable($path), 404, 'That file is not in the video library.');

        $lessons = Lesson::where('video_path', $path)->get(['id', 'title']);

        DB::transaction(function () use ($lessons) {
            Lesson::whereIn('id', $lessons->pluck('id'))->delete();
        });

        $erased = VideoLibrary::delete($path);

        $names = $lessons->pluck('title')->implode(', ');

        return back()->with(
            $erased ? 'success' : 'error',
            $erased
                ? trim(sprintf(
                    '%s deleted from the server.%s',
                    basename($path),
                    $lessons->isEmpty()
                        ? ' It was not attached to any lesson.'
                        : " Students can no longer see: {$names}.",
                ))
                : "The lessons were removed, but {$path} could not be deleted from the server.",
        );
    }

    /**
     * Delete one lesson from the order list, leaving its file alone.
     *
     * Asked for 2026-09-11 alongside the Save button: *"topic tika ethana remove
     * krann option ekak dala denn"*. The rows this exists for are the six
     * CourseSeeder demo topics — "Financial Basics", "Introduction to
     * Management" and so on — which have no video to delete and which students
     * can see and open to a blank page.
     *
     * ⚠️ Same cascade as destroy(): progress, materials and view requests go
     * with the lesson. The file, if there is one, is deliberately kept — use
     * Delete video on the file row for that.
     */
    public function removeLesson(Request $request)
    {
        $data = $request->validate(['lesson_id' => ['required', 'integer', 'exists:lessons,id']]);

        $lesson = Lesson::findOrFail($data['lesson_id']);
        $title = $lesson->title;
        $hadVideo = filled($lesson->video_path);

        $lesson->delete();

        return back()->with('success', sprintf(
            '"%s" removed. Students no longer see it.%s',
            $title,
            $hadVideo ? ' The video file is still on the server.' : '',
        ));
    }

    /** Approve a student's request to watch a lesson again. */
    public function approveRequest(LessonViewRequest $viewRequest)
    {
        LmsSchemaRepair::ensure();

        $problem = $this->approveOne($viewRequest);

        return $problem === null
            ? back()->with('success', $viewRequest->user?->name.' can watch that lesson again.')
            : back()->with('error', $problem);
    }

    public function declineRequest(LessonViewRequest $viewRequest)
    {
        $problem = $this->declineOne($viewRequest);

        return $problem === null
            ? back()->with('success', 'Request declined.')
            : back()->with('error', $problem);
    }

    /**
     * Allow — or decline — every ticked request at once (2026-10-10).
     *
     * *"select all dila allow krnn puluwn widiyt hadl denn"*. Each request goes
     * through exactly the same rule as its own button (approveOne/declineOne),
     * so a bulk allow can never grant what a single click would refuse: one
     * extra viewing per request, only for a student still enrolled, and only
     * while the request is pending. Anything skipped is counted out loud.
     */
    public function bulkRequests(Request $request)
    {
        LmsSchemaRepair::ensure();

        $data = $request->validate([
            'action' => ['required', 'in:approve,decline'],
            'ids' => ['required', 'array', 'min:1', 'max:'.max(self::REQUEST_PAGE_SIZES)],
            'ids.*' => ['integer'],
        ]);

        $ids = array_values(array_unique(array_map('intval', $data['ids'])));
        $done = 0;

        $found = LessonViewRequest::whereIn('id', $ids)
            ->with('lesson:id,course_id')
            ->orderBy('id')
            ->get();

        // Ticked rows that are gone (their lesson or student was deleted
        // meanwhile) count as skipped, so the message never under-reports.
        $skipped = count($ids) - $found->count();

        $found->each(function (LessonViewRequest $viewRequest) use ($data, &$done, &$skipped) {
            $problem = $data['action'] === 'approve' ? $this->approveOne($viewRequest) : $this->declineOne($viewRequest);

            $problem === null ? $done++ : $skipped++;
        });

        $verb = $data['action'] === 'approve' ? 'allowed to watch again' : 'declined';
        $message = $done === 1 ? "1 request {$verb}." : "{$done} requests {$verb}.";

        if ($skipped > 0) {
            $message .= " {$skipped} skipped — already dealt with, no longer there, or the student is no longer enrolled.";
        }

        return back()->with($done > 0 ? 'success' : 'error', $message);
    }

    /** The one rule for allowing a request. Null when it went through, else why not. */
    protected function approveOne(LessonViewRequest $viewRequest): ?string
    {
        if ($viewRequest->status !== 'pending') {
            return 'That request has already been dealt with.';
        }

        $lesson = $viewRequest->lesson;

        $progress = LessonProgress::whereHas(
            'enrollment',
            fn ($q) => $q->where('user_id', $viewRequest->user_id)->where('course_id', $lesson?->course_id),
        )->where('lesson_id', $viewRequest->lesson_id)->first();

        if (! $progress) {
            return 'That student no longer has an active enrollment for this course.';
        }

        LessonViewWindow::grantExtra($progress);

        $viewRequest->update([
            'status' => 'approved',
            'decided_by' => auth()->id(),
            'decided_at' => now(),
        ]);

        return null;
    }

    protected function declineOne(LessonViewRequest $viewRequest): ?string
    {
        if ($viewRequest->status !== 'pending') {
            return 'That request has already been dealt with.';
        }

        $viewRequest->update([
            'status' => 'declined',
            'decided_by' => auth()->id(),
            'decided_at' => now(),
        ]);

        return null;
    }

    /**
     * Play a library file in the admin screen itself.
     *
     * ⚠️ A SECOND DOOR TO THE SAME FILES, SO IT IS GATED IN ITS OWN RIGHT.
     * The student route (Student\LessonVideoController) demands an active
     * enrollment — an admin previewing their own course would get "you are not
     * enrolled". This one demands a manage role instead, which the route group
     * already applies, and takes the path from the REQUEST, so VideoLibrary
     * re-validates it: anything resolving outside the library is refused.
     *
     * ⚠️ AND IT CONSUMES NO VIEWING WINDOW. It is not attached to a lesson or
     * an enrollment at all, so an admin checking a file cannot spend a
     * student's one viewing. That is the whole reason it is separate.
     */
    public function preview(Request $request)
    {
        $path = (string) $request->query('path', '');

        abort_unless(VideoLibrary::isPlayable($path), 404, 'That file is not in the video library.');

        $absolute = VideoLibrary::absolutePath($path);

        abort_unless($absolute !== null && is_file($absolute), 404);

        return \App\Services\VideoStream::respond($absolute, $request, VideoLibrary::mimeFor($path));
    }

    /**
     * Put a course's lessons in the order students should meet them.
     *
     * Asked for 2026-09-10: "video wala mulata idn anthima wenak videos wala
     * than maru krnn puluwn wenn one — mulat enn one mokakd anthimat enn mokd
     * kiyla admin drag and drop krla dann puluwn widiyta."
     *
     * Nothing about how students read the order changes: LearnController and
     * CourseController::show() already sort by lessons.sort_order, so writing
     * that column IS the feature.
     *
     * ⚠️ SCOPED TO ONE COURSE, AND IDS ARE NOT TRUSTED. The list arrives from
     * the browser; any lesson that does not belong to this course is dropped
     * rather than renumbered, so a hand-posted id cannot reshuffle somebody
     * else's course.
     */
    public function reorder(Request $request)
    {
        $data = $request->validate([
            'course_id' => ['required', 'exists:courses,id'],
            'lesson_ids' => ['required', 'array', 'min:1'],
            'lesson_ids.*' => ['integer'],
        ]);

        $ids = array_values(array_unique(array_map('intval', $data['lesson_ids'])));

        $owned = Lesson::where('course_id', $data['course_id'])
            ->whereIn('id', $ids)
            ->pluck('id')
            ->all();

        $ordered = array_values(array_filter($ids, fn (int $id) => in_array($id, $owned, true)));

        if ($ordered === []) {
            return $this->reorderResponse($request, false, 'Nothing was reordered.');
        }

        DB::transaction(function () use ($ordered) {
            foreach ($ordered as $position => $id) {
                Lesson::whereKey($id)->update(['sort_order' => $position + 1]);
            }
        });

        return $this->reorderResponse($request, true, 'Lesson order saved.');
    }

    /**
     * ⚠️ ONE RETURN FOR BOTH CALLERS, the convention this app already uses for
     * background posts (see UserManagementController::applied()). Drag-and-drop
     * saves in the background, but the same action must still work as a plain
     * form post if JavaScript is off or the fetch fails.
     */
    protected function reorderResponse(Request $request, bool $ok, string $message)
    {
        if ($request->expectsJson() || $request->ajax()) {
            return response()->json(['ok' => $ok, 'message' => $message]);
        }

        return back()->with($ok ? 'success' : 'error', $message);
    }
    /** lessons carries unique(course_id, slug), so make sure we do not collide. */
    protected function uniqueSlug(Course $course, string $title): string
    {
        $base = Str::slug($title) ?: 'lesson';
        $slug = $base;
        $n = 2;

        while (Lesson::where('course_id', $course->id)->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$n++;
        }

        return $slug;
    }
}
