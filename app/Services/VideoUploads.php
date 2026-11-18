<?php

namespace App\Services;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\User;
use App\Support\Mp4FastStart;
use App\Support\Mp4Probe;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Uploading a course video from the browser (2026-10-10).
 *
 * *"course eka mokk kiyla select krla lesson ekak hadal ekat add krna widiyt
 * hadal denn"* — pick the course, name the lesson (or pick one), choose the file.
 *
 * ⚠️ IN PIECES, BECAUSE NO HOST TAKES 2 GB IN ONE REQUEST. PHP refuses any
 * upload larger than upload_max_filesize (2 MB by default; Hostinger's own
 * limit is not known here). So the browser slices the file and sends it piece
 * by piece — each piece well under the server's limit, which chunkBytes() reads
 * from the server itself — and the pieces are appended in order to one
 * "<id>.part" file. A piece that is re-sent after a dropped connection is
 * recognised and skipped, so an upload survives bad wifi.
 *
 * ⚠️ THEN WEB-OPTIMISED, WITHOUT RE-ENCODING. If the index is at the end, the
 * finished upload goes through FastStartJob on its way into the library — the
 * picture bytes are copied unchanged, so quality cannot change. If optimising
 * is refused or fails, the upload is still kept, exactly as sent.
 *
 * ⚠️ AN UPLOAD BELONGS TO WHOEVER STARTED IT. Its id is random, and every later
 * request must come from the same signed-in user — nobody can append to, or
 * finish, someone else's upload.
 */
class VideoUploads
{
    /** The largest video accepted. */
    public const MAX_BYTES = 6 * 1073741824; // 6 GB

    /** Only MP4-family files can be checked and web-optimised. */
    public const EXTENSIONS = ['mp4', 'm4v', 'mov'];

    /** Abandoned uploads are cleared after this long. */
    public const STALE_SECONDS = 172800; // 2 days

    public static function dir(): string
    {
        $dir = storage_path('app/video-uploads');

        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return $dir;
    }

    /**
     * Bytes per piece: the server's own upload limits, less a margin, between
     * 256 KB and 8 MB.
     */
    public static function chunkBytes(): int
    {
        $upload = self::iniBytes((string) ini_get('upload_max_filesize'));
        $post = self::iniBytes((string) ini_get('post_max_size'));

        $limit = min($upload - 4096, $post - 65536);

        return (int) max(262144, min(8388608, $limit));
    }

    /** "2M" → 2097152; "0" or "" (no limit) → PHP_INT_MAX. */
    public static function iniBytes(string $value): int
    {
        $value = trim($value);

        if ($value === '' || $value === '0' || $value === '-1') {
            return PHP_INT_MAX;
        }

        $number = (float) $value;

        return (int) match (strtolower(substr($value, -1))) {
            'g' => $number * 1073741824,
            'm' => $number * 1048576,
            'k' => $number * 1024,
            default => $number,
        };
    }

    /**
     * Open an upload.
     *
     * @param  array{course_id: int, lesson_id?: ?int, title?: ?string, file_name: string, file_size: int}  $data
     * @return array{id: string, chunk_bytes: int}
     */
    public static function start(User $user, array $data): array
    {
        self::sweep();

        $course = Course::findOrFail($data['course_id']);

        // ⚠️ A LECTURER UPLOADS ONLY TO THEIR OWN COURSES — the rule everywhere
        // else in /manage (ManagesCourses). Review 2026-10-10 found a second
        // lecturer could replace the video of a lesson in someone else's course.
        if (! $user->hasAnyRole(['super_admin', 'admin']) && (int) $course->lecturer_id !== (int) $user->id) {
            throw new HttpException(403, 'You can only upload videos to your own courses.');
        }

        $lessonId = $data['lesson_id'] ?? null;

        if ($lessonId && ! Lesson::where('course_id', $course->id)->whereKey($lessonId)->exists()) {
            throw new HttpException(422, 'That lesson does not belong to the chosen course.');
        }

        if (! $lessonId && blank($data['title'] ?? null)) {
            throw new HttpException(422, 'Give the new lesson a name, or pick an existing lesson.');
        }

        $extension = strtolower(pathinfo((string) $data['file_name'], PATHINFO_EXTENSION));

        if (! in_array($extension, self::EXTENSIONS, true)) {
            throw new HttpException(422, 'Only MP4 videos (.mp4, .m4v, .mov) can be uploaded here.');
        }

        $size = (int) $data['file_size'];

        if ($size <= 0 || $size > self::MAX_BYTES) {
            throw new HttpException(422, 'The video must be smaller than 6 GB.');
        }

        // The upload and its web-optimised copy exist side by side for a moment.
        self::needSpace(VideoLibrary::exists() ? VideoLibrary::root() : self::dir(), $size * 2);

        $id = bin2hex(random_bytes(16));

        touch(self::partPath($id));

        self::saveMeta($id, [
            'id' => $id,
            'user_id' => $user->id,
            'course_id' => $course->id,
            'lesson_id' => $lessonId ? (int) $lessonId : null,
            'title' => $lessonId ? null : trim((string) $data['title']),
            'file_name' => (string) $data['file_name'],
            'extension' => $extension,
            'size' => $size,
            'status' => 'uploading',
            'created_at' => time(),
        ]);

        return ['id' => $id, 'chunk_bytes' => self::chunkBytes()];
    }

