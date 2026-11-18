<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\Enrollment;
use Illuminate\Http\Request;

class AssignmentController extends Controller
{
    protected function enrolledCourseIds()
    {
        return Enrollment::where('user_id', auth()->id())
            ->whereIn('status', ['active', 'completed'])
            ->pluck('course_id');
    }

    public function index()
    {
        $assignments = Assignment::query()
            ->with('course')
            ->whereIn('course_id', $this->enrolledCourseIds())
            ->whereIn('status', ['published', 'closed'])
            ->latest()
            ->paginate(10);

        $submissions = AssignmentSubmission::where('user_id', auth()->id())
            ->get()
            ->keyBy('assignment_id');

        return view('student.assignments.index', compact('assignments', 'submissions'));
    }

    public function show(Assignment $assignment)
    {
        abort_unless(
            $this->enrolledCourseIds()->contains($assignment->course_id) && $assignment->status !== 'draft',
            403,
        );

        $submission = AssignmentSubmission::where('assignment_id', $assignment->id)
            ->where('user_id', auth()->id())
            ->first();

        return view('student.assignments.show', compact('assignment', 'submission'));
    }

    public function submit(Request $request, Assignment $assignment)
    {
        abort_unless($this->enrolledCourseIds()->contains($assignment->course_id), 403);

        if ($assignment->status !== 'published') {
            return back()->with('error', 'This assignment is no longer accepting submissions.');
        }

        $existing = AssignmentSubmission::where('assignment_id', $assignment->id)->where('user_id', auth()->id())->first();
        if ($existing && $existing->status === 'graded') {
            return back()->with('error', 'Your submission has already been graded and can no longer be changed.');
        }

        $data = $request->validate([
            'submission_text' => ['nullable', 'required_without:file', 'string', 'max:20000'],
            'file' => ['nullable', 'required_without:submission_text', 'file', 'mimes:pdf,doc,docx,zip,png,jpg,jpeg', 'max:20480'],
        ]);

        $payload = [
            'submission_text' => $data['submission_text'] ?? null,
            'submitted_at' => now(),
            'status' => $assignment->due_date && now()->gt($assignment->due_date) ? 'late' : 'submitted',
        ];

        if ($request->hasFile('file')) {
            $payload['file_path'] = $request->file('file')->store('submissions', 'public');
        }

        AssignmentSubmission::updateOrCreate(
            ['assignment_id' => $assignment->id, 'user_id' => auth()->id()],
            $payload,
        );

        return redirect()->route('student.assignments.show', $assignment)->with('success', 'Assignment submitted.');
    }
}
