<?php

namespace Tests\Concerns;

use App\Support\Mp4FastStart;

/**
 * Small but structurally real MP4 files for the web-optimise tests.
 *
 * The picture data is position-dependent — every byte differs from its
 * neighbours — so a chunk read from the wrong place can never pass by luck,
 * and the checks compare EVERY chunk, not a sample.
 */
trait BuildsMp4
{
    protected function box(string $type, string $body): string
    {
        return pack('N', 8 + strlen($body)).$type.$body;
    }

    protected function payload(int $length, int $seed = 7): string
    {
        $out = '';

        for ($i = 0; $i < $length; $i++) {
            $out .= chr(($i * 31 + intdiv($i, 7) + $seed) % 256);
        }

        return $out;
    }

    /**
     * @param  array{
     *   faststart?: bool, co64?: bool, mdat64?: bool, free?: bool, trailer?: bool,
     *   chunks?: int, chunk?: int, timescale?: int, duration?: int, offsets?: ?array<int>,
     *   moov_extra?: string, after?: string
     * }  $o
     */
    protected function mp4(array $o = []): string
    {
        $chunks = $o['chunks'] ?? 40;
        $chunkSize = $o['chunk'] ?? 97;
        $ftyp = $this->box('ftyp', 'isom'.pack('N', 512).'isomiso2avc1mp41');
        $free = ($o['free'] ?? false) ? $this->box('free', str_repeat("\0", 13)) : '';
        $data = $this->payload($chunks * $chunkSize);
        $mdatHeader = ($o['mdat64'] ?? false)
            ? pack('N', 1).'mdat'.pack('J', 16 + strlen($data))
            : pack('N', 8 + strlen($data)).'mdat';
        $mdat = $mdatHeader.$data;
        $trailer = ($o['trailer'] ?? false) ? $this->box('udta', $this->box('note', 'kept as is')) : '';

        // Two passes: the offsets depend on where mdat lands, which depends on
        // the index's own length when the index comes first.
        $build = function (array $offsets) use ($o) {
            $mvhd = $this->box('mvhd', "\0\0\0\0".pack('NN', 0, 0).pack('N', $o['timescale'] ?? 1000).pack('N', $o['duration'] ?? 12345).str_repeat("\0", 80));
            $table = ($o['co64'] ?? false)
                ? $this->box('co64', "\0\0\0\0".pack('N', count($offsets)).pack('J*', ...$offsets))
                : $this->box('stco', "\0\0\0\0".pack('N', count($offsets)).pack('N*', ...$offsets));
            $stbl = $this->box('stbl', $this->box('stsd', str_repeat("\0", 8)).$table);
            $trak = $this->box('trak', $this->box('tkhd', str_repeat("\0", 84)).$this->box('mdia', $this->box('minf', $stbl)));

            return $this->box('moov', $mvhd.$trak.($o['moov_extra'] ?? ''));
        };

        $relative = [];
        for ($i = 0; $i < $chunks; $i++) {
            $relative[] = $i * $chunkSize;
        }

        $headerLength = strlen($mdatHeader);

        if ($o['faststart'] ?? false) {
            $moovLength = strlen($build(array_fill(0, $chunks, 0)));
            $base = strlen($ftyp.$free) + $moovLength + $headerLength;
            $moov = $build($o['offsets'] ?? array_map(fn ($r) => $base + $r, $relative));

            return $ftyp.$free.$moov.$mdat.$trailer;
        }

        $base = strlen($ftyp.$free) + $headerLength;
        $moov = $build($o['offsets'] ?? array_map(fn ($r) => $base + $r, $relative));

        return $ftyp.$free.$mdat.$trailer.$moov.($o['after'] ?? '');
    }

    /** Every chunk offset in a file's index, in order. */
    protected function chunkOffsets(string $path): array
    {
        $handle = fopen($path, 'rb');
        $boxes = Mp4FastStart::topLevel($handle, filesize($path));
        $moov = collect($boxes)->firstWhere('type', 'moov');
        fseek($handle, $moov['start']);
        $bytes = fread($handle, $moov['size']);
        fclose($handle);

        $found = [];
        $walk = function (int $from, int $to) use (&$walk, &$found, $bytes) {
            $at = $from;

            while ($to - $at >= 8) {
                $size = unpack('N', substr($bytes, $at, 4))[1];
                $type = substr($bytes, $at + 4, 4);
                $header = 8;

                if ($size === 1) {
                    $size = unpack('J', substr($bytes, $at + 8, 8))[1];
                    $header = 16;
                }

                if (in_array($type, ['trak', 'mdia', 'minf', 'stbl'], true)) {
                    $walk($at + $header, $at + $size);
                }

                if ($type === 'stco' || $type === 'co64') {
                    $count = unpack('N', substr($bytes, $at + $header + 4, 4))[1];
                    $width = $type === 'co64' ? 8 : 4;

                    foreach (unpack($width === 8 ? 'J*' : 'N*', substr($bytes, $at + $header + 8, $count * $width)) as $value) {
                        $found[] = $value;
                    }
                }

                $at += $size;
            }
        };

        $walk($moov['header'], $moov['size']);

        return $found;
    }

    /** Fails the test unless every chunk holds the same bytes in both files. */
    protected function assertSameChunks(string $original, string $optimised, int $chunkSize): void
    {
        $old = $this->chunkOffsets($original);
        $new = $this->chunkOffsets($optimised);

        $this->assertCount(count($old), $new, 'same number of chunks');

        $a = file_get_contents($original);
        $b = file_get_contents($optimised);

        foreach ($old as $i => $offset) {
            $this->assertSame(substr($a, $offset, $chunkSize), substr($b, $new[$i], $chunkSize), "chunk {$i} moved with its bytes");
        }
    }
}
