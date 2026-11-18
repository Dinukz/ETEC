<?php

namespace Tests\Feature;

use App\Http\Controllers\Student\LessonVideoController;
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
 * ⚠️ SLOW VIDEOS + THE E-TEC MARK (2026-10-01).
 *
 * The video now arrives in pieces (see VideoStreamRangeTest), so four things
 * around it had to change, and each is pinned here:
 *
 *  - the link lives as long as the viewing (max 3 h), not 15 minutes — a
 *    15-minute link would stop every lesson at minute 15;
 *  - a RESUME (the player re-asking after a dropped connection) re-signs an
 *    open window and NEVER opens a new one, so it cannot spend a viewing;
 *  - a light "SD" copy beside the original plays first, behind the same
 *    signature, and never appears in the attach list;
 *  - every player carries the E-TEC mark and a fullscreen that keeps it.
 *
 * Uses its own library folder so it never collides with LessonVideoAccessTest,
 * which deletes everything in "__test__" when it finishes.
 */
class LessonVideoDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private const FOLDER = '__test_delivery__';

    private User $student;

    private Course $course;

    private Lesson $lesson;

    private string $relative;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        LmsSchemaRepair::ensure();

        $this->student = User::factory()->create();
        $this->student->syncRoles(['student']);
        $this->course = Course::where('status', 'published')->firstOrFail();

        $dir = VideoLibrary::root().DIRECTORY_SEPARATOR.self::FOLDER;
        @mkdir($dir, 0777, true);

        $name = 'lesson-'.Str::random(6).'.mp4';
        file_put_contents($dir.DIRECTORY_SEPARATOR.$name, str_repeat('H', 3000));
        $this->relative = self::FOLDER.'/'.$name;

        $this->lesson = Lesson::create([
            'course_id' => $this->course->id,
            'title' => 'Delivery Lesson',
            'slug' => 'delivery-lesson-'.Str::random(5),
            'status' => 'published',
            'sort_order' => 998,
            'video_provider' => 'upload',
            'video_path' => $this->relative,
        ]);
    }

    protected function tearDown(): void
    {
        $this->removeTree(VideoLibrary::root().DIRECTORY_SEPARATOR.self::FOLDER);

        parent::tearDown();
    }

    private function removeTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir.DIRECTORY_SEPARATOR.$entry;
            is_dir($path) ? $this->removeTree($path) : @unlink($path);
        }

        @rmdir($dir);
    }

    /** Puts a light copy in an "SD" folder (spelled $folder) beside the original. */
    private function addLightCopy(string $folder = 'SD'): void
    {
        $dir = VideoLibrary::root().DIRECTORY_SEPARATOR.self::FOLDER.DIRECTORY_SEPARATOR.$folder;
        @mkdir($dir, 0777, true);
        file_put_contents($dir.DIRECTORY_SEPARATOR.basename($this->relative), str_repeat('s', 1000));
    }

    private function enrol(): Enrollment
    {
        return Enrollment::create([
            'user_id' => $this->student->id,
            'course_id' => $this->course->id,
            'status' => 'active',
            'enrolled_at' => now(),
        ]);
    }

    private function ticket(bool $resume = false)
    {
        return $this->actingAs($this->student)
            ->post(route('student.lesson.video.ticket', [$this->course, $this->lesson]).($resume ? '?resume=1' : ''));
    }

    private function progressFor(Enrollment $enrollment): LessonProgress
    {
        return LessonProgress::where('enrollment_id', $enrollment->id)
            ->where('lesson_id', $this->lesson->id)->firstOrFail();
    }

    private function expiresOf(string $url): int
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return (int) $query['expires'];
    }

    // ----- how long a link lives ---------------------------------------------

    public function test_a_link_lives_three_hours_not_fifteen_minutes(): void
    {
        $this->enrol();

        $expires = $this->expiresOf($this->ticket()->json('url'));

        $this->assertEqualsWithDelta(now()->addHours(3)->timestamp, $expires, 5);
    }

    public function test_a_link_never_outlives_the_viewing_window(): void
    {
        $enrollment = $this->enrol();
        $this->ticket();

        $closes = now()->addMinutes(40);
        LessonProgress::whereKey($this->progressFor($enrollment)->id)->update(['window_expires_at' => $closes]);

        // A resume re-signs the viewing already open. (Since 2026-11-11 a fresh
        // press starts the NEXT viewing, with its own window — strictly two.)
        $expires = $this->expiresOf($this->ticket(resume: true)->json('url'));

        $this->assertEqualsWithDelta($closes->timestamp, $expires, 5);
    }

    public function test_link_expiry_is_the_earlier_of_window_and_three_hours(): void
    {
        $progress = new LessonProgress(['window_expires_at' => now()->addHours(30)]);

        $this->assertEqualsWithDelta(now()->addHours(3)->timestamp, LessonVideoController::linkExpiry($progress)->timestamp, 5);
    }

    // ----- resume never spends a viewing --------------------------------------

    public function test_a_resume_inside_the_window_gets_a_new_link_and_no_new_viewing(): void
    {
        $enrollment = $this->enrol();
        $this->ticket();

        $response = $this->ticket(resume: true);

        $response->assertOk()->assertJson(['ok' => true]);
        $this->assertSame(1, $this->progressFor($enrollment)->view_count);
    }

    /**
     * ⚠️ THE CASE THAT MATTERS. The window has closed and the office granted one
     * more viewing. A plain ticket would open it — a resume (sent by the player
     * on its own after a dropped connection) must NOT, or the granted viewing
     * is spent without the student pressing anything.
     */
    public function test_a_resume_after_the_window_closed_is_refused_and_spends_nothing(): void
    {
        $enrollment = $this->enrol();
        $this->ticket();

        $progress = $this->progressFor($enrollment);
        $progress->update(['window_expires_at' => now()->subMinute(), 'extra_views_granted' => 1]);

        $this->ticket(resume: true)->assertForbidden();

        $fresh = $progress->fresh();
        $this->assertSame(1, $fresh->view_count, 'the granted viewing is still unspent');
        $this->assertTrue($fresh->window_expires_at->isPast());
    }

    public function test_a_resume_with_no_window_yet_is_refused(): void
    {
        $this->enrol();

        $this->ticket(resume: true)->assertForbidden();
    }

    // ----- the light copy ------------------------------------------------------

    public function test_without_a_light_copy_the_original_plays(): void
    {
        $this->enrol();

        $body = $this->ticket()->json();

        $this->assertSame('hd', $body['quality']);
        $this->assertArrayNotHasKey('sd', $body['urls']);
        $this->assertSame($body['urls']['hd'], $body['url']);
    }

    public function test_a_light_copy_plays_first_and_streams_its_own_bytes(): void
    {
        $this->addLightCopy();
        $this->enrol();

        $body = $this->ticket()->json();

        $this->assertSame('sd', $body['quality']);
        $this->assertSame($body['urls']['sd'], $body['url']);

        $sd = $this->actingAs($this->student)->get($body['urls']['sd']);
        $sd->assertOk()->assertHeader('Content-Length', '1000');

        $hd = $this->actingAs($this->student)->get($body['urls']['hd']);
        $hd->assertOk()->assertHeader('Content-Length', '3000');
    }

    /** Typed by hand over FTP onto a Linux server — "sd" must work as well as "SD". */
    public function test_the_light_folder_is_found_whatever_its_case(): void
    {
        $this->addLightCopy('sd');

        $this->assertNotNull(VideoLibrary::lightCopyOf($this->relative));
    }

    /** ⚠️ q is inside the signature: editing it breaks the link. */
    public function test_the_quality_cannot_be_edited_into_a_link(): void
    {
        $this->addLightCopy();
        $this->enrol();

        $hd = $this->ticket()->json('urls.hd');

        $this->actingAs($this->student)->get($hd.'&q=sd')->assertForbidden();
    }

    public function test_a_light_copy_is_never_offered_for_attaching(): void
    {
        $this->addLightCopy();

        $paths = VideoLibrary::filesIn(self::FOLDER)->pluck('path')->all();

        $this->assertSame([$this->relative], $paths);
    }

    public function test_deleting_a_video_removes_its_light_copy_too(): void
    {
        $this->addLightCopy();
        $light = VideoLibrary::lightCopyOf($this->relative);
        $this->assertNotNull($light);

        $this->assertTrue(VideoLibrary::delete($this->relative));

        $this->assertNull(VideoLibrary::absolutePath($light));
    }

    // ----- the Course Videos screen says which files are slow ----------------------

    public function test_the_admin_screen_flags_a_heavy_unoptimised_file_and_an_sd_copy(): void
    {
        // A skeleton MP4: picture data FIRST, index after it (not web-optimised),
        // and a header that says it lasts 1 ms — so 3 KB is an absurd bitrate.
        $box = fn (string $type, string $body) => pack('N', 8 + strlen($body)).$type.$body;
        $mvhd = $box('mvhd', "\0\0\0\0".pack('N', 0).pack('N', 0).pack('N', 1000).pack('N', 1).str_repeat("\0", 80));
        file_put_contents(
            VideoLibrary::absolutePath($this->relative),
            $box('mdat', str_repeat('x', 3000)).$box('moov', $mvhd),
        );
        $this->addLightCopy();

        $this->actingAs(User::role('super_admin')->firstOrFail())
            ->get(route('manage.course-videos.index', ['folder' => self::FOLDER]))
            ->assertOk()
            ->assertSee('Heavy — add an SD copy')
            ->assertSee('Not web-optimised')
            ->assertSee('SD copy ✓');
    }

    // ----- the E-TEC mark --------------------------------------------------------

    public function test_an_uploaded_lesson_carries_the_mark_and_our_fullscreen(): void
    {
        $this->enrol();

        $html = $this->actingAs($this->student)
            ->get(route('student.learn.lesson', [$this->course, $this->lesson]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-etec-watermark', $html);
        // "E-TEC Business" since 2026-11-11 — the owner's wording.
        $this->assertStringContainsString('>E-TEC Business</div>', $html);
        $this->assertStringContainsString('data-etec-fullscreen', $html);
        // The browser's own fullscreen would show the bare video without the mark.
        $this->assertStringContainsString('controlsList="nodownload noplaybackrate nofullscreen"', $html);
        $this->assertStringContainsString('playsinline', $html);
    }

    public function test_a_youtube_lesson_carries_the_mark_and_loses_its_own_fullscreen(): void
    {
        $this->enrol();
        $this->lesson->update(['video_provider' => 'youtube', 'video_url' => 'https://www.youtube.com/watch?v=abc123', 'video_path' => null]);

        $html = $this->actingAs($this->student)
            ->get(route('student.learn.lesson', [$this->course, $this->lesson]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-etec-watermark', $html);
        $this->assertStringContainsString('data-etec-fullscreen', $html);
        $this->assertStringContainsString('youtube.com/embed/abc123?fs=0', $html);
        $this->assertStringNotContainsString('allowfullscreen', $html);
    }
}
