<?php
/**
 * includes/qrcode.php — ตัวสร้าง QR Code ในตัวระบบ (ไม่พึ่งไลบรารีภายนอก)
 *
 * ทำไมต้องเขียนเอง:
 *  - ระบบนี้ตั้งใจไม่มี dependency ใดๆ ไม่มี Composer ติดตั้งแล้วใช้ได้เลย
 *  - CSP อนุญาตรูปจาก 'self' กับ data: เท่านั้น จะดึงไลบรารีหรือรูปจาก CDN ไม่ได้
 *  - ที่สำคัญที่สุด: QR ของ 2FA บรรจุ "กุญแจลับ" ของบัญชีผู้ดูแล ถ้าส่งไปให้บริการสร้าง QR
 *    ภายนอกวาดให้ เท่ากับยกกุญแจให้คนอื่นไปทั้งดอก — ต้องสร้างในเครื่องตัวเองเท่านั้น
 *
 * ขอบเขตที่รองรับ: โหมด Byte (8-bit), ระดับกันพลาด L, เวอร์ชัน 1–10
 *  - ระดับ L เพราะ QR แบบนี้ถูกสแกนจากจอในระยะใกล้ทันที ไม่ได้พิมพ์ติดผนังให้เปื้อน
 *    และ L ให้พื้นที่เก็บข้อมูลมากที่สุด ซึ่งจำเป็นเพราะชื่อหน่วยงานภาษาไทยใน URL
 *    ถูก percent-encode เป็น 9 ตัวอักษรต่อ 1 ตัวไทย
 *  - เวอร์ชัน 10 ระดับ L เก็บได้ 271 ไบต์ ถ้าข้อมูลยาวกว่านั้น qr_matrix() คืน null
 *    ให้ผู้เรียกไปแสดงทางเลือกอื่นแทน (เช่น ให้พิมพ์คีย์เอง)
 *
 * อ้างอิงมาตรฐาน ISO/IEC 18004
 */

/* จำนวนคำรหัส (codeword) ของข้อมูลและกันพลาด ระดับ L เวอร์ชัน 1–10
   [จำนวน EC ต่อบล็อก, บล็อกกลุ่ม 1, ข้อมูลต่อบล็อกกลุ่ม 1, บล็อกกลุ่ม 2, ข้อมูลต่อบล็อกกลุ่ม 2] */
const QR_EC_L = [
    1  => [7,  1, 19, 0, 0],
    2  => [10, 1, 34, 0, 0],
    3  => [15, 1, 55, 0, 0],
    4  => [20, 1, 80, 0, 0],
    5  => [26, 1, 108, 0, 0],
    6  => [18, 2, 68, 0, 0],
    7  => [20, 2, 78, 0, 0],
    8  => [24, 2, 97, 0, 0],
    9  => [30, 2, 116, 0, 0],
    10 => [18, 2, 68, 2, 69],
];

/* พิกัดกึ่งกลางของ alignment pattern แต่ละเวอร์ชัน (เวอร์ชัน 1 ไม่มี) */
const QR_ALIGN = [
    1 => [], 2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30],
    6 => [6, 34], 7 => [6, 22, 38], 8 => [6, 24, 42], 9 => [6, 26, 46], 10 => [6, 28, 50],
];

/* บิตเศษที่ต้องเติมท้ายหลังวางข้อมูลครบ (เวอร์ชัน 2–6 ต้องเติม 7 บิต) */
const QR_REMAINDER = [1 => 0, 2 => 7, 3 => 7, 4 => 7, 5 => 7, 6 => 7, 7 => 0, 8 => 0, 9 => 0, 10 => 0];

/* ───────── เลขคณิตบนสนามจำกัด GF(256) สำหรับ Reed–Solomon ───────── */

function qr_gf(): array {
    static $t = null;
    if ($t !== null) return $t;
    $exp = array_fill(0, 512, 0);
    $log = array_fill(0, 256, 0);
    $x = 1;
    for ($i = 0; $i < 255; $i++) {
        $exp[$i] = $x;
        $log[$x] = $i;
        $x <<= 1;
        if ($x & 0x100) $x ^= 0x11D;          /* พหุนามกำเนิดมาตรฐานของ QR */
    }
    for ($i = 255; $i < 512; $i++) $exp[$i] = $exp[$i - 255];
    return $t = ['exp' => $exp, 'log' => $log];
}

