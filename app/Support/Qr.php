<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Dependency-free QR code encoder (ISO/IEC 18004, byte mode, versions 1–40,
 * error-correction L/M/Q/H) with PNG output through GD. Written in pure PHP so
 * it works on any shared host — no Composer package or shell tool required.
 *
 * Verified in tests/Unit/QrTest by decoding the generated images with an
 * independent decoder.
 */
final class Qr
{
    /** @var array<int,int>|null */
    private static ?array $exp = null;
    /** @var array<int,int>|null */
    private static ?array $log = null;

    private const LEVEL_BITS = ['L' => 1, 'M' => 0, 'Q' => 3, 'H' => 2];

    /**
     * @return list<list<bool>> square matrix, true = dark module (no quiet zone)
     */
    public static function matrix(string $data, string $ecc = 'M'): array
    {
        $ecc = strtoupper($ecc);
        if (!isset(self::LEVEL_BITS[$ecc])) {
            throw new \InvalidArgumentException('ECC must be L, M, Q or H.');
        }
        if ($data === '') {
            throw new \InvalidArgumentException('Nothing to encode.');
        }
        self::initGf();

        [$version, $blocks] = self::pickVersion(strlen($data), $ecc);
        $codewords = self::buildCodewords($data, $version, $blocks);

        $best = null;
        $bestPenalty = PHP_INT_MAX;
        for ($mask = 0; $mask < 8; $mask++) {
            $m = self::buildMatrix($version, $ecc, $mask, $codewords);
            $pen = self::penalty($m);
            if ($pen < $bestPenalty) {
                $bestPenalty = $pen;
                $best = $m;
            }
        }
        /** @var list<list<bool>> $best */
        return $best;
    }

    /**
     * PNG image bytes. $scale = pixels per module, $quiet = quiet-zone modules.
     */
    public static function png(string $data, int $scale = 10, int $quiet = 4, string $ecc = 'M'): string
    {
        if (!function_exists('imagecreate')) {
            throw new \RuntimeException('The PHP GD extension is required to render QR codes.');
        }
        $m = self::matrix($data, $ecc);
        $n = count($m);
        $size = ($n + 2 * $quiet) * $scale;
        $img = imagecreate($size, $size);
        $white = imagecolorallocate($img, 255, 255, 255);
        $black = imagecolorallocate($img, 12, 12, 24);
        imagefill($img, 0, 0, $white);
        for ($r = 0; $r < $n; $r++) {
            for ($c = 0; $c < $n; $c++) {
                if ($m[$r][$c]) {
                    $x = ($c + $quiet) * $scale;
                    $y = ($r + $quiet) * $scale;
                    imagefilledrectangle($img, $x, $y, $x + $scale - 1, $y + $scale - 1, $black);
                }
            }
        }
        ob_start();
        imagepng($img, null, 9);
        $bytes = (string) ob_get_clean();
        imagedestroy($img);
        return $bytes;
    }

    // ───────────────────────── Galois field GF(256) ─────────────────────────

    private static function initGf(): void
    {
        if (self::$exp !== null) {
            return;
        }
        $exp = array_fill(0, 512, 0);
        $log = array_fill(0, 256, 0);
        $x = 1;
        for ($i = 0; $i < 255; $i++) {
            $exp[$i] = $x;
            $log[$x] = $i;
            $x <<= 1;
            if ($x & 0x100) {
                $x ^= 0x11D;
            }
        }
        for ($i = 255; $i < 512; $i++) {
            $exp[$i] = $exp[$i - 255];
        }
        self::$exp = $exp;
        self::$log = $log;
    }

    /** @return list<int> generator polynomial coefficients (highest degree first, leading 1 implied) */
    private static function generator(int $degree): array
    {
        $g = [1];
        for ($i = 0; $i < $degree; $i++) {
            $next = array_fill(0, count($g) + 1, 0);
            foreach ($g as $j => $coef) {
                $next[$j] ^= $coef;
                $next[$j + 1] ^= self::$exp[(self::$log[$coef] + $i) % 255];
            }
            $g = $next;
        }
        return $g;
    }

