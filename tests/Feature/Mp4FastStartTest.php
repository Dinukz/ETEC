<?php

namespace Tests\Feature;

use App\Services\FastStartJob;
use App\Support\Mp4FastStart;
use App\Support\Mp4Probe;
use Tests\Concerns\BuildsMp4;
use Tests\TestCase;

/**
 * ⚠️ WEB-OPTIMISING WITHOUT RE-ENCODING (2026-10-10).
 *
 * *"upload kraddi video eka web optimize wela upload wenn one, quality eka adu
 * wen nathiwa"*. The index moves in front of the picture data; every chunk of
 * picture must then be found at its NEW position holding exactly the bytes it
 * held at its OLD one. These tests check every chunk, in every layout the
 * owner's files can come in, and that the original is never harmed when
 * anything goes wrong.
 *
 * (Also run by hand on a real 1080p MP4 whose index was at the end: 12 chunks,
 * picture data byte-identical, length 21.917 s before and after.)
 */
class Mp4FastStartTest extends TestCase
{
    use BuildsMp4;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'faststart-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->dir);

        // The jobs these tests started — never leave their records behind.
        foreach (glob(FastStartJob::dir().DIRECTORY_SEPARATOR.'*.json') ?: [] as $record) {
            if (str_contains((string) @file_get_contents($record), basename($this->dir))) {
                @unlink($record);
                @unlink(substr($record, 0, -5).'.moov');
            }
        }

