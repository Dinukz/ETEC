<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Manage\Concerns\ManagesCourses;
use App\Models\Exam;
use App\Models\ExamQuestion;
use App\Models\ExamResult;
use App\Notifications\ExamResultPublished;
use App\Services\ExamSchemaRepair;
use App\Support\ExamPaperImport;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ExamController extends Controller
{
    use ManagesCourses;

    public function index(Request $request)
    {
        ExamSchemaRepair::heal();

        $courseIds = $this->teachableCourses()->pluck('id');

        $exams = Exam::query()
            ->with('course')
            ->withCount(['questions', 'results'])
            ->whereIn('course_id', $courseIds)
            ->when($request->filled('course_id'), fn ($q) => $q->where('course_id', $request->integer('course_id')))
            ->latest()
            ->paginate(10)
            ->withQueryString();

        $courses = $this->teachableCourses()->orderBy('title')->get(['id', 'title']);

        return view('manage.exams.index', compact('exams', 'courses'));
    }

    public function create()
    {
        ExamSchemaRepair::heal();

        $courses = $this->teachableCourses()->orderBy('title')->get(['id', 'title']);

        return view('manage.exams.create', compact('courses'));
    }

    public function store(Request $request)
    {
        ExamSchemaRepair::heal();
        $data = $this->validated($request);
        $data['lecturer_id'] = auth()->id();

        $exam = Exam::create($data);

        return redirect()->route('manage.exams.edit', $exam)->with('success', "Exam \"{$exam->title}\" created. Now add questions.");
    }

    /** Edit page doubles as the question builder. */
    public function edit(Exam $exam)
    {
        ExamSchemaRepair::heal();
        $this->ensureCanTeach($exam->course);
        $courses = $this->teachableCourses()->orderBy('title')->get(['id', 'title']);
        $exam->load(['questions' => fn ($q) => $q->orderBy('sort_order')]);

        return view('manage.exams.edit', compact('exam', 'courses'));
    }

    public function update(Request $request, Exam $exam)
    {
        ExamSchemaRepair::heal();
        $this->ensureCanTeach($exam->course);
        $exam->update($this->validated($request));

        return back()->with('success', 'Exam settings saved.');
    }

    public function destroy(Exam $exam)
    {
        $this->ensureCanTeach($exam->course);
        $exam->delete();

        return redirect()->route('manage.exams.index')->with('success', 'Exam deleted.');
    }

    public function publish(Exam $exam)
    {
        $this->ensureCanTeach($exam->course);

        if (! $exam->is_published && $exam->questions()->count() === 0) {
            return back()->with('error', 'Add at least one question before publishing.');
        }

        $exam->update(['is_published' => ! $exam->is_published]);

        return back()->with('success', $exam->is_published ? 'Exam published — enrolled students can now take it.' : 'Exam unpublished.');
    }

    /**
     * Marks for one paper (2026-10-18: "lecturersta me submit krana papers wala
     * mark blann puluwn wennth one, adminta blann puluwn wennth one"). A
     * lecturer sees their own courses' papers; an admin sees every paper.
     */
    public function results(Request $request, Exam $exam)
    {
        ExamSchemaRepair::heal();
        $this->ensureCanTeach($exam->course);
        $exam->load(['results' => fn ($q) => $q->orderByDesc('marks_obtained')->orderBy('submitted_at'), 'results.student.student', 'course']);

        if ($request->input('export') === 'csv') {
            return $this->resultsCsv($exam);
        }

        return view('manage.exams.results', compact('exam'));
    }

    /** One student's paper: every question, their answer and the right one. */
    public function answerSheet(ExamResult $result)
    {
        ExamSchemaRepair::heal();
        $exam = $result->exam;
        $this->ensureCanTeach($exam->course);

        $exam->load(['questions' => fn ($q) => $q->orderBy('sort_order'), 'course']);
        $result->load('student.student');

        return view('manage.exams.answer-sheet', compact('exam', 'result'));
    }

    protected function resultsCsv(Exam $exam)
    {
        $file = 'marks-'.\Illuminate\Support\Str::slug($exam->title).'.csv';

        return response()->streamDownload(function () use ($exam) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // so Excel shows Sinhala correctly
            fputcsv($out, ['Student', 'Code', 'Email', 'Marks', 'Out of', 'Percentage', 'Grade', 'Outcome', 'Started', 'Submitted']);

            foreach ($exam->results as $result) {
                fputcsv($out, [
                    $result->student?->name,
                    $result->student?->student?->student_code,
                    $result->student?->email,
                    $result->submitted_at ? $result->marks_obtained : '',
                    $result->total_marks,
                    $result->submitted_at ? $result->percentage : '',
                    $result->submitted_at ? $result->grade : '',
                    $result->submitted_at ? ($result->passed ? 'Passed' : 'Failed') : 'In progress',
                    \App\Casts\SriLankaWallClock::show($result->started_at, 'Y-m-d H:i'),
                    \App\Casts\SriLankaWallClock::show($result->submitted_at, 'Y-m-d H:i'),
                ]);
            }

            fclose($out);
        }, $file, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * A whole paper at once (2026-10-18) — the owner's HTML quiz file, JSON, or
     * the typed text format. See App\Support\ExamPaperImport. Nothing is added
     * unless EVERY question reads correctly.
     */
    public function import(Request $request, Exam $exam)
    {
        ExamSchemaRepair::heal();
        $this->ensureCanTeach($exam->course);

        $request->validate([
            'paper' => ['nullable', 'file', 'max:2048', 'mimetypes:text/plain,text/html,application/json,text/csv,application/octet-stream'],
            'content' => ['nullable', 'string', 'max:1000000'],
            'marks_each' => ['required', 'integer', 'min:1', 'max:100'],
        ]);

        $content = $request->hasFile('paper')
            ? (string) file_get_contents($request->file('paper')->getRealPath())
            : (string) $request->input('content', '');

        try {
            $questions = ExamPaperImport::parse($content);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['paper' => $e->getMessage()])->withInput();
        }

        $order = (int) ($exam->questions()->max('sort_order') ?? 0);
        $marks = (int) $request->integer('marks_each');

        foreach ($questions as $q) {
            $exam->questions()->create([
                'question' => $q['question'],
                'type' => 'mcq',
                'options' => $q['options'],
                'correct_answer' => $q['options'][$q['correct']],
                'option_explanations' => $q['option_explanations'],
                'explanation' => $q['explanation'],
                'hint' => $q['hint'],
                'marks' => $marks,
                'sort_order' => ++$order,
            ]);
        }

        // The paper's total follows its questions, and the pass mark keeps the
        // same share of it (40 of 100 becomes 2 of 5).
        if ($request->boolean('match_total')) {
            $total = max(1, (int) $exam->questions()->sum('marks'));
            $share = $exam->total_marks > 0 ? $exam->pass_marks / $exam->total_marks : 0.4;

            $exam->update(['total_marks' => $total, 'pass_marks' => min($total, (int) ceil($total * $share))]);
        }

        return back()->with('success', count($questions).' question(s) imported. Check them below, then publish the paper.');
    }

    /** Release all results of this exam to students. */
    public function publishResults(Exam $exam)
    {
        $this->ensureCanTeach($exam->course);

        $results = $exam->results()->where('is_published', false)->with('student')->get();
        $exam->results()->update(['is_published' => true]);

        foreach ($results as $result) {
            $result->student?->notify(new ExamResultPublished($result));
        }

        return back()->with('success', "Results released to {$results->count()} student(s).");
    }

    /** Manually adjust marks (essay questions) on a single result. */
    public function gradeResult(Request $request, ExamResult $result)
    {
        $this->ensureCanTeach($result->exam->course);

        $data = $request->validate([
            'marks_obtained' => ['required', 'integer', 'min:0', 'max:'.$result->total_marks],
        ]);

        $percentage = $result->total_marks > 0 ? round($data['marks_obtained'] / $result->total_marks * 100, 2) : 0;

        $result->update([
            'marks_obtained' => $data['marks_obtained'],
            'percentage' => $percentage,
            'grade' => ExamResult::gradeFor($percentage),
            'passed' => $data['marks_obtained'] >= $result->exam->pass_marks,
            'evaluated_by' => auth()->id(),
        ]);

        return back()->with('success', 'Result updated.');
    }

    // ---- Question builder ---------------------------------------------------

    public function storeQuestion(Request $request, Exam $exam)
    {
        ExamSchemaRepair::heal();
        $this->ensureCanTeach($exam->course);
        $data = $this->validatedQuestion($request);

        $exam->questions()->create($data + ['sort_order' => ($exam->questions()->max('sort_order') ?? 0) + 1]);

        return back()->with('success', 'Question added.');
    }

    public function updateQuestion(Request $request, ExamQuestion $question)
    {
        ExamSchemaRepair::heal();
        $this->ensureCanTeach($question->exam->course);
        $question->update($this->validatedQuestion($request));

        return back()->with('success', 'Question updated.');
    }

    public function destroyQuestion(ExamQuestion $question)
    {
        $this->ensureCanTeach($question->exam->course);
        $question->delete();

        return back()->with('success', 'Question removed.');
    }

    protected function validated(Request $request): array
    {
        $courseIds = $this->teachableCourses()->pluck('id')->all();

        $data = $request->validate([
            'course_id' => ['required', 'integer', 'in:'.implode(',', $courseIds ?: [0])],
            'title' => ['required', 'string', 'max:180'],
            'description' => ['nullable', 'string'],
            'type' => ['required', 'in:quiz,exam'],
            'total_marks' => ['required', 'integer', 'min:1', 'max:1000'],
            'pass_marks' => ['required', 'integer', 'min:0', 'lte:total_marks'],
            'duration_minutes' => ['required', 'integer', 'min:1', 'max:600'],
            'start_at' => ['nullable', 'date'],
            'end_at' => ['nullable', 'date', 'after:start_at'],
            'show_answers' => ['nullable', 'boolean'],
        ]);

        if (ExamSchemaRepair::ready()) {
            $data['show_answers'] = $request->boolean('show_answers');
        } else {
            unset($data['show_answers']);
        }

        return $data;
    }

    /**
     * ⚠️ THE RIGHT ANSWER IS PICKED, NOT RETYPED (2026-10-18). The form used to
     * ask for the correct option to be typed again "exactly" — one stray space
     * and every student was marked wrong. Now `correct` is the option's
     * position; the stored correct_answer is that option's own text, as before.
     */
    protected function validatedQuestion(Request $request): array
    {
        $data = $request->validate([
            'question' => ['required', 'string', 'max:2000'],
            'type' => ['required', 'in:mcq,truefalse,essay'],
            'options' => ['required_if:type,mcq', 'nullable', 'array', 'min:2', 'max:6'],
            'options.*' => ['nullable', 'string', 'max:500'],
            'option_explanations' => ['nullable', 'array', 'max:6'],
            'option_explanations.*' => ['nullable', 'string', 'max:2000'],
            'correct' => ['nullable', 'integer', 'min:0', 'max:5'],
            'correct_answer' => ['nullable', 'string', 'max:500'],
            'explanation' => ['nullable', 'string', 'max:4000'],
            'hint' => ['nullable', 'string', 'max:2000'],
            'marks' => ['required', 'integer', 'min:1', 'max:100'],
        ]);

        $extra = [
            'explanation' => filled($data['explanation'] ?? null) ? trim($data['explanation']) : null,
            'hint' => filled($data['hint'] ?? null) ? trim($data['hint']) : null,
            'option_explanations' => null,
        ];

        if ($data['type'] === 'mcq') {
            $options = [];
            $explanations = [];
            $correct = null;

            foreach ((array) ($data['options'] ?? []) as $i => $text) {
                if (! filled($text)) {
                    continue;
                }

                if ((string) ($data['correct'] ?? '') === (string) $i) {
                    $correct = trim($text);
                }

                $options[] = trim($text);
                $explanations[] = filled($data['option_explanations'][$i] ?? null) ? trim($data['option_explanations'][$i]) : null;
            }

            // An older form (or an API caller) may still send the text.
            $correct ??= collect($options)->first(fn ($o) => strcasecmp($o, trim((string) ($data['correct_answer'] ?? ''))) === 0);

            if (count($options) < 2) {
                throw ValidationException::withMessages(['options' => 'Fill in at least two options.']);
            }

            if (count(array_unique(array_map('mb_strtolower', $options))) !== count($options)) {
                throw ValidationException::withMessages(['options' => 'Two options are the same — every option must be different.']);
            }

            if ($correct === null) {
                throw ValidationException::withMessages(['correct' => 'Pick which option is the correct answer.']);
            }

            $data['options'] = $options;
            $data['correct_answer'] = $correct;
            $extra['option_explanations'] = array_filter($explanations) ? $explanations : null;
        } elseif ($data['type'] === 'truefalse') {
            if (! in_array($data['correct_answer'] ?? null, ['True', 'False'], true)) {
                throw ValidationException::withMessages(['correct_answer' => 'Choose True or False.']);
            }

            $data['options'] = ['True', 'False'];
        } else {
            $data['options'] = null;
            $data['correct_answer'] = null;
        }

        // ⚠️ HINTS ARE NO LONGER SHOWN OR ASKED FOR (2026-10-18, "Hint eka ain
        // krann"). A hint already stored (an imported paper) is kept as it is —
        // a form that no longer sends one must not erase it.
        if (! $request->exists('hint')) {
            unset($extra['hint']);
        }

        unset($data['correct'], $data['option_explanations'], $data['explanation'], $data['hint']);

        return ExamSchemaRepair::ready() ? $data + $extra : $data;
    }
}
