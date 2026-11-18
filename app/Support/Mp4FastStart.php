<?php

namespace App\Support;

/**
 * Makes an MP4 "web-optimised" (fast start) WITHOUT re-encoding it.
 *
 * Asked for 2026-10-10: *"upload kraddi video eka web optimize wela upload wenn
 * one, quality eka adu wen nathiwa"*. Every lesson on the Course Videos screen
 * read "Not web-optimised": the file's index (the `moov` box — where every frame
 * is, how long the video is) sits AFTER the picture data (`mdat`), so a player
 * has to fetch the END of a 2 GB file before it can show the first frame.
 *
 * ⚠️ QUALITY CANNOT CHANGE, BECAUSE NO PICTURE IS TOUCHED. Fast start is a
 * re-arrangement: the index box moves in front of the picture data, and the
 * only numbers that change are the chunk positions inside the index (stco /
 * co64 tables), each pushed forward by exactly the index's own length. Every
 * byte of video and sound is copied as-is. This is what HandBrake's "Web
 * Optimized" tick and ffmpeg's -movflags +faststart do; neither tool exists on
 * this host, so it is done here in PHP.
 *
 *   before:  [ftyp][mdat ............ picture ............][moov]
 *   after:   [ftyp][moov][mdat ............ picture ............]
 *
 * ⚠️ IT REFUSES ANYTHING IT CANNOT PROVE SAFE, rather than guessing:
 * fragmented files (moof/sidx/mfra), a top-level `meta` (its iloc offsets are
 * absolute and not rewritten here), a compressed index (cmov), sample
 * auxiliary offsets (saio — encrypted content), an index above 64 MB, a chunk
 * offset that points outside the file or into the index itself, an offset that
 * would no longer fit in 32 bits, and any file whose boxes do not tile it
 * exactly. Refused means "left exactly as it is" — it still plays as before.
 *
 * The copy itself is done in resumable steps by App\Services\FastStartJob, so a
 * 2 GB file never has to fit inside one web request.
 */
class Mp4FastStart
{
    /** The index is held in memory while it is patched. */
    public const MAX_MOOV_BYTES = 64 * 1048576;

    /** Top-level boxes holding absolute offsets this class does not rewrite. */
    protected const REFUSE_TOP = ['moof', 'mfra', 'sidx', 'meta'];

    /** The only boxes walked on the way from moov down to the chunk tables. */
    protected const CONTAINERS = ['trak', 'mdia', 'minf', 'stbl'];

    /** How many (old, new) offset pairs are kept to check the result against. */
    public const SAMPLES_PER_TABLE = 16;

    /**
     * What it would take to make this file fast start.
     *
     * @return array{
     *   status: 'optimised'|'needed'|'unsupported',
     *   reason: ?string,
     *   size: int,
     *   insert_at?: int,
     *   moov_start?: int,
     *   moov_length?: int,
     *   moov?: string,
     *   samples?: array<int, array{0: int, 1: int}>
     * }
     */
    public static function analyse(string $path): array
    {
        $size = @filesize($path);

        if (! $size) {
            return self::refuse('The file is empty or missing.', 0);
        }

        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return self::refuse('The file cannot be opened.', $size);
        }

