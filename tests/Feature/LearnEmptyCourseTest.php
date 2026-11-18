<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A course with no published lessons must not dead-end an enrolled student.
 *
 * That is the NORMAL state right after a course is created — Course
 * Management's own success message is "Now add modules and lessons". But
 * LearnController::index() aborted 404, so a student who had paid, been
 * verified and enrolled clicked "Continue learning" and got a bare
 * "Page not found" with no explanation and no way back (reported 2026-08-19
 * against a freshly created Diploma course).
 */
class LearnEmptyCourseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function enrolledStudentFor(Course $course): User
    {
        $user = User::factory()->create();
        $user->assignRole('student');

        \App\Models\Student::create([
            'user_id' => $user->id,
            'student_code' => 'SRN'.\Illuminate\Support\Str::random(6),
            'status' => 'active',
            'enrolled_at' => now(),
        ]);

        Enrollment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'status' => 'active',
            'progress_percentage' => 0,
            'enrolled_at' => now(),
        ]);

        return $user;
    }

    public function test_an_enrolled_student_is_sent_back_with_an_explanation_not_a_404(): void
    {
        $course = Course::create([
            'title' => 'Brand New Course',
            'slug' => 'brand-new-course',
            'price' => 28000,
            'level' => 'beginner',
            'status' => 'published',
            'published_at' => now(),
        ]);

        $student = $this->enrolledStudentFor($course);

        $response = $this->actingAs($student)->get(route('student.learn', $course));

        $response->assertRedirect(route('student.courses'));
        // 'status', not 'info' — components/flash.blade.php renders only
        // success / error / status, so any other key would show nothing.
        $response->assertSessionHas('status');
        $this->assertStringContainsString(
            'Brand New Course',
            (string) session('status'),
            'The message must name the course so the student knows which one is still empty.',
        );
    }

    /** A course that DOES have a lesson still jumps straight into it. */
    public function test_a_course_with_a_published_lesson_still_opens_normally(): void
    {
        $course = Course::create([
            'title' => 'Ready Course',
            'slug' => 'ready-course',
            'price' => 28000,
            'level' => 'beginner',
            'status' => 'published',
            'published_at' => now(),
        ]);

        $lesson = Lesson::create([
            'course_id' => $course->id,
            'title' => 'Lesson One',
            'slug' => 'lesson-one',
            'sort_order' => 1,
            'status' => 'published',
        ]);

        $student = $this->enrolledStudentFor($course);

        $this->actingAs($student)
            ->get(route('student.learn', $course))
            ->assertRedirect(route('student.learn.lesson', [$course, $lesson]));
    }

    /** Someone not enrolled is still refused — the fix must not open access up. */
    public function test_a_non_enrolled_user_is_still_refused(): void
    {
        $course = Course::create([
            'title' => 'Locked Course',
            'slug' => 'locked-course',
            'price' => 28000,
            'level' => 'beginner',
            'status' => 'published',
            'published_at' => now(),
        ]);

        $stranger = User::factory()->create();
        $stranger->assignRole('student');

        $this->actingAs($stranger)
            ->get(route('student.learn', $course))
            ->assertForbidden();
    }
}
