<?php

declare(strict_types=1);

namespace Cms\Security;

use InvalidArgumentException;

/**
 * A QR CODE, drawn here — ISO/IEC 18004, byte mode, versions 1–15.
 *
 * DESIGN-LINT: RAW-HEX-OK — a QR code is pure #000 on #fff or phones cannot read it; it must not follow the theme.
 *
 * It exists for one thing: the otpauth:// link an authenticator app reads when
 * two-step sign-in is set up (`Totp::uri()`). Every plugin that offers this
 * ships a QR library or calls a chart service with the SECRET in the URL;
 * the CMS does neither, so the encoder is written out in full.
 *
 * ── WHAT IS IN IT, IN THE ORDER A SYMBOL IS MADE ────────────────────────────
 *   1. codewords()   mode 0100, the length, the bytes, a terminator, 0xEC/0x11
 *                    padding — split into the version's blocks, each given its
 *                    Reed–Solomon error correction, then interleaved
 *   2. base()        finder, separator, timing and alignment patterns, the dark
 *                    module, the version information (7+), format areas reserved
 *   3. place()       the bits, two columns at a time from the bottom right,
 *                    up and down in turn, skipping column 6 and every function
 *                    module; what is left over (remainder bits) stays light
 *   4. matrix()      each of the eight masks tried with its format information,
 *                    the one with the lowest penalty kept
 */
final class Qr
{
    public const MAX_VERSION = 15;

    /** Error correction level => its two format bits. */
    private const LEVELS = ['L' => 1, 'M' => 0, 'Q' => 3, 'H' => 2];

    /**
     * version => level => [EC codewords per block, group-1 blocks, data codewords
     * in each, group-2 blocks, data codewords in each] — ISO/IEC 18004 Table 9.
     */
    private const BLOCKS = [
        1  => ['L' => [7, 1, 19, 0, 0],   'M' => [10, 1, 16, 0, 0],  'Q' => [13, 1, 13, 0, 0],  'H' => [17, 1, 9, 0, 0]],
        2  => ['L' => [10, 1, 34, 0, 0],  'M' => [16, 1, 28, 0, 0],  'Q' => [22, 1, 22, 0, 0],  'H' => [28, 1, 16, 0, 0]],
        3  => ['L' => [15, 1, 55, 0, 0],  'M' => [26, 1, 44, 0, 0],  'Q' => [18, 2, 17, 0, 0],  'H' => [22, 2, 13, 0, 0]],
        4  => ['L' => [20, 1, 80, 0, 0],  'M' => [18, 2, 32, 0, 0],  'Q' => [26, 2, 24, 0, 0],  'H' => [16, 4, 9, 0, 0]],
        5  => ['L' => [26, 1, 108, 0, 0], 'M' => [24, 2, 43, 0, 0],  'Q' => [18, 2, 15, 2, 16], 'H' => [22, 2, 11, 2, 12]],
        6  => ['L' => [18, 2, 68, 0, 0],  'M' => [16, 4, 27, 0, 0],  'Q' => [24, 4, 19, 0, 0],  'H' => [28, 4, 15, 0, 0]],
        7  => ['L' => [20, 2, 78, 0, 0],  'M' => [18, 4, 31, 0, 0],  'Q' => [18, 2, 14, 4, 15], 'H' => [26, 4, 13, 1, 14]],
        8  => ['L' => [24, 2, 97, 0, 0],  'M' => [22, 2, 38, 2, 39], 'Q' => [22, 4, 18, 2, 19], 'H' => [26, 4, 14, 2, 15]],
        9  => ['L' => [30, 2, 116, 0, 0], 'M' => [22, 3, 36, 2, 37], 'Q' => [20, 4, 16, 4, 17], 'H' => [24, 4, 12, 4, 13]],
        10 => ['L' => [18, 2, 68, 2, 69], 'M' => [26, 4, 43, 1, 44], 'Q' => [24, 6, 19, 2, 20], 'H' => [28, 6, 15, 2, 16]],
        11 => ['L' => [20, 4, 81, 0, 0],  'M' => [30, 1, 50, 4, 51], 'Q' => [28, 4, 22, 4, 23], 'H' => [24, 3, 12, 8, 13]],
        12 => ['L' => [24, 2, 92, 2, 93], 'M' => [22, 6, 36, 2, 37], 'Q' => [26, 4, 20, 6, 21], 'H' => [28, 7, 14, 4, 15]],
        13 => ['L' => [26, 4, 107, 0, 0], 'M' => [22, 8, 37, 1, 38], 'Q' => [24, 8, 20, 4, 21], 'H' => [22, 12, 11, 4, 12]],
        14 => ['L' => [30, 3, 115, 1, 116], 'M' => [24, 4, 40, 5, 41], 'Q' => [20, 11, 16, 5, 17], 'H' => [24, 11, 12, 5, 13]],
        15 => ['L' => [22, 5, 87, 1, 88], 'M' => [24, 5, 41, 5, 42], 'Q' => [30, 5, 24, 7, 25], 'H' => [24, 11, 12, 7, 13]],
    ];

