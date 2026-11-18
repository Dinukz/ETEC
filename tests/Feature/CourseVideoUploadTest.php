<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\User;
use App\Services\FastStartJob;
use App\Services\VideoLibrary;
use App\Services\VideoUploads;
use App\Support\Mp4FastStart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsMp4;
use Tests\TestCase;

/**
 * ⚠️ UPLOADING A COURSE VIDEO FROM THE BROWSER (2026-10-10).
 *
 * *"course eka mokk kiyla select krla lesson ekak hadal ekat add krna widiyt
 * hadal denn … upload kraddi video eka web optimize wela upload wenn one,
 * quality eka adu wen nathiwa."*
 *
 * Pick the course, name the lesson (or pick one), send the file in pieces; the
 * server web-optimises it without re-encoding and attaches it. These tests
 * drive the same requests the page's script makes, in small pieces, and check
 * the file that lands in the library chunk by chunk against what was sent.
 */
class CourseVideoUploadTest extends TestCase
{
    use BuildsMp4;
    use RefreshDatabase;

    private User $admin;

    private Course $course;

    private string $folder;

    /** @var array<int, string> */
    private array $uploads = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Cache::forever('settings.mail.grouped', collect());

        $this->admin = User::role('super_admin')->firstOrFail();
        $template = Course::where('status', 'published')->firstOrFail();

        $this->course = Course::create([
            'title' => 'Upload Test '.Str::random(6),
            'slug' => 'upload-test-'.Str::random(6),
            'description' => 'x',
            'price' => $template->price,
            'currency' => 'LKR',
            'status' => 'published',
            'lecturer_id' => $template->lecturer_id,
            'published_at' => now(),
        ]);

