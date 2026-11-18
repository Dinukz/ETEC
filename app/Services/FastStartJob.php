<?php

namespace App\Services;

use App\Support\Mp4FastStart;
use App\Support\Mp4Probe;

/**
 * Writes the fast-start copy of a video in resumable steps (2026-10-10).
 *
 * A lesson is up to 2 GB. Copying that inside ONE web request would run into
 * the host's request time limit, so the copy is split: every call to advance()
 * copies at most STEP_BYTES / STEP_SECONDS and records how far it got. The
 * browser keeps calling until it is done, showing the progress.
 *
 * ⚠️ THE ORIGINAL IS NEVER TOUCHED UNTIL THE COPY HAS PROVED ITSELF. The copy is
 * written beside it as "<name>.etec-optimising" (not a playable extension, so
 * the library ignores it). Only once it is complete — right size, index now in
 * front, same length in seconds, and sampled chunks byte-identical to the
 * original at their old and new positions — is it renamed over the original
 * in one step. Any failure deletes the copy and leaves the original exactly as
 * it was.
 *
 * ⚠️ RESUMABLE BY CONSTRUCTION. The output is written at the recorded offset
 * (not appended), so a step killed half-way is simply redone by the next one,
 * and the file is truncated to its exact size at the end. Each step holds an
 * exclusive lock on the job file, so two browser tabs cannot interleave.
 */
class FastStartJob
{
    public const STEP_BYTES = 268435456; // 256 MB

    public const STEP_SECONDS = 15.0;

    public const BUFFER = 8388608; // 8 MB

    /** Bytes compared at each sampled chunk. */
    public const SAMPLE_BYTES = 64;

    /** Jobs untouched for this long are swept away with their partial copy. */
    public const STALE_SECONDS = 172800; // 2 days

    public const SUFFIX = '.etec-optimising';

    public static function dir(): string
    {
        $dir = storage_path('app/video-work');

        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return $dir;
    }

    public static function validId(string $id): bool
    {
        return (bool) preg_match('/^[a-f0-9]{32}$/', $id);
    }

    /** @return array<string, mixed>|null */
    public static function load(string $id): ?array
    {
        if (! self::validId($id)) {
            return null;
        }

        $path = self::dir().DIRECTORY_SEPARATOR.$id.'.json';

        if (! is_file($path)) {
            return null;
        }

        $job = json_decode((string) file_get_contents($path), true);

        return is_array($job) ? $job : null;
    }