    /**
     * Append one piece. Returns how many bytes the server now holds.
     *
     * A piece the server already has (a retry after a lost reply) is accepted
     * and ignored; a piece from further ahead is refused with the true count,
     * so the browser resumes from the right place.
     */
    public static function chunk(User $user, string $id, int $offset, UploadedFile $piece): int
    {
        $meta = self::ownedMeta($user, $id);

        if ($meta['status'] !== 'uploading') {
            throw new HttpException(409, 'That upload is already finished.');
        }

        $length = (int) $piece->getSize();

        if ($length <= 0) {
            throw new HttpException(422, 'An empty piece was sent.');
        }

        $part = fopen(self::partPath($id), 'c+b');

        if ($part === false) {
            throw new HttpException(500, 'The upload could not be written.');
        }

        try {
            flock($part, LOCK_EX);
            $received = (int) fstat($part)['size'];

            if ($offset + $length <= $received && $offset >= 0) {
                return $received; // already have it
            }

            if ($offset !== $received) {
                throw new HttpException(409, (string) $received);
            }

            if ($received + $length > $meta['size']) {
                throw new HttpException(422, 'More was sent than the file\'s size.');
            }

            $in = fopen($piece->getRealPath(), 'rb');

            if ($in === false) {
                throw new HttpException(500, 'The piece could not be read.');
            }

            fseek($part, $received);
            $copied = stream_copy_to_stream($in, $part);
            fclose($in);
            fflush($part);

            if ($copied !== $length) {
                // Put the file back to where it was, so a retry starts clean.
                ftruncate($part, $received);

                // 507, not 500: the page shows this instead of retrying it as
                // if the connection had dropped.
                throw new HttpException(507, 'The piece could not be saved — the server disk (or the hosting quota) is full.');
            }

            return $received + $length;
        } finally {
            flock($part, LOCK_UN);
            fclose($part);
        }
    }

    /**
     * The last piece is in: check it is a video, then optimise or file it.
     *
     * @return array<string, mixed>
     */
    public static function finish(User $user, string $id): array
    {
        $meta = self::ownedMeta($user, $id);

        if ($meta['status'] !== 'uploading') {
            return self::report($meta);
        }

        $part = self::partPath($id);
        clearstatcache();
        $received = (int) @filesize($part);

        if ($received !== (int) $meta['size']) {
            throw new HttpException(422, "The upload is incomplete — {$received} of {$meta['size']} bytes arrived.");
        }

        if (Mp4Probe::read($part, $received)['duration'] === null) {
            self::discard($id);

            throw new HttpException(422, 'That file is not a playable MP4 video. Nothing was saved.');
        }

        $course = Course::findOrFail($meta['course_id']);
        $folder = VideoLibrary::folderForCourse($course->title, $course->id);
        $directory = VideoLibrary::root().DIRECTORY_SEPARATOR.$folder;

        if (! is_dir($directory) && ! @mkdir($directory, 0775, true)) {
            throw new HttpException(500, 'The course folder could not be created on the server.');
        }

        $wanted = VideoLibrary::safeFileName($meta['file_name'], $meta['extension']);
        $plan = Mp4FastStart::analyse($part);

        if ($plan['status'] === 'needed') {
            // The job reserves its file name the moment it starts; if another
            // upload took the same name an instant earlier, take the next one.
            for ($attempt = 0; ; $attempt++) {
                $destination = $directory.DIRECTORY_SEPARATOR.VideoLibrary::uniqueName($directory, $wanted);

                try {
                    $job = FastStartJob::begin($part, $destination, $plan, true, ['upload_id' => $id, 'user_id' => $user->id]);
                    break;
                } catch (\RuntimeException) {
                    if ($attempt >= 5) {
                        throw new HttpException(500, 'No free file name could be reserved for this video. Try again.');
                    }
                }
            }

            $meta['destination'] = $destination;
            $meta['status'] = 'optimising';
            $meta['job_id'] = $job['id'];
            self::saveMeta($id, $meta);

            return ['done' => false, 'job' => $job['id'], 'progress' => 0];
        }

        // Already optimised — or not something this can rewrite: keep it as sent.
        $meta['destination'] = $directory.DIRECTORY_SEPARATOR.VideoLibrary::uniqueName($directory, $wanted);

        if (! self::place($part, $meta)) {
            throw new HttpException(500, 'The video could not be moved into the library.');
        }

        $meta['optimised'] = $plan['status'] === 'optimised';
        $meta['note'] = $plan['status'] === 'unsupported' ? $plan['reason'] : null;

        return self::report(self::attach($id, $meta));
    }

