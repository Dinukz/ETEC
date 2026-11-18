<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\User;
use App\Services\LmsSchemaRepair;
use App\Services\VideoLibrary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ⚠️ TWO DOORS TO THE SAME FILES, AND THEY MUST STAY DIFFERENT.
 *
 * Asked for 2026-09-10: "Course videos walath e thiyen video eka load kragen
 * video eka ethanm balann option ekak hadal denn" — let an admin play a video
 * on the Course Videos screen itself — and "video wala mulata idn anthima wenak
 * videos wala than maru krnn puluwn wenn one... drag and drop".
 *
 * The student route demands an active enrollment and spends a viewing window.
 * An admin previewing their own course has neither and must not spend one. So
 * the preview is a separate route with its own gate, and these tests exist to
 * stop the two ever collapsing into each other:
 *
 *   - an admin previews with no enrollment, and NO student viewing is consumed
 *   - a student cannot reach the admin preview at all
 *   - both doors still return 206 for a Range request after the extraction
 */
class CourseVideoPreviewAndOrderTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Course $course;

    private string $folder;

    private string $relative;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        LmsSchemaRepair::ensure();

        $this->admin = User::where('email', 'admin@etec.lk')->firstOrFail();
        $this->course = Course::where('status', 'published')->firstOrFail();

        $this->folder = '__preview_test__'.Str::random(4);
        $dir = VideoLibrary::root().DIRECTORY_SEPARATOR.$this->folder;

        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        file_put_contents($dir.DIRECTORY_SEPARATOR.'clip.mp4', str_repeat('A', 2048));

        $this->relative = $this->folder.'/clip.mp4';
    }

    protected function tearDown(): void
    {
        $dir = VideoLibrary::root().DIRECTORY_SEPARATOR.$this->folder;

        if (is_dir($dir)) {
            foreach (glob($dir.DIRECTORY_SEPARATOR.'*') ?: [] as $leftover) {
                @unlink($leftover);
            }

            @rmdir($dir);
        }

        parent::tearDown();
    }

    // ----- the admin preview -------------------------------------------------

    public function test_an_admin_can_preview_without_being_enrolled(): void
    {
        $this->assertSame(
            0,
            Enrollment::where('user_id', $this->admin->id)->count(),
            'guard: the admin is deliberately not enrolled',
        );

        $this->actingAs($this->admin)
            ->get(route('manage.course-videos.preview', ['path' => $this->relative]))
            ->assertOk()
            ->assertHeader('Accept-Ranges', 'bytes')
            ->assertHeader('Content-Disposition', 'inline');
    }

    public function test_the_preview_supports_range_requests(): void
    {
        $this->actingAs($this->admin)
            ->withHeaders(['Range' => 'bytes=0-99'])
            ->get(route('manage.course-videos.preview', ['path' => $this->relative]))
            ->assertStatus(206)
            ->assertHeader('Content-Range', 'bytes 0-99/2048')
            ->assertHeader('Content-Length', '100');
    }

    /**
     * ⚠️ THE POINT OF A SEPARATE ROUTE. An admin checking a file must not spend
     * a student's one viewing.
     */
    public function test_previewing_consumes_no_student_viewing_window(): void
    {
        $lesson = Lesson::create([
            'course_id' => $this->course->id,
            'title' => 'Preview lesson',
            'slug' => 'preview-lesson-'.Str::random(5),
            'status' => 'published',
            'video_provider' => 'upload',
            'video_path' => $this->relative,
        ]);

        $before = LessonProgress::count();

        $this->actingAs($this->admin)
            ->get(route('manage.course-videos.preview', ['path' => $this->relative]))
            ->assertOk();

        $this->assertSame($before, LessonProgress::count(), 'no progress row was created');
        $this->assertSame(0, LessonProgress::where('lesson_id', $lesson->id)->count());
    }

    public function test_a_student_cannot_reach_the_admin_preview(): void
    {
        $student = User::factory()->create();
        $student->syncRoles(['student']);

        $this->actingAs($student)
            ->get(route('manage.course-videos.preview', ['path' => $this->relative]))
            ->assertForbidden();
    }

    /** ⚠️ The path comes from the request, so the library must re-check it. */
    public function test_the_preview_refuses_a_path_outside_the_library(): void
    {
        $this->actingAs($this->admin)
            ->get(route('manage.course-videos.preview', ['path' => '../../.env']))
            ->assertNotFound();
    }

    // ----- reordering --------------------------------------------------------

    /** @return array<int, Lesson> */
    private function threeLessons(): array
    {
        $made = [];

        foreach (['First', 'Second', 'Third'] as $i => $title) {
            $made[] = Lesson::create([
                'course_id' => $this->course->id,
                'title' => $title,
                'slug' => Str::slug($title).'-'.Str::random(5),
                'status' => 'published',
                'sort_order' => $i + 1,
            ]);
        }

        return $made;
    }

    public function test_dragging_a_lesson_writes_the_new_order(): void
    {
        [$a, $b, $c] = $this->threeLessons();

        // Third moved to the front.
        $this->actingAs($this->admin)
            ->postJson(route('manage.course-videos.reorder'), [
                'course_id' => $this->course->id,
                'lesson_ids' => [$c->id, $a->id, $b->id],
            ])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertSame(1, $c->fresh()->sort_order);
        $this->assertSame(2, $a->fresh()->sort_order);
        $this->assertSame(3, $b->fresh()->sort_order);
    }

    /** And that order is what a student actually meets. */
    public function test_the_new_order_is_what_students_see(): void
    {
        [$a, $b, $c] = $this->threeLessons();

        $this->actingAs($this->admin)->postJson(route('manage.course-videos.reorder'), [
            'course_id' => $this->course->id,
            'lesson_ids' => [$c->id, $b->id, $a->id],
        ]);

        $ordered = Lesson::where('course_id', $this->course->id)
            ->whereIn('id', [$a->id, $b->id, $c->id])
            ->orderBy('sort_order')
            ->pluck('id')
            ->all();

        $this->assertSame([$c->id, $b->id, $a->id], $ordered);
    }

    /** ⚠️ A lesson from another course must not be reshuffled by a posted id. */
    public function test_a_lesson_from_another_course_is_ignored(): void
    {
        [$a] = $this->threeLessons();

        $other = Course::create([
            'title' => 'Other course',
            'slug' => 'other-'.Str::random(6),
            'description' => 'x',
            'price' => $this->course->price,
            'currency' => 'LKR',
            'status' => 'published',
            'lecturer_id' => $this->course->lecturer_id,
            'published_at' => now(),
        ]);

        $foreign = Lesson::create([
            'course_id' => $other->id,
            'title' => 'Not yours',
            'slug' => 'not-yours-'.Str::random(5),
            'status' => 'published',
            'sort_order' => 7,
        ]);

        $this->actingAs($this->admin)->postJson(route('manage.course-videos.reorder'), [
            'course_id' => $this->course->id,
            'lesson_ids' => [$foreign->id, $a->id],
        ])->assertOk();

        $this->assertSame(7, $foreign->fresh()->sort_order, 'the other course was left alone');
    }

    public function test_a_student_cannot_reorder(): void
    {
        $student = User::factory()->create();
        $student->syncRoles(['student']);

        [$a] = $this->threeLessons();

        $this->actingAs($student)
            ->postJson(route('manage.course-videos.reorder'), [
                'course_id' => $this->course->id,
                'lesson_ids' => [$a->id],
            ])
            ->assertForbidden();
    }

    public function test_the_order_panel_lists_the_courses_lessons(): void
    {
        [$a, $b, $c] = $this->threeLessons();

        $lessons = $this->actingAs($this->admin)
            ->get(route('manage.course-videos.index', ['order_course' => $this->course->id]))
            ->assertOk()
            ->viewData('orderLessons');

        // The seeded course already ships six lessons of its own, so assert on
        // the three this test made rather than on the whole list.
        $mine = $lessons->whereIn('id', [$a->id, $b->id, $c->id])->pluck('title')->values()->all();

        $this->assertSame(['First', 'Second', 'Third'], $mine, 'listed in sort_order');
    }
}
