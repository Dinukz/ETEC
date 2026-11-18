<?php

namespace Tests\Feature;

use App\Models\CommissionSetting;
use App\Models\Course;
use App\Services\CourseSchemaRepair;
use App\Support\CourseMoney;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The columns behind a course's own half payment / direct service income are
 * added by the first course-screen request (no CLI on live) — and until then
 * every course simply uses the standard, with no error (2026-10-22).
 *
 * ⚠️ The two numbered tests run in order on purpose: the second proves the
 * memo from the first is forgotten (tests/TestCase.php), so a fresh database
 * is never remembered as already repaired.
 */
class CourseSchemaRepairTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_1_the_columns_are_added_once(): void
    {
        $this->assertFalse(Schema::hasColumn('courses', 'direct_commission'), 'guard: a database from before');
        $this->assertFalse(CourseSchemaRepair::ready());

        $this->assertCount(2, CourseSchemaRepair::ensure());

        $this->assertTrue(Schema::hasColumn('courses', 'half_payment_amount'));
        $this->assertTrue(Schema::hasColumn('courses', 'direct_commission'));
        $this->assertSame([], CourseSchemaRepair::ensure(), 'nothing to do the second time');

        // LAST, so the memo is left set to "ready" for the next test to forget.
        $this->assertTrue(CourseSchemaRepair::ready());
    }

    public function test_2_a_fresh_database_is_not_remembered_as_repaired(): void
    {
        $this->assertFalse(Schema::hasColumn('courses', 'direct_commission'), 'guard: rolled back after the test above');
        $this->assertFalse(CourseSchemaRepair::ready());
    }

    public function test_without_the_columns_every_course_uses_the_standard(): void
    {
        $course = Course::where('status', 'published')->firstOrFail();
        $settings = CommissionSetting::current();

        $this->assertSame(18000.0, CourseMoney::halfPayment($course));
        $this->assertSame((float) $settings->direct_commission, CourseMoney::directCommission($course->id, $settings));
        $this->assertSame((float) $settings->direct_commission, CourseMoney::directCommission(null, $settings));
        $this->assertSame([], CourseMoney::coursesWithOwnDirect()->all());
    }

    public function test_the_course_screens_add_them(): void
    {
        $this->actingAs(\App\Models\User::where('email', 'admin@etec.lk')->firstOrFail())
            ->get(route('manage.courses.create'))
            ->assertOk();

        $this->assertTrue(Schema::hasColumn('courses', 'direct_commission'));
    }
}