    /**
     * One step of an optimising job — an upload's, or a library file's.
     *
     * @return array<string, mixed>
     */
    public static function step(User $user, string $jobId): array
    {
        $job = FastStartJob::load($jobId);

        if (! $job) {
            throw new HttpException(404, 'That optimising job is not there any more.');
        }

        $uploadId = $job['meta']['upload_id'] ?? null;

        if ($uploadId !== null && (int) ($job['meta']['user_id'] ?? 0) !== (int) $user->id) {
            throw new HttpException(403, 'That upload is not yours.');
        }

        $job = FastStartJob::advance($jobId) ?? $job;

        if ($job['status'] === 'running') {
            return ['done' => false, 'job' => $jobId, 'progress' => FastStartJob::progress($job)];
        }

        if ($uploadId === null) {
            // A file already in the library, optimised where it lies.
            return $job['status'] === 'done'
                ? ['done' => true, 'message' => basename($job['destination']).' is now web-optimised. Quality is unchanged.']
                : ['done' => true, 'failed' => true, 'message' => $job['error']];
        }

        $meta = self::meta($uploadId);

        if (! $meta) {
            throw new HttpException(404, 'That upload is not there any more.');
        }

        if ($meta['status'] === 'optimising') {
            if ($job['status'] === 'done') {
                $meta['optimised'] = true;
            } else {
                // Optimising failed: keep the upload exactly as sent.
                if (! self::place(self::partPath($uploadId), $meta)) {
                    throw new HttpException(500, 'The video could not be moved into the library.');
                }

                $meta['optimised'] = false;
                $meta['note'] = $job['error'];
            }

            $meta = self::attach($uploadId, $meta);
        }

        return self::report($meta);
    }

    /**
     * Web-optimise a file already in the library, where it lies.
     *
     * @return array<string, mixed>
     */
    public static function optimiseInPlace(string $relative): array
    {
        abort_unless(VideoLibrary::isPlayable($relative), 404, 'That file is not in the video library.');

        $absolute = VideoLibrary::absolutePath($relative);
        $plan = Mp4FastStart::analyse($absolute);

        if ($plan['status'] === 'optimised') {
            return ['done' => true, 'message' => basename($absolute).' is already web-optimised.'];
        }

        if ($plan['status'] === 'unsupported') {
            throw new HttpException(422, basename($absolute).' cannot be optimised here: '.$plan['reason']);
        }

        // One job per file: a second click — or a second admin — resumes it.
        $id = md5('in-place|'.$absolute);
        $existing = FastStartJob::load($id);

        if ($existing && $existing['status'] === 'running' && time() - (int) $existing['updated_at'] < 3600) {
            return ['done' => false, 'job' => $id, 'progress' => FastStartJob::progress($existing)];
        }

        self::needSpace(dirname($absolute), (int) $plan['size']);

        $job = FastStartJob::begin($absolute, $absolute, $plan, false, ['path' => $relative], $id);

        return ['done' => false, 'job' => $job['id'], 'progress' => 0];
    }

    // ----------------------------------------------------------------------

    /** @param  array<string, mixed>  $meta */
    protected static function attach(string $id, array $meta): array
    {
        $course = Course::findOrFail($meta['course_id']);
        $relative = VideoLibrary::relativePath((string) realpath($meta['destination']));

        $lesson = $meta['lesson_id']
            ? Lesson::where('course_id', $course->id)->find($meta['lesson_id'])
            : null;

        if (! $lesson) {
            $title = $meta['title'] ?: VideoLibrary::suggestTitle($relative);

            $lesson = Lesson::create([
                'course_id' => $course->id,
                'title' => $title,
                'slug' => self::uniqueSlug($course, $title),
                'status' => 'published',
                'sort_order' => (int) Lesson::where('course_id', $course->id)->max('sort_order') + 1,
            ]);
        }

        $lesson->update(['video_provider' => 'upload', 'video_path' => $relative]);

        $meta['status'] = 'done';
        $meta['lesson_id'] = $lesson->id;
        $meta['lesson_title'] = $lesson->title;
        $meta['path'] = $relative;
        self::saveMeta($id, $meta);

        return $meta;
    }