function qr_mul(int $a, int $b): int {
    if ($a === 0 || $b === 0) return 0;
    $g = qr_gf();
    return $g['exp'][$g['log'][$a] + $g['log'][$b]];
}

/** พหุนามกำเนิดของรหัสกันพลาดขนาด n คำ */
function qr_gen_poly(int $n): array {
    $p = [1];
    for ($i = 0; $i < $n; $i++) {
        $g = qr_gf();
        $next = array_fill(0, count($p) + 1, 0);
        foreach ($p as $j => $c) {
            $next[$j]     ^= qr_mul($c, 1);
            $next[$j + 1] ^= qr_mul($c, $g['exp'][$i]);
        }
        $p = $next;
    }
    return $p;
}

/** คำนวณคำรหัสกันพลาดของบล็อกข้อมูลหนึ่งบล็อก */
function qr_ec(array $data, int $n): array {
    $gen = qr_gen_poly($n);
    $buf = array_merge($data, array_fill(0, $n, 0));
    $len = count($data);
    for ($i = 0; $i < $len; $i++) {
        $f = $buf[$i];
        if ($f === 0) continue;
        foreach ($gen as $j => $c) $buf[$i + $j] ^= qr_mul($c, $f);
    }
    return array_slice($buf, $len, $n);
}

/* ───────── ข้อมูลรูปแบบและเวอร์ชัน (รหัส BCH) ───────── */

/** 15 บิตบอกระดับกันพลาดและหมายเลขมาสก์ */
function qr_format_bits(int $maskId): int {
    $v = (0b01 << 3) | $maskId;                /* 01 = ระดับ L */
    $d = $v << 10;
    for ($i = 4; $i >= 0; $i--) {
        if ($d & (1 << ($i + 10))) $d ^= 0b10100110111 << $i;
    }
    return (($v << 10) | $d) ^ 0b101010000010010;
}

/** 18 บิตบอกหมายเลขเวอร์ชัน — ต้องใส่ตั้งแต่เวอร์ชัน 7 ขึ้นไป */
function qr_version_bits(int $ver): int {
    $d = $ver << 12;
    for ($i = 5; $i >= 0; $i--) {
        if ($d & (1 << ($i + 12))) $d ^= 0b1111100100101 << $i;
    }
    return ($ver << 12) | $d;
}

/* ───────── สร้างเมทริกซ์ ───────── */

/** ความจุข้อมูลโหมด Byte (ไบต์) ของเวอร์ชันนั้น */
function qr_capacity(int $ver): int {
    [$ecPer, $b1, $d1, $b2, $d2] = QR_EC_L[$ver];
    $dataCw   = $b1 * $d1 + $b2 * $d2;
    $countBits = $ver >= 10 ? 16 : 8;
    return intdiv($dataCw * 8 - 4 - $countBits, 8);
}

/** จำนวนไบต์สูงสุดที่ qr_matrix() รับไหว — ให้ผู้เรียกย่อข้อมูลมาก่อนได้ */
function qr_max_bytes(): int {
    return qr_capacity(10);
}

/**
 * สร้างเมทริกซ์ QR จากข้อความ — คืนอาร์เรย์สองมิติของ bool (true = ช่องทึบ)
 * คืน null ถ้าข้อความยาวเกินความจุสูงสุดที่รองรับ
 *
 * $forceMask ใช้สำหรับการทดสอบเท่านั้น (ปกติระบบเลือกมาสก์ที่คะแนนโทษต่ำสุดให้เอง)
 */
