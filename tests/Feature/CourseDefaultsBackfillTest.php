<?php

namespace Tests\Feature;

use App\Models\Course;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Add Student form's Language/Class Type/Course Type auto-fill reads each
 * course's default_language/default_class_type/default_course_type. No course
 * ever had these set (not seeded, never filled in by an admin), so selecting a
 * course silently auto-filled nothing. A migration backfills the common
 * baseline for anything still missing one.
 */
class CourseDefaultsBackfillTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_seeded_courses_have_registration_defaults_after_backfill(): void
    {
        $course = Course::first();

        $this->assertNotEmpty($course->default_language, 'Every course should have a default language after the backfill.');
        $this->assertNotEmpty($course->default_class_type, 'Every course should have a default class type after the backfill.');
        $this->assertNotEmpty($course->default_course_type, 'Every course should have a default course type after the backfill.');
    }

    public function test_backfill_does_not_override_a_courses_own_explicit_defaults(): void
    {
        $course = Course::first();
        $course->update(['default_language' => 'Tamil']);

        // Re-run just the backfill migration in isolation to confirm it only
        // fills blanks, never overwrites an explicit choice.
        (require database_path('migrations/2026_07_27_110000_backfill_course_registration_defaults.php'))->up();

        $this->assertSame('Tamil', $course->fresh()->default_language);
    }
}