    /** @param  array<string, mixed>  $meta */
    protected static function report(array $meta): array
    {
        if ($meta['status'] !== 'done') {
            return ['done' => false, 'job' => $meta['job_id'] ?? null, 'progress' => 0];
        }

        $message = "\"{$meta['lesson_title']}\" now plays {$meta['path']}.";

        $message .= ($meta['optimised'] ?? false)
            ? ' It is web-optimised; quality is unchanged.'
            : ' It was kept exactly as uploaded'.(filled($meta['note'] ?? null) ? ' — not web-optimised: '.$meta['note'] : '.');

        return ['done' => true, 'message' => $message, 'path' => $meta['path'], 'lesson_id' => $meta['lesson_id']];
    }

    /**
     * Move the upload to $meta['destination'] — or, when that name was taken
     * meanwhile (another upload, a file dropped in by FTP), to the next free
     * name, recorded back on $meta. Never overwrites anything.
     *
     * @param  array<string, mixed>  $meta
     */
    protected static function place(string $from, array &$meta): bool
    {
        $directory = dirname($meta['destination']);
        $meta['destination'] = $directory.DIRECTORY_SEPARATOR.VideoLibrary::uniqueName($directory, basename($meta['destination']));
        $to = $meta['destination'];

        if (@rename($from, $to)) {
            return true;
        }

        // A different disk: copy, then remove the original. A half-written
        // copy is removed rather than left looking like a real video.
        if (@copy($from, $to) && filesize($to) === filesize($from)) {
            @unlink($from);

            return true;
        }

        @unlink($to);

        return false;
    }

    /** Refuse up front rather than fill the disk half-way through. */
    protected static function needSpace(string $directory, int $bytes): void
    {
        $free = function_exists('disk_free_space') ? @disk_free_space($directory) : false;

        if ($free !== false && $free < $bytes + 104857600) {
            throw new HttpException(507, sprintf(
                'Not enough space on the server: %s needed, %s free.',
                VideoLibrary::humanSize($bytes),
                VideoLibrary::humanSize((int) $free),
            ));
        }
    }

    /** @return array<string, mixed> */
    protected static function ownedMeta(User $user, string $id): array
    {
        $meta = self::meta($id);

        if (! $meta) {
            throw new HttpException(404, 'That upload is not there any more — start it again.');
        }

        if ((int) $meta['user_id'] !== (int) $user->id) {
            throw new HttpException(403, 'That upload is not yours.');
        }

        return $meta;
    }

    /** @return array<string, mixed>|null */
    public static function meta(string $id): ?array
    {
        if (! FastStartJob::validId($id)) {
            return null;
        }

        $path = self::dir().DIRECTORY_SEPARATOR.$id.'.json';
        $meta = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        return is_array($meta) ? $meta : null;
    }

    /** @param  array<string, mixed>  $meta */
    protected static function saveMeta(string $id, array $meta): void
    {
        file_put_contents(self::dir().DIRECTORY_SEPARATOR.$id.'.json', json_encode($meta), LOCK_EX);
    }

    public static function partPath(string $id): string
    {
        return self::dir().DIRECTORY_SEPARATOR.$id.'.part';
    }

    protected static function discard(string $id): void
    {
        @unlink(self::partPath($id));
        @unlink(self::dir().DIRECTORY_SEPARATOR.$id.'.json');
    }

    /** Clear uploads nobody has touched for two days. */
    protected static function sweep(): void
    {
        foreach (glob(self::dir().DIRECTORY_SEPARATOR.'*.json') ?: [] as $file) {
            $id = basename($file, '.json');
            $part = self::partPath($id);
            $touched = max((int) @filemtime($file), (int) @filemtime($part));

            if (time() - $touched >= self::STALE_SECONDS) {
                self::discard($id);
            }
        }
    }

    /** lessons carries unique(course_id, slug). */
    protected static function uniqueSlug(Course $course, string $title): string
    {
        $base = Str::slug($title) ?: 'lesson';
        $slug = $base;
        $n = 2;

        while (Lesson::where('course_id', $course->id)->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$n++;
        }

        return $slug;
    }
}
