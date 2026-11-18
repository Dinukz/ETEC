<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\User;
use App\Services\LmsSchemaRepair;
use App\Services\VideoLibrary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Removing a video has to remove it for STUDENTS.
 *
 * Reported 2026-09-11: *"courses video remove krata student and consultant
 * lagen baluwaham remove wela na"*. The old Remove only nulled the lesson's
 * video_path; every student query filters on `status = 'published'` and never
 * looks at the video, so the entry stayed on the sidebar and played nothing.
 *
 * ⚠️ THE DANGEROUS ONE IN THIS FILE IS THE TRAVERSAL TEST. Delete now reaches
 * unlink(), so the containment check in VideoLibrary::absolutePath() is the
 * only thing between a posted path and any file the web user can write. If
 * that test ever goes green by accident, the bug it guards is catastrophic and
 * silent.
 */
class CourseVideoDeleteTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Course $course;

    private string $folder;

    private string $relative;

    private string $absolute;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        LmsSchemaRepair::ensure();

        $this->admin = User::where('email', 'admin@etec.lk')->firstOrFail();
        $this->course = Course::where('status', 'published')->firstOrFail();

        $this->folder = '__delete_test__'.Str::random(4);
        $dir = VideoLibrary::root().DIRECTORY_SEPARATOR.$this->folder;

        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $this->absolute = $dir.DIRECTORY_SEPARATOR.'clip.mp4';
        file_put_contents($this->absolute, str_repeat('A', 1024));

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

    private function lessonFor(string $path, string $title): Lesson
    {
        return Lesson::create([
            'course_id' => $this->course->id,
            'title' => $title,
            'slug' => Str::slug($title).'-'.Str::random(6),
            'status' => 'published',
            'video_provider' => 'upload',
            'video_path' => $path,
        ]);
    }

    private function deleteVideo(array $payload = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin)->post(
            route('manage.course-videos.destroy'),
            $payload + ['video_path' => $this->relative, 'confirm' => 1],
        );
    }

    // ----- the reported symptom ----------------------------------------------

    public function test_delete_removes_the_lesson_and_the_file(): void
    {
        $lesson = $this->lessonFor($this->relative, 'Day 99');

        $this->deleteVideo()->assertRedirect();

        $this->assertNull(Lesson::find($lesson->id), 'the lesson is gone');
        $this->assertFileDoesNotExist($this->absolute, 'and so is the file');
    }

    /** ⚠️ The actual complaint: it must be gone from the student's list. */
    public function test_the_student_no_longer_sees_it(): void
    {
        $lesson = $this->lessonFor($this->relative, 'Day 99');

        $before = $this->course->lessons()->where('status', 'published')->pluck('id');
        $this->assertTrue($before->contains($lesson->id), 'guard: visible before');

        $this->deleteVideo();

        $after = $this->course->fresh()->lessons()->where('status', 'published')->pluck('id');
        $this->assertFalse($after->contains($lesson->id), 'gone from what students are served');
    }

    /** One file, several lessons — this library really does have Day 04 twice. */
    public function test_every_lesson_playing_that_file_goes(): void
    {
        $first = $this->lessonFor($this->relative, 'Day 99');
        $second = $this->lessonFor($this->relative, 'Day 99 again');

        $this->deleteVideo()->assertRedirect();

        $this->assertNull(Lesson::find($first->id));
        $this->assertNull(Lesson::find($second->id));
    }

    /** A file nobody uses can still be cleared off the disk. */
    public function test_an_unattached_file_can_be_deleted(): void
    {
        $this->deleteVideo()->assertRedirect();

        $this->assertFileDoesNotExist($this->absolute);
    }

    // ----- the guards ---------------------------------------------------------

    /**
     * ⚠️ THE ONE THAT MATTERS. A posted path must never reach a file outside
     * the library. The sentinel proves nothing was unlinked, not merely that
     * the request failed.
     */
    public function test_a_path_outside_the_library_deletes_nothing(): void
    {
        $sentinel = storage_path('app/__must_survive__.txt');
        file_put_contents($sentinel, 'still here');

        try {
            $this->actingAs($this->admin)
                ->post(route('manage.course-videos.destroy'), [
                    'video_path' => '../../storage/app/__must_survive__.txt',
                    'confirm' => 1,
                ])
                ->assertNotFound();

            $this->assertFileExists($sentinel, 'the traversal deleted nothing');
        } finally {
            @unlink($sentinel);
        }
    }

    /**
     * ⚠️ THE REAL TRAVERSAL GUARD, TESTED AT THE LIBRARY.
     *
     * The route test above stops at the controller's isPlayable() check and
     * never reaches unlink(), so on its own it proves nothing about
     * VideoLibrary::delete(). This calls delete() directly, with a traversal
     * path pointing at a PLAYABLE file outside the library — the exact shape
     * that would erase an arbitrary .mp4 if the containment check were ever
     * dropped. Verified to go red when absolutePath() is bypassed.
     */
    public function test_the_library_itself_refuses_to_delete_outside_its_root(): void
    {
        $outside = storage_path('app/__outside_the_library__.mp4');
        file_put_contents($outside, 'not yours to delete');

        // The library root is <base>/resources/Videos, so two hops reach the
        // project root. Spelled out rather than computed, because a wrong hop
        // count makes this test pass for the wrong reason.
        $traversal = '../../storage/app/__outside_the_library__.mp4';

        try {
            // ⚠️ GUARD ON THE GUARD. Prove the traversal really does reach the
            // sentinel by a naive concat — otherwise a bad path would make the
            // assertion below green while testing nothing at all.
            $this->assertSame(
                realpath($outside),
                realpath(VideoLibrary::root().DIRECTORY_SEPARATOR.$traversal),
                'the traversal string really does point at the sentinel',
            );

            $this->assertFalse(
                VideoLibrary::delete($traversal),
                'delete() refused a path outside the library',
            );

            $this->assertFileExists($outside, 'and the file is still there');
        } finally {
            @unlink($outside);
        }
    }

    /** The library only ever deletes videos, never any other file it can see. */
    public function test_a_non_video_inside_the_library_is_refused(): void
    {
        $notes = VideoLibrary::root().DIRECTORY_SEPARATOR.$this->folder.DIRECTORY_SEPARATOR.'notes.txt';
        file_put_contents($notes, 'keep me');

        $this->assertFalse(VideoLibrary::delete($this->folder.'/notes.txt'));
        $this->assertFileExists($notes);
    }

    public function test_without_a_confirmation_nothing_is_deleted(): void
    {
        $lesson = $this->lessonFor($this->relative, 'Day 99');

        $this->actingAs($this->admin)
            ->post(route('manage.course-videos.destroy'), ['video_path' => $this->relative])
            ->assertSessionHasErrors('confirm');

        $this->assertNotNull(Lesson::find($lesson->id));
        $this->assertFileExists($this->absolute);
    }

    public function test_a_student_cannot_delete(): void
    {
        $student = User::factory()->create();
        $student->syncRoles(['student']);

        $this->actingAs($student)
            ->post(route('manage.course-videos.destroy'), ['video_path' => $this->relative, 'confirm' => 1])
            ->assertForbidden();

        $this->assertFileExists($this->absolute);
    }

    // ----- removing a topic from the order list -------------------------------

    /** ⚠️ Removing a lesson must NOT delete its file — that is the other button. */
    public function test_removing_a_lesson_keeps_the_file(): void
    {
        $lesson = $this->lessonFor($this->relative, 'Day 99');

        $this->actingAs($this->admin)
            ->post(route('manage.course-videos.remove-lesson'), ['lesson_id' => $lesson->id])
            ->assertRedirect();

        $this->assertNull(Lesson::find($lesson->id), 'the lesson is gone');
        $this->assertFileExists($this->absolute, 'the file is untouched');
    }

    /** The demo topics with no video at all are what this is really for. */
    public function test_a_topic_with_no_video_can_be_removed(): void
    {
        $topic = Lesson::create([
            'course_id' => $this->course->id,
            'title' => 'Financial Basics',
            'slug' => 'financial-basics-'.Str::random(6),
            'status' => 'published',
        ]);

        $this->actingAs($this->admin)
            ->post(route('manage.course-videos.remove-lesson'), ['lesson_id' => $topic->id])
            ->assertRedirect();

        $this->assertNull(Lesson::find($topic->id));
    }

    public function test_a_student_cannot_remove_a_lesson(): void
    {
        $student = User::factory()->create();
        $student->syncRoles(['student']);

        $lesson = $this->lessonFor($this->relative, 'Day 99');

        $this->actingAs($student)
            ->post(route('manage.course-videos.remove-lesson'), ['lesson_id' => $lesson->id])
            ->assertForbidden();

        $this->assertNotNull(Lesson::find($lesson->id));
    }

    // ----- the order the student actually meets -------------------------------

    /**
     * ⚠️ THE TIEBREAKER REGRESSION. The admin ordered by (sort_order, id) and
     * students by sort_order alone, so on ties the dragged order was not the
     * order students met — the sidebar read Day 06 … Day 15, Day 01.
     */
    public function test_the_saved_order_is_the_order_students_meet(): void
    {
        $made = [];

        foreach (['Alpha', 'Beta', 'Gamma'] as $title) {
            $made[] = $this->lessonFor($this->relative, $title);
        }

        [$a, $b, $c] = $made;

        $this->actingAs($this->admin)->postJson(route('manage.course-videos.reorder'), [
            'course_id' => $this->course->id,
            'lesson_ids' => [$c->id, $a->id, $b->id],
        ])->assertOk();

        // Exactly the query LearnController serves the sidebar from.
        $studentOrder = $this->course->fresh()->lessons()
            ->where('status', 'published')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->pluck('id')
            ->filter(fn ($id) => in_array($id, [$a->id, $b->id, $c->id], true))
            ->values()
            ->all();

        $this->assertSame([$c->id, $a->id, $b->id], $studentOrder);
    }

    // ----- the buttons are actually on the page -------------------------------

    /**
     * ⚠️ RENDERED, NOT JUST ROUTED. On 2026-09-10 a whole panel existed in the
     * view data and was never on screen. A button nobody can reach is not a
     * feature.
     */
    public function test_the_delete_and_save_controls_render(): void
    {
        $this->lessonFor($this->relative, 'Day 99');

        $this->actingAs($this->admin)
            ->get(route('manage.course-videos.index', [
                'folder' => $this->folder,
                'order_course' => $this->course->id,
            ]))
            ->assertOk()
            ->assertSee('Delete video')
            ->assertSee('Save order')
            ->assertSee(route('manage.course-videos.destroy'), false)
            ->assertSee(route('manage.course-videos.remove-lesson'), false);
    }

    // ----- the Rename button that never renamed -------------------------------

    public function test_rename_actually_renames(): void
    {
        $lesson = $this->lessonFor($this->relative, 'Old Name');

        $this->actingAs($this->admin)
            ->post(route('manage.course-videos.attach'), [
                'course_id' => $this->course->id,
                'lesson_id' => $lesson->id,
                'video_path' => $this->relative,
                'title' => 'New Name',
            ])
            ->assertRedirect();

        $this->assertSame('New Name', $lesson->fresh()->title);
    }

    /** A blank title must not wipe the one that is there. */
    public function test_attaching_without_a_title_keeps_the_existing_one(): void
    {
        $lesson = $this->lessonFor($this->relative, 'Keep Me');

        $this->actingAs($this->admin)
            ->post(route('manage.course-videos.attach'), [
                'course_id' => $this->course->id,
                'lesson_id' => $lesson->id,
                'video_path' => $this->relative,
            ])
            ->assertRedirect();

        $this->assertSame('Keep Me', $lesson->fresh()->title);
    }
}
