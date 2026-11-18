<?php

namespace App\Services;

use Illuminate\Support\Collection;

/**
 * The folder of course videos, and the only thing allowed to turn a stored path
 * into a real file on disk.
 *
 * ⚠️ WHY THE FILES LIVE HERE AND NOT IN storage/app/public.
 *
 * The owner's videos are already in `resources/Videos/<Course name>/…` — 10.4 GB
 * of them, the largest a single 2.13 GB file. They cannot be uploaded through
 * the browser: Manage → Lessons caps a video at 500 MB, and no shared-hosting
 * PHP would accept 2 GB anyway (upload_max_filesize, post_max_size, the request
 * timeout). So files are placed on the server by FTP and attached to a lesson
 * from the admin screen; nothing is copied or re-uploaded.
 *
 * `resources/` is outside the document root, so none of it is reachable over
 * the web. Every byte a student sees is served by LessonVideoController, which
 * checks who they are first. That is the whole security model — there is no
 * public URL to leak.
 *
 * ⚠️ NEVER ACCEPT A PATH FROM A REQUEST. Callers pass a path that was stored on
 * a lesson by an admin, and even that is re-checked here: absolutePath() refuses
 * anything that resolves outside the library root, so a stray "../" in the
 * database cannot read /etc/passwd or the .env file.
 */
class VideoLibrary
{
    /** Extensions a browser can actually play. */
    public const PLAYABLE = ['mp4', 'webm', 'mov', 'm4v'];

    /**
     * ⚠️ LIGHT COPIES (2026-10-01). The originals are 2 GB camera files and
     * load slowly on Sri Lankan mobile data — "quality eka wadi nisa load wenna
     * godak welawa yanawa". Neither this PC nor Hostinger has ffmpeg, so the
     * server cannot shrink a file. The owner makes a smaller copy with HandBrake
     * and puts it, under the SAME file name, in a folder called "SD" beside the
     * original:
     *
     *   Crypto Master Guide/day 01/0811.mp4      <- the original, attached to the lesson
     *   Crypto Master Guide/day 01/SD/0811.mp4   <- the light copy, found automatically
     *
     * Matched without regard to case — the live server is Linux, and "sd" or
     * "Sd" typed by hand must work there too.
     */
    public const LIGHT_FOLDER = 'sd';

    /**
     * The library folder a course's uploads go into (2026-10-10).
     *
     * An existing folder with the course's name wins (the owner's FTP folders
     * are named after the courses — "Crypto Master Guide"); otherwise the title
     * made safe for a folder name: no slashes or other characters a filesystem
     * refuses, no leading/trailing dots (so never "." or ".."), at most 180
     * BYTES, and never "SD", which is reserved for light copies.
     *
     * ⚠️ BYTES, NOT CHARACTERS. Linux allows 255 bytes in a name, and a Sinhala
     * letter is 3 bytes — 100 characters of a Sinhala title is ~300 bytes, and
     * the folder could never be created (review 2026-10-10). Windows counts
     * characters, so this never shows on the dev PC.
     */
    public static function folderForCourse(string $title, int $courseId): string
    {
        $wanted = mb_strtolower(trim($title));

        foreach (self::folders() as $folder) {
            if (mb_strtolower(trim($folder)) === $wanted) {
                return $folder;
            }
        }

        $name = preg_replace('/[\\\\\/:*?"<>|\x00-\x1F\x7F]+/u', ' ', $title) ?? '';
        $name = trim((string) preg_replace('/\s+/u', ' ', $name), " .\t");
        $name = trim(mb_strcut($name, 0, 180, 'UTF-8'), " .\t");

        if ($name === '' || strtolower($name) === self::LIGHT_FOLDER) {
            $name = 'Course '.$courseId;
        }

        return $name;
    }

    /**
     * A file name that is safe on the server and keeps what the admin will
     * recognise: letters (any script) WITH their vowel signs and joiners —
     * without \p{M} "පාඩම" came out as "ප ඩම" — digits, spaces, dots, dashes,
     * brackets. At most 150 bytes, leaving room for " (n)", the extension and
     * the ".etec-optimising" work suffix inside Linux's 255.
     */
    public static function safeFileName(string $original, string $extension): string
    {
        // Own split rather than pathinfo(), which is locale-dependent with
        // non-Latin names.
        $name = (string) preg_replace('#^.*/#s', '', str_replace('\\', '/', $original));
        $base = (string) preg_replace('/\.[^.]*$/', '', $name);
        $base = preg_replace('/[^\p{L}\p{M}\p{N}\x{200C}\x{200D} ._()\-]+/u', ' ', $base) ?? '';
        $base = trim((string) preg_replace('/\s+/u', ' ', $base), " .-");
        $base = trim(mb_strcut($base, 0, 150, 'UTF-8'), " .-");

        if ($base === '') {
            $base = 'video-'.now()->format('Ymd-His');
        }

        return $base.'.'.strtolower($extension);
    }