    /** version => alignment pattern centre coordinates — ISO/IEC 18004 Annex E. */
    private const ALIGN = [
        1 => [], 2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30], 6 => [6, 34],
        7 => [6, 22, 38], 8 => [6, 24, 42], 9 => [6, 26, 46], 10 => [6, 28, 50],
        11 => [6, 30, 54], 12 => [6, 32, 58], 13 => [6, 34, 62],
        14 => [6, 26, 46, 66], 15 => [6, 26, 48, 70],
    ];

    /** @var list<int> GF(256) antilog and log tables, x^8 + x^4 + x^3 + x^2 + 1 */
    private static array $exp = [];
    /** @var array<int, int> */
    private static array $log = [];

    /**
     * An inline SVG: four modules of quiet zone, black on white, drawn at
     * `$scale` pixels a module — a whole number, so no module is smeared
     * across two pixels for a camera to misread.
     */
    public static function svg(string $data, string $level = 'M', int $scale = 4): string
    {
        $matrix = self::matrix($data, $level);
        $quiet  = 4;
        $full   = count($matrix) + 2 * $quiet;
        $px     = $full * max(1, $scale);

        $path = '';
        foreach ($matrix as $r => $row) {
            foreach ($row as $c => $dark) {
                if ($dark) {
                    $path .= 'M' . ($c + $quiet) . ' ' . ($r + $quiet) . 'h1v1h-1z';
                }
            }
        }

        return '<svg class="qr" width="' . $px . '" height="' . $px . '" viewBox="0 0 ' . $full . ' ' . $full . '" shape-rendering="crispEdges"'
            . ' role="img" aria-label="QR code">'
            . '<rect width="' . $full . '" height="' . $full . '" fill="#fff"/>'
            . '<path d="' . $path . '" fill="#000"/></svg>';
    }

    /**
     * The symbol, `[row][column]`, true for dark, without the quiet zone.
     *
     * @return list<list<bool>>
     */
    public static function matrix(string $data, string $level = 'M', ?int $mask = null): array
    {
        if (!isset(self::LEVELS[$level])) {
            throw new InvalidArgumentException('No such error correction level: ' . $level);
        }
        $version = self::versionFor(strlen($data), $level);

        [$modules, $function] = self::base($version);
        self::place($modules, $function, self::codewords($data, $version, $level));

        $best  = null;
        $score = PHP_INT_MAX;
        foreach ($mask !== null ? [$mask] : range(0, 7) as $candidate) {
            $masked = $modules;
            foreach ($masked as $r => $row) {
                foreach ($row as $c => $dark) {
                    if (!$function[$r][$c] && self::flips($candidate, $r, $c)) {
                        $masked[$r][$c] = !$dark;
                    }
                }
            }
            self::drawFormat($masked, $level, $candidate);
            $penalty = self::penalty($masked);
            if ($penalty < $score) {
                [$best, $score] = [$masked, $penalty];
            }
        }

        return $best;
    }

    /** The smallest version that holds `$bytes` bytes at `$level`. */
    public static function versionFor(int $bytes, string $level): int
    {
        for ($version = 1; $version <= self::MAX_VERSION; $version++) {
            $need = 4 + ($version <= 9 ? 8 : 16) + 8 * $bytes;
            if ($need <= self::dataCodewords($version, $level) * 8) {
                return $version;
            }
        }

        throw new InvalidArgumentException('Too long for a version ' . self::MAX_VERSION . ' QR code: ' . $bytes . ' bytes.');
    }

    /** How many data codewords a version holds at a level (Table 9, summed). */
    public static function dataCodewords(int $version, string $level): int
    {
        [, $g1, $g1Data, $g2, $g2Data] = self::BLOCKS[$version][$level];

        return $g1 * $g1Data + $g2 * $g2Data;
    }

    /** Every codeword, data and error correction, for a version and level (Table 9, summed). */
    public static function totalCodewords(int $version, string $level): int
    {
        [$ec, $g1, , $g2] = self::BLOCKS[$version][$level];

        return self::dataCodewords($version, $level) + $ec * ($g1 + $g2);
    }

    /**
     * The final codeword sequence: data then error correction, interleaved.
     *
     * @return list<int>
     */
    public static function codewords(string $data, int $version, string $level): array
    {
        [$ecPer, $g1, $g1Data, $g2, $g2Data] = self::BLOCKS[$version][$level];
        $capacity = self::dataCodewords($version, $level) * 8;

        $bits = '0100' . str_pad(decbin(strlen($data)), $version <= 9 ? 8 : 16, '0', STR_PAD_LEFT);
        foreach (str_split($data) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        $bits .= str_repeat('0', min(4, $capacity - strlen($bits)));
        $bits .= str_repeat('0', (8 - strlen($bits) % 8) % 8);

        $bytes = array_map('bindec', str_split($bits, 8));
        for ($pad = 0xEC; count($bytes) * 8 < $capacity; $pad ^= 0xEC ^ 0x11) {
            $bytes[] = $pad;
        }

        $blocks = [];
        $ecs    = [];
        $at     = 0;
        for ($b = 0; $b < $g1 + $g2; $b++) {
            $length   = $b < $g1 ? $g1Data : $g2Data;
            $block    = array_slice($bytes, $at, $length);
            $at      += $length;
            $blocks[] = $block;
            $ecs[]    = self::reedSolomon($block, $ecPer);
        }

        $out = [];
        for ($i = 0, $longest = max($g1Data, $g2Data); $i < $longest; $i++) {
            foreach ($blocks as $block) {
                if ($i < count($block)) {
                    $out[] = (int) $block[$i];
                }
            }
        }
        for ($i = 0; $i < $ecPer; $i++) {
            foreach ($ecs as $ec) {
                $out[] = $ec[$i];
            }
        }

        return $out;
    }

    /**
     * The `$count` error correction codewords for one block: the remainder of
     * the block, times x^count, divided by the generator Π(x − α^i).
     *
     * @param list<int> $data
     * @return list<int>
     */
    public static function reedSolomon(array $data, int $count): array
    {
        self::tables();

        $generator = [1];
        for ($i = 0; $i < $count; $i++) {
            $next = array_fill(0, count($generator) + 1, 0);
            foreach ($generator as $j => $coefficient) {
                $next[$j]     ^= $coefficient;
                $next[$j + 1] ^= self::multiply($coefficient, self::$exp[$i]);
            }
            $generator = $next;
        }

        $message = array_merge(array_map('intval', $data), array_fill(0, $count, 0));
        for ($i = 0, $n = count($data); $i < $n; $i++) {
            $factor = $message[$i];
            if ($factor !== 0) {
                for ($j = 1; $j <= $count; $j++) {
                    $message[$i + $j] ^= self::multiply($generator[$j], $factor);
                }
            }
        }

        return array_slice($message, count($data));
    }

    /** The 15 format bits for a level and mask: BCH(15,5), then XOR 101010000010010. */
    public static function formatBits(string $level, int $mask): int
    {
        $data      = (self::LEVELS[$level] << 3) | $mask;
        $remainder = $data;
        for ($i = 0; $i < 10; $i++) {
            $remainder = ($remainder << 1) ^ (($remainder >> 9) * 0x537);
        }

        return (($data << 10) | ($remainder & 0x3FF)) ^ 0x5412;
    }

    /** The 18 version bits (versions 7+): BCH(18,6). */
    public static function versionBits(int $version): int
    {
        $remainder = $version;
        for ($i = 0; $i < 12; $i++) {
            $remainder = ($remainder << 1) ^ (($remainder >> 11) * 0x1F25);
        }

        return ($version << 12) | ($remainder & 0xFFF);
    }

    /**
     * How many modules place() fills for a version — every module that is not
     * a function pattern. The known-answer checks compare it with the block
     * table: floor(this / 8) must be exactly totalCodewords().
     */
    public static function dataModules(int $version): int
    {
        [, $function] = self::base($version);
        $count = 0;
        foreach ($function as $row) {
            foreach ($row as $isFunction) {
                $count += $isFunction ? 0 : 1;
            }
        }

        return $count;
    }

    /**
     * The function patterns, drawn, and a map of which modules they own.
     *
     * @return array{0: list<list<bool>>, 1: list<list<bool>>}
     */
    private static function base(int $version): array
    {
        $size     = 17 + 4 * $version;
        $modules  = array_fill(0, $size, array_fill(0, $size, false));
        $function = $modules;
        $set = static function (int $r, int $c, bool $dark) use (&$modules, &$function, $size): void {
            if ($r >= 0 && $r < $size && $c >= 0 && $c < $size) {
                $modules[$r][$c]  = $dark;
                $function[$r][$c] = true;
            }
        };

        // Finders, each with its one-module light separator.
        foreach ([[0, 0], [0, $size - 7], [$size - 7, 0]] as [$top, $left]) {
            for ($dr = -1; $dr <= 7; $dr++) {
                for ($dc = -1; $dc <= 7; $dc++) {
                    $inside = $dr >= 0 && $dr <= 6 && $dc >= 0 && $dc <= 6;
                    $ring   = $dr === 0 || $dr === 6 || $dc === 0 || $dc === 6;
                    $core   = $dr >= 2 && $dr <= 4 && $dc >= 2 && $dc <= 4;
                    $set($top + $dr, $left + $dc, $inside && ($ring || $core));
                }
            }
        }

        // Timing: row 6 and column 6, dark on even positions.
        for ($i = 8; $i < $size - 8; $i++) {
            $set(6, $i, $i % 2 === 0);
            $set($i, 6, $i % 2 === 0);
        }

        // Alignment patterns, except the three that would sit on a finder.
        $centres = self::ALIGN[$version];
        $last    = $centres === [] ? 0 : $centres[count($centres) - 1];
        foreach ($centres as $r) {
            foreach ($centres as $c) {
                if (($r === 6 && $c === 6) || ($r === 6 && $c === $last) || ($r === $last && $c === 6)) {
                    continue;
                }
                for ($dr = -2; $dr <= 2; $dr++) {
                    for ($dc = -2; $dc <= 2; $dc++) {
                        $set($r + $dr, $c + $dc, max(abs($dr), abs($dc)) !== 1);
                    }
                }
            }
        }

        // Format areas are reserved now and written per mask; the dark module.
        for ($i = 0; $i <= 8; $i++) {
            $function[8][$i] = true;
            $function[$i][8] = true;
        }
        for ($i = 0; $i < 8; $i++) {
            $function[8][$size - 1 - $i] = true;
            $function[$size - 1 - $i][8] = true;
        }
        $set($size - 8, 8, true);

        // Version information, twice, for versions 7 and up.
        if ($version >= 7) {
            $bits = self::versionBits($version);
            for ($i = 0; $i < 18; $i++) {
                $dark = (($bits >> $i) & 1) === 1;
                $a    = $size - 11 + $i % 3;
                $b    = intdiv($i, 3);
                $set($b, $a, $dark);
                $set($a, $b, $dark);
            }
        }

        return [$modules, $function];
    }

    /**
     * @param list<list<bool>> $modules
     * @param list<list<bool>> $function
     * @param list<int>        $codewords
     */
    private static function place(array &$modules, array $function, array $codewords): void
    {
        $size  = count($modules);
        $total = count($codewords) * 8;
        $i     = 0;

        for ($right = $size - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right = 5;
            }
            $upward = (($right + 1) & 2) === 0;
            for ($vertical = 0; $vertical < $size; $vertical++) {
                $r = $upward ? $size - 1 - $vertical : $vertical;
                for ($j = 0; $j < 2; $j++) {
                    $c = $right - $j;
                    if (!$function[$r][$c] && $i < $total) {
                        $modules[$r][$c] = (($codewords[$i >> 3] >> (7 - ($i & 7))) & 1) === 1;
                        $i++;
                    }
                }
            }
        }
    }

    /** @param list<list<bool>> $modules */
    private static function drawFormat(array &$modules, string $level, int $mask): void
    {
        $size = count($modules);
        $bits = self::formatBits($level, $mask);
        $bit  = static fn (int $i): bool => (($bits >> $i) & 1) === 1;

        for ($i = 0; $i <= 5; $i++) {
            $modules[$i][8] = $bit($i);
        }
        $modules[7][8] = $bit(6);
        $modules[8][8] = $bit(7);
        $modules[8][7] = $bit(8);
        for ($i = 9; $i < 15; $i++) {
            $modules[8][14 - $i] = $bit($i);
        }

        for ($i = 0; $i < 8; $i++) {
            $modules[8][$size - 1 - $i] = $bit($i);
        }
        for ($i = 8; $i < 15; $i++) {
            $modules[$size - 15 + $i][8] = $bit($i);
        }
        $modules[$size - 8][8] = true;
    }

    /** Does mask `$mask` flip the module at row `$r`, column `$c`? */
    private static function flips(int $mask, int $r, int $c): bool
    {
        return match ($mask) {
            0 => ($r + $c) % 2 === 0,
            1 => $r % 2 === 0,
            2 => $c % 3 === 0,
            3 => ($r + $c) % 3 === 0,
            4 => (intdiv($r, 2) + intdiv($c, 3)) % 2 === 0,
            5 => ($r * $c) % 2 + ($r * $c) % 3 === 0,
            6 => ((($r * $c) % 2) + (($r * $c) % 3)) % 2 === 0,
            default => ((($r + $c) % 2) + (($r * $c) % 3)) % 2 === 0,
        };
    }

    /**
     * The standard's four penalties: runs of five or more, 2×2 blocks, the
     * finder-like 1:1:3:1:1 with four light modules beside it, and the
     * distance of the dark share from half. Only the choice of mask depends on
     * it — every mask decodes.
     *
     * @param list<list<bool>> $m
     */
    private static function penalty(array $m): int
    {
        $size  = count($m);
        $score = 0;
        $lines = [];
        for ($i = 0; $i < $size; $i++) {
            $row = '';
            $column = '';
            for ($j = 0; $j < $size; $j++) {
                $row    .= $m[$i][$j] ? '1' : '0';
                $column .= $m[$j][$i] ? '1' : '0';
            }
            $lines[] = $row;
            $lines[] = $column;
        }

        foreach ($lines as $line) {
            if (preg_match_all('/0{5,}|1{5,}/', $line, $runs) > 0) {
                foreach ($runs[0] as $run) {
                    $score += 3 + strlen($run) - 5;
                }
            }
            $score += 40 * (preg_match_all('/(?=10111010000|00001011101)/', $line));
        }

        $dark = 0;
        for ($r = 0; $r < $size; $r++) {
            for ($c = 0; $c < $size; $c++) {
                $dark += $m[$r][$c] ? 1 : 0;
                if ($r < $size - 1 && $c < $size - 1
                    && $m[$r][$c] === $m[$r][$c + 1] && $m[$r][$c] === $m[$r + 1][$c] && $m[$r][$c] === $m[$r + 1][$c + 1]) {
                    $score += 3;
                }
            }
        }
        $score += 10 * intdiv(abs($dark * 100 - 50 * $size * $size), 5 * $size * $size);

        return $score;
    }

    private static function tables(): void
    {
        if (self::$exp !== []) {
            return;
        }
        $x = 1;
        for ($i = 0; $i < 255; $i++) {
            self::$exp[$i] = $x;
            self::$log[$x] = $i;
            $x <<= 1;
            if ($x & 0x100) {
                $x ^= 0x11D;
            }
        }
        for ($i = 255; $i < 512; $i++) {
            self::$exp[$i] = self::$exp[$i - 255];
        }
    }

    private static function multiply(int $a, int $b): int
    {
        return ($a === 0 || $b === 0) ? 0 : self::$exp[self::$log[$a] + self::$log[$b]];
    }
}