        $this->folder = VideoLibrary::folderForCourse($this->course->title, $this->course->id);
    }

    protected function tearDown(): void
    {
        $this->removeTree(VideoLibrary::root().DIRECTORY_SEPARATOR.$this->folder);

        foreach ($this->uploads as $id) {
            $jobId = VideoUploads::meta($id)['job_id'] ?? null;

            if ($jobId) {
                @unlink(FastStartJob::dir().DIRECTORY_SEPARATOR.$jobId.'.json');
                @unlink(FastStartJob::dir().DIRECTORY_SEPARATOR.$jobId.'.moov');
            }

            @unlink(VideoUploads::partPath($id));
            @unlink(VideoUploads::dir().DIRECTORY_SEPARATOR.$id.'.json');
        }

        parent::tearDown();
    }

    private function removeTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $path = $dir.DIRECTORY_SEPARATOR.$entry;
                is_dir($path) ? $this->removeTree($path) : @unlink($path);
            }
        }

        @rmdir($dir);
    }

    private function startUpload(string $bytes, array $overrides = [], ?User $as = null)
    {
        $response = $this->actingAs($as ?? $this->admin)->postJson(route('manage.course-videos.upload.start'), $overrides + [
            'course_id' => $this->course->id,
            'title' => 'Day 16',
            'file_name' => 'day 16.mp4',
            'file_size' => strlen($bytes),
        ]);

        if ($response->json('id')) {
            $this->uploads[] = $response->json('id');
        }

        return $response;
    }

    private function sendPiece(string $id, int $offset, string $piece, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->admin)->post(
            route('manage.course-videos.upload.chunk', $id),
            ['offset' => $offset, 'chunk' => UploadedFile::fake()->createWithContent('piece.bin', $piece)],
            ['Accept' => 'application/json'],
        );
    }

    /** The whole journey the page's script makes, in $pieceSize pieces. Returns the last response body. */
    private function upload(string $bytes, array $overrides = [], int $pieceSize = 700): array
    {
        $id = $this->startUpload($bytes, $overrides)->assertOk()->json('id');

        for ($offset = 0; $offset < strlen($bytes); $offset += $pieceSize) {
            $this->sendPiece($id, $offset, substr($bytes, $offset, $pieceSize))->assertOk();
        }

        $result = $this->actingAs($this->admin)->postJson(route('manage.course-videos.upload.finish', $id))->assertOk()->json();

        for ($i = 0; $i < 1000 && ! $result['done']; $i++) {
            $result = $this->actingAs($this->admin)->postJson(route('manage.course-videos.job.step', $result['job']))->assertOk()->json();
        }

        $this->assertTrue($result['done']);

        return $result;
    }

    // ----- the journey -------------------------------------------------------------

    public function test_an_upload_lands_in_the_course_folder_web_optimised_as_a_new_lesson(): void
    {
        $bytes = $this->mp4();
        $result = $this->upload($bytes);

        $lesson = Lesson::where('course_id', $this->course->id)->where('title', 'Day 16')->firstOrFail();
        $this->assertSame('upload', $lesson->video_provider);
        $this->assertSame($this->folder.'/day 16.mp4', $lesson->video_path);
        $this->assertSame($lesson->video_path, $result['path']);
        $this->assertStringContainsString('web-optimised', $result['message']);

        $stored = VideoLibrary::absolutePath($lesson->video_path);
        $this->assertSame('optimised', Mp4FastStart::analyse($stored)['status']);

        $original = tempnam(sys_get_temp_dir(), 'orig');
        file_put_contents($original, $bytes);
        $this->assertSameChunks($original, $stored, 97);
        @unlink($original);

        $this->assertFileDoesNotExist(VideoUploads::partPath($this->uploads[0]), 'the upload pieces are cleaned up');
    }

    public function test_an_already_optimised_upload_is_filed_as_it_is(): void
    {
        $bytes = $this->mp4(['faststart' => true]);
        $this->upload($bytes);

        $lesson = Lesson::where('course_id', $this->course->id)->where('title', 'Day 16')->firstOrFail();
        $this->assertSame($bytes, file_get_contents(VideoLibrary::absolutePath($lesson->video_path)));
    }

    public function test_an_upload_can_replace_the_video_of_an_existing_lesson(): void
    {
        $lesson = Lesson::create([
            'course_id' => $this->course->id, 'title' => 'Day 03', 'slug' => 'day-03',
            'status' => 'published', 'sort_order' => 1,
        ]);

        $this->upload($this->mp4(), ['lesson_id' => $lesson->id, 'title' => null, 'file_name' => 'new day 3.mp4']);

        $this->assertSame($this->folder.'/new day 3.mp4', $lesson->fresh()->video_path);
        $this->assertSame(1, Lesson::where('course_id', $this->course->id)->count(), 'no second lesson');
    }

    public function test_a_second_file_with_the_same_name_is_not_overwritten(): void
    {
        $this->upload($this->mp4(), ['title' => 'First']);
        $this->upload($this->mp4(['chunks' => 12]), ['title' => 'Second']);

        $this->assertSame($this->folder.'/day 16.mp4', Lesson::where('title', 'First')->value('video_path'));
        $this->assertSame($this->folder.'/day 16 (2).mp4', Lesson::where('title', 'Second')->value('video_path'));
    }

    // ----- dropped connections ----------------------------------------------------------

    public function test_a_piece_sent_twice_is_accepted_once(): void
    {
        $bytes = $this->mp4();
        $id = $this->startUpload($bytes)->json('id');

        $this->sendPiece($id, 0, substr($bytes, 0, 500))->assertOk()->assertJson(['received' => 500]);
        $this->sendPiece($id, 0, substr($bytes, 0, 500))->assertOk()->assertJson(['received' => 500]);

        $this->assertSame(500, filesize(VideoUploads::partPath($id)));
    }

    public function test_a_piece_from_further_ahead_is_told_where_to_resume(): void
    {
        $bytes = $this->mp4();
        $id = $this->startUpload($bytes)->json('id');

        $this->sendPiece($id, 0, substr($bytes, 0, 500))->assertOk();
        $this->sendPiece($id, 1000, substr($bytes, 1000, 500))->assertStatus(409)->assertJson(['received' => 500]);
    }

    public function test_finishing_before_every_piece_arrived_is_refused(): void
    {
        $bytes = $this->mp4();
        $id = $this->startUpload($bytes)->json('id');
        $this->sendPiece($id, 0, substr($bytes, 0, 500))->assertOk();

        $this->actingAs($this->admin)->postJson(route('manage.course-videos.upload.finish', $id))
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'The upload is incomplete — 500 of '.strlen($bytes).' bytes arrived.']);
    }

    // ----- what is refused ----------------------------------------------------------------

    public function test_something_that_is_not_a_video_is_refused_and_thrown_away(): void
    {
        $bytes = str_repeat('not a video at all ', 50);
        $id = $this->startUpload($bytes)->json('id');
        $this->sendPiece($id, 0, $bytes)->assertOk();

        $this->actingAs($this->admin)->postJson(route('manage.course-videos.upload.finish', $id))->assertStatus(422);

        $this->assertFileDoesNotExist(VideoUploads::partPath($id));
        $this->assertSame(0, Lesson::where('course_id', $this->course->id)->count());
    }

    public function test_only_mp4_files_can_be_started(): void
    {
        $this->startUpload('x', ['file_name' => 'lesson.avi'])->assertStatus(422);
    }

    public function test_a_lesson_from_another_course_is_refused(): void
    {
        $other = Lesson::where('course_id', '!=', $this->course->id)->firstOrFail();

        $this->startUpload('x', ['lesson_id' => $other->id, 'title' => null])->assertStatus(422);
    }

    /** ⚠️ An upload belongs to whoever started it. */
    public function test_nobody_else_can_add_to_or_finish_your_upload(): void
    {
        $bytes = $this->mp4();
        $id = $this->startUpload($bytes)->json('id');
        $otherAdmin = User::role('admin')->firstOrFail();

        $this->sendPiece($id, 0, substr($bytes, 0, 500), $otherAdmin)->assertForbidden();
        $this->actingAs($otherAdmin)->postJson(route('manage.course-videos.upload.finish', $id))->assertForbidden();
        $this->assertSame(0, filesize(VideoUploads::partPath($id)));
    }

    public function test_students_and_consultants_cannot_upload(): void
    {
        foreach (['student@etec.lk', 'consultant@etec.lk'] as $email) {
            $this->startUpload('x', [], User::where('email', $email)->firstOrFail())->assertForbidden();
        }
    }

    // ----- optimising a file already in the library -----------------------------------------

    public function test_a_library_file_is_optimised_where_it_lies_and_its_lesson_keeps_playing_it(): void
    {
        $directory = VideoLibrary::root().DIRECTORY_SEPARATOR.$this->folder;
        @mkdir($directory, 0777, true);
        $bytes = $this->mp4();
        file_put_contents($directory.DIRECTORY_SEPARATOR.'old.mp4', $bytes);
        $relative = $this->folder.'/old.mp4';

        $lesson = Lesson::create([
            'course_id' => $this->course->id, 'title' => 'Old', 'slug' => 'old', 'status' => 'published',
            'sort_order' => 1, 'video_provider' => 'upload', 'video_path' => $relative,
        ]);

        $this->actingAs($this->admin)
            ->get(route('manage.course-videos.index', ['folder' => $this->folder]))
            ->assertOk()
            ->assertSee('Make web-optimised')
            ->assertSee('Web-optimise all 1 in this folder');

        $result = $this->actingAs($this->admin)->postJson(route('manage.course-videos.optimise'), ['video_path' => $relative])->assertOk()->json();

        for ($i = 0; $i < 100 && ! $result['done']; $i++) {
            $result = $this->actingAs($this->admin)->postJson(route('manage.course-videos.job.step', $result['job']))->assertOk()->json();
        }

        $this->assertArrayNotHasKey('failed', $result);
        $this->assertSame('optimised', Mp4FastStart::analyse(VideoLibrary::absolutePath($relative))['status']);
        $this->assertSame($relative, $lesson->fresh()->video_path);

        $this->actingAs($this->admin)->postJson(route('manage.course-videos.optimise'), ['video_path' => $relative])
            ->assertOk()
            ->assertJson(['done' => true]);

        @unlink(FastStartJob::dir().DIRECTORY_SEPARATOR.md5('in-place|'.VideoLibrary::absolutePath($relative)).'.json');
    }

    public function test_a_path_outside_the_library_cannot_be_optimised(): void
    {
        $this->actingAs($this->admin)->postJson(route('manage.course-videos.optimise'), ['video_path' => '../../.env'])->assertNotFound();
    }

    // ----- found by the review (2026-10-10) ------------------------------------------------

    private function lecturer(): User
    {
        $user = User::factory()->create();
        $user->syncRoles(['lecturer']);

        return $user;
    }

    /** ⚠️ A lecturer uploads only to their own courses — the rule everywhere else in /manage. */
    public function test_a_lecturer_cannot_upload_to_someone_elses_course(): void
    {
        $this->startUpload('x', [], $this->lecturer())
            ->assertForbidden()
            ->assertJsonFragment(['message' => 'You can only upload videos to your own courses.']);
    }

    public function test_a_lecturer_can_upload_to_their_own_course_and_sees_only_those(): void
    {
        $lecturer = $this->lecturer();
        $this->course->update(['lecturer_id' => $lecturer->id]);

        $this->startUpload('x', [], $lecturer)->assertOk();

        $courses = $this->actingAs($lecturer)->get(route('manage.course-videos.index'))->assertOk()->viewData('uploadCourses');
        $this->assertSame([$this->course->id], $courses->pluck('id')->all());
    }

    /** Sending from the start again after a drop is told how much the server already has. */
    public function test_an_interrupted_upload_carries_on_from_what_arrived(): void
    {
        $bytes = $this->mp4();
        $id = $this->startUpload($bytes)->json('id');

        $this->sendPiece($id, 0, substr($bytes, 0, 500))->assertOk();

        // The page, pressed again: it sends from 0 with its usual piece size.
        $this->sendPiece($id, 0, substr($bytes, 0, 700))->assertStatus(409)->assertJson(['received' => 500]);

        for ($offset = 500; $offset < strlen($bytes); $offset += 700) {
            $this->sendPiece($id, $offset, substr($bytes, $offset, 700))->assertOk();
        }

        $this->assertSame($bytes, file_get_contents(VideoUploads::partPath($id)));
    }

    /** A finish whose reply was lost, asked again, answers the same — no second lesson. */
    public function test_finishing_twice_gives_the_same_answer_and_one_lesson(): void
    {
        $bytes = $this->mp4(['faststart' => true]);
        $id = $this->startUpload($bytes)->json('id');
        $this->sendPiece($id, 0, $bytes)->assertOk();

        $first = $this->actingAs($this->admin)->postJson(route('manage.course-videos.upload.finish', $id))->assertOk()->json();
        $second = $this->actingAs($this->admin)->postJson(route('manage.course-videos.upload.finish', $id))->assertOk()->json();

        $this->assertSame($first['path'], $second['path']);
        $this->assertSame(1, Lesson::where('course_id', $this->course->id)->count());

        // And a piece re-sent after that is not an error the page cannot handle.
        $this->sendPiece($id, 0, substr($bytes, 0, 100))->assertStatus(409);
    }

    /** Two uploads of the same name finishing together each get their own file. */
    public function test_two_same_name_uploads_finishing_together_get_their_own_names(): void
    {
        $a = $this->mp4();
        $b = $this->mp4(['chunks' => 15]);
        $idA = $this->startUpload($a, ['title' => 'A'])->json('id');
        $idB = $this->startUpload($b, ['title' => 'B'])->json('id');
        $this->sendPiece($idA, 0, $a)->assertOk();
        $this->sendPiece($idB, 0, $b)->assertOk();

        $jobA = $this->actingAs($this->admin)->postJson(route('manage.course-videos.upload.finish', $idA))->json('job');
        $jobB = $this->actingAs($this->admin)->postJson(route('manage.course-videos.upload.finish', $idB))->json('job');

        foreach ([$jobA, $jobB] as $job) {
            for ($i = 0, $done = false; $i < 100 && ! $done; $i++) {
                $done = $this->actingAs($this->admin)->postJson(route('manage.course-videos.job.step', $job))->assertOk()->json('done');
            }
        }

        $this->assertSame($this->folder.'/day 16.mp4', Lesson::where('title', 'A')->value('video_path'));
        $this->assertSame($this->folder.'/day 16 (2).mp4', Lesson::where('title', 'B')->value('video_path'));
        $this->assertSame('optimised', Mp4FastStart::analyse(VideoLibrary::absolutePath($this->folder.'/day 16 (2).mp4'))['status']);
    }

    /** A file dropped in by FTP under the same name meanwhile: the upload takes the next name, never a dead end. */
    public function test_a_file_appearing_under_the_same_name_meanwhile_does_not_strand_the_upload(): void
    {
        $bytes = $this->mp4();
        $id = $this->startUpload($bytes)->json('id');
        $this->sendPiece($id, 0, $bytes)->assertOk();
        $job = $this->actingAs($this->admin)->postJson(route('manage.course-videos.upload.finish', $id))->json('job');

        file_put_contents(VideoLibrary::root().DIRECTORY_SEPARATOR.$this->folder.DIRECTORY_SEPARATOR.'day 16.mp4', 'dropped in by FTP');

        for ($i = 0, $result = ['done' => false]; $i < 100 && ! $result['done']; $i++) {
            $result = $this->actingAs($this->admin)->postJson(route('manage.course-videos.job.step', $job))->assertOk()->json();
        }

        $this->assertSame($this->folder.'/day 16 (2).mp4', $result['path']);
        $this->assertSame('dropped in by FTP', file_get_contents(VideoLibrary::root().DIRECTORY_SEPARATOR.$this->folder.DIRECTORY_SEPARATOR.'day 16.mp4'), 'never overwritten');
        $this->assertSame($this->folder.'/day 16 (2).mp4', Lesson::where('title', 'Day 16')->value('video_path'));
    }

    /** Sinhala (and Tamil, Hindi) names keep their vowel signs, and stay inside Linux's 255 bytes. */
    public function test_names_in_sinhala_are_kept_whole_and_short_enough_for_linux(): void
    {
        $this->assertSame('පාඩම 1.mp4', VideoLibrary::safeFileName('පාඩම 1.mp4', 'mp4'));
        $this->assertSame('ශ්‍රී ලංකාව.mp4', VideoLibrary::safeFileName('ශ්‍රී ලංකාව.mp4', 'mp4'));
        $this->assertSame('தமிழ் பாடம்.mp4', VideoLibrary::safeFileName('தமிழ் பாடம்.mp4', 'mp4'));

        $folder = VideoLibrary::folderForCourse(str_repeat('ශ්‍රී ලංකා ', 20), 5);
        $this->assertLessThanOrEqual(180, strlen($folder));
        $this->assertTrue(mb_check_encoding($folder, 'UTF-8'), 'cut on a character boundary');

        $file = VideoLibrary::safeFileName(str_repeat('පාඩම ', 40).'.mp4', 'mp4');
        $this->assertLessThanOrEqual(255, strlen($file.' (999)'.FastStartJob::SUFFIX));
        $this->assertTrue(mb_check_encoding($file, 'UTF-8'));
    }

    // ----- the page --------------------------------------------------------------------------

    public function test_the_page_offers_the_upload_form_with_this_servers_piece_size(): void
    {
        $this->actingAs($this->admin)
            ->get(route('manage.course-videos.index'))
            ->assertOk()
            ->assertSee('Upload a video')
            ->assertSee('chunkBytes: '.VideoUploads::chunkBytes(), false);
    }

    public function test_the_piece_size_follows_the_servers_limits(): void
    {
        $this->assertSame(2097152, VideoUploads::iniBytes('2M'));
        $this->assertSame(PHP_INT_MAX, VideoUploads::iniBytes('0'));
        $this->assertGreaterThanOrEqual(262144, VideoUploads::chunkBytes());
        $this->assertLessThanOrEqual(min(VideoUploads::iniBytes((string) ini_get('upload_max_filesize')), 8388608), VideoUploads::chunkBytes());
    }
}
