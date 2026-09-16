<?php
/**
 * check-theme-contrast.php — ตรวจค่าความต่างของสีของธีมสำเร็จรูปทุกชุด
 *
 * เว็บหน่วยงานราชการต้องผ่านเกณฑ์ WCAG 2.1 ระดับ AA
 *   ตัวอักษรปกติ  ≥ 4.5 : 1
 *   ตัวอักษรใหญ่  ≥ 3.0 : 1   (หัวข้อขนาดใหญ่/ตัวหนา)
 *
 * รันจากบรรทัดคำสั่ง:  php tools/check-theme-contrast.php
 * คืน exit code 1 ถ้ามีธีมใดตก — ใส่ใน CI ได้
 *
 * ธีมที่ตกไม่ใช่แค่เรื่องความสวย: ผู้สูงอายุและผู้ที่สายตาเลือนรางจะอ่านเว็บไม่ออกจริงๆ
 */
if (PHP_SAPI !== 'cli') exit('CLI only');

define('APP_ROOT', dirname(__DIR__));

/* ตัวช่วยไม่กี่ตัวที่ theme-presets.php ต้องใช้ — ที่นี่ไม่ต้องต่อฐานข้อมูล */
function setting(string $k, string $d = ''): string { return $d; }
function valid_hex(string $c): bool { return (bool)preg_match('/^#[0-9A-Fa-f]{6}$/', $c); }
function site_fonts(): array { return ['Prompt' => '']; }
function layout_max_width(): string { return '1180px'; }
function shade_hex(string $hex, float $factor = 0.68): string {
    $n = hexdec(substr($hex, 1));
    $f = fn($x) => max(0, (int)round($x * $factor));
    return sprintf('#%02x%02x%02x', $f(($n >> 16) & 255), $f(($n >> 8) & 255), $f($n & 255));
}
function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function url(string $p = ''): string { return '/' . ltrim($p, '/'); }

require APP_ROOT . '/includes/theme-presets.php';

/* [ชื่อคู่สี, token หน้า, token หลัง, เกณฑ์ขั้นต่ำ] */
$checks = [
    ['เนื้อความ / พื้นการ์ด',      'text',  'card',  4.5],
    ['หัวข้อ / พื้นการ์ด',         'ink',   'card',  4.5],
    ['ข้อความรอง / พื้นการ์ด',     'muted', 'card',  4.5],
    ['เนื้อความ / พื้นหน้าเว็บ',   'text',  'bg',    4.5],
    ['หัวข้อ / พื้นหน้าเว็บ',      'ink',   'bg',    4.5],
    ['สีหลัก / พื้นการ์ด (ลิงก์)', 'color', 'card',  3.0],
];

$fail = 0;
foreach (theme_presets() as $key => $p) {
    $t = theme_preset_tokens($key);
    $rows = [];
    $bad  = 0;
    foreach ($checks as [$label, $fg, $bgk, $need]) {
        $r  = contrast_ratio($t[$fg], $t[$bgk]);
        $ok = $r >= $need;
        if (!$ok) $bad++;
        $rows[] = sprintf('    %-32s %5.2f : 1  (ต้อง %.1f)  %s',
            $label, $r, $need, $ok ? 'ผ่าน' : '*** ตก ***');
    }
    /* ตัวอักษรบนปุ่ม ไม่ได้ขาวเสมอไป — on_color() เลือกขาวหรือดำตามสีของปุ่ม */
    foreach ([['ตัวอักษร / ปุ่มสีหลัก', $t['color'], on_color($t['color'])],
              ['ตัวอักษร / ปุ่มสีหลักตอนชี้', $t['dark'], on_color($t['dark'])],
              ['สีแบรนด์ / พื้นขาว (กล่องพื้นเข้ม)', '#FFFFFF', brand_ink($t['color'])]] as [$lb, $bgc, $fgc]) {
        $r  = contrast_ratio($fgc, $bgc);
        $ok = $r >= 4.5;
        if (!$ok) $bad++;
        $rows[] = sprintf('    %-32s %5.2f : 1  (ต้อง 4.5)  %s  [%s]', $lb, $r, $ok ? 'ผ่าน' : '*** ตก ***', $fgc);
    }

    printf("%s  [%s]%s\n", $p['name'], $key, $bad ? '  ← มี ' . $bad . ' คู่ที่ตก' : '');
    echo implode("\n", $rows), "\n\n";
    if ($bad) $fail++;
}

if ($fail) {
    echo "สรุป: ธีมที่ยังไม่ผ่านเกณฑ์ {$fail} ชุด\n";
    exit(1);
}
echo "สรุป: ธีมสำเร็จรูปทุกชุดผ่านเกณฑ์ WCAG AA\n";
