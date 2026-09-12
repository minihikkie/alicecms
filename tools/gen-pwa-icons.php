<?php
/** gen-pwa-icons.php — สร้างไอคอนแอป PWA (อาคารราชการ บนพื้นไล่สีน้ำเงิน) ด้วย GD
 *  php tools/gen-pwa-icons.php   → assets/img/pwa/icon-192.png, icon-512.png, icon-maskable-512.png
 */
if (PHP_SAPI !== 'cli') exit('CLI only');
$out = dirname(__DIR__) . '/assets/img/pwa';
if (!is_dir($out)) mkdir($out, 0755, true);

function hx($im, $hex) { $n = hexdec(ltrim($hex, '#')); return imagecolorallocate($im, ($n >> 16) & 255, ($n >> 8) & 255, $n & 255); }

/** วาดไอคอน S×S; $pad = สัดส่วนขอบปลอดภัย (สำหรับ maskable) */
function draw_icon(int $S, float $pad = 0.0): \GdImage {
    $im = imagecreatetruecolor($S, $S);
    imagealphablending($im, true); imagesavealpha($im, true);

    // พื้นไล่สีแนวทแยง (น้ำเงิน -> น้ำเงินเข้ม)
    $c1 = [26, 115, 232]; $c2 = [21, 87, 176];
    for ($y = 0; $y < $S; $y++) {
        $t = $y / $S;
        $col = imagecolorallocate($im,
            (int)round($c1[0] + ($c2[0] - $c1[0]) * $t),
            (int)round($c1[1] + ($c2[1] - $c1[1]) * $t),
            (int)round($c1[2] + ($c2[2] - $c1[2]) * $t));
        imagefilledrectangle($im, 0, $y, $S, $y, $col);
    }

    $white = imagecolorallocatealpha($im, 255, 255, 255, 0);
    // พื้นที่วาดอาคาร (เว้นขอบปลอดภัย)
    $m = $S * (0.22 + $pad);          // ขอบซ้าย/ขวา
    $w = $S - 2 * $m;                  // ความกว้างอาคาร
    $cx = $S / 2;
    $top = $S * (0.26 + $pad * 0.5);   // ยอดหน้าจั่ว
    $colTop = $S * (0.46 + $pad * 0.4);
    $base = $S * (0.72 - $pad * 0.4);

    // หน้าจั่ว (สามเหลี่ยม)
    $tri = [$cx, $top,  $cx + $w / 2 + $S * 0.02, $colTop,  $cx - $w / 2 - $S * 0.02, $colTop];
    imagefilledpolygon($im, $tri, $white);
    // คาน
    imagefilledrectangle($im, (int)($cx - $w / 2), (int)$colTop, (int)($cx + $w / 2), (int)($colTop + $S * 0.035), $white);
    // เสา 4 ต้น
    $cols = 4; $gap = $w / $cols;
    $cw = $gap * 0.46;
    $cTop = $colTop + $S * 0.06;
    for ($i = 0; $i < $cols; $i++) {
        $x = $cx - $w / 2 + $gap * ($i + 0.5);
        imagefilledrectangle($im, (int)($x - $cw / 2), (int)$cTop, (int)($x + $cw / 2), (int)$base, $white);
    }
    // ฐาน (สองชั้น)
    imagefilledrectangle($im, (int)($cx - $w / 2 - $S * 0.03), (int)$base, (int)($cx + $w / 2 + $S * 0.03), (int)($base + $S * 0.04), $white);
    imagefilledrectangle($im, (int)($cx - $w / 2 - $S * 0.06), (int)($base + $S * 0.05), (int)($cx + $w / 2 + $S * 0.06), (int)($base + $S * 0.09), $white);
    return $im;
}

/** ใส่มุมโค้งให้ภาพ (สำหรับไอคอนปกติ ไม่ใช่ maskable) */
function round_corners(\GdImage $im, int $S, float $r): void {
    $rad = (int)($S * $r);
    $mask = imagecreatetruecolor($S, $S);
    imagealphablending($mask, false); imagesavealpha($mask, true);
    $trans = imagecolorallocatealpha($mask, 0, 0, 0, 127);
    imagefilledrectangle($mask, 0, 0, $S, $S, $trans);
    // คัดลอกเฉพาะในพื้นที่โค้ง: ง่ายสุด—เคลียร์มุมเป็นโปร่งใส
    for ($y = 0; $y < $S; $y++) for ($x = 0; $x < $S; $x++) {
        $inX = ($x < $rad) ? $rad - $x : (($x > $S - $rad) ? $x - ($S - $rad) : 0);
        $inY = ($y < $rad) ? $rad - $y : (($y > $S - $rad) ? $y - ($S - $rad) : 0);
        if ($inX && $inY && ($inX * $inX + $inY * $inY) > $rad * $rad) {
            imagesetpixel($im, $x, $y, imagecolorallocatealpha($im, 0, 0, 0, 127));
        }
    }
}

foreach ([192, 512] as $S) {
    $im = draw_icon($S, 0.0);
    round_corners($im, $S, 0.18);
    imagepng($im, "$out/icon-$S.png", 6);
    imagedestroy($im);
}
// maskable: เต็มสี่เหลี่ยม + เว้น safe zone ~10%
$im = draw_icon(512, 0.06);
imagepng($im, "$out/icon-maskable-512.png", 6);
imagedestroy($im);

echo "สร้างไอคอน PWA แล้ว: " . implode(', ', array_map('basename', glob("$out/*.png"))) . "\n";