function qr_matrix(string $text, ?int $forceMask = null): ?array {
    $len = strlen($text);
    $ver = 0;
    for ($v = 1; $v <= 10; $v++) {
        if ($len <= qr_capacity($v)) { $ver = $v; break; }
    }
    if ($ver === 0) return null;

    [$ecPer, $b1, $d1, $b2, $d2] = QR_EC_L[$ver];
    $dataCw = $b1 * $d1 + $b2 * $d2;

    /* ── 1) เข้ารหัสข้อมูลเป็นสายบิต ── */
    $bits = '';
    $bits .= '0100';                                                  /* โหมด Byte */
    $bits .= str_pad(decbin($len), $ver >= 10 ? 16 : 8, '0', STR_PAD_LEFT);
    for ($i = 0; $i < $len; $i++) $bits .= str_pad(decbin(ord($text[$i])), 8, '0', STR_PAD_LEFT);

    $cap = $dataCw * 8;
    $bits .= str_repeat('0', min(4, $cap - strlen($bits)));           /* ตัวปิดท้าย */
    if (strlen($bits) % 8) $bits .= str_repeat('0', 8 - strlen($bits) % 8);
    $pad = [0xEC, 0x11];
    for ($i = 0; strlen($bits) < $cap; $i++) {
        $bits .= str_pad(decbin($pad[$i % 2]), 8, '0', STR_PAD_LEFT);
    }

    $cw = [];
    for ($i = 0; $i < $cap; $i += 8) $cw[] = bindec(substr($bits, $i, 8));

    /* ── 2) แบ่งบล็อก คิดรหัสกันพลาด แล้วสลับเรียง (interleave) ── */
    $blocks = [];
    $p = 0;
    for ($i = 0; $i < $b1; $i++) { $blocks[] = array_slice($cw, $p, $d1); $p += $d1; }
    for ($i = 0; $i < $b2; $i++) { $blocks[] = array_slice($cw, $p, $d2); $p += $d2; }
    $ecBlocks = [];
    foreach ($blocks as $b) $ecBlocks[] = qr_ec($b, $ecPer);

    $final = [];
    $maxD = max($d1, $d2);
    for ($i = 0; $i < $maxD; $i++) {
        foreach ($blocks as $b) if (isset($b[$i])) $final[] = $b[$i];
    }
    for ($i = 0; $i < $ecPer; $i++) {
        foreach ($ecBlocks as $b) $final[] = $b[$i];
    }

    $stream = '';
    foreach ($final as $c) $stream .= str_pad(decbin($c), 8, '0', STR_PAD_LEFT);
    $stream .= str_repeat('0', QR_REMAINDER[$ver]);

    /* ── 3) วางลวดลายประจำตำแหน่ง ── */
    $n = $ver * 4 + 17;
    $m   = array_fill(0, $n, array_fill(0, $n, false));   /* สีของช่อง */
    $fix = array_fill(0, $n, array_fill(0, $n, false));   /* ช่องที่จองไว้ ห้ามใส่ข้อมูล */

    $put = function (int $r, int $c, bool $dark) use (&$m, &$fix, $n) {
        if ($r < 0 || $c < 0 || $r >= $n || $c >= $n) return;
        $m[$r][$c] = $dark; $fix[$r][$c] = true;
    };

    /* finder + separator สามมุม */
    foreach ([[0, 0], [0, $n - 7], [$n - 7, 0]] as [$r0, $c0]) {
        for ($r = -1; $r <= 7; $r++) for ($c = -1; $c <= 7; $c++) {
            $inBox = $r >= 0 && $r <= 6 && $c >= 0 && $c <= 6;
            $dark  = $inBox && (($r === 0 || $r === 6 || $c === 0 || $c === 6)
                   || ($r >= 2 && $r <= 4 && $c >= 2 && $c <= 4));
            $put($r0 + $r, $c0 + $c, $dark);
        }
    }
    /* timing */
    for ($i = 8; $i < $n - 8; $i++) { $put(6, $i, $i % 2 === 0); $put($i, 6, $i % 2 === 0); }
    /* alignment — ข้ามตัวที่ทับ finder */
    $ac = QR_ALIGN[$ver];
    foreach ($ac as $r0) foreach ($ac as $c0) {
        if (($r0 <= 8 && $c0 <= 8) || ($r0 <= 8 && $c0 >= $n - 9) || ($r0 >= $n - 9 && $c0 <= 8)) continue;
        for ($r = -2; $r <= 2; $r++) for ($c = -2; $c <= 2; $c++) {
            $put($r0 + $r, $c0 + $c, max(abs($r), abs($c)) !== 1);
        }
    }
    /* ช่องทึบตายตัว + จองพื้นที่ข้อมูลรูปแบบ */
    $put($n - 8, 8, true);
    for ($i = 0; $i <= 8; $i++) { $put(8, $i, false); $put($i, 8, false); }
    for ($i = 0; $i < 8; $i++) { $put(8, $n - 1 - $i, false); $put($n - 1 - $i, 8, false); }
    /* จองพื้นที่ข้อมูลเวอร์ชัน (เวอร์ชัน 7 ขึ้นไป) */
    if ($ver >= 7) {
        for ($i = 0; $i < 6; $i++) for ($j = 0; $j < 3; $j++) {
            $put($n - 11 + $j, $i, false); $put($i, $n - 11 + $j, false);
        }
    }

    /* ── 4) วางบิตข้อมูลแบบซิกแซกจากขวาล่างขึ้นบน ── */
    $pos = 0; $up = true; $total = strlen($stream);
    for ($col = $n - 1; $col > 0; $col -= 2) {
        if ($col === 6) $col--;                       /* ข้ามคอลัมน์ timing */
        for ($k = 0; $k < $n; $k++) {
            $row = $up ? $n - 1 - $k : $k;
            for ($d = 0; $d < 2; $d++) {
                $c = $col - $d;
                if ($fix[$row][$c]) continue;
                $m[$row][$c] = $pos < $total && $stream[$pos] === '1';
                $pos++;
            }
        }
        $up = !$up;
    }

    /* ── 5) เลือกมาสก์ที่ให้คะแนนโทษต่ำสุด ── */
    $best = null; $bestScore = PHP_INT_MAX;
    $masks = $forceMask === null ? range(0, 7) : [$forceMask];   /* บังคับมาสก์ได้เพื่อการทดสอบ */
    foreach ($masks as $mask) {
        $t = qr_apply_mask($m, $fix, $n, $mask);
        qr_put_format($t, $n, $mask);
        if ($ver >= 7) qr_put_version($t, $n, $ver);
        $s = qr_penalty($t, $n);
        if ($s < $bestScore) { $bestScore = $s; $best = $t; }
    }
    return $best;
}