    /**
     * @param list<int> $data
     * @return list<int> error-correction codewords
     */
    private static function reedSolomon(array $data, int $ecLen): array
    {
        $gen = self::generator($ecLen);
        $buf = array_merge($data, array_fill(0, $ecLen, 0));
        $len = count($data);
        for ($i = 0; $i < $len; $i++) {
            $coef = $buf[$i];
            if ($coef === 0) {
                continue;
            }
            $lc = self::$log[$coef];
            for ($j = 1, $gl = count($gen); $j < $gl; $j++) {
                if ($gen[$j] !== 0) {
                    $buf[$i + $j] ^= self::$exp[$lc + self::$log[$gen[$j]]];
                }
            }
        }
        return array_slice($buf, $len);
    }

    // ───────────────────────── data → codewords ─────────────────────────────

    /** @return array{0:int,1:list<array{0:int,1:int,2:int}>} */
    private static function pickVersion(int $byteLen, string $ecc): array
    {
        for ($v = 1; $v <= 40; $v++) {
            $blocks = QrTables::RS[$v][$ecc];
            $dataCw = 0;
            foreach ($blocks as [$count, , $data]) {
                $dataCw += $count * $data;
            }
            $lenBits = $v < 10 ? 8 : 16;
            if (4 + $lenBits + 8 * $byteLen <= $dataCw * 8) {
                return [$v, $blocks];
            }
        }
        throw new \InvalidArgumentException('That is too long to fit in a QR code.');
    }

    /**
     * @param list<array{0:int,1:int,2:int}> $blocks
     * @return list<int> interleaved data + EC codewords
     */
    private static function buildCodewords(string $data, int $version, array $blocks): array
    {
        $bits = '';
        $bits .= '0100';                                                    // byte mode
        $bits .= str_pad(decbin(strlen($data)), $version < 10 ? 8 : 16, '0', STR_PAD_LEFT);
        foreach (str_split($data) as $ch) {
            $bits .= str_pad(decbin(ord($ch)), 8, '0', STR_PAD_LEFT);
        }
        $capacity = 0;
        foreach ($blocks as [$count, , $dc]) {
            $capacity += $count * $dc * 8;
        }
        $bits .= str_repeat('0', min(4, $capacity - strlen($bits)));       // terminator
        if (strlen($bits) % 8 !== 0) {
            $bits .= str_repeat('0', 8 - strlen($bits) % 8);
        }
        $bytes = array_map('bindec', str_split($bits, 8));
        for ($pad = 0; count($bytes) * 8 < $capacity; $pad++) {
            $bytes[] = $pad % 2 === 0 ? 0xEC : 0x11;
        }

        $dataBlocks = [];
        $ecBlocks = [];
        $offset = 0;
        foreach ($blocks as [$count, $total, $dc]) {
            for ($i = 0; $i < $count; $i++) {
                $chunk = array_slice($bytes, $offset, $dc);
                $offset += $dc;
                $dataBlocks[] = $chunk;
                $ecBlocks[] = self::reedSolomon($chunk, $total - $dc);
            }
        }
        $out = [];
        $maxData = max(array_map('count', $dataBlocks));
        for ($i = 0; $i < $maxData; $i++) {
            foreach ($dataBlocks as $b) {
                if (isset($b[$i])) {
                    $out[] = $b[$i];
                }
            }
        }
        $maxEc = max(array_map('count', $ecBlocks));
        for ($i = 0; $i < $maxEc; $i++) {
            foreach ($ecBlocks as $b) {
                if (isset($b[$i])) {
                    $out[] = $b[$i];
                }
            }
        }
        return $out;
    }

    // ───────────────────────── matrix construction ──────────────────────────

