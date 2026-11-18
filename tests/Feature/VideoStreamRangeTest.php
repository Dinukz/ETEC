<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\VideoStream;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * ⚠️ "VIDEO LOAD WENNA GODAK WELAWA YANAWA" (2026-10-01).
 *
 * The browser opens a lesson with `Range: bytes=0-`. VideoStream used to answer
 * that with the WHOLE rest of the file in one response, and the browser reads
 * it as slowly as it plays — so every viewer held one PHP worker for the whole
 * lesson. Hostinger caps those workers; a handful of students watching at once
 * starved the site and each other.
 *
 * Every ranged answer is now capped. The browser simply asks for the next
 * piece as it plays (that is how <video> works against any server that answers
 * short), so a worker is busy for a moment instead of an hour.
 *
 * ⚠️ THE FILE IS NOT ONE REPEATED BYTE. The older video tests use
 * str_repeat('A', 2048), where every offset holds the same byte — an answer
 * from the wrong place in the file can never fail them. Every byte here
 * depends on its position, and the body is compared against the exact slice.
 */
class VideoStreamRangeTest extends TestCase
{
    private string $file;

    private string $bytes;

    protected function setUp(): void
    {
        parent::setUp();

        // 10,000 bytes, each one a function of its offset.
        $this->bytes = '';
        for ($i = 0; $i < 10000; $i++) {
            $this->bytes .= chr(($i * 7 + intdiv($i, 251)) % 256);
        }

        $this->file = tempnam(sys_get_temp_dir(), 'vstream');
        file_put_contents($this->file, $this->bytes);
    }

    protected function tearDown(): void
    {
        @unlink($this->file);

        parent::tearDown();
    }

    /** Runs the response and returns [status, headers, body]. */
    private function fetch(?string $range, ?int $span = 1000): array
    {
        $request = Request::create('/video', 'GET');

        if ($range !== null) {
            $request->headers->set('Range', $range);
        }

        $response = VideoStream::respond($this->file, $request, 'video/mp4', $span);

        ob_start();
        $response->sendContent();
        $body = ob_get_clean();

        return [$response->getStatusCode(), $response->headers, $body];
    }

    public function test_an_open_ended_range_is_answered_in_one_capped_piece(): void
    {
        [$status, $headers, $body] = $this->fetch('bytes=0-');

        $this->assertSame(206, $status);
        $this->assertSame('bytes 0-999/10000', $headers->get('Content-Range'));
        $this->assertSame('1000', $headers->get('Content-Length'));
        $this->assertSame(substr($this->bytes, 0, 1000), $body);
    }

    public function test_the_next_piece_starts_where_the_browser_asks(): void
    {
        [$status, $headers, $body] = $this->fetch('bytes=4321-');

        $this->assertSame(206, $status);
        $this->assertSame('bytes 4321-5320/10000', $headers->get('Content-Range'));
        $this->assertSame(substr($this->bytes, 4321, 1000), $body);
    }

    public function test_the_last_piece_is_shorter_and_ends_at_the_file(): void
    {
        [$status, $headers, $body] = $this->fetch('bytes=9500-');

        $this->assertSame(206, $status);
        $this->assertSame('bytes 9500-9999/10000', $headers->get('Content-Range'));
        $this->assertSame(substr($this->bytes, 9500), $body);
    }

    /** Safari's first request — tiny, and must come back exactly. */
    public function test_a_two_byte_probe_is_answered_exactly(): void
    {
        [$status, $headers, $body] = $this->fetch('bytes=0-1');

        $this->assertSame(206, $status);
        $this->assertSame('bytes 0-1/10000', $headers->get('Content-Range'));
        $this->assertSame(substr($this->bytes, 0, 2), $body);
    }

    public function test_a_closed_range_larger_than_the_cap_is_capped_too(): void
    {
        [$status, $headers, $body] = $this->fetch('bytes=100-8999');

        $this->assertSame(206, $status);
        $this->assertSame('bytes 100-1099/10000', $headers->get('Content-Range'));
        $this->assertSame(substr($this->bytes, 100, 1000), $body);
    }

    /** ⚠️ "bytes=-N" means the LAST N bytes. It used to be read as the first N. */
    public function test_a_suffix_range_is_the_end_of_the_file(): void
    {
        [$status, $headers, $body] = $this->fetch('bytes=-100');

        $this->assertSame(206, $status);
        $this->assertSame('bytes 9900-9999/10000', $headers->get('Content-Range'));
        $this->assertSame(substr($this->bytes, -100), $body);
    }

    public function test_a_suffix_longer_than_the_file_is_the_whole_file_capped(): void
    {
        [$status, $headers, $body] = $this->fetch('bytes=-50000', span: 0);

        $this->assertSame(206, $status);
        $this->assertSame('bytes 0-9999/10000', $headers->get('Content-Range'));
        $this->assertSame($this->bytes, $body);
    }

    public function test_a_start_past_the_end_is_a_416(): void
    {
        [$status, $headers, $body] = $this->fetch('bytes=10000-');

        $this->assertSame(416, $status);
        $this->assertSame('bytes */10000', $headers->get('Content-Range'));
        $this->assertSame('', $body);
    }

    /** Kept from the original: some mobile browsers open with nonsense. */
    public function test_a_backwards_range_still_gets_the_whole_file(): void
    {
        [$status, $headers, $body] = $this->fetch('bytes=500-100', span: 0);

        $this->assertSame(200, $status);
        $this->assertSame('10000', $headers->get('Content-Length'));
        $this->assertSame($this->bytes, $body);
    }

    public function test_no_range_header_is_still_the_whole_file(): void
    {
        [$status, $headers, $body] = $this->fetch(null);

        $this->assertSame(200, $status);
        $this->assertSame('10000', $headers->get('Content-Length'));
        $this->assertSame($this->bytes, $body);
    }

    public function test_a_cap_of_zero_switches_capping_off(): void
    {
        [$status, $headers, $body] = $this->fetch('bytes=0-', span: 0);

        $this->assertSame(206, $status);
        $this->assertSame('bytes 0-9999/10000', $headers->get('Content-Range'));
        $this->assertSame($this->bytes, $body);
    }

    /** ⚠️ The protections stay exactly as they were. */
    public function test_it_is_still_inline_and_never_cached(): void
    {
        [, $headers] = $this->fetch('bytes=0-');

        $this->assertSame('inline', $headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', $headers->get('Cache-Control'));
        $this->assertSame('bytes', $headers->get('Accept-Ranges'));
    }

    /**
     * The cap comes from Settings (video_chunk_mb) so it can be changed or
     * switched off on the live site without an upload — FTP is the only way
     * in. 4 MB unless set.
     */
    public function test_the_default_cap_is_four_megabytes_and_a_setting_changes_it(): void
    {
        $this->assertSame(4 * 1048576, VideoStream::maxSpan(fn () => null));
        $this->assertSame(2 * 1048576, VideoStream::maxSpan(fn () => '2'));
        $this->assertSame(0, VideoStream::maxSpan(fn () => '0'));
        $this->assertSame(4 * 1048576, VideoStream::maxSpan(fn () => 'nonsense'));
    }
}
