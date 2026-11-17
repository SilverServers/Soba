<?php

declare(strict_types=1);

namespace Cms\Media;

use Cms\Helpers\Dates;

/**
 * WHEN A PHOTO WAS TAKEN, from the EXIF its camera wrote into it.
 *
 * Read as the file enters the library, from the file as it came: the copy the
 * library keeps carries no EXIF (`Optimizer` removes it, and the camera's GPS
 * with it), so that is the one moment the date can be had.
 *
 * Read from the bytes, with no extension needed: a JPEG's APP1 segment, a
 * PNG's eXIf chunk and a WebP's EXIF chunk hold the same TIFF structure, and
 * three of its tags are all that is wanted:
 *
 *   DateTimeOriginal    when the picture was taken
 *   DateTimeDigitized   when it was made digital: the same moment for a camera,
 *                       and what a scanner writes. Read when there is no
 *                       DateTimeOriginal
 *   OffsetTimeOriginal  the camera's offset from UTC, when it wrote one
 *
 * IFD0's DateTime is not read: it is when the file was last changed, which for
 * an edited photo is the day of the edit.
 *
 * A camera writes its own clock's time, with no zone. With an offset it is read
 * in that offset; without one, in the site's zone (Settings → General →
 * Timezone), as a date typed in the admin is. It is returned in UTC, as every
 * stored moment is.
 *
 * Nothing here trusts the file. Every offset is checked against the bytes there
 * are, and a date that is not a real one ("0000:00:00 00:00:00" is what a camera
 * with no clock set writes) is no date.
 */
final class Exif
{
    /** Entries read from one directory at most. A camera writes a few dozen. */
    private const MAX_ENTRIES = 512;

    /** UTC `Y-m-d H:i:s` from the file at `$path`, or null when it says nothing usable. */
    public static function taken(string $path, string $mime): ?string
    {
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            return null;
        }
        $bytes = @file_get_contents($path);

