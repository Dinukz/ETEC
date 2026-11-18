<?php

namespace App\Http\Controllers\Manage\Concerns;

use App\Models\Course;
use App\Services\LecturerCourses;

/**
 * Shared authorization for the course-management area: lecturers may only
 * touch their own courses; admins and super admins may touch any.
 */
trait ManagesCourses
{
    protected function canManageAll(): bool
    {
        return auth()->user()->hasAnyRole(['super_admin', 'admin']);
    }

    protected function ensureCanManage(Course $course): void
    {
        abort_unless($this->canManageAll() || $course->lecturer_id === auth()->id(), 403);
    }

    /** Courses the current user is allowed to manage. */
    protected function manageableCourses()
    {
        return Course::query()->when(! $this->canManageAll(), fn ($q) => $q->where('lecturer_id', auth()->id()));
    }

    /**
     * Courses whose EXAMS and ASSIGNMENTS the current user may run (2026-10-25):
     * every course for an admin; for a lecturer, their own plus the courses
     * assigned to them in User Management — so an exam an admin or another
     * lecturer wrote shows to everyone who teaches that course. Only the exam
     * and assignment screens use this. See App\Services\LecturerCourses.
     */
    protected function teachableCourses()
    {
        return Course::query()->when(
            ! $this->canManageAll(),
            fn ($q) => $q->whereIn('id', LecturerCourses::teachableIds((int) auth()->id()) ?: [0]),
        );
    }

    protected function ensureCanTeach(?Course $course): void
    {
        abort_unless(
            $this->canManageAll()
                || ($course && in_array((int) $course->id, LecturerCourses::teachableIds((int) auth()->id()), true)),
            403,
        );
    }
}