    /** "$name", or "$name (2)", "(3)"… — whichever is not taken in $directory. */
    public static function uniqueName(string $directory, string $name): string
    {
        $base = pathinfo($name, PATHINFO_FILENAME);
        $extension = pathinfo($name, PATHINFO_EXTENSION);
        $candidate = $name;

        for ($n = 2; file_exists($directory.DIRECTORY_SEPARATOR.$candidate)
            || file_exists($directory.DIRECTORY_SEPARATOR.$candidate.FastStartJob::SUFFIX); $n++) {
            $candidate = "{$base} ({$n}).{$extension}";
        }

        return $candidate;
    }

    /** Is any folder in this relative path the light-copy folder? */
    public static function isLightCopyPath(string $relative): bool
    {
        $folders = array_slice(explode('/', str_replace('\\', '/', $relative)), 0, -1);

        foreach ($folders as $folder) {
            if (strtolower(trim($folder)) === self::LIGHT_FOLDER) {
                return true;
            }
        }

        return false;
    }

    /**
     * The light copy of a stored path, as a stored-style relative path, or null
     * when there is none. Resolved through absolutePath(), so it is held to the
     * same containment rule as everything else.
     */
    public static function lightCopyOf(string $relative): ?string
    {
        $original = self::absolutePath($relative);

        if ($original === null || ! is_file($original) || self::isLightCopyPath($relative)) {
            return null;
        }

        $dir = dirname($original);
        $name = strtolower(basename($original));

        foreach (scandir($dir) ?: [] as $entry) {
            if (strtolower($entry) !== self::LIGHT_FOLDER || ! is_dir($dir.DIRECTORY_SEPARATOR.$entry)) {
                continue;
            }

            foreach (scandir($dir.DIRECTORY_SEPARATOR.$entry) ?: [] as $file) {
                if (strtolower($file) !== $name) {
                    continue;
                }

                $candidate = self::relativePath($dir.DIRECTORY_SEPARATOR.$entry.DIRECTORY_SEPARATOR.$file);

                return self::isPlayable($candidate) ? $candidate : null;
            }
        }

        return null;
    }

    /** The library root — absolute, with symlinks resolved. */
    public static function root(): string
    {
        return realpath(resource_path('Videos')) ?: resource_path('Videos');
    }

    public static function exists(): bool
    {
        return is_dir(self::root());
    }

    /**
     * The course folders sitting in the library, by name.
     *
     * @return Collection<int, string>
     */
    public static function folders(): Collection
    {
        if (! self::exists()) {
            return collect();
        }

        return collect(scandir(self::root()) ?: [])
            ->reject(fn ($entry) => $entry === '.' || $entry === '..')
            // An "SD" folder is never a course — see LIGHT_FOLDER.
            ->reject(fn ($entry) => strtolower($entry) === self::LIGHT_FOLDER)
            ->filter(fn ($entry) => is_dir(self::root().DIRECTORY_SEPARATOR.$entry))
            ->values();
    }

    /**
     * Every playable file under a folder, as paths relative to the library root
     * (which is exactly what gets stored on `lessons.video_path`).
     *
     * Recursive because the real folders are nested and inconsistent — the
     * owner's "Crypto Master Guide" has both an `All video/` folder and
     * per-day folders holding the same files, with casing that varies
     * ("day 01" beside "Day 02").
     *
     * @return Collection<int, array{path: string, name: string, size: int}>
     */
    public static function filesIn(string $folder): Collection
    {
        $base = self::absolutePath($folder);

        if ($base === null || ! is_dir($base)) {
            return collect();
        }

        $found = collect();

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            if (! in_array(strtolower($file->getExtension()), self::PLAYABLE, true)) {
                continue;
            }

            // ⚠️ A light copy is never offered for attaching — it rides along
            // with its original. Listing it would put every lesson in twice.
            if (self::isLightCopyPath(self::relativePath($file->getPathname()))) {
                continue;
            }

            $found->push([
                'path' => self::relativePath($file->getPathname()),
                'name' => $file->getFilename(),
                'size' => $file->getSize(),
            ]);
        }

