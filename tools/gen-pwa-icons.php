<?php
/**
 * gen-pwa-icons.php — สร้างไอคอนแอป PWA จากตรา AliceCMS ด้วย GD
 *
 *   php tools/gen-pwa-icons.php
 *     → assets/img/pwa/icon-192.png
 *       assets/img/pwa/icon-512.png
 *       assets/img/pwa/icon-maskable-512.png
 *
 * ลายตราลอกพิกัดมาจาก assets/img/alicecms-mark.svg ตรงๆ (ระบบพิกัด 64×64)
 * ถ้าแก้ไฟล์ SVG ต้องมาแก้ค่าคงที่ในไฟล์นี้ให้ตรงกันแล้วรันใหม่
 *
 * วาดที่ความละเอียด 4 เท่าแล้วค่อยย่อลง เพื่อให้ขอบมนและปลายแฉกของประกายเรียบ
 * — GD ไม่มี antialiasing ให้กับรูปทรงที่ระบายสีทึบ
 */
if (PHP_SAPI !== 'cli') exit('CLI only');

const VB    = 64.0;          /* ระบบพิกัดของ SVG ต้นฉบับ */
const SS    = 4;             /* ตัวคูณความละเอียดตอนวาด */
const BLUE  = [0x1A, 0x73, 0xE8];
const RADIUS = 15.0;         /* rx ของกรอบสี่เหลี่ยมมุมมน */

/* แถบสามขีด: [x, y, กว้าง, สูง, ความทึบ] — ปลายมนเต็ม (rx = สูง/2) */
const BARS = [
    [20.05, 20.75, 23.90, 5.29, 0.55],
    [16.65, 29.37, 30.70, 5.29, 0.82],
    [24.30, 37.98, 15.35, 5.29, 1.00],
];

/* ประกาย AI: [จุดกึ่งกลาง x, y, สเกล] — ตัวแฉกมีรัศมี 9 หน่วยก่อนสเกล */
const SPARKS = [
    [46.0, 14.0, 0.9444],
    [54.0, 22.0, 0.4667],
    [53.0,  9.5, 0.3111],
];

/* เส้นโค้งของแฉกประกาย เทียบกับจุดกึ่งกลาง (จาก path id="sp" ใน SVG)
   แต่ละชุด = [c1x, c1y, c2x, c2y, ปลายทาง x, y] ของ cubic Bézier */
const SPARK_PATH = [
    [ 1.35, -2.70,  2.70, -1.35,  9.00,  0.00],
    [ 2.70,  1.35,  1.35,  2.70,  0.00,  9.00],
    [-1.35,  2.70, -2.70,  1.35, -9.00,  0.00],
    [-2.70, -1.35, -1.35, -2.70,  0.00, -9.00],
];

/** จุดบนเส้นโค้ง cubic Bézier ที่พารามิเตอร์ t */
function bez(float $p0, float $c1, float $c2, float $p3, float $t): float {
    $u = 1 - $t;
    return $u * $u * $u * $p0 + 3 * $u * $u * $t * $c1 + 3 * $u * $t * $t * $c2 + $t * $t * $t * $p3;
}

/** แฉกประกายหนึ่งดวง — คืนรายการพิกัดสำหรับ imagefilledpolygon */
function spark_points(float $cx, float $cy, float $scale, float $k): array {
    $pts = [];
    $x = 0.0; $y = -9.0;                       /* เริ่มที่ปลายแฉกด้านบน */
    foreach (SPARK_PATH as [$c1x, $c1y, $c2x, $c2y, $ex, $ey]) {
        for ($i = 1; $i <= 12; $i++) {         /* 12 ช่วงต่อเส้นโค้ง เรียบพอที่ 4 เท่า */
            $t = $i / 12;
            $pts[] = ($cx + bez($x, $c1x, $c2x, $ex, $t) * $scale) * $k;
            $pts[] = ($cy + bez($y, $c1y, $c2y, $ey, $t) * $scale) * $k;
        }
        $x = $ex; $y = $ey;
    }
    return $pts;
}

/** สีขาวความทึบ $op ที่ผสมกับพื้นน้ำเงินไว้แล้ว — ได้สีทึบที่วาดซ้อนกันได้ไม่เพี้ยน */
function blend_white($im, float $op) {
    $mix = fn(int $c) => (int)round(255 * $op + $c * (1 - $op));
    return imagecolorallocate($im, $mix(BLUE[0]), $mix(BLUE[1]), $mix(BLUE[2]));
}

