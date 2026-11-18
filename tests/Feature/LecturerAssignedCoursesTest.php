<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\Course;
use App\Models\Exam;
use App\Models\User;
use App\Services\LecturerCourses;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A lecturer sees the exams and assignments of the courses given to them in
 * User Management, whoever wrote them (2026-10-25). Owner: "lecturer add krnakot
 * saha eka user management eken edit kranakot eyta course type eka dann e course
 * type ekata adala exam and assignement admin ho wena lecturer kenk dammath
 * penwann one". See App\Services\LecturerCourses.
 */
class LecturerAssignedCoursesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $lecturer;

    private Course $given;

    private Course $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->admin = User::where('email', 'admin@etec.lk')->firstOrFail();

        // A lecturer who owns no course — like the one in the owner's screenshot.
        $this->lecturer = User::factory()->create(['name' => 'Charith Lecturer']);
        $this->lecturer->assignRole('lecturer');

        $this->given = $this->course('Diploma in Digital Marketing', $this->admin);
        $this->other = $this->course('Other Course', User::where('email', 'lecturer@etec.lk')->firstOrFail());
    }

    private function course(string $title, User $owner): Course
    {
        return Course::create([
            'title' => $title, 'slug' => Str::slug($title).'-'.Str::random(4), 'price' => 28000,
            'currency' => 'LKR', 'level' => 'beginner', 'status' => 'published', 'lecturer_id' => $owner->id,
        ]);
    }

    private function exam(Course $course, string $title, User $by): Exam
    {
        return Exam::create([
            'course_id' => $course->id, 'lecturer_id' => $by->id, 'title' => $title, 'type' => 'quiz',
            'total_marks' => 5, 'pass_marks' => 2, 'duration_minutes' => 60, 'is_published' => true,
        ]);
    }

    private function assignment(Course $course, string $title, User $by): Assignment
    {
        return Assignment::create([
            'course_id' => $course->id, 'lecturer_id' => $by->id, 'title' => $title,
            'total_marks' => 100, 'status' => 'published',
        ]);
    }

    private function give(array $courses): void
    {
        $this->actingAs($this->admin)
            ->patch(route('users.lecturer-courses', $this->lecturer), ['course_ids' => array_map(fn ($c) => $c->id, $courses)])
            ->assertSessionHas('success');
    }

    // ----- the owner's case -------------------------------------------------------

    public function test_a_lecturer_sees_the_exams_of_a_course_given_to_them_whoever_wrote_them(): void
    {
        $byAdmin = $this->exam($this->given, 'Digital Marketing Fundamentals', $this->admin);
        $byOtherLecturer = $this->exam($this->given, 'Second Paper', User::where('email', 'lecturer@etec.lk')->firstOrFail());
        $notTheirs = $this->exam($this->other, 'Not Their Paper', $this->admin);

        $this->actingAs($this->lecturer)->get(route('manage.exams.index'))
            ->assertOk()->assertSee('No exams yet');

        $this->give([$this->given]);

        $this->actingAs($this->lecturer)->get(route('manage.exams.index'))
            ->assertOk()
            ->assertSee('Digital Marketing Fundamentals')
            ->assertSee('Second Paper')
            ->assertDontSee('Not Their Paper');

        $this->actingAs($this->lecturer)->get(route('manage.exams.results', $byAdmin))->assertOk();
        $this->actingAs($this->lecturer)->get(route('manage.exams.edit', $byOtherLecturer))->assertOk();
        $this->actingAs($this->lecturer)->get(route('manage.exams.results', $notTheirs))->assertForbidden();
        $this->actingAs($this->lecturer)->get(route('manage.exams.edit', $notTheirs))->assertForbidden();
    }

    public function test_and_the_assignments(): void
    {
        $theirs = $this->assignment($this->given, 'Case Study One', $this->admin);
        $notTheirs = $this->assignment($this->other, 'Someone Elses Work', $this->admin);

        $this->give([$this->given]);

        $this->actingAs($this->lecturer)->get(route('manage.assignments.index'))
            ->assertOk()->assertSee('Case Study One')->assertDontSee('Someone Elses Work');

        $this->actingAs($this->lecturer)->get(route('manage.assignments.show', $theirs))->assertOk();
        $this->actingAs($this->lecturer)->get(route('manage.assignments.show', $notTheirs))->assertForbidden();
    }

    public function test_they_can_add_an_exam_to_a_given_course_but_not_another(): void
    {
        $this->give([$this->given]);

        $paper = ['title' => 'New Paper', 'type' => 'quiz', 'total_marks' => 10, 'pass_marks' => 5, 'duration_minutes' => 30];

        $this->actingAs($this->lecturer)->post(route('manage.exams.store'), $paper + ['course_id' => $this->given->id])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->lecturer)->post(route('manage.exams.store'), $paper + ['course_id' => $this->other->id])
            ->assertSessionHasErrors('course_id');

        $this->assertSame(1, Exam::where('title', 'New Paper')->count());
    }

    public function test_taking_a_course_away_takes_its_exams_away(): void
    {
        $exam = $this->exam($this->given, 'Digital Marketing Fundamentals', $this->admin);

        $this->give([$this->given]);
        $this->actingAs($this->lecturer)->get(route('manage.exams.edit', $exam))->assertOk();

        $this->give([]);
        $this->actingAs($this->lecturer)->get(route('manage.exams.edit', $exam))->assertForbidden();
    }

    /** Only exams and assignments: the course itself stays with its own lecturer. */
    public function test_the_course_itself_is_not_handed_over(): void
    {
        $this->give([$this->given]);

        $this->actingAs($this->lecturer)->get(route('manage.courses.edit', $this->given))->assertForbidden();
    }

    public function test_their_own_courses_still_show_without_being_given(): void
    {
        $owner = User::where('email', 'lecturer@etec.lk')->firstOrFail();
        $this->exam($this->other, 'Own Course Paper', $owner);

        $this->actingAs($owner)->get(route('manage.exams.index'))->assertOk()->assertSee('Own Course Paper');
    }

    public function test_the_lecturer_dashboard_counts_the_given_courses_exams(): void
    {
        $this->exam($this->given, 'Digital Marketing Fundamentals', $this->admin);
        $this->give([$this->given]);

        $stats = $this->actingAs($this->lecturer)->get(route('lecturer.dashboard'))->assertOk()->viewData('stats');

        $this->assertSame(1, $stats['exams']);
    }

    // ----- User Management ----------------------------------------------------------

    public function test_an_admin_gives_courses_when_creating_a_lecturer(): void
    {
        $this->actingAs($this->admin)->get(route('users.create'))
            ->assertOk()->assertSee('Courses this lecturer teaches')->assertSee('Diploma in Digital Marketing');

        $this->actingAs($this->admin)->post(route('users.store'), [
            'name' => 'New Lecturer', 'email' => 'new.lecturer@example.com', 'role' => 'lecturer',
            'password' => 'Secret-pass-123', 'password_confirmation' => 'Secret-pass-123', 'status' => 'active',
            'course_ids' => [$this->given->id],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $new = User::where('email', 'new.lecturer@example.com')->firstOrFail();
        $this->assertSame([$this->given->id], LecturerCourses::assignedIds($new->id));
    }

    public function test_courses_ticked_for_another_role_are_not_saved(): void
    {
        $this->actingAs($this->admin)->post(route('users.store'), [
            'name' => 'New Accountant', 'email' => 'new.accountant@example.com', 'role' => 'accountant',
            'password' => 'Secret-pass-123', 'password_confirmation' => 'Secret-pass-123', 'status' => 'active',
            'course_ids' => [$this->given->id],
        ])->assertSessionHasNoErrors();

        $this->assertSame([], LecturerCourses::assignedIds(User::where('email', 'new.accountant@example.com')->value('id')));
    }

    public function test_the_edit_page_shows_and_saves_the_lecturers_courses(): void
    {
        $this->give([$this->given]);

        $this->actingAs($this->admin)->get(route('users.edit', $this->lecturer))
            ->assertOk()
            ->assertSee('Courses this lecturer teaches')
            ->assertSee('Diploma in Digital Marketing')
            ->assertSee('Other Course');

        $this->give([$this->other]);
        $this->assertSame([$this->other->id], LecturerCourses::assignedIds($this->lecturer->id), 'the list is replaced');
    }

    public function test_courses_are_only_given_to_lecturers(): void
    {
        $accountant = User::where('email', 'accountant@etec.lk')->firstOrFail();

        $this->actingAs($this->admin)->get(route('users.edit', $accountant))
            ->assertOk()->assertDontSee('Courses this lecturer teaches');

        $this->actingAs($this->admin)
            ->patch(route('users.lecturer-courses', $accountant), ['course_ids' => [$this->given->id]])
            ->assertSessionHas('error');

        $this->assertSame([], LecturerCourses::assignedIds($accountant->id));
    }

    public function test_a_lecturer_cannot_give_themselves_courses(): void
    {
        $this->actingAs($this->lecturer)
            ->patch(route('users.lecturer-courses', $this->lecturer), ['course_ids' => [$this->given->id]])
            ->assertForbidden();
    }

    public function test_the_table_is_added_by_the_first_screen_that_needs_it(): void
    {
        $this->assertFalse(Schema::hasTable('course_lecturers'), 'guard: a database from before');

        $this->actingAs($this->admin)->get(route('users.create'))->assertOk();

        $this->assertTrue(Schema::hasTable('course_lecturers'));
    }

    // ----- Opens at / Closes at -----------------------------------------------------

    public function test_the_exam_settings_show_the_time_not_only_the_date(): void
    {
        $exam = $this->exam($this->given, 'Timed Paper', $this->admin);
        $exam->update(['start_at' => '2026-10-30T20:08', 'end_at' => '2026-10-30T21:30']);

        $this->actingAs($this->admin)->get(route('manage.exams.edit', $exam))
            ->assertOk()
            ->assertSee('value="2026-10-30T20:08"', false)
            ->assertSee('Opens Fri, 30 Oct 2026 at 8:08 PM')
            ->assertSee('Closes Fri, 30 Oct 2026 at 9:30 PM');
    }
}
