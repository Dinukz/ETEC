<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\LessonViewRequest;
use App\Models\User;
use App\Services\LessonViewWindow;
use App\Services\LmsSchemaRepair;
use App\Services\VideoLibrary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ⚠️ COURSE VIDEOS — asked for 2026-09-10.
 *
 * "E courses ekat adala video student lata and consultant lata penwann one.
 * Habai me videos blann puluwn eka parai, aye balann one nm request krnn one,
 * saha monam krmayakata wath download krnn krann bari wenn."
 *
 * The files live in resources/Videos, OUTSIDE the document root, so the only
 * way to a byte is LessonVideoController — which checks enrolment, checks the
 * viewing window, and needs a signed URL that dies in 15 minutes.
 *
 * ⚠️ WHAT THIS DOES NOT CLAIM. Screen recording is not blocked and cannot be,
 * by this or any website. The owner chose "just block the easy ways" knowing
 * that. Nothing in these tests should ever be written to imply otherwise.
 */
class LessonVideoAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    private Course $course;

    private Lesson $lesson;

    private string $videoRelative;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        LmsSchemaRepair::ensure();

        $this->student = User::factory()->create();
        $this->student->syncRoles(['student']);

        $this->course = Course::where('status', 'published')->firstOrFail();

        // A real (tiny) file in the real library, so path resolution and Range
        // are exercised against the filesystem rather than a mock.
        $folder = VideoLibrary::root().DIRECTORY_SEPARATOR.'__test__';

        if (! is_dir($folder)) {
            mkdir($folder, 0777, true);
        }

        $name = 'probe-'.Str::random(6).'.mp4';
        file_put_contents($folder.DIRECTORY_SEPARATOR.$name, str_repeat('A', 2048));

        $this->videoRelative = '__test__/'.$name;

        $this->lesson = Lesson::create([
            'course_id' => $this->course->id,
            'title' => 'Video Lesson',
            'slug' => 'video-lesson-'.Str::random(5),
            'status' => 'published',
            'sort_order' => 999,
            'video_provider' => 'upload',
            'video_path' => $this->videoRelative,
        ]);
    }

    /**
     * ⚠️ Clean the FOLDER, not just the file. These tests write into the real
     * video library (the only way to exercise real path resolution and Range),
     * so leaving "__test__" behind would put a phantom course in front of the
     * admin on the Course Videos screen.
     */
    protected function tearDown(): void
    {
        $absolute = VideoLibrary::absolutePath($this->videoRelative);

        if ($absolute && is_file($absolute)) {
            @unlink($absolute);
        }

        $folder = VideoLibrary::root().DIRECTORY_SEPARATOR.'__test__';

        if (is_dir($folder)) {
            foreach (glob($folder.DIRECTORY_SEPARATOR.'*') ?: [] as $leftover) {
                @unlink($leftover);
            }

            @rmdir($folder);
        }

        parent::tearDown();
    }

    private function enrol(string $status = 'active'): Enrollment
    {
        return Enrollment::create([
            'user_id' => $this->student->id,
            'course_id' => $this->course->id,
            'status' => $status,
            'enrolled_at' => now(),
        ]);
    }

    private function ticket(): array
    {
        return $this->actingAs($this->student)
            ->post(route('student.lesson.video.ticket', [$this->course, $this->lesson]))
            ->json();
    }

    // ----- who may watch ----------------------------------------------------

    public function test_an_enrolled_student_gets_a_ticket(): void
    {
        $this->enrol();

        $body = $this->ticket();

        $this->assertTrue($body['ok']);
        $this->assertStringContainsString('signature=', $body['url'], 'the URL is signed');
    }

    public function test_a_pending_enrollment_is_refused(): void
    {
        $this->enrol('pending');

        $this->actingAs($this->student)
            ->post(route('student.lesson.video.ticket', [$this->course, $this->lesson]))
            ->assertForbidden();
    }

    public function test_someone_not_enrolled_is_refused(): void
    {
        $this->actingAs($this->student)
            ->post(route('student.lesson.video.ticket', [$this->course, $this->lesson]))
            ->assertForbidden();
    }

    /** A consultant needs no special case — they enrol like anybody else. */
    public function test_a_consultant_with_an_enrollment_can_watch(): void
    {
        $this->student->syncRoles(['consultant']);
        $this->enrol();

        $this->assertTrue($this->ticket()['ok']);
    }

    // ----- the file itself --------------------------------------------------

    public function test_the_video_streams_and_accepts_a_range(): void
    {
        $this->enrol();

        $url = $this->ticket()['url'];

        $response = $this->actingAs($this->student)
            ->withHeaders(['Range' => 'bytes=0-99'])
            ->get($url);

        $response->assertStatus(206);
        $response->assertHeader('Accept-Ranges', 'bytes');
        $response->assertHeader('Content-Range', 'bytes 0-99/2048');
        $response->assertHeader('Content-Length', '100');
    }

    public function test_a_whole_file_request_is_a_200(): void
    {
        $this->enrol();

        $this->actingAs($this->student)
            ->get($this->ticket()['url'])
            ->assertOk()
            ->assertHeader('Content-Length', '2048');
    }

    /** ⚠️ It must never arrive as a download. */
    public function test_it_is_served_inline_not_as_an_attachment(): void
    {
        $this->enrol();

        $this->actingAs($this->student)
            ->get($this->ticket()['url'])
            ->assertHeader('Content-Disposition', 'inline');
    }

    /** ⚠️ An unsigned or tampered URL is worthless. */
    public function test_an_unsigned_url_is_refused(): void
    {
        $this->enrol();

        $this->actingAs($this->student)
            ->get(route('student.lesson.video', [$this->course, $this->lesson]))
            ->assertForbidden();
    }

    public function test_an_expired_link_is_refused(): void
    {
        $this->enrol();

        // Models, not ids — Course's route key is its slug.
        $url = URL::temporarySignedRoute(
            'student.lesson.video',
            now()->subMinute(),
            ['course' => $this->course, 'lesson' => $this->lesson],
        );

        $this->actingAs($this->student)->get($url)->assertForbidden();
    }

    /** The page itself must not contain the file address. */
    public function test_the_lesson_page_does_not_leak_the_file_path(): void
    {
        $this->enrol();

        $this->actingAs($this->student)
            ->get(route('student.learn.lesson', [$this->course, $this->lesson]))
            ->assertOk()
            ->assertDontSee($this->videoRelative)
            ->assertDontSee('storage/'.$this->videoRelative);
    }

    // ----- watch twice, then ask (strictly two since 2026-11-11) -------------

    public function test_the_first_play_opens_a_viewing_window(): void
    {
        $enrollment = $this->enrol();

        $this->ticket();

        $progress = LessonProgress::where('enrollment_id', $enrollment->id)
            ->where('lesson_id', $this->lesson->id)->firstOrFail();

        $this->assertSame(1, $progress->view_count);
        $this->assertTrue($progress->window_expires_at->isFuture());
    }

    /**
     * ⚠️ STRICTLY TWO (2026-11-11). Every press counts — even while the last
     * viewing is still open, which is how a refresh or a second visit used to
     * replay for free ("3 park itath wada play krla balann puluwn una").
     */
    public function test_every_press_counts_and_the_third_is_refused(): void
    {
        $enrollment = $this->enrol();

        $first = $this->ticket();
        $second = $this->ticket();

        $this->assertTrue($first['ok']);
        $this->assertSame(1, $first['remaining']);
        $this->assertTrue($second['ok']);
        $this->assertSame(0, $second['remaining']);

        $this->actingAs($this->student)
            ->post(route('student.lesson.video.ticket', [$this->course, $this->lesson]))
            ->assertForbidden()
            ->assertJson(['ok' => false, 'remaining' => 0]);

        $progress = LessonProgress::where('enrollment_id', $enrollment->id)
            ->where('lesson_id', $this->lesson->id)->firstOrFail();

        $this->assertSame(2, $progress->view_count, 'two viewings, and the refused third did not count');
        $this->assertTrue($progress->window_expires_at->isFuture(), 'the second viewing itself still plays');
    }

    /** ⚠️ After both are used — even long after — they are locked out until they ask. */
    public function test_after_two_viewings_it_is_refused(): void
    {
        $enrollment = $this->enrol();

        $this->ticket();

        LessonProgress::where('enrollment_id', $enrollment->id)
            ->where('lesson_id', $this->lesson->id)
            ->update(['window_expires_at' => now()->subHour()]);

        $this->assertTrue($this->ticket()['ok'], 'the second viewing is still theirs');

        LessonProgress::where('enrollment_id', $enrollment->id)
            ->where('lesson_id', $this->lesson->id)
            ->update(['window_expires_at' => now()->subHour()]);

        $this->actingAs($this->student)
            ->post(route('student.lesson.video.ticket', [$this->course, $this->lesson]))
            ->assertForbidden();
    }

    /** A dropped connection carries on inside the SAME viewing — the resume never counts. */
    public function test_a_resume_inside_a_viewing_does_not_count(): void
    {
        $enrollment = $this->enrol();
        $this->ticket();

        foreach ([1, 2, 3] as $ignored) {
            $this->actingAs($this->student)
                ->post(route('student.lesson.video.ticket', [$this->course, $this->lesson]).'?resume=1')
                ->assertOk()
                ->assertJson(['ok' => true, 'remaining' => 1]);
        }

        $this->assertSame(1, LessonProgress::where('enrollment_id', $enrollment->id)->where('lesson_id', $this->lesson->id)->value('view_count'));
    }

    /** A resume never starts a new viewing — once one has ended, only a press does. */
    public function test_a_resume_after_a_viewing_ended_is_refused_and_says_what_is_left(): void
    {
        $enrollment = $this->enrol();
        $this->ticket();

        LessonProgress::where('enrollment_id', $enrollment->id)->where('lesson_id', $this->lesson->id)
            ->update(['window_expires_at' => now()->subHour()]);

        $this->actingAs($this->student)
            ->post(route('student.lesson.video.ticket', [$this->course, $this->lesson]).'?resume=1')
            ->assertForbidden()
            ->assertJson(['ok' => false, 'remaining' => 1]);

        $this->assertSame(1, LessonProgress::where('enrollment_id', $enrollment->id)->where('lesson_id', $this->lesson->id)->value('view_count'));
    }

    /** The page says how many are left, and opens on the request screen when none are. */
    public function test_the_lesson_page_shows_the_viewings_left(): void
    {
        $this->enrol();
        $page = fn () => $this->actingAs($this->student)->get(route('student.learn.lesson', [$this->course, $this->lesson]))->assertOk();

        $page()->assertSee('Viewings left:', false)->assertSee('left: 2,', false);

        $this->ticket();
        $page()->assertSee('left: 1,', false);

        $this->ticket();
        $page()->assertSee('left: 0,', false)->assertSee('Request to watch again');

        $this->actingAs($this->student)
            ->from(route('student.learn.lesson', [$this->course, $this->lesson]))
            ->post(route('student.lesson.video.request', [$this->course, $this->lesson]));

        $page()->assertSee('Your request has been sent')->assertDontSee('Request to watch again');
    }

    public function test_a_student_can_ask_to_watch_again(): void
    {
        $this->enrol();

        $this->actingAs($this->student)
            ->from(route('student.learn.lesson', [$this->course, $this->lesson]))
            ->post(route('student.lesson.video.request', [$this->course, $this->lesson]), ['reason' => 'Lost connection'])
            ->assertSessionHas('success');

        $this->assertDatabaseHas('lesson_view_requests', [
            'user_id' => $this->student->id,
            'lesson_id' => $this->lesson->id,
            'status' => 'pending',
        ]);
    }

    public function test_asking_twice_does_not_make_two_requests(): void
    {
        $this->enrol();

        foreach ([1, 2] as $ignored) {
            $this->actingAs($this->student)
                ->from(route('student.learn.lesson', [$this->course, $this->lesson]))
                ->post(route('student.lesson.video.request', [$this->course, $this->lesson]));
        }

        $this->assertSame(1, LessonViewRequest::where('user_id', $this->student->id)->count());
    }

    /** ⚠️ Approving grants exactly one more viewing, not unlimited access. */
    public function test_approving_a_request_grants_one_more_viewing(): void
    {
        $enrollment = $this->enrol();
        $this->ticket();
        $this->ticket();

        $progress = LessonProgress::where('enrollment_id', $enrollment->id)
            ->where('lesson_id', $this->lesson->id)->firstOrFail();

        $progress->update(['window_expires_at' => now()->subHour()]);

        $request = LessonViewRequest::create([
            'user_id' => $this->student->id,
            'lesson_id' => $this->lesson->id,
            'status' => 'pending',
        ]);

        $this->actingAs(User::role('super_admin')->firstOrFail())
            ->post(route('manage.course-videos.approve', $request))
            ->assertSessionHas('success');

        $this->assertSame(1, $progress->fresh()->extra_views_granted);

        // They can watch once more...
        $this->assertTrue($this->ticket()['ok']);

        // ...and then they are locked out again.
        LessonProgress::whereKey($progress->id)->update(['window_expires_at' => now()->subHour()]);

        $this->actingAs($this->student)
            ->post(route('student.lesson.video.ticket', [$this->course, $this->lesson]))
            ->assertForbidden();
    }

    // ----- the library refuses to be tricked --------------------------------

    /** ⚠️ Directory traversal: a stray "../" in the database reads nothing. */
    public function test_a_path_outside_the_library_resolves_to_nothing(): void
    {
        $this->assertNull(VideoLibrary::absolutePath('../../.env'));
        $this->assertNull(VideoLibrary::absolutePath('../../../windows/win.ini'));
        $this->assertFalse(VideoLibrary::isPlayable('../../.env'));
    }

    public function test_a_lesson_pointing_at_a_missing_file_is_a_404_not_a_crash(): void
    {
        $this->enrol();

        $url = $this->ticket()['url'];

        $this->lesson->update(['video_path' => '__test__/gone-'.Str::random(5).'.mp4']);

        $this->actingAs($this->student)->get($url)->assertNotFound();
    }
}