/** สี่เหลี่ยมมุมมนแบบระบายทึบ (GD ไม่มีให้ในตัว) */
function rounded_rect($im, float $x, float $y, float $w, float $h, float $r, $color): void {
    $r = min($r, $w / 2, $h / 2);
    $x2 = $x + $w; $y2 = $y + $h;
    imagefilledrectangle($im, (int)round($x + $r), (int)round($y), (int)round($x2 - $r), (int)round($y2), $color);
    imagefilledrectangle($im, (int)round($x), (int)round($y + $r), (int)round($x2), (int)round($y2 - $r), $color);
    $d = (int)round($r * 2);
    foreach ([[$x + $r, $y + $r], [$x2 - $r, $y + $r], [$x + $r, $y2 - $r], [$x2 - $r, $y2 - $r]] as [$ex, $ey]) {
        imagefilledellipse($im, (int)round($ex), (int)round($ey), $d, $d, $color);
    }
}

/**
 * วาดตรา AliceCMS ขนาด $size พิกเซล
 * @param bool $maskable true = พื้นเต็มสี่เหลี่ยมไม่มุมมน และย่อลายลงให้อยู่ในเขตปลอดภัย
 *                       (ระบบปฏิบัติการจะตัดมุมเป็นรูปทรงของตัวเองทับอีกที)
 */
function draw_mark(int $size, bool $maskable): \GdImage {
    $S  = $size * SS;
    $im = imagecreatetruecolor($S, $S);
    imagealphablending($im, false);
    imagesavealpha($im, true);
    imagefilledrectangle($im, 0, 0, $S, $S, imagecolorallocatealpha($im, 0, 0, 0, 127));
    imagealphablending($im, true);

    $blue = imagecolorallocate($im, BLUE[0], BLUE[1], BLUE[2]);
    $k    = $S / VB;                 /* พิกัด SVG → พิกเซล */

    if ($maskable) {
        imagefilledrectangle($im, 0, 0, $S, $S, $blue);
        /* เขตปลอดภัยของ maskable คือวงกลมกลางภาพขนาด 80% — ย่อลายลงให้อยู่ในนั้น */
        $k *= 0.80;
    } else {
        rounded_rect($im, 0, 0, $S, $S, RADIUS * ($S / VB), $blue);
    }
    /* จุดกึ่งกลางของลายหลังย่อ (ตอนไม่ย่อ ค่า off เป็น 0) */
    $off = ($S - VB * $k) / 2;

    foreach (BARS as [$bx, $by, $bw, $bh, $op]) {
        /* ผสมสีเองแล้ววาดทึบ ห้ามใช้สีโปร่งใส — rounded_rect วาดสี่เหลี่ยมสองอันกับวงกลมสี่มุม
           ซ้อนกัน ถ้าสีโปร่งใสส่วนที่ซ้อนจะถูกผสมสองรอบ ปลายมนของแถบจะออกมาขาวกว่าตรงกลาง
           พื้นหลังใต้แถบเป็นสีน้ำเงินเรียบอยู่แล้ว ผสมล่วงหน้าจึงได้ผลเหมือนกันเป๊ะ */
        rounded_rect($im, $off + $bx * $k, $off + $by * $k, $bw * $k, $bh * $k, ($bh / 2) * $k,
                     blend_white($im, $op));
    }

    $white = imagecolorallocate($im, 255, 255, 255);
    foreach (SPARKS as [$sx, $sy, $sc]) {
        $pts = spark_points($sx, $sy, $sc, $k);
        /* เลื่อนตามระยะกึ่งกลางหลังย่อ */
        for ($i = 0; $i < count($pts); $i++) $pts[$i] += $off;
        imagefilledpolygon($im, array_map('intval', array_map('round', $pts)), $white);
    }

    /* ย่อกลับเป็นขนาดจริง — ขั้นนี้เองที่ทำให้ขอบเรียบ */
    $out = imagecreatetruecolor($size, $size);
    imagealphablending($out, false);
    imagesavealpha($out, true);
    imagecopyresampled($out, $im, 0, 0, 0, 0, $size, $size, $S, $S);
    imagedestroy($im);
    return $out;
}

$dir = dirname(__DIR__) . '/assets/img/pwa';
if (!is_dir($dir) && !mkdir($dir, 0755, true)) exit("สร้างโฟลเดอร์ปลายทางไม่ได้\n");

foreach ([['icon-192.png', 192, false], ['icon-512.png', 512, false], ['icon-maskable-512.png', 512, true]] as [$name, $size, $mask]) {
    $im = draw_mark($size, $mask);
    if (!imagepng($im, $dir . '/' . $name, 9)) exit("เขียน $name ไม่สำเร็จ\n");
    imagedestroy($im);
    printf("%-26s %d×%d  %s  %s\n", $name, $size, $size,
        $mask ? 'maskable' : 'any     ', number_format(filesize($dir . '/' . $name)) . ' ไบต์');
}
echo "เสร็จแล้ว — ไอคอนแอปใช้ตรา AliceCMS แล้ว\n";
