<?php

namespace Tests\Feature;

use App\Models\Certificate;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\User;
use App\Services\EnrollmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LmsFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_payment_verification_activates_enrollment_and_notifies(): void
    {
        $student = User::where('email', 'student@etec.lk')->firstOrFail();
        $course = Course::where('status', 'published')->firstOrFail();
        app(EnrollmentService::class)->enroll($student, $course);

        $payment = Payment::where('user_id', $student->id)->where('status', 'pending')->firstOrFail();
        $accountant = User::where('email', 'accountant@etec.lk')->firstOrFail();

        $this->actingAs($accountant)->patch(route('payments.verify', $payment), ['remarks' => 'ok'])->assertRedirect();

        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'verified']);
        $this->assertDatabaseHas('enrollments', ['user_id' => $student->id, 'course_id' => $course->id, 'status' => 'active']);
        $this->assertDatabaseHas('company_revenues', ['payment_id' => $payment->id]);
        $this->assertSame(1, $student->fresh()->notifications()->count(), 'student should receive a payment-verified notification');
    }

    public function test_completing_all_lessons_issues_a_certificate(): void
    {
        $student = User::where('email', 'student@etec.lk')->firstOrFail();
        $course = Course::firstOrFail();

        // Ensure at least one published lesson exists.
        if ($course->lessons()->where('status', 'published')->count() === 0) {
            $course->lessons()->create(['title' => 'Only lesson', 'slug' => 'only-lesson', 'status' => 'published', 'sort_order' => 1]);
        }

        $enrollment = Enrollment::firstOrCreate(
            ['user_id' => $student->id, 'course_id' => $course->id],
            ['status' => 'active', 'progress_percentage' => 0, 'enrolled_at' => now()],
        );
        $enrollment->update(['status' => 'active']);

        foreach ($course->lessons()->where('status', 'published')->get() as $lesson) {
            $this->actingAs($student)->post(route('student.learn.complete', [$course, $lesson]))->assertRedirect();
        }

        $this->assertDatabaseHas('enrollments', ['id' => $enrollment->id, 'status' => 'completed']);
        $this->assertDatabaseHas('certificates', ['user_id' => $student->id, 'course_id' => $course->id, 'status' => 'issued']);
    }
}
