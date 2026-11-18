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
 * ⚠️ VIDEOS ARE ATTACHED, NOT UPLOADED — and the reason is arithmetic.
 *
 * The owner's library is 10.4 GB with a single 2.13 GB lesson. Manage → Lessons
 * caps a video upload at 500 MB, and no shared-hosting PHP would take 2 GB
 * through a browser anyway. So files are placed by FTP into
 * resources/Videos/<folder>/ and this screen points a lesson at one.
 */
class CourseVideoLibraryTest extends TestCase
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

        $this->folder = '__lib_test__'.Str::random(4);
        $dir = VideoLibrary::root().DIRECTORY_SEPARATOR.$this->folder;

        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        // A nested path with a space in it, like the real library
        // ("Crypto Master Guide/All video/Day 2.mp4").
        mkdir($dir.DIRECTORY_SEPARATOR.'All video', 0777, true);
        file_put_contents($dir.DIRECTORY_SEPARATOR.'All video'.DIRECTORY_SEPARATOR.'Day 2.mp4', 'x');

        $this->relative = $this->folder.'/All video/Day 2.mp4';
    }

    protected function tearDown(): void
    {
        $dir = VideoLibrary::root().DIRECTORY_SEPARATOR.$this->folder;

        if (is_dir($dir)) {
            @unlink($dir.DIRECTORY_SEPARATOR.'All video'.DIRECTORY_SEPARATOR.'Day 2.mp4');
            @rmdir($dir.DIRECTORY_SEPARATOR.'All video');
            @rmdir($dir);
        }

        parent::tearDown();
    }

    // ----- the library ------------------------------------------------------

    public function test_it_finds_nested_files_with_spaces_in_the_name(): void
    {
        $files = VideoLibrary::filesIn($this->folder);

        $this->assertCount(1, $files);
        $this->assertSame($this->relative, $files->first()['path']);
        $this->assertSame('Day 2.mp4', $files->first()['name']);
    }

    public function test_only_playable_extensions_are_offered(): void
    {
        $dir = VideoLibrary::root().DIRECTORY_SEPARATOR.$this->folder;
        file_put_contents($dir.DIRECTORY_SEPARATOR.'notes.txt', 'x');
        file_put_contents($dir.DIRECTORY_SEPARATOR.'cover.jpg', 'x');

        $this->assertCount(1, VideoLibrary::filesIn($this->folder), 'only the mp4 is listed');

        @unlink($dir.DIRECTORY_SEPARATOR.'notes.txt');
        @unlink($dir.DIRECTORY_SEPARATOR.'cover.jpg');
    }

    // ----- attaching --------------------------------------------------------

    public function test_the_screen_lists_the_library(): void
    {
        $this->actingAs($this->admin)
            ->get(route('manage.course-videos.index', ['folder' => $this->folder]))
            ->assertOk()
            ->assertSee('Day 2.mp4');
    }

    public function test_attaching_creates_a_lesson_that_plays_the_file(): void
    {
        $this->actingAs($this->admin)
            ->from(route('manage.course-videos.index'))
            ->post(route('manage.course-videos.attach'), [
                'video_path' => $this->relative,
                'course_id' => $this->course->id,
                'title' => 'Day 2 — Getting started',
            ])
            ->assertSessionHasNoErrors();

        $lesson = Lesson::where('course_id', $this->course->id)
            ->where('title', 'Day 2 — Getting started')->firstOrFail();

        $this->assertSame('upload', $lesson->video_provider);
        $this->assertSame($this->relative, $lesson->video_path);
        $this->assertSame('published', $lesson->status);
    }

    public function test_attaching_to_an_existing_lesson_repoints_it(): void
    {
        $lesson = Lesson::create([
            'course_id' => $this->course->id,
            'title' => 'Existing lesson',
            'slug' => 'existing-'.Str::random(5),
            'status' => 'published',
        ]);

        $this->actingAs($this->admin)
            ->post(route('manage.course-videos.attach'), [
                'video_path' => $this->relative,
                'course_id' => $this->course->id,
                'lesson_id' => $lesson->id,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame($this->relative, $lesson->fresh()->video_path);
    }

    /** ⚠️ The path arrives in a request, so it is not trusted. */
    public function test_a_path_outside_the_library_is_refused(): void
    {
        $this->actingAs($this->admin)
            ->post(route('manage.course-videos.attach'), [
                'video_path' => '../../.env',
                'course_id' => $this->course->id,
                'title' => 'Sneaky',
            ])
            ->assertSessionHasErrors('video_path');

        $this->assertSame(0, Lesson::where('title', 'Sneaky')->count());
    }

    public function test_a_file_that_does_not_exist_is_refused(): void
    {
        $this->actingAs($this->admin)
            ->post(route('manage.course-videos.attach'), [
                'video_path' => $this->folder.'/nope.mp4',
                'course_id' => $this->course->id,
                'title' => 'Missing',
            ])
            ->assertSessionHasErrors('video_path');
    }

    /** Detaching leaves the file alone — only the lesson forgets it. */
    public function test_detaching_keeps_the_file_on_disk(): void
    {
        $this->actingAs($this->admin)
            ->post(route('manage.course-videos.attach'), [
                'video_path' => $this->relative,
                'course_id' => $this->course->id,
                'title' => 'To detach',
            ]);

        $lesson = Lesson::where('title', 'To detach')->firstOrFail();

        $this->actingAs($this->admin)
            ->post(route('manage.course-videos.detach'), ['lesson_id' => $lesson->id])
            ->assertSessionHasNoErrors();

        $this->assertNull($lesson->fresh()->video_path);
        $this->assertTrue(VideoLibrary::isPlayable($this->relative), 'the file itself is untouched');
    }

    // ----- bulk import ------------------------------------------------------

    /**
     * ⚠️ THE ONE THAT MAKES THIS USABLE. A real course folder is 29 files;
     * attaching them one at a time is 29 round trips.
     */
    public function test_many_files_become_lessons_in_one_press(): void
    {
        $dir = VideoLibrary::root().DIRECTORY_SEPARATOR.$this->folder;
        mkdir($dir.DIRECTORY_SEPARATOR.'day 01', 0777, true);
        mkdir($dir.DIRECTORY_SEPARATOR.'day 02', 0777, true);
        file_put_contents($dir.DIRECTORY_SEPARATOR.'day 01'.DIRECTORY_SEPARATOR.'0811.mp4', 'x');
        file_put_contents($dir.DIRECTORY_SEPARATOR.'day 02'.DIRECTORY_SEPARATOR.'0812.mp4', 'x');

        $before = Lesson::where('course_id', $this->course->id)->count();

        $this->actingAs($this->admin)
            ->from(route('manage.course-videos.index'))
            ->post(route('manage.course-videos.attach-many'), [
                'course_id' => $this->course->id,
                'paths' => [
                    $this->folder.'/day 01/0811.mp4',
                    $this->folder.'/day 02/0812.mp4',
                    $this->relative,
                ],
            ])
            ->assertSessionHas('success');

        $this->assertSame($before + 3, Lesson::where('course_id', $this->course->id)->count());

        // Cleanup beyond what tearDown covers.
        @unlink($dir.DIRECTORY_SEPARATOR.'day 01'.DIRECTORY_SEPARATOR.'0811.mp4');
        @unlink($dir.DIRECTORY_SEPARATOR.'day 02'.DIRECTORY_SEPARATOR.'0812.mp4');
        @rmdir($dir.DIRECTORY_SEPARATOR.'day 01');
        @rmdir($dir.DIRECTORY_SEPARATOR.'day 02');
    }

    /**
     * ⚠️ Filenames are not lesson names. "0811.mp4" inside "day 01" must become
     * "Day 01", not "0811" — 29 lessons called things like "0728(1)" would be
     * worse than no import at all.
     */
    public function test_a_numeric_filename_is_named_from_its_folder(): void
    {
        $this->assertSame('Day 01', VideoLibrary::suggestTitle('Course/day 01/0811.mp4'));
        $this->assertSame('Day 2', VideoLibrary::suggestTitle('Course/All video/Day 2.mp4'));
        $this->assertSame('Day 03', VideoLibrary::suggestTitle('Course/day 03/0728(1)/0728(1).mp4'));
    }

    /** Importing twice must not create a second lesson for the same file. */
    public function test_a_file_already_attached_is_skipped(): void
    {
        $this->actingAs($this->admin)->post(route('manage.course-videos.attach-many'), [
            'course_id' => $this->course->id,
            'paths' => [$this->relative],
        ]);

        $after = Lesson::where('course_id', $this->course->id)->count();

        $this->actingAs($this->admin)
            ->from(route('manage.course-videos.index'))
            ->post(route('manage.course-videos.attach-many'), [
                'course_id' => $this->course->id,
                'paths' => [$this->relative],
            ]);

        $this->assertSame($after, Lesson::where('course_id', $this->course->id)->count());
    }

    /** ⚠️ Renaming an attached file must not mint a duplicate lesson. */
    public function test_renaming_an_attached_lesson_does_not_duplicate_it(): void
    {
        $this->actingAs($this->admin)->post(route('manage.course-videos.attach'), [
            'video_path' => $this->relative,
            'course_id' => $this->course->id,
            'title' => 'First name',
        ]);

        $lesson = Lesson::where('title', 'First name')->firstOrFail();
        $count = Lesson::where('course_id', $this->course->id)->count();

        $this->actingAs($this->admin)->post(route('manage.course-videos.attach'), [
            'video_path' => $this->relative,
            'course_id' => $this->course->id,
            'lesson_id' => $lesson->id,
            'title' => 'Better name',
        ])->assertSessionHasNoErrors();

        $this->assertSame($count, Lesson::where('course_id', $this->course->id)->count(), 'no second lesson');
        $this->assertSame($this->relative, $lesson->fresh()->video_path);
    }

    public function test_a_student_cannot_reach_the_screen(): void
    {
        $student = User::factory()->create();
        $student->syncRoles(['student']);

        $this->actingAs($student)
            ->get(route('manage.course-videos.index'))
            ->assertForbidden();
    }
}
