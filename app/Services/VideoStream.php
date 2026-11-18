<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pushes a video file down the wire, honouring HTTP Range.
 *
 * ⚠️ BYTES ONLY — NO ACCESS RULES LIVE HERE. Two very different doors reach
 * these files: a STUDENT (must be enrolled, must have a viewing window open,
 * must present a signed link) and an ADMIN previewing on the Course Videos
 * screen (must hold a manage role, and must NOT consume a student's viewing).
 * Those rules belong to their own controllers. What must not be written twice
 * is the streaming itself, because getting it subtly different in one of them
 * is how one door ends up unable to seek.
 *
 * ⚠️ RANGE IS NOT OPTIONAL. Without a 206 a <video> element cannot seek at
 * all, and every scrub re-downloads from byte 0 — on a 2 GB lesson that is the
 * difference between a working player and an unusable one.
 *
 * ⚠️ EVERY RANGED ANSWER IS CAPPED (2026-10-01). A player opens with
 * `Range: bytes=0-`, and this used to answer with the WHOLE rest of the file in
 * one response — which the browser then reads only as fast as it plays, so one
 * viewer held one PHP worker for the entire lesson. Hostinger caps those
 * workers, so a few students watching at once starved the site and each other
 * ("video load wenna godak welawa yanawa"). Now each answer is at most
 * maxSpan() bytes and the browser asks for the next piece as it plays, which is
 * ordinary <video> behaviour against any server that answers short. A worker
 * is busy for a moment, and nothing is sent for parts nobody watches.
 */
class VideoStream
{
    /** Bytes per read. Small enough that a 2 GB file never approaches memory_limit. */
    public const CHUNK = 262144; // 256 KB

    /** Settings key holding the cap in MB. 0 switches capping off. */
    public const SPAN_SETTING = 'video_chunk_mb';

    public const DEFAULT_SPAN_MB = 4;

    /**
     * The most one ranged answer may carry, in bytes; 0 = no cap.
     *
     * Read from Settings so it can be changed — or switched off — on the live
     * site without an upload. $read is injectable for tests.
     */
    public static function maxSpan(?callable $read = null): int
    {
        try {
            $value = $read ? $read() : Setting::get(self::SPAN_SETTING);
        } catch (\Throwable) {
            $value = null;
        }

        $mb = is_numeric($value) ? (float) $value : (float) self::DEFAULT_SPAN_MB;

        return $mb > 0 ? (int) round($mb * 1048576) : 0;
    }

    /**
     * @param  string  $path  an ABSOLUTE path that the caller has already
     *                        validated — VideoLibrary::absolutePath() is the
     *                        only thing allowed to produce one.
     * @param  int|null  $maxSpan  bytes per ranged answer; null = the setting, 0 = no cap
     */
    public static function respond(string $path, Request $request, string $mime, ?int $maxSpan = null): Response
    {
        $size = filesize($path);
        $start = 0;
        $end = $size - 1;
        $status = 200;

        $range = $request->headers->get('Range');

        if ($range && preg_match('/bytes\s*=\s*(\d*)\s*-\s*(\d*)/i', $range, $m)) {
            if ($m[1] === '' && $m[2] !== '') {
                // ⚠️ "bytes=-N" is the LAST N bytes — players ask for it to find
                // an index stored at the end of the file. It used to be read as
                // the FIRST N, handing back the wrong part of the video.
                $suffix = (int) $m[2];

                if ($suffix > 0 && $size > 0) {
                    $start = max(0, $size - $suffix);
                    $status = 206;
                }
            } elseif ($m[1] !== '') {
                $start = (int) $m[1];

                if ($m[2] !== '') {
                    $end = (int) $m[2];
                }

                if ($size > 0 && $start >= $size) {
                    // Asked to start past the end — the one case HTTP has an
                    // answer for, and the player then knows the real size.
                    return response('', 416, [
                        'Content-Range' => "bytes */{$size}",
                        'Accept-Ranges' => 'bytes',
                        'Cache-Control' => 'private, no-store, max-age=0',
                    ]);
                }

                // A nonsense range gets the whole file rather than an error — some
                // mobile browsers send one on the very first request.
                if ($start > $end) {
                    $start = 0;
                    $end = $size - 1;
                } else {
                    $status = 206;
                }
            }
        }

        $end = min($end, $size - 1);

        $maxSpan ??= self::maxSpan();

        if ($status === 206 && $maxSpan > 0 && $end - $start + 1 > $maxSpan) {
            $end = $start + $maxSpan - 1;
        }

        $length = $end - $start + 1;

        $headers = [
            'Content-Type' => $mime,
            'Content-Length' => (string) $length,
            'Accept-Ranges' => 'bytes',
            // Never an attachment: this must play, not download.
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ];

        if ($status === 206) {
            $headers['Content-Range'] = "bytes {$start}-{$end}/{$size}";
        }

        return response()->stream(function () use ($path, $start, $length) {
            $handle = fopen($path, 'rb');

            if ($handle === false) {
                return;
            }

            fseek($handle, $start);
            $remaining = $length;

            while ($remaining > 0 && ! feof($handle)) {
                $read = fread($handle, (int) min(self::CHUNK, $remaining));

                if ($read === false) {
                    break;
                }

                echo $read;
                flush();

                $remaining -= strlen($read);

                // The visitor closed the tab — stop reading a 2 GB file into a
                // socket nobody is listening to.
                if (connection_aborted()) {
                    break;
                }
            }

            fclose($handle);
        }, $status, $headers);
    }
}
