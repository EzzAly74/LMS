<?php

namespace App\Support;

/**
 * "Fast start" for MP4 / MOV (D-111): many recorders write the index
 * (`moov`) after the media (`mdat`), so a browser must fetch the end of the
 * file before it can play. This moves `moov` in front of `mdat` and shifts
 * every chunk offset (`stco` / `co64`) by the bytes it moved, the same
 * transformation as ffmpeg's `-movflags +faststart` / qt-faststart, in plain
 * PHP (the server needs no ffmpeg).
 *
 * Anything unexpected (compressed or oversized index, fragmented file, a
 * 32-bit offset that would overflow, a malformed box) leaves the file as it
 * was: playback still works, it just starts later.
 */
final class Mp4FastStart
{
    /** An index larger than this is not read into memory (normal: KB to a few MB). */
    private const MAX_MOOV = 64 * 1024 * 1024;

    /** Boxes whose children hold the sample tables. */
    private const CONTAINERS = ['moov', 'trak', 'mdia', 'minf', 'stbl'];

    /** Returns true when the file was rewritten. */
    public static function apply(string $path): bool
    {
        try {
            return self::run($path);
        } catch (\Throwable $e) {
            report($e);

            return false;
        }
    }

    private static function run(string $path): bool
    {
        $size = filesize($path);
        $in = fopen($path, 'rb');
        if ($in === false || $size === false) {
            return false;
        }

        // Top-level boxes: [type, offset, length].
        $boxes = [];
        $pos = 0;
        while ($pos < $size) {
            fseek($in, $pos);
            $head = fread($in, 8);
            if ($head === false || strlen($head) < 8) {
                fclose($in);

                return false;
            }
            $len = unpack('N', substr($head, 0, 4))[1];
            $type = substr($head, 4, 4);
            if ($len === 1) {
                $ext = fread($in, 8);
                if ($ext === false || strlen($ext) < 8) {
                    fclose($in);

                    return false;
                }
                $len = unpack('J', $ext)[1];
            } elseif ($len === 0) {
                $len = $size - $pos;
            }
            if ($len < 8 || $pos + $len > $size) {
                fclose($in);

                return false;
            }
            $boxes[] = [$type, $pos, $len];
            $pos += $len;
        }

        $types = array_column($boxes, 0);
        $moovAt = array_search('moov', $types, true);
        $mdatAt = array_search('mdat', $types, true);
        if ($moovAt === false || $mdatAt === false || $moovAt < $mdatAt || in_array('moof', $types, true)) {
            fclose($in);

            return false; // already fast, no media, or fragmented
        }

        [, $moovPos, $moovLen] = $boxes[$moovAt];
        if ($moovLen > self::MAX_MOOV) {
            fclose($in);

            return false;
        }
        fseek($in, $moovPos);
        $moov = fread($in, $moovLen);
        if ($moov === false || strlen($moov) !== $moovLen || ! self::shiftOffsets($moov, 0, $moovLen, $moovLen)) {
            fclose($in);

            return false;
        }

        // Boxes before the first mdat stay first, then the index, then the rest.
        $tmp = $path.'.faststart';
        $out = fopen($tmp, 'wb');
        if ($out === false) {
            fclose($in);

            return false;
        }
        $ok = true;
        foreach ($boxes as $i => [$type, $at, $len]) {
            if ($i === $mdatAt) {
                $ok = $ok && fwrite($out, $moov) === $moovLen;
            }
            if ($i === $moovAt) {
                continue;
            }
            fseek($in, $at);
            $ok = $ok && stream_copy_to_stream($in, $out, $len) === $len;
        }
        fclose($in);
        fclose($out);

        if (! $ok || filesize($tmp) !== $size) {
            @unlink($tmp);

            return false;
        }

        return rename($tmp, $path);
    }

    /**
     * Add $delta to every chunk offset inside the boxes of $buf[$from..$to).
     * False when the index cannot be shifted safely.
     */
    private static function shiftOffsets(string &$buf, int $from, int $to, int $delta): bool
    {
        $pos = $from;
        while ($pos + 8 <= $to) {
            $len = unpack('N', substr($buf, $pos, 4))[1];
            $type = substr($buf, $pos + 4, 4);
            $header = 8;
            if ($len === 1) {
                if ($pos + 16 > $to) {
                    return false;
                }
                $len = unpack('J', substr($buf, $pos + 8, 8))[1];
                $header = 16;
            } elseif ($len === 0) {
                $len = $to - $pos;
            }
            if ($len < $header || $pos + $len > $to) {
                return false;
            }

            if ($type === 'cmov') {
                return false; // compressed index
            }
            if (in_array($type, self::CONTAINERS, true)) {
                if (! self::shiftOffsets($buf, $pos + $header, $pos + $len, $delta)) {
                    return false;
                }
            } elseif ($type === 'stco' || $type === 'co64') {
                $wide = $type === 'co64';
                $count = unpack('N', substr($buf, $pos + $header + 4, 4))[1];
                $entry = $wide ? 8 : 4;
                $first = $pos + $header + 8;
                if ($first + $count * $entry > $pos + $len) {
                    return false;
                }
                // Checked first, written after: a failure leaves $buf as it was.
                $values = [];
                for ($k = 0; $k < $count; $k++) {
                    $at = $first + $k * $entry;
                    $value = unpack($wide ? 'J' : 'N', substr($buf, $at, $entry))[1] + $delta;
                    if (! $wide && $value > 0xFFFFFFFF) {
                        return false;
                    }
                    $values[$at] = pack($wide ? 'J' : 'N', $value);
                }
                // In place, byte by byte: no copy of the whole index per entry.
                foreach ($values as $at => $bytes) {
                    for ($b = 0; $b < $entry; $b++) {
                        $buf[$at + $b] = $bytes[$b];
                    }
                }
            }
            $pos += $len;
        }

        return true;
    }
}
