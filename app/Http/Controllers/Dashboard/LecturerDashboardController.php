<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\AssignmentSubmission;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Exam;

class LecturerDashboardController extends Controller
{
    public function __invoke()
    {
        $lecturerId = auth()->id();

        $courseIds = Course::where('lecturer_id', $lecturerId)->pluck('id');

        // Exams and assignments also come from the courses assigned to this
        // lecturer in User Management (2026-10-25) — see LecturerCourses.
        $teachIds = \App\Services\LecturerCourses::teachableIds($lecturerId) ?: [0];
        $ofMine = fn ($q) => $q->where(fn ($w) => $w->where('lecturer_id', $lecturerId)->orWhereIn('course_id', $teachIds));

        $stats = [
            'courses' => $courseIds->count(),
            'published' => Course::where('lecturer_id', $lecturerId)->where('status', 'published')->count(),
            'students' => Enrollment::whereIn('course_id', $courseIds)->distinct('user_id')->count('user_id'),
            'pending_grading' => AssignmentSubmission::whereHas('assignment', $ofMine)
                ->where('status', 'submitted')->count(),
            'exams' => Exam::query()->tap($ofMine)->count(),
        ];

        $myCourses = Course::where('lecturer_id', $lecturerId)
            ->withCount('enrollments')
            ->latest()
            ->take(6)
            ->get();

        $pendingSubmissions = AssignmentSubmission::with(['assignment', 'student'])
            ->whereHas('assignment', $ofMine)
            ->where('status', 'submitted')
            ->latest('submitted_at')
            ->take(8)
            ->get();

        return view('dashboard.lecturer.index', compact('stats', 'myCourses', 'pendingSubmissions'));
    }
}