function qr_apply_mask(array $m, array $fix, int $n, int $mask): array {
    for ($r = 0; $r < $n; $r++) for ($c = 0; $c < $n; $c++) {
        if ($fix[$r][$c]) continue;
        switch ($mask) {
            case 0: $f = ($r + $c) % 2 === 0; break;
            case 1: $f = $r % 2 === 0; break;
            case 2: $f = $c % 3 === 0; break;
            case 3: $f = ($r + $c) % 3 === 0; break;
            case 4: $f = (intdiv($r, 2) + intdiv($c, 3)) % 2 === 0; break;
            case 5: $f = (($r * $c) % 2) + (($r * $c) % 3) === 0; break;
            case 6: $f = ((($r * $c) % 2) + (($r * $c) % 3)) % 2 === 0; break;
            default: $f = (((($r + $c) % 2) + (($r * $c) % 3)) % 2) === 0; break;
        }
        if ($f) $m[$r][$c] = !$m[$r][$c];
    }
    return $m;
}

function qr_put_format(array &$m, int $n, int $mask): void {
    $bits = qr_format_bits($mask);
    for ($i = 0; $i < 15; $i++) {
        $b = (bool)(($bits >> $i) & 1);
        /* สำเนาที่ 1 — รอบ finder มุมบนซ้าย */
        if ($i < 6)       $m[8][$i] = $b;
        elseif ($i === 6) $m[8][7] = $b;
        elseif ($i === 7) $m[8][8] = $b;
        elseif ($i === 8) $m[7][8] = $b;
        else              $m[14 - $i][8] = $b;
        /* สำเนาที่ 2 — บิต 0–7 วางในแนวนอนบนแถว 8 ไล่จากขอบขวาเข้ามา
           ส่วนบิต 8–14 วางในแนวตั้งบนคอลัมน์ 8 ไล่ลงไปถึงขอบล่าง
           (ช่อง (n-8, 8) ไม่ถูกเขียนตรงนี้ เพราะเป็นช่องทึบตายตัว ใส่ทีหลัง) */
        if ($i < 8) $m[8][$n - 1 - $i] = $b;
        else        $m[$n - 15 + $i][8] = $b;
    }
    $m[$n - 8][8] = true;
}

function qr_put_version(array &$m, int $n, int $ver): void {
    $bits = qr_version_bits($ver);
    for ($i = 0; $i < 18; $i++) {
        $b = (bool)(($bits >> $i) & 1);
        $r = intdiv($i, 3); $c = $i % 3;
        $m[$r][$n - 11 + $c] = $b;
        $m[$n - 11 + $c][$r] = $b;
    }
}

