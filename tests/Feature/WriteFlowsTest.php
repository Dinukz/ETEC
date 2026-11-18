<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Exam;
use App\Models\Hall;
use App\Models\HallBooking;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Exercises the create/save paths of the new modules end to end. */
class WriteFlowsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Checkout now requires a slip; keep it out of the real disk.
        \Illuminate\Support\Facades\Storage::fake('public');
        $this->seed();
    }

    public function test_staff_can_book_a_hall(): void
    {
        $admin = User::where('email', 'admin@etec.lk')->firstOrFail();
        $hall = Hall::firstOrFail();

        $response = $this->actingAs($admin)->post(route('bookings.store'), [
            'hall_id' => $hall->id,
            'booking_date' => now()->addDay()->toDateString(),
            'start_time' => '09:00',
            'end_time' => '11:00',
            'reason' => 'Team meeting',
        ]);

        $response->assertRedirect(route('bookings.index'));
        $this->assertDatabaseHas('hall_bookings', ['hall_id' => $hall->id, 'reason' => 'Team meeting', 'status' => 'approved']);
    }

    public function test_overlapping_booking_is_rejected(): void
    {
        $admin = User::where('email', 'admin@etec.lk')->firstOrFail();
        $hall = Hall::firstOrFail();
        $date = now()->addDay()->toDateString();

        HallBooking::create(['hall_id' => $hall->id, 'user_id' => $admin->id, 'booking_date' => $date, 'start_time' => '09:00', 'end_time' => '11:00', 'status' => 'approved']);

        $this->actingAs($admin)->post(route('bookings.store'), [
            'hall_id' => $hall->id, 'booking_date' => $date, 'start_time' => '10:00', 'end_time' => '12:00', 'reason' => 'Clash',
        ])->assertSessionHas('error');

        $this->assertDatabaseMissing('hall_bookings', ['reason' => 'Clash']);
    }

    public function test_lecturer_can_create_course_module_and_lesson(): void
    {
        $lecturer = User::where('email', 'lecturer@etec.lk')->firstOrFail();

        $this->actingAs($lecturer)->post(route('manage.courses.store'), [
            'title' => 'Test Automation 101', 'price' => 15000, 'status' => 'published',
        ])->assertRedirect();

        $course = Course::where('title', 'Test Automation 101')->firstOrFail();
        $this->assertSame($lecturer->id, $course->lecturer_id);

        $this->actingAs($lecturer)->post(route('manage.modules.store', $course), ['title' => 'Module 1'])->assertRedirect();
        $module = $course->modules()->firstOrFail();

        $this->actingAs($lecturer)->post(route('manage.lessons.store', $course), [
            'title' => 'Intro lesson', 'module_id' => $module->id, 'status' => 'published', 'video_provider' => 'youtube', 'video_url' => 'https://youtube.com/watch?v=abc',
        ])->assertRedirect();

        $this->assertDatabaseHas('lessons', ['course_id' => $course->id, 'title' => 'Intro lesson']);
    }

    /**
     * The Level <select> defaults to a blank "All levels" placeholder
     * (_form.blade.php), which submits as an EXPLICIT empty string — not an
     * omitted field. ConvertEmptyStringsToNull turns that into null, and
     * 'level' being 'nullable' lets it through validation straight into the
     * INSERT. A column DEFAULT only ever applies when a column is omitted
     * entirely; an explicit null against a NOT NULL column is always
     * rejected, with or without a default — true in both MySQL and sqlite.
     * Reported 2026-08-19: "Column 'level' cannot be null" on every course
     * creation via the real form. `title => ..., price => ...` alone (no
     * 'level' key at all, as the OTHER course test above does) would never
     * have caught this — that path never sends the key in the first place.
     */
    public function test_leaving_the_level_field_blank_still_creates_the_course(): void
    {
        $lecturer = User::where('email', 'lecturer@etec.lk')->firstOrFail();

        $this->actingAs($lecturer)->post(route('manage.courses.store'), [
            'title' => 'Blank Level Course', 'price' => 15000, 'status' => 'published', 'level' => '',
        ])->assertRedirect();

        $course = Course::where('title', 'Blank Level Course')->firstOrFail();
        $this->assertSame('beginner', $course->level, 'A blank Level selection falls back to beginner rather than crashing.');
    }

    public function test_lecturer_can_build_exam_with_question(): void
    {
        $lecturer = User::where('email', 'lecturer@etec.lk')->firstOrFail();
        $course = Course::firstOrFail();

        $this->actingAs($lecturer)->post(route('manage.exams.store'), [
            'course_id' => $course->id, 'title' => 'Final Quiz', 'type' => 'quiz',
            'total_marks' => 20, 'pass_marks' => 10, 'duration_minutes' => 30,
        ])->assertRedirect();

        $exam = Exam::where('title', 'Final Quiz')->firstOrFail();

        $this->actingAs($lecturer)->post(route('manage.questions.store', $exam), [
            'question' => 'Is water wet?', 'type' => 'truefalse', 'correct_answer' => 'True', 'marks' => 10,
        ])->assertRedirect();

        $this->assertDatabaseHas('exam_questions', ['exam_id' => $exam->id, 'question' => 'Is water wet?']);
    }

    public function test_student_can_self_checkout_a_course(): void
    {
        $student = User::where('email', 'student@etec.lk')->firstOrFail();
        $course = Course::where('status', 'published')->firstOrFail();

        $this->actingAs($student)->post(route('checkout.store', $course), [
            'method' => 'bank_transfer', 'reference_number' => 'TXN-123',
            'receipt' => \Illuminate\Http\UploadedFile::fake()->create('slip.pdf', 8, 'application/pdf'),
        ])->assertRedirect(route('student.courses'));

        $this->assertDatabaseHas('enrollments', ['user_id' => $student->id, 'course_id' => $course->id, 'status' => 'pending']);
        $this->assertDatabaseHas('payments', ['user_id' => $student->id, 'course_id' => $course->id, 'status' => 'pending']);
    }

    public function test_admin_can_create_blog_post(): void
    {
        $admin = User::where('email', 'admin@etec.lk')->firstOrFail();

        $this->actingAs($admin)->post(route('cms.blogs.store'), [
            'title' => 'Hello World Post', 'content' => '<p>Body</p>', 'status' => 'published',
        ])->assertRedirect(route('cms.blogs.index'));

        $this->assertDatabaseHas('blogs', ['title' => 'Hello World Post', 'status' => 'published']);
    }
}
