<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⚠️ A COURSE YOU REGISTERED FOR MUST BE VISIBLE BEFORE IT IS PAID FOR.
 *
 * Reported 2026-09-10: "thaman register una cource eka penwan na" — the course
 * I registered for does not show, for a consultant or a student.
 *
 * `DashboardController::courses()` filtered to ['active','completed'], but a
 * consultant-registered enrollment starts 'pending' and only becomes 'active'
 * when the accountant verifies the payment. Two manual steps sit in that gap,
 * so the page said "No courses yet" to someone who had just registered — and
 * checkout redirects onto this very page saying "your payment is pending
 * verification".
 *
 * ⚠️ THE HALF OF THIS THAT MATTERS MOST IS THE SECOND HALF. Listing a pending
 * course must NOT grant access to its content. Student\LearnController keeps
 * its own ['active','completed'] gate, and the tests below fail if anyone
 * "helpfully" widens it to match the list.
 */
class PendingEnrollmentIsVisibleTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    private Course $course;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->student = User::factory()->create();
        $this->student->syncRoles(['student']);

        $this->course = Course::where('status', 'published')->firstOrFail();
    }

    private function enrol(string $status): Enrollment
    {
        return Enrollment::create([
            'user_id' => $this->student->id,
            'course_id' => $this->course->id,
            'status' => $status,
            'enrolled_at' => now(),
        ]);
    }

    // ----- it is listed ------------------------------------------------------

    public function test_a_pending_enrollment_is_listed(): void
    {
        $this->enrol('pending');

        $this->actingAs($this->student)
            ->get(route('student.courses'))
            ->assertOk()
            ->assertSee($this->course->title)
            ->assertDontSee('No courses yet');
    }

    /** And it says why it is not openable yet, rather than looking broken. */
    public function test_it_is_labelled_as_waiting_for_verification(): void
    {
        $this->enrol('pending');

        $this->actingAs($this->student)
            ->get(route('student.courses'))
            ->assertOk()
            ->assertSee('Pending payment verification')
            ->assertDontSee('Continue learning');
    }

    public function test_a_verified_enrollment_still_offers_the_course(): void
    {
        $this->enrol('active');

        $this->actingAs($this->student)
            ->get(route('student.courses'))
            ->assertOk()
            ->assertSee('Continue learning')
            ->assertDontSee('Pending payment verification');
    }

    /** A consultant reaches the same page by the same route — one query, no role filter. */
    public function test_a_consultant_sees_their_own_pending_course_too(): void
    {
        $this->student->syncRoles(['consultant']);
        $this->enrol('pending');

        $this->actingAs($this->student)
            ->get(route('student.courses'))
            ->assertOk()
            ->assertSee($this->course->title);
    }

    /** Somebody else's registration is still nobody else's business. */
    public function test_another_persons_enrollment_is_not_listed(): void
    {
        $other = User::factory()->create();
        $other->syncRoles(['student']);

        Enrollment::create([
            'user_id' => $other->id,
            'course_id' => $this->course->id,
            'status' => 'pending',
            'enrolled_at' => now(),
        ]);

        $this->actingAs($this->student)
            ->get(route('student.courses'))
            ->assertOk()
            ->assertSee('No courses yet');
    }

    // ----- ...but it is NOT openable ----------------------------------------

    /**
     * ⚠️ THE GUARD. Listing is not access. If this ever passes, an unpaid
     * student can watch the whole course.
     */
    public function test_a_pending_enrollment_cannot_open_the_course(): void
    {
        $this->enrol('pending');

        $this->actingAs($this->student)
            ->get(route('student.learn', $this->course))
            ->assertForbidden();
    }

    /**
     * The other side of the gate. Asserted as "not refused" rather than 200:
     * LearnController::index() is an entry point that forwards to the first
     * lesson, so success here is a redirect INTO the course — the opposite of
     * the 403 a pending enrollment gets.
     */
    public function test_a_verified_enrollment_is_not_refused(): void
    {
        $this->enrol('active');

        $response = $this->actingAs($this->student)->get(route('student.learn', $this->course));

        $response->assertStatus(302);

        $this->assertStringContainsString(
            '/student/learn/'.$this->course->slug,
            $response->headers->get('Location') ?? '',
            'a verified student is forwarded into the course, not turned away',
        );
    }

    /**
     * ⚠️ THE SAFETY NET. A course paid for with NO enrollment row behind it is
     * still listed — the payment is the harder fact, and the page must not tell
     * somebody who registered that they registered for nothing.
     */
    public function test_a_paid_course_shows_even_with_no_enrollment_row(): void
    {
        \App\Models\Payment::create([
            'payment_code' => 'PYM'.\Illuminate\Support\Str::random(8),
            'user_id' => $this->student->id,
            'course_id' => $this->course->id,
            'amount' => 39000,
            'currency' => 'LKR',
            'method' => 'bank_transfer',
            'payment_type' => 'full',
            'status' => 'pending',
            'paid_at' => now(),
        ]);

        $this->assertSame(
            0,
            Enrollment::where('user_id', $this->student->id)->count(),
            'guard: there is deliberately no enrollment row',
        );

        $this->actingAs($this->student)
            ->get(route('student.courses'))
            ->assertOk()
            ->assertSee($this->course->title)
            ->assertDontSee('No courses yet');
    }

    /** It must not double up when the enrollment does exist. */
    public function test_a_course_is_not_listed_twice(): void
    {
        $this->enrol('active');

        \App\Models\Payment::create([
            'payment_code' => 'PYM'.\Illuminate\Support\Str::random(8),
            'user_id' => $this->student->id,
            'course_id' => $this->course->id,
            'amount' => 39000,
            'currency' => 'LKR',
            'method' => 'bank_transfer',
            'payment_type' => 'full',
            'status' => 'verified',
            'paid_at' => now(),
        ]);

        $unlinked = $this->actingAs($this->student)
            ->get(route('student.courses'))
            ->viewData('unlinked');

        $this->assertCount(0, $unlinked, 'the enrollment already covers this course');
    }

    /**
     * The student DASHBOARD is deliberately left alone — it renders no course
     * list, and a pending row at 0% would drag "Avg. Progress" down for a
     * course nobody has started.
     */
    public function test_the_dashboard_average_ignores_a_pending_course(): void
    {
        $active = $this->enrol('active');
        $active->update(['progress_percentage' => 80]);

        // A DIFFERENT course — enrollments carry unique(user_id, course_id).
        $second = Course::create([
            'title' => 'Second Course',
            'slug' => 'second-course-'.\Illuminate\Support\Str::random(6),
            'description' => 'x',
            'price' => $this->course->price,
            'currency' => 'LKR',
            'status' => 'published',
            'lecturer_id' => $this->course->lecturer_id,
            'published_at' => now(),
        ]);

        Enrollment::create([
            'user_id' => $this->student->id,
            'course_id' => $second->id,
            'status' => 'pending',
            'enrolled_at' => now(),
        ]);

        $stats = $this->actingAs($this->student)
            ->get(route('student.dashboard'))
            ->assertOk()
            ->viewData('stats');

        $this->assertSame(1, $stats['active'], 'the pending course is not an active course');
        $this->assertSame(80, $stats['avg_progress'], 'and it does not halve the average');
    }
}
