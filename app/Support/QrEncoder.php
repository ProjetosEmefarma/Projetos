<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Compact QR Code (versions 1–4, byte mode, ECC-M). Enough for EME-YYYY-NNNNNN.
 * Generates SVG and PNG without third-party packages.
 */
final class QrEncoder
{
    public static function svg(string $text, int $scale = 4): string
    {
        $m = self::matrix($text);
        $n = count($m);
        $q = 4;
        $size = ($n + $q * 2) * $scale;
        $out = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $size . ' ' . $size . '" shape-rendering="crispEdges">';
        $out .= '<rect width="100%" height="100%" fill="#ffffff"/>';
        for ($y = 0; $y < $n; $y++) {
            for ($x = 0; $x < $n; $x++) {
                if ($m[$y][$x]) {
                    $out .= '<rect x="' . (($x + $q) * $scale) . '" y="' . (($y + $q) * $scale)
                        . '" width="' . $scale . '" height="' . $scale . '" fill="#000"/>';
                }
            }
        }
        return $out . '</svg>';
    }

    public static function png(string $text, int $scale = 6): string
    {
        $m = self::matrix($text);
        $n = count($m);
        $q = 4;
        $px = ($n + $q * 2) * $scale;
        $raw = '';
        for ($y = 0; $y < $px; $y++) {
            $raw .= "\x00";
            $my = intdiv($y, $scale) - $q;
            for ($x = 0; $x < $px; $x++) {
                $mx = intdiv($x, $scale) - $q;
                $on = $my >= 0 && $mx >= 0 && $my < $n && $mx < $n && !empty($m[$my][$mx]);
                $raw .= $on ? "\x00\x00\x00" : "\xff\xff\xff";
            }
        }
        return self::pngRgb($px, $px, $raw);
    }

    public static function pngDataUri(string $text, int $scale = 6): string
    {
        return 'data:image/png;base64,' . base64_encode(self::png($text, $scale));
    }

    private static function pngRgb(int $w, int $h, string $filtered): string
    {
        $ihdr = pack('NNCCCCC', $w, $h, 8, 2, 0, 0, 0);
        $idat = function_exists('gzcompress') ? (string) gzcompress($filtered, 9) : $filtered;
        return "\x89PNG\r\n\x1a\n"
            . self::pngChunk('IHDR', $ihdr)
            . self::pngChunk('IDAT', $idat)
            . self::pngChunk('IEND', '');
    }

    private static function pngChunk(string $type, string $data): string
    {
        return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    }

    /** @return list<list<int>> */
    public static function matrix(string $text): array
    {
        $bytes = array_values(unpack('C*', $text) ?: []);
        $ver = self::pickVersion(count($bytes));
        $size = $ver * 4 + 17;
        [$dataCw, $eccCw] = self::capacity($ver);
        $bits = self::byteBits($bytes, $ver, $dataCw * 8);
        $data = self::bitsToBytes($bits, $dataCw);
        $ecc = self::rsEncode($data, $eccCw);
        $codewords = array_merge($data, $ecc);

        $reserved = self::reserve($size);
        $grid = array_fill(0, $size, array_fill(0, $size, 0));
        $func = array_fill(0, $size, array_fill(0, $size, 0));
        self::drawFinders($grid, $func, $size);
        self::drawTiming($grid, $func, $size);
        self::drawAlign($grid, $func, $ver);
        self::placeData($grid, $func, $size, $codewords);

        $best = $grid;
        $bestScore = PHP_INT_MAX;
        $bestMask = 0;
        for ($mask = 0; $mask < 8; $mask++) {
            $cand = $grid;
            self::applyMask($cand, $func, $size, $mask);
            self::drawFormat($cand, $func, $size, $mask);
            $score = self::penalty($cand, $size);
            if ($score < $bestScore) {
                $bestScore = $score;
                $best = $cand;
                $bestMask = $mask;
            }
        }
        unset($bestMask);
        return $best;
    }

    private static function pickVersion(int $len): int
    {
        // ECC-M data codewords: v1=16, v2=28, v3=44, v4=64. Byte header = 4+8 bits + data + pad.
        foreach ([1 => 14, 2 => 26, 3 => 42, 4 => 62] as $v => $max) {
            if ($len <= $max) {
                return $v;
            }
        }
        throw new \InvalidArgumentException('Texto longo demais para o QR do sistema.');
    }

    /** @return array{0:int,1:int} data, ecc */
    private static function capacity(int $ver): array
    {
        return match ($ver) {
            1 => [16, 10],
            2 => [28, 16],
            3 => [44, 26],
            default => [64, 36],
        };
    }

    /** @param list<int> $bytes */
    private static function byteBits(array $bytes, int $ver, int $totalBits): string
    {
        $bits = '0100'; // byte mode
        $bits .= str_pad(decbin(count($bytes)), $ver >= 10 ? 16 : 8, '0', STR_PAD_LEFT);
        foreach ($bytes as $b) {
            $bits .= str_pad(decbin($b), 8, '0', STR_PAD_LEFT);
        }
        $remain = $totalBits - strlen($bits);
        $bits .= substr('0000', 0, min(4, max(0, $remain)));
        while (strlen($bits) % 8 !== 0) {
            $bits .= '0';
        }
        $pads = ['11101100', '00010001'];
        $i = 0;
        while (strlen($bits) < $totalBits) {
            $bits .= $pads[$i % 2];
            $i++;
        }
        return substr($bits, 0, $totalBits);
    }