        return is_string($bytes) ? self::takenFrom($bytes, $mime) : null;
    }

    /** The same, from a file's bytes. */
    public static function takenFrom(string $bytes, string $mime): ?string
    {
        $tiff = match ($mime) {
            'image/jpeg' => self::fromJpeg($bytes),
            'image/png'  => self::fromPng($bytes),
            'image/webp' => self::fromWebp($bytes),
            default      => null,
        };

        return $tiff === null ? null : self::fromTiff($tiff);
    }

    /** The TIFF block in a JPEG's "Exif" APP1 segment. */
    private static function fromJpeg(string $b): ?string
    {
        $n = strlen($b);
        if ($n < 4 || substr($b, 0, 2) !== "\xFF\xD8") {
            return null;
        }
        $i = 2;
        while ($i + 4 <= $n) {
            if ($b[$i] !== "\xFF") {
                return null;
            }
            $marker = ord($b[$i + 1]);
            if ($marker === 0xFF) {
                $i++;                                        // a fill byte
                continue;
            }
            if ($marker === 0xDA || $marker === 0xD9) {
                return null;                                 // the picture starts, or the file ends
            }
            if ($marker === 0x01 || ($marker >= 0xD0 && $marker <= 0xD7)) {
                $i += 2;                                     // a marker with no length
                continue;
            }
            $len = (int) unpack('n', $b, $i + 2)[1];
            if ($len < 2 || $i + 2 + $len > $n) {
                return null;
            }
            if ($marker === 0xE1 && $len >= 8 && substr($b, $i + 4, 5) === "Exif\0") {
                return substr($b, $i + 10, $len - 8);
            }
            $i += 2 + $len;
        }

        return null;
    }

    /** A PNG's eXIf chunk, which is the TIFF block itself. */
    private static function fromPng(string $b): ?string
    {
        $n = strlen($b);
        if (substr($b, 0, 8) !== "\x89PNG\r\n\x1a\n") {
            return null;
        }
        $i = 8;
        while ($i + 12 <= $n) {
            $len  = (int) unpack('N', $b, $i)[1];
            $type = substr($b, $i + 4, 4);
            if ($len > $n - $i - 12) {
                return null;
            }
            if ($type === 'eXIf') {
                return substr($b, $i + 8, $len);
            }
            if ($type === 'IEND') {
                return null;
            }
            $i += 12 + $len;
        }

        return null;
    }

    /** A WebP's EXIF chunk. Some writers keep JPEG's "Exif" header in front of it. */
    private static function fromWebp(string $b): ?string
    {
        $n = strlen($b);
        if ($n < 12 || substr($b, 0, 4) !== 'RIFF' || substr($b, 8, 4) !== 'WEBP') {
            return null;
        }
        $i = 12;
        while ($i + 8 <= $n) {
            $type = substr($b, $i, 4);
            $len  = (int) unpack('V', $b, $i + 4)[1];
            if ($len > $n - $i - 8) {
                return null;
            }
            if ($type === 'EXIF') {
                $data = substr($b, $i + 8, $len);
                return str_starts_with($data, "Exif\0") ? substr($data, 6) : $data;
            }
            $i += 8 + $len + ($len & 1);                     // chunks are padded to an even size
        }

        return null;
    }

    /** The date in a TIFF block: its Exif directory first, then IFD0, where some writers put it. */
    private static function fromTiff(string $t): ?string
    {
        $le = match (substr($t, 0, 2)) {
            'II'    => true,
            'MM'    => false,
            default => null,
        };
        if ($le === null || self::u16($t, 2, $le) !== 42) {
            return null;
        }
        $ifd0 = self::entries($t, self::u32($t, 4, $le), $le);

        // The Exif directory's address: a LONG, or an IFD as some writers type it.
        $pointer = $ifd0[0x8769] ?? null;
        $exif    = $pointer !== null && in_array($pointer['type'], [4, 13], true) && $pointer['count'] === 1
            ? self::entries($t, self::u32($t, $pointer['at'], $le), $le)
            : [];

        foreach ([[0x9003, 0x9011], [0x9004, 0x9012]] as [$tag, $offsetTag]) {
            foreach ([$exif, $ifd0] as $dir) {
                $when = self::ascii($t, $dir[$tag] ?? null, $le);
                if ($when === null) {
                    continue;
                }
                $moment = self::moment($when, self::ascii($t, $dir[$offsetTag] ?? null, $le));
                if ($moment !== null) {
                    return $moment;
                }
            }
        }

        return null;
    }

    /**
     * A directory's entries, by tag: each one's type, count, and where its
     * four value bytes sit.
     *
     * @return array<int, array{type: int, count: int, at: int}>
     */
    private static function entries(string $t, ?int $at, bool $le): array
    {
        $count = $at === null ? null : self::u16($t, $at, $le);
        if ($count === null) {
            return [];
        }
        $count = min($count, self::MAX_ENTRIES, intdiv(strlen($t) - $at - 2, 12));

        $out = [];
        for ($k = 0; $k < $count; $k++) {
            $e   = $at + 2 + $k * 12;
            $tag = (int) self::u16($t, $e, $le);
            $out[$tag] ??= [
                'type'  => (int) self::u16($t, $e + 2, $le),
                'count' => (int) self::u32($t, $e + 4, $le),
                'at'    => $e + 8,
            ];
        }

        return $out;
    }

    /** An ASCII entry's text: in its own four bytes when it fits, else where they point. */
    private static function ascii(string $t, ?array $entry, bool $le): ?string
    {
        if ($entry === null || $entry['type'] !== 2 || $entry['count'] < 1 || $entry['count'] > 64) {
            return null;
        }
        $from = $entry['count'] <= 4 ? $entry['at'] : self::u32($t, $entry['at'], $le);
        if ($from === null || $from + $entry['count'] > strlen($t)) {
            return null;
        }

        return rtrim(substr($t, $from, $entry['count']), "\0 ");
    }

    /** "2019:07:04 14:30:05", with its offset or in the site's zone, as UTC. */
    private static function moment(string $when, ?string $offset): ?string
    {
        if (preg_match('/^(\d{4})[:\-](\d{2})[:\-](\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?/', $when, $m) !== 1) {
            return null;
        }
        [$y, $mo, $d, $h, $mi, $s] = [(int) $m[1], (int) $m[2], (int) $m[3], (int) $m[4], (int) $m[5], (int) ($m[6] ?? 0)];
        if (!checkdate($mo, $d, $y) || $h > 23 || $mi > 59 || $s > 59) {
            return null;
        }
        $zone = $offset !== null && preg_match('/^[+-](?:0\d|1[0-4]):[0-5]\d$/', $offset) === 1
            ? new \DateTimeZone($offset)
            : Dates::zone();
        $utc = (new \DateTimeImmutable('now', $zone))
            ->setDate($y, $mo, $d)
            ->setTime($h, $mi, $s)
            ->setTimezone(new \DateTimeZone('UTC'));
        $year = (int) $utc->format('Y');

        return $year >= 1000 && $year <= 9999 ? $utc->format('Y-m-d H:i:s') : null;
    }

    private static function u16(string $b, int $at, bool $le): ?int
    {
        return $at >= 0 && $at + 2 <= strlen($b) ? (int) unpack($le ? 'v' : 'n', $b, $at)[1] : null;
    }

    private static function u32(string $b, int $at, bool $le): ?int
    {
        return $at >= 0 && $at + 4 <= strlen($b) ? (int) unpack($le ? 'V' : 'N', $b, $at)[1] : null;
    }
}