    /**
     * @param list<int> $codewords
     * @return list<list<bool>>
     */
    private static function buildMatrix(int $version, string $ecc, int $mask, array $codewords): array
    {
        $n = 17 + 4 * $version;
        /** @var array<int,array<int,?bool>> $m null = still free for data */
        $m = array_fill(0, $n, array_fill(0, $n, null));

        // finder patterns + separators
        foreach ([[0, 0], [$n - 7, 0], [0, $n - 7]] as [$row, $col]) {
            for ($r = -1; $r <= 7; $r++) {
                if ($row + $r <= -1 || $row + $r >= $n) {
                    continue;
                }
                for ($c = -1; $c <= 7; $c++) {
                    if ($col + $c <= -1 || $col + $c >= $n) {
                        continue;
                    }
                    $m[$row + $r][$col + $c] =
                        ($r >= 0 && $r <= 6 && ($c === 0 || $c === 6))
                        || ($c >= 0 && $c <= 6 && ($r === 0 || $r === 6))
                        || ($r >= 2 && $r <= 4 && $c >= 2 && $c <= 4);
                }
            }
        }
        // alignment patterns
        $pos = QrTables::ALIGN[$version - 1];
        foreach ($pos as $row) {
            foreach ($pos as $col) {
                if ($m[$row][$col] !== null) {
                    continue;
                }
                for ($r = -2; $r <= 2; $r++) {
                    for ($c = -2; $c <= 2; $c++) {
                        $m[$row + $r][$col + $c] = ($r === -2 || $r === 2 || $c === -2 || $c === 2 || ($r === 0 && $c === 0));
                    }
                }
            }
        }
        // timing patterns
        for ($i = 8; $i < $n - 8; $i++) {
            if ($m[$i][6] === null) {
                $m[$i][6] = $i % 2 === 0;
            }
            if ($m[6][$i] === null) {
                $m[6][$i] = $i % 2 === 0;
            }
        }
        // format information
        $fmt = self::bchFormat((self::LEVEL_BITS[$ecc] << 3) | $mask);
        for ($i = 0; $i < 15; $i++) {
            $bit = (($fmt >> $i) & 1) === 1;
            if ($i < 6) {
                $m[$i][8] = $bit;
            } elseif ($i < 8) {
                $m[$i + 1][8] = $bit;
            } else {
                $m[$n - 15 + $i][8] = $bit;
            }
            if ($i < 8) {
                $m[8][$n - $i - 1] = $bit;
            } elseif ($i < 9) {
                $m[8][15 - $i - 1 + 1] = $bit;
            } else {
                $m[8][15 - $i - 1] = $bit;
            }
        }
        $m[$n - 8][8] = true; // fixed dark module
        // version information
        if ($version >= 7) {
            $ver = self::bchVersion($version);
            for ($i = 0; $i < 18; $i++) {
                $bit = (($ver >> $i) & 1) === 1;
                $m[intdiv($i, 3)][$i % 3 + $n - 8 - 3] = $bit;
                $m[$i % 3 + $n - 8 - 3][intdiv($i, 3)] = $bit;
            }
        }
        // data placement (zig-zag from the bottom-right corner)
        $inc = -1;
        $row = $n - 1;
        $bitIndex = 7;
        $byteIndex = 0;
        $total = count($codewords);
        for ($col = $n - 1; $col > 0; $col -= 2) {
            if ($col <= 6) {
                $col--;
            }
            while (true) {
                for ($c = $col; $c >= $col - 1; $c--) {
                    if ($m[$row][$c] === null) {
                        $dark = $byteIndex < $total && ((($codewords[$byteIndex] >> $bitIndex) & 1) === 1);
                        if (self::masked($mask, $row, $c)) {
                            $dark = !$dark;
                        }
                        $m[$row][$c] = $dark;
                        if (--$bitIndex === -1) {
                            $byteIndex++;
                            $bitIndex = 7;
                        }
                    }
                }
                $row += $inc;
                if ($row < 0 || $row >= $n) {
                    $row -= $inc;
                    $inc = -$inc;
                    break;
                }
            }
        }
        /** @var list<list<bool>> $m */
        return $m;
    }