    /** @return list<int> */
    private static function bitsToBytes(string $bits, int $count): array
    {
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $out[] = bindec(substr($bits, $i * 8, 8));
        }
        return $out;
    }

    /** @param list<int> $data @return list<int> */
    private static function rsEncode(array $data, int $ec): array
    {
        $gen = self::rsGenerator($ec);
        $ecc = array_fill(0, $ec, 0);
        foreach ($data as $b) {
            $factor = $b ^ $ecc[0];
            array_shift($ecc);
            $ecc[] = 0;
            if ($factor === 0) {
                continue;
            }
            for ($i = 0; $i < $ec; $i++) {
                $ecc[$i] ^= self::gfMul($gen[$i], $factor);
            }
        }
        return $ecc;
    }

    /** @return list<int> */
    private static function rsGenerator(int $degree): array
    {
        $poly = [1];
        for ($i = 0; $i < $degree; $i++) {
            $next = array_fill(0, count($poly) + 1, 0);
            $root = self::gfPow(2, $i);
            for ($j = 0; $j < count($poly); $j++) {
                $next[$j] ^= $poly[$j];
                $next[$j + 1] ^= self::gfMul($poly[$j], $root);
            }
            $poly = $next;
        }
        array_shift($poly); // drop leading 1 — remainder loop expects this form
        return $poly;
    }

    private static function gfMul(int $a, int $b): int
    {
        if ($a === 0 || $b === 0) {
            return 0;
        }
        return self::gfPow(2, (self::gfLog($a) + self::gfLog($b)) % 255);
    }

    private static function gfPow(int $base, int $exp): int
    {
        $x = 1;
        for ($i = 0; $i < $exp; $i++) {
            $x = self::gfMulRaw($x, $base);
        }
        return $x;
    }

    private static function gfMulRaw(int $a, int $b): int
    {
        $p = 0;
        for ($i = 0; $i < 8; $i++) {
            if ($b & 1) {
                $p ^= $a;
            }
            $hi = $a & 0x80;
            $a = ($a << 1) & 0xFF;
            if ($hi) {
                $a ^= 0x1D;
            }
            $b >>= 1;
        }
        return $p;
    }

    private static function gfLog(int $a): int
    {
        static $log = null;
        if ($log === null) {
            $log = array_fill(0, 256, 0);
            $x = 1;
            for ($i = 0; $i < 255; $i++) {
                $log[$x] = $i;
                $x = self::gfMulRaw($x, 2);
            }
        }
        return $log[$a];
    }

    /** @return list<list<int>> */
    private static function reserve(int $size): array
    {
        return array_fill(0, $size, array_fill(0, $size, 0));
    }

    /** @param list<list<int>> $g @param list<list<int>> $f */
    private static function drawFinders(array &$g, array &$f, int $size): void
    {
        foreach ([[0, 0], [0, $size - 7], [$size - 7, 0]] as [$r, $c]) {
            for ($y = -1; $y <= 7; $y++) {
                for ($x = -1; $x <= 7; $x++) {
                    $yy = $r + $y;
                    $xx = $c + $x;
                    if ($yy < 0 || $xx < 0 || $yy >= $size || $xx >= $size) {
                        continue;
                    }
                    $on = ($x >= 0 && $x <= 6 && $y >= 0 && $y <= 6)
                        && ($x === 0 || $x === 6 || $y === 0 || $y === 6 || ($x >= 2 && $x <= 4 && $y >= 2 && $y <= 4));
                    $g[$yy][$xx] = $on ? 1 : 0;
                    $f[$yy][$xx] = 1;
                }
            }
        }
    }

    /** @param list<list<int>> $g @param list<list<int>> $f */
    private static function drawTiming(array &$g, array &$f, int $size): void
    {
        for ($i = 8; $i < $size - 8; $i++) {
            $g[6][$i] = $i % 2 === 0 ? 1 : 0;
            $g[$i][6] = $i % 2 === 0 ? 1 : 0;
            $f[6][$i] = 1;
            $f[$i][6] = 1;
        }
        $g[$size - 8][8] = 1;
        $f[$size - 8][8] = 1;
    }

    /** @param list<list<int>> $g @param list<list<int>> $f */
    private static function drawAlign(array &$g, array &$f, int $ver): void
    {
        if ($ver < 2) {
            return;
        }
        $pos = match ($ver) {
            2 => [18],
            3 => [22],
            default => [26],
        };
        foreach ($pos as $r) {
            foreach ($pos as $c) {
                if ($f[$r][$c]) {
                    continue;
                }
                for ($y = -2; $y <= 2; $y++) {
                    for ($x = -2; $x <= 2; $x++) {
                        $on = max(abs($x), abs($y)) !== 1;
                        $g[$r + $y][$c + $x] = $on ? 1 : 0;
                        $f[$r + $y][$c + $x] = 1;
                    }
                }
            }
        }
    }

    /** @param list<list<int>> $g @param list<list<int>> $f @param list<int> $codewords */
    private static function placeData(array &$g, array $f, int $size, array $codewords): void
    {
        $bits = '';
        foreach ($codewords as $b) {
            $bits .= str_pad(decbin($b), 8, '0', STR_PAD_LEFT);
        }
        $i = 0;
        $len = strlen($bits);
        $up = true;
        for ($col = $size - 1; $col > 0; $col -= 2) {
            if ($col === 6) {
                $col--;
            }
            for ($n = 0; $n < $size; $n++) {
                $row = $up ? $size - 1 - $n : $n;
                foreach ([$col, $col - 1] as $c) {
                    if ($f[$row][$c]) {
                        continue;
                    }
                    $g[$row][$c] = $i < $len ? (int) $bits[$i] : 0;
                    $i++;
                }
            }
            $up = !$up;
        }
    }

    /** @param list<list<int>> $g @param list<list<int>> $f */
    private static function applyMask(array &$g, array $f, int $size, int $mask): void
    {
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                if ($f[$y][$x]) {
                    continue;
                }
                $bit = match ($mask) {
                    0 => ($x + $y) % 2 === 0,
                    1 => $y % 2 === 0,
                    2 => $x % 3 === 0,
                    3 => ($x + $y) % 3 === 0,
                    4 => ((int) ($y / 2) + (int) ($x / 3)) % 2 === 0,
                    5 => (($x * $y) % 2) + (($x * $y) % 3) === 0,
                    6 => ((($x * $y) % 2) + (($x * $y) % 3)) % 2 === 0,
                    default => ((($x + $y) % 2) + (($x * $y) % 3)) % 2 === 0,
                };
                if ($bit) {
                    $g[$y][$x] ^= 1;
                }
            }
        }
    }

    /** @param list<list<int>> $g @param list<list<int>> $f */
    private static function drawFormat(array &$g, array $f, int $size, int $mask): void
    {
        $data = (0b00 << 3) | $mask; // ECC-M = 00
        $bits = self::bchFormat($data);
        $map = [
            [8, 0], [8, 1], [8, 2], [8, 3], [8, 4], [8, 5], [8, 7], [8, 8],
            [7, 8], [5, 8], [4, 8], [3, 8], [2, 8], [1, 8], [0, 8],
        ];
        $map2 = [
            [$size - 1, 8], [$size - 2, 8], [$size - 3, 8], [$size - 4, 8], [$size - 5, 8], [$size - 6, 8], [$size - 7, 8],
            [8, $size - 8], [8, $size - 7], [8, $size - 6], [8, $size - 5], [8, $size - 4], [8, $size - 3], [8, $size - 2], [8, $size - 1],
        ];
        for ($i = 0; $i < 15; $i++) {
            $bit = (int) $bits[$i];
            $g[$map[$i][0]][$map[$i][1]] = $bit;
            $g[$map2[$i][0]][$map2[$i][1]] = $bit;
        }
    }

    private static function bchFormat(int $data): string
    {
        $d = $data << 10;
        $gen = 0b10100110111;
        for ($i = 14; $i >= 10; $i--) {
            if ($d & (1 << $i)) {
                $d ^= $gen << ($i - 10);
            }
        }
        $val = (($data << 10) | $d) ^ 0b101010000010010;
        return str_pad(decbin($val), 15, '0', STR_PAD_LEFT);
    }

    /** @param list<list<int>> $g */
    private static function penalty(array $g, int $size): int
    {
        $score = 0;
        for ($y = 0; $y < $size; $y++) {
            $run = 1;
            for ($x = 1; $x < $size; $x++) {
                if ($g[$y][$x] === $g[$y][$x - 1]) {
                    $run++;
                    if ($run === 5) {
                        $score += 3;
                    } elseif ($run > 5) {
                        $score++;
                    }
                } else {
                    $run = 1;
                }
            }
        }
        for ($x = 0; $x < $size; $x++) {
            $run = 1;
            for ($y = 1; $y < $size; $y++) {
                if ($g[$y][$x] === $g[$y - 1][$x]) {
                    $run++;
                    if ($run === 5) {
                        $score += 3;
                    } elseif ($run > 5) {
                        $score++;
                    }
                } else {
                    $run = 1;
                }
            }
        }
        $dark = 0;
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                $dark += $g[$y][$x];
                if ($x < $size - 1 && $y < $size - 1
                    && $g[$y][$x] === $g[$y][$x + 1]
                    && $g[$y][$x] === $g[$y + 1][$x]
                    && $g[$y][$x] === $g[$y + 1][$x + 1]) {
                    $score += 3;
                }
            }
        }
        $pct = (int) ($dark * 100 / ($size * $size));
        $score += (int) (abs($pct - 50) / 5) * 10;
        return $score;
    }
}
