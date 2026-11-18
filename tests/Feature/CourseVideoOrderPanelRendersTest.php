<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\User;
use App\Services\LmsSchemaRepair;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The reorder panel has to be ON the page, not merely in the view data.
 *
 * Reported 2026-09-10 after the first upload: preview worked but "course wal
 * mulata enn one mokkd wage piliwela wens krana eka hadila na" — the ordering
 * was nowhere to be seen. The existing test only read viewData('orderLessons'),
 * which passes even when the markup never renders.
 */
class CourseVideoOrderPanelRendersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        LmsSchemaRepair::ensure();
    }

    private function admin(): User
    {
        return User::where('email', 'admin@etec.lk')->firstOrFail();
    }

    public function test_the_panel_renders_even_before_a_course_is_picked(): void
    {
        $this->actingAs($this->admin())
            ->get(route('manage.course-videos.index'))
            ->assertOk()
            ->assertSee('Lesson order')
            ->assertSee('order_course', false);
    }

    public function test_the_draggable_list_renders_once_a_course_is_picked(): void
    {
        $course = Course::where('status', 'published')->firstOrFail();

        $this->actingAs($this->admin())
            ->get(route('manage.course-videos.index', ['order_course' => $course->id]))
            ->assertOk()
            ->assertSee('id="lesson-order"', false)
            ->assertSee('draggable="true"', false)
            ->assertSee(route('manage.course-videos.reorder'), false);
    }
}
