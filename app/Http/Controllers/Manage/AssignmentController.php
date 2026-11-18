<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Manage\Concerns\ManagesCourses;
use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Notifications\AssignmentGraded;
use Illuminate\Http\Request;

class AssignmentController extends Controller
{
    use ManagesCourses;

    public function index(Request $request)
    {
        $courseIds = $this->teachableCourses()->pluck('id');

        $assignments = Assignment::query()
            ->with('course')
            ->withCount(['submissions', 'submissions as ungraded_count' => fn ($q) => $q->where('status', '!=', 'graded')])
            ->whereIn('course_id', $courseIds)
            ->when($request->filled('course_id'), fn ($q) => $q->where('course_id', $request->integer('course_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->latest()
            ->paginate(10)
            ->withQueryString();

        $courses = $this->teachableCourses()->orderBy('title')->get(['id', 'title']);

        return view('manage.assignments.index', compact('assignments', 'courses'));
    }

    public function create()
    {
        $courses = $this->teachableCourses()->orderBy('title')->get(['id', 'title']);

        return view('manage.assignments.create', compact('courses'));
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        if ($request->hasFile('attachment')) {
            $data['attachment_path'] = $request->file('attachment')->store('assignments', 'public');
        }

        $data['lecturer_id'] = auth()->id();
        $assignment = Assignment::create($data);

        return redirect()->route('manage.assignments.index')->with('success', "Assignment \"{$assignment->title}\" created.");
    }

    /** Submissions list + grading for one assignment. */
    public function show(Assignment $assignment)
    {
        $this->ensureCanTeach($assignment->course);
        $assignment->load(['course', 'submissions.student']);

        return view('manage.assignments.show', compact('assignment'));
    }

    public function edit(Assignment $assignment)
    {
        $this->ensureCanTeach($assignment->course);
        $courses = $this->teachableCourses()->orderBy('title')->get(['id', 'title']);

        return view('manage.assignments.edit', compact('assignment', 'courses'));
    }

    public function update(Request $request, Assignment $assignment)
    {
        $this->ensureCanTeach($assignment->course);
        $data = $this->validated($request);

        if ($request->hasFile('attachment')) {
            $data['attachment_path'] = $request->file('attachment')->store('assignments', 'public');
        }

        $assignment->update($data);

        return redirect()->route('manage.assignments.index')->with('success', "Assignment \"{$assignment->title}\" updated.");
    }

    public function destroy(Assignment $assignment)
    {
        $this->ensureCanTeach($assignment->course);
        $assignment->delete();

        return redirect()->route('manage.assignments.index')->with('success', 'Assignment deleted.');
    }

    public function grade(Request $request, AssignmentSubmission $submission)
    {
        $this->ensureCanTeach($submission->assignment->course);

        $data = $request->validate([
            'marks_obtained' => ['required', 'integer', 'min:0', 'max:'.$submission->assignment->total_marks],
            'feedback' => ['nullable', 'string', 'max:2000'],
        ]);

        $submission->update([
            ...$data,
            'status' => 'graded',
            'graded_by' => auth()->id(),
            'graded_at' => now(),
        ]);

        $submission->student?->notify(new AssignmentGraded($submission));

        return back()->with('success', "Submission graded: {$data['marks_obtained']}/{$submission->assignment->total_marks}.");
    }

    protected function validated(Request $request): array
    {
        $courseIds = $this->teachableCourses()->pluck('id')->all();

        return $request->validate([
            'course_id' => ['required', 'integer', 'in:'.implode(',', $courseIds ?: [0])],
            'title' => ['required', 'string', 'max:180'],
            'description' => ['nullable', 'string'],
            'instructions' => ['nullable', 'string'],
            'total_marks' => ['required', 'integer', 'min:1', 'max:1000'],
            'due_date' => ['nullable', 'date'],
            'status' => ['required', 'in:draft,published,closed'],
            'attachment' => ['nullable', 'file', 'mimes:pdf,doc,docx,zip,png,jpg', 'max:20480'],
        ]);
    }
}