    private static function masked(int $mask, int $r, int $c): bool
    {
        return match ($mask) {
            0 => ($r + $c) % 2 === 0,
            1 => $r % 2 === 0,
            2 => $c % 3 === 0,
            3 => ($r + $c) % 3 === 0,
            4 => (intdiv($r, 2) + intdiv($c, 3)) % 2 === 0,
            5 => ($r * $c) % 2 + ($r * $c) % 3 === 0,
            6 => (($r * $c) % 2 + ($r * $c) % 3) % 2 === 0,
            default => (($r * $c) % 3 + ($r + $c) % 2) % 2 === 0,
        };
    }

    private static function bchDigit(int $d): int
    {
        $n = 0;
        while ($d !== 0) {
            $n++;
            $d >>= 1;
        }
        return $n;
    }

    private static function bchFormat(int $data): int
    {
        $g = 0x537;
        $d = $data << 10;
        while (self::bchDigit($d) - self::bchDigit($g) >= 0) {
            $d ^= $g << (self::bchDigit($d) - self::bchDigit($g));
        }
        return (($data << 10) | $d) ^ 0x5412;
    }

    private static function bchVersion(int $data): int
    {
        $g = 0x1F25;
        $d = $data << 12;
        while (self::bchDigit($d) - self::bchDigit($g) >= 0) {
            $d ^= $g << (self::bchDigit($d) - self::bchDigit($g));
        }
        return ($data << 12) | $d;
    }

    // ───────────────────────── mask penalty (ISO 18004 §7.8.3) ──────────────

    /** @param list<list<bool>> $m */
    private static function penalty(array $m): int
    {
        $n = count($m);
        $p = 0;
        // N1: runs of 5+ same-colour modules in rows and columns
        for ($i = 0; $i < $n; $i++) {
            foreach ([0, 1] as $dir) {
                $run = 1;
                for ($j = 1; $j < $n; $j++) {
                    $cur = $dir === 0 ? $m[$i][$j] : $m[$j][$i];
                    $prev = $dir === 0 ? $m[$i][$j - 1] : $m[$j - 1][$i];
                    if ($cur === $prev) {
                        $run++;
                    } else {
                        if ($run >= 5) {
                            $p += 3 + ($run - 5);
                        }
                        $run = 1;
                    }
                }
                if ($run >= 5) {
                    $p += 3 + ($run - 5);
                }
            }
        }
        // N2: 2x2 blocks
        for ($r = 0; $r < $n - 1; $r++) {
            for ($c = 0; $c < $n - 1; $c++) {
                $v = $m[$r][$c];
                if ($v === $m[$r][$c + 1] && $v === $m[$r + 1][$c] && $v === $m[$r + 1][$c + 1]) {
                    $p += 3;
                }
            }
        }
        // N3: finder-like 1:1:3:1:1 patterns with 4 light modules on a side
        $pat1 = [true, false, true, true, true, false, true, false, false, false, false];
        $pat2 = [false, false, false, false, true, false, true, true, true, false, true];
        for ($i = 0; $i < $n; $i++) {
            for ($j = 0; $j <= $n - 11; $j++) {
                foreach ([0, 1] as $dir) {
                    $m1 = true;
                    $m2 = true;
                    for ($k = 0; $k < 11 && ($m1 || $m2); $k++) {
                        $v = $dir === 0 ? $m[$i][$j + $k] : $m[$j + $k][$i];
                        $m1 = $m1 && $v === $pat1[$k];
                        $m2 = $m2 && $v === $pat2[$k];
                    }
                    if ($m1) {
                        $p += 40;
                    }
                    if ($m2) {
                        $p += 40;
                    }
                }
            }
        }
        // N4: dark/light balance
        $dark = 0;
        foreach ($m as $row) {
            foreach ($row as $v) {
                $dark += $v ? 1 : 0;
            }
        }
        $percent = ($dark * 100) / ($n * $n);
        $p += (int) (abs($percent - 50) / 5) * 10;
        return $p;
    }
}
