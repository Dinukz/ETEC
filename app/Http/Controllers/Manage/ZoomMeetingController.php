<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Manage\Concerns\ManagesCourses;
use App\Models\ZoomMeeting;
use Illuminate\Http\Request;

class ZoomMeetingController extends Controller
{
    use ManagesCourses;

    public function index(Request $request)
    {
        $courseIds = $this->manageableCourses()->pluck('id');

        $meetings = ZoomMeeting::query()
            ->with('course')
            ->whereIn('course_id', $courseIds)
            ->when($request->filled('course_id'), fn ($q) => $q->where('course_id', $request->integer('course_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->orderByDesc('start_at')
            ->paginate(10)
            ->withQueryString();

        $courses = $this->manageableCourses()->orderBy('title')->get(['id', 'title']);

        return view('manage.zoom.index', compact('meetings', 'courses'));
    }

    public function create()
    {
        $courses = $this->manageableCourses()->orderBy('title')->get(['id', 'title']);

        return view('manage.zoom.create', compact('courses'));
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['lecturer_id'] = auth()->id();

        ZoomMeeting::create($data);

        return redirect()->route('manage.zoom.index')->with('success', 'Meeting scheduled. Enrolled students can see the join link.');
    }

    public function edit(ZoomMeeting $meeting)
    {
        $this->ensureCanManage($meeting->course);
        $courses = $this->manageableCourses()->orderBy('title')->get(['id', 'title']);

        return view('manage.zoom.edit', compact('meeting', 'courses'));
    }

    public function update(Request $request, ZoomMeeting $meeting)
    {
        $this->ensureCanManage($meeting->course);
        $meeting->update($this->validated($request));

        return redirect()->route('manage.zoom.index')->with('success', 'Meeting updated.');
    }

    public function destroy(ZoomMeeting $meeting)
    {
        $this->ensureCanManage($meeting->course);
        $meeting->update(['status' => 'cancelled']);

        return back()->with('success', 'Meeting cancelled.');
    }

    protected function validated(Request $request): array
    {
        $courseIds = $this->manageableCourses()->pluck('id')->all();

        return $request->validate([
            'course_id' => ['required', 'integer', 'in:'.implode(',', $courseIds ?: [0])],
            'title' => ['required', 'string', 'max:180'],
            'agenda' => ['nullable', 'string', 'max:2000'],
            'join_url' => ['required', 'url', 'max:255'],
            'start_url' => ['nullable', 'url', 'max:255'],
            'meeting_id' => ['nullable', 'string', 'max:50'],
            'passcode' => ['nullable', 'string', 'max:50'],
            'start_at' => ['required', 'date'],
            'duration_minutes' => ['required', 'integer', 'min:15', 'max:600'],
            'status' => ['required', 'in:scheduled,live,ended,cancelled'],
            'recording_url' => ['nullable', 'url', 'max:255'],
        ]);
    }
}
