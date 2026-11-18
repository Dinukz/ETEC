<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Reads just enough of an MP4 to say whether it will be slow to watch.
 *
 * Asked for indirectly on 2026-10-01 ("video quality eka wadi nisa load wenna
 * godak welawa yanawa"): the Course Videos screen now says WHICH files are the
 * problem, so the owner knows what to run through HandBrake first. Two facts:
 *
 *  - LENGTH, from the movie header (mvhd) — with the file size that gives the
 *    average bitrate. Above ~2.5 Mbit/s a lesson stutters on ordinary mobile
 *    data.
 *  - WEB-OPTIMISED, i.e. the index (moov) sits BEFORE the picture data (mdat).
 *    When it sits at the end, a player must fetch the end of the file before
 *    it can show a single frame. HandBrake's "Web Optimized" tick fixes it.
 *
 * ⚠️ NEVER READS THE PICTURE. Only box headers are read — a few dozen bytes per
 * box, seeking over the rest — so a 2 GB file costs the same as a 2 MB one.
 * Handles 64-bit box sizes (size 1), "runs to the end" (size 0), mvhd version 0
 * and 1, and a truncated or non-MP4 file (returns nulls rather than throwing).
 */
class Mp4Probe
{
    /** Above this many bits per second a lesson is flagged as heavy. */
    public const HEAVY_BPS = 2_500_000;

    /**
     * @return array{duration: ?float, faststart: ?bool}
     */
    public static function inspect(string $path): array
    {
        $size = @filesize($path);

        if (! $size) {
            return ['duration' => null, 'faststart' => null];
        }

        $key = 'mp4probe:'.md5($path.'|'.$size.'|'.@filemtime($path));

        try {
            return Cache::rememberForever($key, fn () => self::read($path, (int) $size));
        } catch (\Throwable) {
            return self::read($path, (int) $size);
        }
    }

    /** Average bits per second, or null when the length is unknown. */
    public static function bitrate(int $bytes, ?float $seconds): ?float
    {
        return $seconds !== null && $seconds > 0 ? $bytes * 8 / $seconds : null;
    }

    /**
     * @return array{duration: ?float, faststart: ?bool}
     */
    public static function read(string $path, int $size): array
    {
        $result = ['duration' => null, 'faststart' => null];
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return $result;
        }

        try {
            $moovAt = null;
            $mdatAt = null;

            foreach (self::boxes($handle, 0, $size) as [$type, $start, $headerLength, $boxSize]) {
                if ($type === 'moov' && $moovAt === null) {
                    $moovAt = $start;
                    $result['duration'] = self::durationIn($handle, $start + $headerLength, $start + $boxSize);
                }

                if ($type === 'mdat' && $mdatAt === null) {
                    $mdatAt = $start;
                }
            }

            if ($moovAt !== null) {
                $result['faststart'] = $mdatAt === null || $moovAt < $mdatAt;
            }
        } finally {
            fclose($handle);
        }

        return $result;
    }

    /**
     * The boxes between two offsets, as [type, start, header length, size].
     *
     * @param  resource  $handle
     * @return \Generator<int, array{0: string, 1: int, 2: int, 3: int}>
     */
    protected static function boxes($handle, int $from, int $to): \Generator
    {
        $offset = $from;

        for ($i = 0; $i < 4096 && $offset + 8 <= $to; $i++) {
            if (fseek($handle, $offset) !== 0) {
                return;
            }

            $header = fread($handle, 8);

            if ($header === false || strlen($header) < 8) {
                return;
            }

            $boxSize = unpack('N', substr($header, 0, 4))[1];
            $type = substr($header, 4, 4);
            $headerLength = 8;

            if ($boxSize === 1) {
                // A 64-bit size follows — normal for a multi-gigabyte mdat.
                $large = fread($handle, 8);

                if ($large === false || strlen($large) < 8) {
                    return;
                }

                $boxSize = unpack('J', $large)[1];
                $headerLength = 16;
            } elseif ($boxSize === 0) {
                // "Runs to the end of the file."
                $boxSize = $to - $offset;
            }

            if ($boxSize < $headerLength || ! preg_match('/^[\x20-\x7e]{4}$/', $type)) {
                return; // not an MP4, or damaged — stop rather than guess
            }

            yield [$type, $offset, $headerLength, $boxSize];

            $offset += $boxSize;
        }
    }

    /**
     * Seconds, from the mvhd box inside moov.
     *
     * @param  resource  $handle
     */
    protected static function durationIn($handle, int $from, int $to): ?float
    {
        foreach (self::boxes($handle, $from, $to) as [$type, $start, $headerLength]) {
            if ($type !== 'mvhd') {
                continue;
            }

            fseek($handle, $start + $headerLength);
            $body = fread($handle, 32);

            if ($body === false || strlen($body) < 20) {
                return null;
            }

            if (ord($body[0]) === 1) {
                // version 1: 8-byte creation + modification, 4 timescale, 8 duration
                if (strlen($body) < 32) {
                    return null;
                }

                $timescale = unpack('N', substr($body, 20, 4))[1];
                $duration = unpack('J', substr($body, 24, 8))[1];
            } else {
                // version 0: 4-byte creation + modification, 4 timescale, 4 duration
                $timescale = unpack('N', substr($body, 12, 4))[1];
                $duration = unpack('N', substr($body, 16, 4))[1];
            }

            return $timescale > 0 ? $duration / $timescale : null;
        }

        return null;
    }
}