        return $found->sortBy('path', SORT_NATURAL | SORT_FLAG_CASE)->values();
    }

    /**
     * Turn a stored relative path into an absolute one, or null if it does not
     * exist or escapes the library.
     *
     * ⚠️ THE CONTAINMENT CHECK IS THE POINT OF THIS METHOD. realpath() resolves
     * "..", symlinks and Windows/POSIX separator differences, and the result
     * must still start with the root. Do not "simplify" this to a string concat.
     */
    public static function absolutePath(string $relative): ?string
    {
        $relative = str_replace('\\', '/', trim($relative));

        if ($relative === '' || str_contains($relative, "\0")) {
            return null;
        }

        $candidate = realpath(self::root().DIRECTORY_SEPARATOR.$relative);

        if ($candidate === false) {
            return null;
        }

        $root = self::root();

        // Compare with the separator appended so "/videos-secret" cannot pass as
        // a child of "/videos".
        return str_starts_with($candidate, $root.DIRECTORY_SEPARATOR) || $candidate === $root
            ? $candidate
            : null;
    }

    /**
     * Erase a file from the library. There is no undo and no backup.
     *
     * Asked for 2026-09-11: *"methana delete button ekk dala denn eka delete
     * kraham sampuran video ekam delete wenn"* — removing the lesson alone left
     * the .mp4 sitting on a disk nobody could clear without FTP.
     *
     * ⚠️ THE ONLY SAFE PATH TO unlink() IS THROUGH absolutePath(). It is what
     * resolves "..", symlinks and separator differences and then proves the
     * result is still inside the library. A caller-supplied string reaching
     * unlink() any other way can erase anything the web user can write. Read
     * that method's warning before touching this one.
     *
     * Refuses a directory, and refuses anything that is not a playable video —
     * this is the video library, not a general file manager.
     *
     * @return bool true when the file is gone (including when it already was)
     */
    public static function delete(string $relative): bool
    {
        $absolute = self::absolutePath($relative);

        if ($absolute === null || $absolute === self::root() || ! is_file($absolute)) {
            return false;
        }

        if (! in_array(strtolower(pathinfo($absolute, PATHINFO_EXTENSION)), self::PLAYABLE, true)) {
            return false;
        }

        // Its light copy goes with it — otherwise it is left on a disk nobody
        // can clear without FTP, which is exactly what this button replaced.
        $light = self::lightCopyOf($relative);

        $gone = @unlink($absolute);

        if ($gone && $light !== null && ($lightAbsolute = self::absolutePath($light)) !== null) {
            @unlink($lightAbsolute);
        }

        return $gone;
    }

    /** The path as it should be stored: relative to the root, forward slashes. */
    public static function relativePath(string $absolute): string
    {
        $root = self::root().DIRECTORY_SEPARATOR;

        $relative = str_starts_with($absolute, $root)
            ? substr($absolute, strlen($root))
            : $absolute;

        return str_replace('\\', '/', $relative);
    }

    /** Is this stored path a real, playable file inside the library? */
    public static function isPlayable(?string $relative): bool
    {
        if (blank($relative)) {
            return false;
        }

        $absolute = self::absolutePath($relative);

        return $absolute !== null
            && is_file($absolute)
            && in_array(strtolower(pathinfo($absolute, PATHINFO_EXTENSION)), self::PLAYABLE, true);
    }

    /** Bytes, or null when the file is missing. */
    public static function size(string $relative): ?int
    {
        $absolute = self::absolutePath($relative);

        return $absolute !== null && is_file($absolute) ? filesize($absolute) : null;
    }

    /** A human size for the admin screen. */
    public static function humanSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;

        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, $i === 0 ? 0 : 1).' '.$units[$i];
    }

    /**
     * A sensible lesson name for a file, guessed from its path.
     *
     * ⚠️ THE FILENAMES ARE NOT LESSON NAMES. The real library holds
     * "day 01/0811.mp4", "All video/Day 2.mp4" and
     * "day 03/0728(1)/0728(1).mp4" — a date, a proper name, and a camera dump.
     * Naming 29 lessons "0811" would be worse than useless.
     *
     * So: use the filename when it actually says something (it contains a
     * letter), otherwise walk up the folders until one does. "0811.mp4" inside
     * "day 01" becomes "Day 01"; "Day 2.mp4" stays "Day 2".
     *
     * It is a guess, and the admin can rename it on the same screen.
     */
    public static function suggestTitle(string $relative): string
    {
        $parts = array_values(array_filter(explode('/', str_replace('\\', '/', $relative))));

        if ($parts === []) {
            return 'Lesson';
        }

        // Last part without its extension, then each parent, nearest first.
        $candidates = [pathinfo(array_pop($parts), PATHINFO_FILENAME)];

        while ($parts !== []) {
            $candidates[] = array_pop($parts);
        }

        foreach ($candidates as $candidate) {
            if (preg_match('/\p{L}/u', $candidate)) {
                return \Illuminate\Support\Str::title(trim($candidate));
            }
        }

        return \Illuminate\Support\Str::title(trim($candidates[0])) ?: 'Lesson';
    }

    /** The MIME type to send, keyed off the extension we already allowlisted. */
    public static function mimeFor(string $relative): string
    {
        return match (strtolower(pathinfo($relative, PATHINFO_EXTENSION))) {
            'webm' => 'video/webm',
            'mov', 'm4v' => 'video/quicktime',
            default => 'video/mp4',
        };
    }
}