        parent::tearDown();
    }

    private function file(string $bytes, string $name = 'lesson.mp4'): string
    {
        $path = $this->dir.DIRECTORY_SEPARATOR.$name;
        file_put_contents($path, $bytes);

        return $path;
    }

    /** Runs a job to the end in deliberately tiny steps; returns the final job. */
    private function runJob(string $source, string $destination, bool $removeSource = false, int $step = 50): array
    {
        $plan = Mp4FastStart::analyse($source);
        $this->assertSame('needed', $plan['status'], (string) $plan['reason']);

        $job = FastStartJob::begin($source, $destination, $plan, $removeSource);

        for ($i = 0; $i < 10000 && $job['status'] === 'running'; $i++) {
            $job = FastStartJob::advance($job['id'], $step, 30);
        }

        return $job;
    }

    private function assertOptimisedCopyOf(string $original, string $result, int $chunkSize = 97): void
    {
        $this->assertSame(filesize($original), filesize($result), 'same size — nothing added, nothing lost');
        $this->assertSame('optimised', Mp4FastStart::analyse($result)['status']);
        $this->assertTrue(Mp4Probe::read($result, filesize($result))['faststart']);
        $this->assertSame(
            Mp4Probe::read($original, filesize($original))['duration'],
            Mp4Probe::read($result, filesize($result))['duration'],
        );
        $this->assertSameChunks($original, $result, $chunkSize);
        $this->assertFileDoesNotExist($result.FastStartJob::SUFFIX, 'no partial copy left behind');
    }

    // ----- the layouts ----------------------------------------------------------

    public function test_a_file_with_its_index_at_the_end_is_made_fast_start(): void
    {
        $original = $this->file($this->mp4(), 'original.mp4');
        $work = $this->file($this->mp4());

        $job = $this->runJob($work, $work);

        $this->assertSame('done', $job['status'], (string) $job['error']);
        $this->assertOptimisedCopyOf($original, $work);
        $this->assertFileDoesNotExist($job['moov_file']);
    }

    public function test_64_bit_offsets_and_a_64_bit_picture_box_are_handled(): void
    {
        $bytes = $this->mp4(['co64' => true, 'mdat64' => true]);
        $original = $this->file($bytes, 'original.mp4');
        $work = $this->file($bytes);

        $this->assertSame('done', $this->runJob($work, $work)['status']);
        $this->assertOptimisedCopyOf($original, $work);
    }

    public function test_boxes_before_the_picture_and_after_the_index_stay_in_place(): void
    {
        $bytes = $this->mp4(['free' => true, 'trailer' => true]);
        $original = $this->file($bytes, 'original.mp4');
        $work = $this->file($bytes);

        $this->assertSame('done', $this->runJob($work, $work)['status']);
        $this->assertOptimisedCopyOf($original, $work);

        $types = array_column(Mp4FastStart::topLevel(fopen($work, 'rb'), filesize($work)), 'type');
        $this->assertSame(['ftyp', 'free', 'moov', 'mdat', 'udta'], $types);
    }

    public function test_a_copy_can_go_to_a_new_place_and_the_source_is_removed(): void
    {
        $bytes = $this->mp4();
        $original = $this->file($bytes, 'original.mp4');
        $upload = $this->file($bytes, 'upload.part');
        $destination = $this->dir.DIRECTORY_SEPARATOR.'Day 01.mp4';

        $this->assertSame('done', $this->runJob($upload, $destination, removeSource: true)['status']);
        $this->assertFileDoesNotExist($upload);
        $this->assertOptimisedCopyOf($original, $destination);
    }

    public function test_an_already_optimised_file_is_left_alone(): void
    {
        $this->assertSame('optimised', Mp4FastStart::analyse($this->file($this->mp4(['faststart' => true])))['status']);
    }

    // ----- what it refuses -------------------------------------------------------

    public function test_a_fragmented_file_is_refused(): void
    {
        $bytes = $this->mp4().$this->box('moof', str_repeat("\0", 16));

        $plan = Mp4FastStart::analyse($this->file($bytes));

        $this->assertSame('unsupported', $plan['status']);
        $this->assertStringContainsString('moof', $plan['reason']);
    }

    public function test_a_cut_off_file_is_refused(): void
    {
        $bytes = $this->mp4();

        $this->assertSame('unsupported', Mp4FastStart::analyse($this->file(substr($bytes, 0, -10)))['status']);
    }

    public function test_something_that_is_not_an_mp4_is_refused(): void
    {
        $this->assertSame('unsupported', Mp4FastStart::analyse($this->file("<html>not a video</html>\n"))['status']);
    }

    public function test_an_offset_outside_the_file_is_refused(): void
    {
        $plan = Mp4FastStart::analyse($this->file($this->mp4(['chunks' => 3, 'offsets' => [40, 80, 99999999]])));

        $this->assertSame('unsupported', $plan['status']);
        $this->assertStringContainsString('outside the file', $plan['reason']);
    }

    public function test_an_offset_that_would_overflow_32_bits_is_refused(): void
    {
        $moov = $this->box('moov', $this->box('trak', $this->box('mdia', $this->box('minf', $this->box('stbl',
            $this->box('stco', "\0\0\0\0".pack('N', 1).pack('N', 0xFFFFFF00)),
        )))));

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('32-bit');

        Mp4FastStart::patchMoov($moov, 8, 100, 0x100000000, 0x1000, 0x200000000);
    }

    // ----- the original is never harmed -------------------------------------------

    /** A step killed half-way wrote junk past its recorded point; the next steps redo it. */
    public function test_an_interrupted_step_is_simply_redone(): void
    {
        $bytes = $this->mp4();
        $original = $this->file($bytes, 'original.mp4');
        $work = $this->file($bytes);

        $job = FastStartJob::begin($work, $work, Mp4FastStart::analyse($work), false);
        $job = FastStartJob::advance($job['id'], 300, 30);

        // The "killed" step: bytes written beyond what the job recorded.
        $h = fopen($job['output'], 'r+b');
        fseek($h, $job['written']);
        fwrite($h, str_repeat('X', 5000));
        fclose($h);

        while ($job['status'] === 'running') {
            $job = FastStartJob::advance($job['id'], 300, 30);
        }

        $this->assertSame('done', $job['status'], (string) $job['error']);
        $this->assertOptimisedCopyOf($original, $work);
    }

    /** ⚠️ The safety net: a copy that does not match is thrown away, the original kept. */
    public function test_a_copy_that_does_not_match_is_discarded_and_the_original_kept(): void
    {
        $bytes = $this->mp4();
        $work = $this->file($bytes);

        $job = FastStartJob::begin($work, $work, Mp4FastStart::analyse($work), false);

        while ($job['written'] < $job['total'] - 1) {
            $job = FastStartJob::advance($job['id'], min(200, $job['total'] - 1 - $job['written']), 30);
        }

        // Corrupt one picture byte at a checked chunk's NEW position.
        [, $new] = $job['samples'][0];
        $h = fopen($job['output'], 'r+b');
        fseek($h, $new);
        $byte = fread($h, 1);
        fseek($h, $new);
        fwrite($h, chr((ord($byte) + 1) % 256));
        fclose($h);

        $job = FastStartJob::advance($job['id'], 1000, 30);

        $this->assertSame('failed', $job['status']);
        $this->assertStringContainsString('did not match', $job['error']);
        $this->assertSame($bytes, file_get_contents($work), 'the original is untouched');
        $this->assertFileDoesNotExist($job['output']);
    }

    // ----- found by the review (2026-10-10) ------------------------------------------

    /**
     * ⚠️ A step must never put a limit on the whole PHP process: done
     * unconditionally it killed the full test run after 60 s, and on the live
     * host it could lower a longer limit. 0 (no limit) stays 0.
     */
    public function test_a_step_never_lowers_or_sets_the_time_limit(): void
    {
        set_time_limit(0); // the CLI default: no limit at all
        $before = ini_get('max_execution_time');
        $work = $this->file($this->mp4());

        $job = FastStartJob::begin($work, $work, Mp4FastStart::analyse($work), false);
        FastStartJob::advance($job['id'], 100, 30);

        $this->assertSame($before, ini_get('max_execution_time'));
    }

    /** A chunk shorter than the 64-byte check, right before a moved boundary, is still correct. */
    public function test_a_tiny_last_chunk_before_the_index_does_not_fail_the_check(): void
    {
        $chunks = 10;
        $size = 97;
        $start = strlen($this->box('ftyp', 'isom'.pack('N', 512).'isomiso2avc1mp41')) + 8;
        $offsets = [];

        for ($i = 0; $i < $chunks - 1; $i++) {
            $offsets[] = $start + $i * $size;
        }

        $offsets[] = $start + $chunks * $size - 4; // the last 4 bytes of the picture

        $bytes = $this->mp4(['chunks' => $chunks, 'offsets' => $offsets, 'after' => $this->box('free', str_repeat("\1", 40))]);
        $original = $this->file($bytes, 'original.mp4');
        $work = $this->file($bytes);

        $job = $this->runJob($work, $work);

        $this->assertSame('done', $job['status'], (string) $job['error']);

        $old = $this->chunkOffsets($original);
        $new = $this->chunkOffsets($work);
        $this->assertSame(substr($bytes, end($old), 4), substr(file_get_contents($work), end($new), 4));
    }

    /** "Size 0" on the index means "to the end of the file" — it must get its real size when moved. */
    public function test_an_index_with_no_recorded_size_is_given_one(): void
    {
        $bytes = $this->mp4();
        $boxes = Mp4FastStart::topLevel(fopen($this->file($bytes, 'probe.mp4'), 'rb'), strlen($bytes));
        $moov = collect($boxes)->firstWhere('type', 'moov');
        $bytes = substr_replace($bytes, pack('N', 0), $moov['start'], 4);

        $original = $this->file($bytes, 'original.mp4');
        $work = $this->file($bytes);

        $this->assertSame('done', $this->runJob($work, $work)['status']);
        $this->assertSame('optimised', Mp4FastStart::analyse($work)['status']);
        $this->assertSameChunks($original, $work, 97);
    }

    /** Item locations inside the index are absolute offsets this does not rewrite — refused. */
    public function test_item_locations_inside_the_index_are_refused(): void
    {
        $iloc = $this->box('iloc', str_repeat("\0", 16));
        $meta = $this->box('meta', "\0\0\0\0".$this->box('hdlr', str_repeat("\0", 24)).$iloc);

        $plan = Mp4FastStart::analyse($this->file($this->mp4(['moov_extra' => $this->box('udta', $meta)])));

        $this->assertSame('unsupported', $plan['status']);
        $this->assertStringContainsString('iloc', $plan['reason']);
    }

    /** An ordinary udta (titles, camera notes) is not mistaken for one. */
    public function test_ordinary_user_data_is_still_optimised(): void
    {
        $udta = $this->box('udta', $this->box("\xA9nam", 'Day 01').$this->box('meta', "\0\0\0\0".$this->box('hdlr', str_repeat("\0", 24))));

        $this->assertSame('needed', Mp4FastStart::analyse($this->file($this->mp4(['moov_extra' => $udta])))['status']);
    }

    /** The copy's name is taken the moment the job starts — a second job cannot share it. */
    public function test_the_copy_name_is_reserved_when_the_job_starts(): void
    {
        $work = $this->file($this->mp4());
        $destination = $this->dir.DIRECTORY_SEPARATOR.'Day 02.mp4';

        FastStartJob::begin($work, $destination, Mp4FastStart::analyse($work), false);

        $this->assertFileExists($destination.FastStartJob::SUFFIX);

        $this->expectException(\RuntimeException::class);
        FastStartJob::begin($work, $destination, Mp4FastStart::analyse($work), false);
    }

    /** A second click on a running job carries on with it — it is never reset to zero. */
    public function test_starting_a_running_job_again_carries_on_with_it(): void
    {
        $work = $this->file($this->mp4());
        $id = md5('in-place|'.$work);

        FastStartJob::begin($work, $work, Mp4FastStart::analyse($work), false, [], $id);
        $progressed = FastStartJob::advance($id, 500, 30);

        $again = FastStartJob::begin($work, $work, Mp4FastStart::analyse($work), false, [], $id);

        $this->assertSame($progressed['written'], $again['written']);
        $this->assertGreaterThan(0, $again['written']);
    }

    /** A copy that vanished mid-way is never re-created with a hole of zeros. */
    public function test_a_copy_that_vanished_fails_the_job_instead_of_being_recreated(): void
    {
        $bytes = $this->mp4();
        $work = $this->file($bytes);

        $job = FastStartJob::begin($work, $work, Mp4FastStart::analyse($work), false);
        $job = FastStartJob::advance($job['id'], 500, 30);

        unlink($job['output']);
        touch($job['output']); // an empty file where the copy was

        $job = FastStartJob::advance($job['id'], 100000, 30);

        $this->assertSame('failed', $job['status']);
        $this->assertStringContainsString('went missing', $job['error'], 'stopped at once, before writing anything');
        $this->assertSame($bytes, file_get_contents($work));
    }

    public function test_a_source_that_changes_mid_way_stops_the_job_and_is_untouched(): void
    {
        $work = $this->file($this->mp4());

        $job = FastStartJob::begin($work, $work, Mp4FastStart::analyse($work), false);
        $job = FastStartJob::advance($job['id'], 100, 30);

        $changed = file_get_contents($work).'extra';
        file_put_contents($work, $changed);

        $job = FastStartJob::advance($job['id'], 100000, 30);

        $this->assertSame('failed', $job['status']);
        $this->assertSame($changed, file_get_contents($work));
        $this->assertFileDoesNotExist($job['output']);
    }
}