    /**
     * Start writing the fast-start copy of $source to $destination.
     *
     * @param  array<string, mixed>  $plan  Mp4FastStart::analyse($source) with status "needed"
     * @param  array<string, mixed>  $meta  handed back untouched (who started it, what to attach)
     * @return array<string, mixed>
     */
    public static function begin(string $source, string $destination, array $plan, bool $removeSource, array $meta = [], ?string $id = null): array
    {
        if (($plan['status'] ?? null) !== 'needed') {
            throw new \InvalidArgumentException('That file does not need optimising.');
        }

        self::sweep();

        $id ??= bin2hex(random_bytes(16));
        $dir = self::dir();

        // ⚠️ UNDER THE JOB'S OWN LOCK, so a begin can never reset a job that
        // another request is advancing (review 2026-10-10: two begins on one
        // name let a step write into a re-created, zero-filled copy).
        $jobFile = $dir.DIRECTORY_SEPARATOR.$id.'.json';
        $lock = fopen($jobFile, 'c+');

        if ($lock === false) {
            throw new \RuntimeException('The optimising job could not be recorded.');
        }

        $recorded = false;

        try {
            flock($lock, LOCK_EX);
            $existing = json_decode((string) stream_get_contents($lock), true);

            // Still being worked on (a second click, a second admin): carry on
            // with it rather than start again.
            if (is_array($existing) && ($existing['status'] ?? null) === 'running' && time() - (int) ($existing['updated_at'] ?? 0) < 3600) {
                $recorded = true;

                return $existing;
            }

            $job = self::plan($id, $source, $destination, $plan, $removeSource, $meta, $existing);

            ftruncate($lock, 0);
            rewind($lock);
            fwrite($lock, (string) json_encode($job));
            fflush($lock);
            $recorded = true;

            return $job;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);

            // A brand-new job that could not start leaves no empty record.
            clearstatcache(true, $jobFile);

            if (! $recorded && is_file($jobFile) && filesize($jobFile) === 0) {
                @unlink($jobFile);
            }
        }
    }

    /**
     * Lay out the copy and RESERVE its file name — the name is taken the moment
     * the job exists, so a second upload of "day 16.mp4" picks "day 16 (2).mp4"
     * instead of writing into the same copy.
     *
     * @param  array<string, mixed>  $plan
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>|null  $existing  an earlier, finished or abandoned run of this same job
     * @return array<string, mixed>
     */
    protected static function plan(string $id, string $source, string $destination, array $plan, bool $removeSource, array $meta, ?array $existing): array
    {
        $dir = self::dir();
        $moovFile = $dir.DIRECTORY_SEPARATOR.$id.'.moov';
        $moov = (string) $plan['moov'];

        if (strlen($moov) !== (int) $plan['moov_length']) {
            throw new \UnexpectedValueException('The rewritten index changed length.');
        }

        $output = $destination.self::SUFFIX;

        // Only ever clear a partial copy that THIS job left behind.
        if (is_array($existing) && ($existing['output'] ?? null) === $output) {
            @unlink($output);
        }

        $reserved = @fopen($output, 'x');

        if ($reserved === false) {
            throw new \RuntimeException('Another copy is already being written under that name.');
        }

        fclose($reserved);
        file_put_contents($moovFile, $moov);

        $insert = (int) $plan['insert_at'];
        $moovStart = (int) $plan['moov_start'];
        $moovLength = (int) $plan['moov_length'];
        $size = (int) $plan['size'];

        // The finished file, as a list of pieces: [where from, source offset, length, output offset].
        $pieces = [
            ['src', 0, $insert],
            ['moov', 0, $moovLength],
            ['src', $insert, $moovStart - $insert],
            ['src', $moovStart + $moovLength, $size - $moovStart - $moovLength],
        ];

        $segments = [];
        $at = 0;

        foreach ($pieces as [$kind, $from, $length]) {
            if ($length > 0) {
                $segments[] = [$kind, $from, $length, $at];
                $at += $length;
            }
        }

        clearstatcache();

        return [
            'id' => $id,
            'status' => 'running',
            'error' => null,
            'source' => $source,
            'destination' => $destination,
            'output' => $output,
            'moov_file' => $moovFile,
            'remove_source' => $removeSource,
            'source_size' => $size,
            'source_mtime' => (int) filemtime($source),
            'duration' => Mp4Probe::read($source, $size)['duration'],
            'segments' => $segments,
            'samples' => array_slice($plan['samples'] ?? [], 0, 2000),
            // Where the moved regions of the SOURCE end — a sample is never
            // compared past one (see samplesMatch()).
            'insert_at' => $insert,
            'moov_start' => $moovStart,
            'total' => $at,
            'written' => 0,
            'meta' => $meta,
            'created_at' => time(),
            'updated_at' => time(),
        ];
    }

    /**
     * Copy the next slice. Returns the job as it now stands.
     *
     * @return array<string, mixed>|null
     */
    public static function advance(string $id, int $maxBytes = self::STEP_BYTES, float $maxSeconds = self::STEP_SECONDS): ?array
    {
        if (! self::validId($id)) {
            return null;
        }

        $path = self::dir().DIRECTORY_SEPARATOR.$id.'.json';

        if (! is_file($path)) {
            return null;
        }

        $lock = fopen($path, 'c+');

        if ($lock === false) {
            return null;
        }

        try {
            flock($lock, LOCK_EX);
            $job = json_decode((string) stream_get_contents($lock), true);

            if (! is_array($job) || $job['status'] !== 'running') {
                return is_array($job) ? $job : null;
            }

            // ⚠️ ONLY EVER RAISE A LIMIT, NEVER SET ONE (review 2026-10-10).
            // set_time_limit() lasts for the rest of the process: called
            // unconditionally it put a 60 s limit on the whole test run (which
            // then died half-way), and on the live host it could LOWER a longer
            // limit. 0 = no limit, which is left alone.
            $wanted = max(60, (int) ($maxSeconds * 4));
            $current = (int) ini_get('max_execution_time');

            if ($current > 0 && $current < $wanted) {
                @set_time_limit($wanted);
            }

            ignore_user_abort(true);

            $job = self::copy($job, $maxBytes, $maxSeconds);

            if ($job['status'] === 'running' && $job['written'] >= $job['total']) {
                $job = self::complete($job);
            }

            $job['updated_at'] = time();

            ftruncate($lock, 0);
            rewind($lock);
            fwrite($lock, (string) json_encode($job));
            fflush($lock);

            return $job;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** 0..1 */
    public static function progress(array $job): float
    {
        return $job['total'] > 0 ? min(1.0, $job['written'] / $job['total']) : 1.0;
    }

    /**
     * Remove finished jobs and abandoned partial copies older than two days.
     */
    public static function sweep(): void
    {
        foreach (glob(self::dir().DIRECTORY_SEPARATOR.'*.json') ?: [] as $file) {
            if (time() - (int) @filemtime($file) < self::STALE_SECONDS) {
                continue;
            }

            $job = json_decode((string) @file_get_contents($file), true);

            if (is_array($job)) {
                if (($job['status'] ?? null) === 'running' && ! empty($job['output'])) {
                    @unlink($job['output']);
                }

                if (! empty($job['moov_file'])) {
                    @unlink($job['moov_file']);
                }
            }

            @unlink($file);
        }
    }

    /**
     * @param  array<string, mixed>  $job
     * @return array<string, mixed>
     */
    protected static function copy(array $job, int $maxBytes, float $maxSeconds): array
    {
        clearstatcache();

        if (! is_file($job['source'])
            || (int) filesize($job['source']) !== (int) $job['source_size']
            || (int) filemtime($job['source']) !== (int) $job['source_mtime']) {
            return self::fail($job, 'The video file changed while it was being optimised — nothing was replaced. Try again.');
        }

        // ⚠️ The copy must still be the one this job has been filling. A copy
        // that vanished (or was replaced by an empty one) would otherwise be
        // re-created and its first part left as zeros.
        if (! is_file($job['output']) || (int) filesize($job['output']) < (int) $job['written']) {
            return self::fail($job, 'The partial copy went missing — nothing was replaced. Try again.');
        }

        $in = @fopen($job['source'], 'rb');
        $moov = @fopen($job['moov_file'], 'rb');
        $out = @fopen($job['output'], 'r+b');

        if ($in === false || $moov === false || $out === false) {
            foreach ([$in, $moov, $out] as $h) {
                if (is_resource($h)) {
                    fclose($h);
                }
            }

            return self::fail($job, 'The files needed for optimising could not be opened.');
        }

        try {
            fseek($out, (int) $job['written']);
            $started = microtime(true);
            $copied = 0;

            while ($job['written'] < $job['total'] && $copied < $maxBytes && (microtime(true) - $started) < $maxSeconds) {
                $segment = null;

                foreach ($job['segments'] as $candidate) {
                    if ($job['written'] >= $candidate[3] && $job['written'] < $candidate[3] + $candidate[2]) {
                        $segment = $candidate;
                        break;
                    }
                }

                if ($segment === null) {
                    return self::fail($job, 'The optimising plan is inconsistent.');
                }

                [$kind, $from, $length, $outStart] = $segment;
                $within = $job['written'] - $outStart;
                $n = (int) min(self::BUFFER, $length - $within, $maxBytes - $copied);
                $handle = $kind === 'moov' ? $moov : $in;

                fseek($handle, $from + $within);
                $data = fread($handle, $n);

                if ($data === false || strlen($data) !== $n) {
                    return self::fail($job, 'The video file could not be read.');
                }

                if (fwrite($out, $data) !== $n) {
                    return self::fail($job, 'The copy could not be written — the server disk may be full.');
                }

                $job['written'] += $n;
                $copied += $n;
            }

            fflush($out);

            return $job;
        } finally {
            fclose($in);
            fclose($moov);
            fclose($out);
        }
    }

    /**
     * Prove the copy, then swap it in.
     *
     * @param  array<string, mixed>  $job
     * @return array<string, mixed>
     */
    protected static function complete(array $job): array
    {
        $output = $job['output'];
        $handle = @fopen($output, 'r+b');

        if ($handle === false) {
            return self::fail($job, 'The finished copy is missing.');
        }

        // A step cut short earlier may have left bytes past the end.
        ftruncate($handle, (int) $job['total']);
        fclose($handle);
        clearstatcache();

        if ((int) filesize($output) !== (int) $job['total']) {
            return self::fail($job, 'The finished copy has the wrong size.');
        }

        if (Mp4FastStart::analyse($output)['status'] !== 'optimised') {
            return self::fail($job, 'The finished copy did not check out as web-optimised.');
        }

        $duration = Mp4Probe::read($output, (int) $job['total'])['duration'];

        if ($job['duration'] !== null && ($duration === null || abs($duration - $job['duration']) > 0.01)) {
            return self::fail($job, 'The finished copy has a different length — nothing was replaced.');
        }

        if (! self::samplesMatch($job)) {
            return self::fail($job, 'The finished copy did not match the original — nothing was replaced.');
        }

        if ($job['destination'] !== $job['source'] && file_exists($job['destination'])) {
            return self::fail($job, 'A file with that name appeared meanwhile — nothing was replaced.');
        }

        if (! @rename($output, $job['destination'])) {
            return self::fail($job, 'The finished copy could not be put in place.');
        }

        if ($job['remove_source'] && $job['source'] !== $job['destination']) {
            @unlink($job['source']);
        }

        @unlink($job['moov_file']);

        $job['status'] = 'done';

        return $job;
    }

    /** Each sampled chunk must hold the same bytes at its new position. */
    protected static function samplesMatch(array $job): bool
    {
        $src = @fopen($job['source'], 'rb');
        $dst = @fopen($job['output'], 'rb');

        if ($src === false || $dst === false) {
            return false;
        }

        try {
            foreach ($job['samples'] as [$old, $new]) {
                // Never compare across the end of a moved region: past it the
                // source and the copy legitimately hold different boxes (a
                // 4-byte chunk just before the old index is correct on its own).
                $regionEnd = match (true) {
                    $old < $job['insert_at'] => $job['insert_at'],
                    $old < $job['moov_start'] => $job['moov_start'],
                    default => $job['source_size'],
                };

                $n = (int) min(self::SAMPLE_BYTES, $regionEnd - $old, $job['total'] - $new);

                if ($n <= 0) {
                    return false;
                }

                fseek($src, (int) $old);
                fseek($dst, (int) $new);

                if (fread($src, $n) !== fread($dst, $n)) {
                    return false;
                }
            }

            return true;
        } finally {
            fclose($src);
            fclose($dst);
        }
    }

    /**
     * @param  array<string, mixed>  $job
     * @return array<string, mixed>
     */
    protected static function fail(array $job, string $reason): array
    {
        @unlink($job['output']);
        @unlink($job['moov_file']);

        $job['status'] = 'failed';
        $job['error'] = $reason;

        return $job;
    }
}
