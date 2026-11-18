<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The Add Student form must pass each course's defaults through to the page so the JS can apply them. */
class StudentRegistrationDefaultsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    /** There is no CourseFactory in this project — courses come from the seeder. */
    private function publishedCourse(string $slug, array $attributes = []): Course
    {
        return Course::create(array_merge([
            'title' => ucwords(str_replace('-', ' ', $slug)),
            'slug' => $slug,
            'description' => 'Test course.',
            'price' => 28000,
            'currency' => 'LKR',
            'level' => 'beginner',
            'status' => 'published',
            'published_at' => now(),
        ], $attributes));
    }

    public function test_create_page_exposes_course_defaults_for_the_auto_fill_script(): void
    {
        $consultant = User::where('email', 'consultant@etec.lk')->firstOrFail();
        $course = Course::where('status', 'published')->firstOrFail();

        $response = $this->actingAs($consultant)->get(route('consultant.students.create'));

        $response->assertOk();

        // @js() escapes quotes as " inside JSON.parse(...), so decode the
        // embedded blob rather than string-match the raw HTML.
        preg_match('/courseDefaults:\s*JSON\.parse\(\'(.*?)\'\)/', $response->getContent(), $matches);
        $this->assertNotEmpty($matches, 'courseDefaults JSON blob not found on the page.');

        $decoded = json_decode(json_decode('"'.$matches[1].'"'), true);
        $this->assertSame($course->default_language, $decoded[(string) $course->id]['language']);
        $this->assertSame($course->default_class_type, $decoded[(string) $course->id]['class_type']);
        $this->assertSame($course->default_course_type, $decoded[(string) $course->id]['course_type']);

        $response->assertSee('applyCourseDefaults');
    }

    /**
     * Those three columns are optional and nearly every course on production
     * still has them blank — the backfill migration meant to seed them cannot
     * run there. Without a fallback the map hands the form empty strings and
     * picking a course visibly does nothing, which is what was reported.
     */
    public function test_a_course_with_no_defaults_of_its_own_falls_back_to_the_house_baseline(): void
    {
        $consultant = User::where('email', 'consultant@etec.lk')->firstOrFail();

        $bare = $this->publishedCourse('bare-course', [
            'default_language' => null,
            'default_class_type' => null,
            'default_course_type' => null,
        ]);

        $response = $this->actingAs($consultant)->get(route('consultant.students.create'));

        preg_match('/courseDefaults:\s*JSON\.parse\(\'(.*?)\'\)/', $response->getContent(), $matches);
        $decoded = json_decode(json_decode('"'.$matches[1].'"'), true);

        $this->assertSame('Sinhala', $decoded[(string) $bare->id]['language']);
        $this->assertSame('Online', $decoded[(string) $bare->id]['class_type']);
        $this->assertSame('Group', $decoded[(string) $bare->id]['course_type']);
    }

    /** An explicit per-course choice must always beat the baseline. */
    public function test_a_courses_own_defaults_are_never_replaced_by_the_baseline(): void
    {
        $consultant = User::where('email', 'consultant@etec.lk')->firstOrFail();

        $custom = $this->publishedCourse('custom-course', [
            'default_language' => 'Tamil',
            'default_class_type' => 'Physical',
            'default_course_type' => 'Individual',
        ]);

        $response = $this->actingAs($consultant)->get(route('consultant.students.create'));

        preg_match('/courseDefaults:\s*JSON\.parse\(\'(.*?)\'\)/', $response->getContent(), $matches);
        $decoded = json_decode(json_decode('"'.$matches[1].'"'), true);

        $this->assertSame('Tamil', $decoded[(string) $custom->id]['language']);
        $this->assertSame('Physical', $decoded[(string) $custom->id]['class_type']);
        $this->assertSame('Individual', $decoded[(string) $custom->id]['course_type']);
    }
}
