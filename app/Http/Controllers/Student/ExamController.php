<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\Enrollment;
use App\Models\Exam;
use App\Models\ExamResult;
use App\Services\ExamGrader;
use App\Services\ExamSchemaRepair;
use Illuminate\Http\Request;

class ExamController extends Controller
{
    protected function enrolledCourseIds()
    {
        return Enrollment::where('user_id', auth()->id())
            ->whereIn('status', ['active', 'completed'])
            ->pluck('course_id');
    }

    public function index()
    {
        ExamSchemaRepair::heal();

        $exams = Exam::query()
            ->with('course')
            ->withCount('questions')
            ->whereIn('course_id', $this->enrolledCourseIds())
            ->where('is_published', true)
            ->latest()
            ->paginate(10);

        $results = ExamResult::where('user_id', auth()->id())->get()->keyBy('exam_id');

        return view('student.exams.index', compact('exams', 'results'));
    }

    /** Published results for the signed-in student. */
    public function results()
    {
        ExamSchemaRepair::heal();

        $results = ExamResult::query()
            ->with('exam.course')
            ->where('user_id', auth()->id())
            ->whereNotNull('submitted_at')
            ->latest('submitted_at')
            ->paginate(10);

        return view('student.exams.results', compact('results'));
    }

    public function take(Exam $exam)
    {
        ExamSchemaRepair::heal();
        $this->ensureCanSit($exam);

        $result = ExamResult::firstOrCreate(
            ['exam_id' => $exam->id, 'user_id' => auth()->id()],
            ['marks_obtained' => 0, 'total_marks' => $exam->total_marks, 'percentage' => 0, 'passed' => false, 'started_at' => now(), 'is_published' => false],
        );

        if ($result->submitted_at) {
            return redirect()->route('student.exams.index')->with('error', 'You have already submitted this paper.');
        }

        // ⚠️ Came back after the time ran out (tab closed, phone died): the
        // attempt is closed now, on the answers saved before the deadline.
        if (ExamGrader::isOver($exam, $result, ExamGrader::SUBMIT_GRACE_SECONDS)) {
            ExamGrader::finish($exam, $result, ExamGrader::clean($exam, (array) $result->answers));

            return redirect()->route('student.exams.results')
                ->with('error', 'Your time for this paper ran out. It was marked on the answers saved before the deadline.');
        }

        $exam->load(['questions' => fn ($q) => $q->orderBy('sort_order')]);
        $deadline = ExamGrader::deadline($exam, $result);
        $secondsLeft = max(0, now()->diffInSeconds($deadline, false));
        $saved = (array) $result->answers;

        return view('student.exams.take', compact('exam', 'result', 'deadline', 'secondsLeft', 'saved'));
    }

    /**
     * Keep the answers picked so far (2026-10-18). Called by the page each
     * time an answer changes. What is saved here is what counts if the time
     * runs out before the paper is submitted.
     */
    public function save(Request $request, Exam $exam)
    {
        $this->ensureCanSit($exam, allowStarted: true);

        $result = ExamResult::where('exam_id', $exam->id)->where('user_id', auth()->id())->first();

        if (! $result || $result->submitted_at) {
            return response()->json(['ok' => false, 'message' => 'This paper is no longer open.'], 409);
        }

        if (ExamGrader::isOver($exam, $result, ExamGrader::SAVE_GRACE_SECONDS)) {
            return response()->json(['ok' => false, 'message' => 'Time is up — answers can no longer be changed.'], 409);
        }

        $result->update(['answers' => ExamGrader::clean($exam, (array) $request->input('answers', []))]);

        return response()->json(['ok' => true]);
    }

    public function submit(Request $request, Exam $exam)
    {
        $this->ensureCanSit($exam, allowStarted: true);

        $result = ExamResult::where('exam_id', $exam->id)->where('user_id', auth()->id())->first();
        abort_unless($result && ! $result->submitted_at, 403, 'No paper in progress.');

        // ⚠️ THE DEADLINE IS THE SERVER'S. A submit after the deadline (plus a
        // short grace for a slow connection) is marked on what was saved in
        // time — never on answers sent afterwards.
        $late = ExamGrader::isOver($exam, $result, ExamGrader::SUBMIT_GRACE_SECONDS);
        $answers = $late
            ? ExamGrader::clean($exam, (array) $result->answers)
            : ExamGrader::clean($exam, (array) $request->input('answers', []));

        ExamGrader::finish($exam, $result, $answers);
        $result->refresh();

        $message = match (true) {
            $late => 'Time had run out — the paper was marked on the answers saved before the deadline.',
            ! $result->is_published => 'Paper submitted. Your result will be released once written answers are marked.',
            default => "Paper submitted — you scored {$result->marks_obtained}/{$result->total_marks}.",
        };

        return redirect()->route(
            $result->is_published && $this->reviewable($exam) ? 'student.exams.review' : 'student.exams.results',
            $result->is_published && $this->reviewable($exam) ? $exam : [],
        )->with($late ? 'error' : 'success', $message);
    }

    /**
     * The marked paper — every question with the student's answer, the right
     * one, and the explanations (2026-10-18), like the owner's own quiz page.
     */
    public function review(Exam $exam)
    {
        ExamSchemaRepair::heal();

        $result = ExamResult::where('exam_id', $exam->id)->where('user_id', auth()->id())->first();

        abort_unless($result && $result->submitted_at, 404);
        abort_unless($result->is_published, 403, 'Your result has not been released yet.');
        abort_unless($this->reviewable($exam), 403, 'Answers for this paper are not shown.');

        $exam->load(['questions' => fn ($q) => $q->orderBy('sort_order'), 'course']);

        return view('student.exams.review', compact('exam', 'result'));
    }

    protected function reviewable(Exam $exam): bool
    {
        return ! ExamSchemaRepair::ready() || (bool) ($exam->show_answers ?? true);
    }

    protected function ensureCanSit(Exam $exam, bool $allowStarted = false): void
    {
        abort_unless($this->enrolledCourseIds()->contains($exam->course_id), 403);
        abort_unless($exam->is_published, 404);

        if ($exam->start_at && now()->lt($exam->start_at)) {
            abort(403, 'This paper has not opened yet.');
        }

        // An attempt already in progress may still save/submit; its own
        // deadline (ExamGrader) decides what counts.
        if (! $allowStarted && $exam->end_at && now()->gt($exam->end_at)) {
            $started = ExamResult::where('exam_id', $exam->id)->where('user_id', auth()->id())->whereNull('submitted_at')->exists();

            abort_unless($started, 403, 'This paper has closed.');
        }
    }
}
