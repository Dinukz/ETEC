<?php

namespace Tests\Unit;

use App\Support\Mp4Probe;
use PHPUnit\Framework\TestCase;

/**
 * The Course Videos screen flags slow files (2026-10-01). These build tiny MP4
 * skeletons box by box, so every branch of the header reader is exercised
 * without a real video in the repository.
 */
class Mp4ProbeTest extends TestCase
{
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    private function box(string $type, string $body): string
    {
        return pack('N', 8 + strlen($body)).$type.$body;
    }

    private function mvhdV0(int $timescale, int $duration): string
    {
        // version+flags, creation, modification, timescale, duration, then padding
        return $this->box('mvhd', "\0\0\0\0".pack('N', 0).pack('N', 0).pack('N', $timescale).pack('N', $duration).str_repeat("\0", 80));
    }

    private function mvhdV1(int $timescale, int $duration): string
    {
        return $this->box('mvhd', "\1\0\0\0".pack('J', 0).pack('J', 0).pack('N', $timescale).pack('J', $duration).str_repeat("\0", 80));
    }

    private function write(string $bytes): string
    {
        $file = tempnam(sys_get_temp_dir(), 'mp4p');
        file_put_contents($file, $bytes);
        $this->files[] = $file;

        return $file;
    }

    private function probe(string $bytes): array
    {
        $file = $this->write($bytes);

        return Mp4Probe::read($file, filesize($file));
    }

    public function test_a_web_optimised_file_has_its_index_first(): void
    {
        $result = $this->probe(
            $this->box('ftyp', 'isom0000')
            .$this->box('moov', $this->mvhdV0(1000, 125000))
            .$this->box('mdat', str_repeat('x', 500)),
        );

        $this->assertTrue($result['faststart']);
        $this->assertEqualsWithDelta(125.0, $result['duration'], 0.001);
    }

    public function test_an_index_at_the_end_is_not_web_optimised(): void
    {
        $result = $this->probe(
            $this->box('ftyp', 'isom0000')
            .$this->box('mdat', str_repeat('x', 500))
            .$this->box('moov', $this->mvhdV0(600, 6000)),
        );

        $this->assertFalse($result['faststart']);
        $this->assertEqualsWithDelta(10.0, $result['duration'], 0.001);
    }

    public function test_a_version_one_header_is_read(): void
    {
        $result = $this->probe($this->box('moov', $this->mvhdV1(90000, 90000 * 3600)));

        $this->assertEqualsWithDelta(3600.0, $result['duration'], 0.001);
    }

    /** A multi-gigabyte mdat carries a 64-bit size — size field 1. */
    public function test_a_64_bit_box_size_is_followed(): void
    {
        $mdatBody = str_repeat('x', 100);
        $mdat = pack('N', 1).'mdat'.pack('J', 16 + strlen($mdatBody)).$mdatBody;

        $result = $this->probe($mdat.$this->box('moov', $this->mvhdV0(10, 50)));

        $this->assertFalse($result['faststart'], 'moov was found AFTER the 64-bit mdat');
        $this->assertEqualsWithDelta(5.0, $result['duration'], 0.001);
    }

    /** Size 0 = "runs to the end of the file". */
    public function test_a_box_that_runs_to_the_end_is_handled(): void
    {
        $result = $this->probe(
            $this->box('moov', $this->mvhdV0(10, 20))
            .pack('N', 0).'mdat'.str_repeat('x', 300),
        );

        $this->assertTrue($result['faststart']);
        $this->assertEqualsWithDelta(2.0, $result['duration'], 0.001);
    }

    public function test_a_truncated_file_gives_nothing_rather_than_a_crash(): void
    {
        $whole = $this->box('ftyp', 'isom0000').$this->box('mdat', str_repeat('x', 500));
        $result = $this->probe(substr($whole, 0, 40)); // cut inside mdat, no moov

        $this->assertNull($result['faststart']);
        $this->assertNull($result['duration']);
    }

    public function test_something_that_is_not_an_mp4_gives_nothing(): void
    {
        $result = $this->probe(random_bytes(64)."\0\0\0\0");

        $this->assertNull($result['faststart']);
        $this->assertNull($result['duration']);
    }

    public function test_bitrate_is_bits_per_second(): void
    {
        $this->assertSame(8000.0, Mp4Probe::bitrate(1000, 1.0));
        $this->assertNull(Mp4Probe::bitrate(1000, null));
        $this->assertNull(Mp4Probe::bitrate(1000, 0.0));
    }
}