/** คะแนนโทษตามมาตรฐาน ใช้เลือกมาสก์ที่อ่านง่ายที่สุด */
function qr_penalty(array $m, int $n): int {
    $p = 0;
    /* กฎ 1 — แถวหรือคอลัมน์สีเดียวกันติดกันตั้งแต่ 5 ช่อง */
    for ($k = 0; $k < 2; $k++) {
        for ($i = 0; $i < $n; $i++) {
            $run = 1;
            for ($j = 1; $j < $n; $j++) {
                $a = $k ? $m[$j][$i] : $m[$i][$j];
                $b = $k ? $m[$j - 1][$i] : $m[$i][$j - 1];
                if ($a === $b) { $run++; }
                else { if ($run >= 5) $p += 3 + ($run - 5); $run = 1; }
            }
            if ($run >= 5) $p += 3 + ($run - 5);
        }
    }
    /* กฎ 2 — บล็อกสีเดียวกันขนาด 2x2 */
    for ($r = 0; $r < $n - 1; $r++) for ($c = 0; $c < $n - 1; $c++) {
        if ($m[$r][$c] === $m[$r][$c + 1] && $m[$r][$c] === $m[$r + 1][$c] && $m[$r][$c] === $m[$r + 1][$c + 1]) $p += 3;
    }
    /* กฎ 3 — ลวดลายคล้าย finder (1:1:3:1:1 พร้อมพื้นว่าง 4 ช่อง) */
    $pat1 = [true, false, true, true, true, false, true, false, false, false, false];
    $pat2 = array_reverse($pat1);
    for ($k = 0; $k < 2; $k++) {
        for ($i = 0; $i < $n; $i++) for ($j = 0; $j <= $n - 11; $j++) {
            $seg = [];
            for ($x = 0; $x < 11; $x++) $seg[] = $k ? $m[$j + $x][$i] : $m[$i][$j + $x];
            if ($seg === $pat1 || $seg === $pat2) $p += 40;
        }
    }
    /* กฎ 4 — สัดส่วนช่องทึบห่างจากครึ่งหนึ่งมากเกินไป */
    $dark = 0;
    foreach ($m as $row) foreach ($row as $v) if ($v) $dark++;
    $pct = $dark * 100 / ($n * $n);
    $p += (int)(floor(abs($pct - 50) / 5) * 10);
    return $p;
}

/**
 * วาด QR เป็น SVG พร้อมฝังในหน้าเว็บ — คืน '' ถ้าสร้างไม่ได้
 * ใช้ SVG ไม่ใช่ไฟล์ภาพ เพราะคมทุกความละเอียด ไม่ต้องเขียนไฟล์ลงดิสก์
 * และกุญแจลับไม่ถูกเก็บค้างไว้ที่ไหนนอกจากหน้าที่ผู้ดูแลกำลังเปิดอยู่
 */
function qr_svg(string $text, int $px = 200, string $alt = ''): string {
    $m = qr_matrix($text);
    if ($m === null) return '';
    $n = count($m);
    $quiet = 4;                                  /* ขอบว่างรอบ QR ตามมาตรฐาน */
    $size = $n + $quiet * 2;

    $d = '';
    for ($r = 0; $r < $n; $r++) {
        $c = 0;
        while ($c < $n) {
            if (!$m[$r][$c]) { $c++; continue; }
            $start = $c;
            while ($c < $n && $m[$r][$c]) $c++;   /* รวมช่องทึบที่ติดกันเป็นแถบเดียว ลดขนาดไฟล์ */
            $d .= 'M' . ($start + $quiet) . ' ' . ($r + $quiet) . 'h' . ($c - $start) . 'v1h-' . ($c - $start) . 'z';
        }
    }
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $size . ' ' . $size . '"'
         . ' width="' . $px . '" height="' . $px . '" shape-rendering="crispEdges" role="img"'
         . ' aria-label="' . htmlspecialchars($alt, ENT_QUOTES, 'UTF-8') . '">'
         . '<rect width="' . $size . '" height="' . $size . '" fill="#fff"/>'
         . '<path fill="#000" d="' . $d . '"/></svg>';
}