        try {
            $boxes = self::topLevel($handle, $size);

            if ($boxes === null) {
                return self::refuse('The file is damaged, or is not a normal MP4.', $size);
            }

            $types = array_column($boxes, 'type');

            foreach (self::REFUSE_TOP as $refused) {
                if (in_array($refused, $types, true)) {
                    return self::refuse("It contains a \"{$refused}\" box, which is not supported here.", $size);
                }
            }

            $moovIndexes = array_keys($types, 'moov', true);
            $firstMdat = array_search('mdat', $types, true);

            if (count($moovIndexes) !== 1) {
                return self::refuse(count($moovIndexes) === 0 ? 'The file has no index (moov) — it is not a playable MP4.' : 'The file has more than one index (moov).', $size);
            }

            if ($firstMdat === false) {
                return self::refuse('The file has no picture data (mdat).', $size);
            }

            $moovIndex = $moovIndexes[0];

            if ($moovIndex < $firstMdat) {
                return ['status' => 'optimised', 'reason' => null, 'size' => $size];
            }

            $moov = $boxes[$moovIndex];

            if ($moov['size'] > self::MAX_MOOV_BYTES) {
                return self::refuse('The index is larger than 64 MB.', $size);
            }

            fseek($handle, $moov['start']);
            $bytes = self::readExactly($handle, $moov['size']);

            if ($bytes === null) {
                return self::refuse('The index could not be read.', $size);
            }

            $insertAt = $boxes[$firstMdat]['start'];
            $samples = [];

            try {
                $patched = self::patchMoov($bytes, $moov['header'], $insertAt, $moov['start'], $moov['size'], $size, $samples);
            } catch (\UnexpectedValueException $e) {
                return self::refuse($e->getMessage(), $size);
            }

            // ⚠️ A LAST BOX MAY SAY "SIZE 0" — "runs to the end of the file".
            // Moved to the front, that would swallow the picture data, so the
            // real size is written in (review 2026-10-10).
            if (unpack('N', substr($patched, 0, 4))[1] === 0) {
                if ($moov['size'] > 0xFFFFFFFF) {
                    return self::refuse('The index has no recorded size and is too large to give it one.', $size);
                }

                $patched = pack('N', $moov['size']).substr($patched, 4);
            }

            return [
                'status' => 'needed',
                'reason' => null,
                'size' => $size,
                'insert_at' => $insertAt,
                'moov_start' => $moov['start'],
                'moov_length' => $moov['size'],
                'moov' => $patched,
                'samples' => $samples,
            ];
        } finally {
            fclose($handle);
        }
    }

    /**
     * The top-level boxes, which must tile the file exactly — or null.
     *
     * @param  resource  $handle
     * @return array<int, array{type: string, start: int, header: int, size: int}>|null
     */
    public static function topLevel($handle, int $fileSize): ?array
    {
        $boxes = [];
        $offset = 0;

        while ($offset < $fileSize) {
            if (count($boxes) >= 10000 || $fileSize - $offset < 8 || fseek($handle, $offset) !== 0) {
                return null;
            }

            $header = fread($handle, 8);

            if ($header === false || strlen($header) < 8) {
                return null;
            }

            $boxSize = unpack('N', substr($header, 0, 4))[1];
            $type = substr($header, 4, 4);
            $headerLength = 8;

            if ($boxSize === 1) {
                $large = fread($handle, 8);

                if ($large === false || strlen($large) < 8) {
                    return null;
                }

                $boxSize = unpack('J', $large)[1];
                $headerLength = 16;
            } elseif ($boxSize === 0) {
                $boxSize = $fileSize - $offset;
            }

            if ($boxSize < $headerLength || $boxSize > $fileSize - $offset || ! preg_match('/^[\x20-\x7e]{4}$/', $type)) {
                return null;
            }

            $boxes[] = ['type' => $type, 'start' => $offset, 'header' => $headerLength, 'size' => $boxSize];
            $offset += $boxSize;
        }

        return $boxes;
    }

    /**
     * The index with every chunk offset moved to where that chunk will sit once
     * the index itself is placed at $insertAt.
     *
     * Only bytes in [insertAt, moovStart) move — forward by exactly the index's
     * length. Bytes before insertAt, and after the old index, stay put.
     *
     * @param  array<int, array{0: int, 1: int}>  $samples  filled with (old, new) pairs to verify the copy against
     *
     * @throws \UnexpectedValueException when the file cannot be safely rewritten
     */
    public static function patchMoov(string $moov, int $moovHeader, int $insertAt, int $moovStart, int $moovLength, int $fileSize, array &$samples = []): string
    {
        $shift = function (int $offset) use ($insertAt, $moovStart, $moovLength, $fileSize): int {
            if ($offset < 0 || $offset >= $fileSize) {
                throw new \UnexpectedValueException('A chunk offset points outside the file.');
            }

            if ($offset >= $moovStart && $offset < $moovStart + $moovLength) {
                throw new \UnexpectedValueException('A chunk offset points into the index itself.');
            }

            return $offset >= $insertAt && $offset < $moovStart ? $offset + $moovLength : $offset;
        };

        self::walk($moov, $moovHeader, strlen($moov), 0, $shift, $samples);

        return $moov;
    }

    /**
     * @param  callable(int): int  $shift
     * @param  array<int, array{0: int, 1: int}>  $samples
     */
    protected static function walk(string &$buffer, int $from, int $to, int $depth, callable $shift, array &$samples): void
    {
        if ($depth > 8) {
            throw new \UnexpectedValueException('The index is nested too deeply.');
        }

        $offset = $from;

        while ($offset < $to) {
            if ($to - $offset < 8) {
                // A few trailing padding bytes inside a container are legal.
                return;
            }

            $boxSize = unpack('N', substr($buffer, $offset, 4))[1];
            $type = substr($buffer, $offset + 4, 4);
            $headerLength = 8;

            if ($boxSize === 1) {
                if ($to - $offset < 16) {
                    throw new \UnexpectedValueException('A box inside the index is cut short.');
                }

                $boxSize = unpack('J', substr($buffer, $offset + 8, 8))[1];
                $headerLength = 16;
            } elseif ($boxSize === 0) {
                $boxSize = $to - $offset;
            }

            if ($boxSize < $headerLength || $boxSize > $to - $offset) {
                throw new \UnexpectedValueException('A box inside the index has an impossible size.');
            }

            if ($type === 'cmov') {
                throw new \UnexpectedValueException('The index is compressed (cmov), which is not supported here.');
            }

            if ($type === 'saio') {
                throw new \UnexpectedValueException('The file carries encryption offsets (saio), which are not supported here.');
            }

            $payload = $offset + $headerLength;
            $end = $offset + $boxSize;

            // ⚠️ Item locations (iloc) inside a meta box hold ABSOLUTE file
            // offsets this class does not rewrite — refuse rather than leave
            // them pointing at the wrong bytes (review 2026-10-10). Looked for
            // without trusting the box layout: a strangely-built udta in a file
            // that plays fine must not make the whole file refused.
            if (($type === 'meta' || $type === 'udta') && self::holdsItemLocations($buffer, $type, $payload, $end, 0)) {
                throw new \UnexpectedValueException('The index carries item locations (iloc), which are not supported here.');
            }

            if (in_array($type, self::CONTAINERS, true)) {
                self::walk($buffer, $payload, $end, $depth + 1, $shift, $samples);
            } elseif ($type === 'stco' || $type === 'co64') {
                self::patchTable($buffer, $type, $payload, $end, $shift, $samples);
            }

            $offset = $end;
        }
    }

    /**
     * Rewrites one chunk-offset table in place (same length, so every other
     * position in the buffer stays valid).
     *
     * @param  callable(int): int  $shift
     * @param  array<int, array{0: int, 1: int}>  $samples
     */
    protected static function patchTable(string &$buffer, string $type, int $payload, int $end, callable $shift, array &$samples): void
    {
        $width = $type === 'co64' ? 8 : 4;

        // version + flags (4), entry_count (4), then the entries.
        if ($end - $payload < 8) {
            throw new \UnexpectedValueException("A {$type} table is cut short.");
        }

        $count = unpack('N', substr($buffer, $payload + 4, 4))[1];
        $tableStart = $payload + 8;

        if ($count * $width > $end - $tableStart) {
            throw new \UnexpectedValueException("A {$type} table claims more entries than it holds.");
        }

        if ($count === 0) {
            return;
        }

        $old = array_values(unpack($width === 8 ? 'J*' : 'N*', substr($buffer, $tableStart, $count * $width)));
        $new = [];

        foreach ($old as $value) {
            $moved = $shift((int) $value);

            if ($width === 4 && $moved > 0xFFFFFFFF) {
                throw new \UnexpectedValueException('The file is too large for its 32-bit index once optimised.');
            }

            $new[] = $moved;
        }

        $buffer = substr_replace($buffer, pack($width === 8 ? 'J*' : 'N*', ...$new), $tableStart, $count * $width);

        // First, last and evenly spaced pairs — checked against the finished copy.
        $step = max(1, intdiv($count, self::SAMPLES_PER_TABLE));

        for ($i = 0; $i < $count; $i += $step) {
            $samples[] = [(int) $old[$i], $new[$i]];
        }

        $samples[] = [(int) $old[$count - 1], $new[$count - 1]];
    }

    /**
     * Is there an iloc box among this meta/udta box's children (or theirs)?
     *
     * Never throws: anything that does not parse is simply "not found here".
     * An ISO meta box starts with 4 bytes of version/flags and a QuickTime one
     * does not, so both readings are tried.
     */
    protected static function holdsItemLocations(string $buffer, string $type, int $from, int $to, int $depth): bool
    {
        if ($depth > 4) {
            return false;
        }

        $starts = $type === 'meta' ? [$from + 4, $from] : [$from];

        foreach ($starts as $start) {
            $offset = $start;

            while ($to - $offset >= 8) {
                $size = unpack('N', substr($buffer, $offset, 4))[1];
                $child = substr($buffer, $offset + 4, 4);
                $header = 8;

                if ($size === 1 && $to - $offset >= 16) {
                    $size = unpack('J', substr($buffer, $offset + 8, 8))[1];
                    $header = 16;
                } elseif ($size === 0) {
                    $size = $to - $offset;
                }

                if ($size < $header || $size > $to - $offset) {
                    break;
                }

                if ($child === 'iloc') {
                    return true;
                }

                if (($child === 'meta' || $child === 'udta')
                    && self::holdsItemLocations($buffer, $child, $offset + $header, $offset + $size, $depth + 1)) {
                    return true;
                }

                $offset += $size;
            }
        }

        return false;
    }

    /** @param  resource  $handle */
    protected static function readExactly($handle, int $length): ?string
    {
        $out = '';

        while (strlen($out) < $length) {
            $piece = fread($handle, min(8388608, $length - strlen($out)));

            if ($piece === false || $piece === '') {
                return null;
            }

            $out .= $piece;
        }

        return $out;
    }

    /** @return array{status: 'unsupported', reason: string, size: int} */
    protected static function refuse(string $reason, int $size): array
    {
        return ['status' => 'unsupported', 'reason' => $reason, 'size' => $size];
    }
}
