<?php
/**
 * theme-presets.php — แกนกลางของระบบธีม
 *
 * ที่นี่ที่เดียวที่ตัดสินว่า "ธีมปัจจุบันมีหน้าตาอย่างไร" แล้วแปลงออกเป็น CSS
 * ใช้ร่วมกันทั้งหน้าเว็บจริง (includes/header.php) และตัวอย่างในหน้า admin
 * — ตัวอย่างจึงเป็นของจริงเสมอ ไม่มีทางเพี้ยนจากหน้าเว็บ
 *
 * ทำไมต้องมีไฟล์นี้: เดิมระบบฉีดตัวแปรให้ CSS แค่ 3 ตัว (--blue, --blue-700, --max)
 * ธีมจึงต่างกันได้แค่ "สีปุ่ม" เท่านั้น ส่วนสีพื้น สีตัวอักษร ความโค้งมุม และเงา
 * ถูกล็อกไว้ค่าเดียวใน theme-kit.css ไฟล์นี้เปิดทั้งชุดให้ตั้งค่าได้
 */
if (!defined('APP_ROOT')) exit('Forbidden');

/** ค่าเริ่มต้นของทุก token — ตรงกับ :root ใน theme-kit.css เป๊ะ
 *  เว็บที่อัปเดตจากรุ่นเก่าจึงหน้าตาเหมือนเดิมทุกพิกเซลจนกว่าจะเลือกธีมใหม่ */
function theme_defaults(): array {
    return [
        'color'  => '#1A73E8',
        'dark'   => '#1557B0',
        'ink'    => '#0B1220',
        'text'   => '#1F2937',
        'muted'  => '#6B7280',
        'bg'     => '#F6F8FC',
        'card'   => '#FFFFFF',
        'font'   => 'Prompt',
        'radius' => 'md',
        'shadow' => 'soft',
        'glow'   => '1',
        'width'  => 'normal',
        'header' => 'left',
    ];
}

/** ความโค้งมุม: ชื่อ → [--r16, --r24] */
function theme_radius_sets(): array {
    return [
        'sharp' => ['4px',  '8px',  'เหลี่ยม (ทางการ)'],
        'sm'    => ['10px', '16px', 'โค้งน้อย'],
        'md'    => ['16px', '24px', 'โค้งปกติ'],
        'lg'    => ['22px', '32px', 'โค้งมาก'],
        'xl'    => ['28px', '42px', 'โค้งมากที่สุด'],
    ];
}

/** ระดับเงา: ชื่อ → คำอธิบาย (ค่าจริงคำนวณใน theme_css_vars เพราะต้องอิงสีของธีม) */
function theme_shadow_sets(): array {
    return ['flat' => 'แบน (ไม่มีเงา)', 'soft' => 'เงานุ่ม', 'deep' => 'เงาลึก'];
}

/* ─────────────────────────────────────────────────────────────
   เครื่องมือคำนวณสี
   ───────────────────────────────────────────────────────────── */

/** #RRGGBB → [r, g, b] */
function hex_rgb(string $hex): array {
    $hex = ltrim(trim($hex), '#');
    if (strlen($hex) === 3) $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    if (!preg_match('/^[0-9A-Fa-f]{6}$/', $hex)) return [0, 0, 0];
    $n = hexdec($hex);
    return [($n >> 16) & 255, ($n >> 8) & 255, $n & 255];
}

/** #RRGGBB + ความทึบ → rgba() ที่ CSS ใช้ได้ */
function hex_rgba(string $hex, float $a): string {
    [$r, $g, $b] = hex_rgb($hex);
    return sprintf('rgba(%d,%d,%d,%s)', $r, $g, $b, rtrim(rtrim(number_format($a, 3, '.', ''), '0'), '.'));
}

/** ความสว่างสัมพัทธ์ตามสูตร WCAG 2.x (0 = ดำสนิท, 1 = ขาวสนิท) */
function srgb_luminance(string $hex): float {
    $c = array_map(function ($v) {
        $v /= 255;
        return $v <= 0.03928 ? $v / 12.92 : pow(($v + 0.055) / 1.055, 2.4);
    }, hex_rgb($hex));
    return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
}

/** อัตราส่วนความต่างของสีสองสี (1 = เหมือนกัน, 21 = ดำกับขาว)
 *  เกณฑ์ WCAG AA: ตัวอักษรปกติ ≥ 4.5 : 1, ตัวอักษรใหญ่/ตัวหนา ≥ 3 : 1 */
function contrast_ratio(string $a, string $b): float {
    $la = srgb_luminance($a);
    $lb = srgb_luminance($b);
    return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
}

/** ธีมนี้เป็นโทนเข้มหรือไม่ (ดูจากสีพื้นหน้า) */
function theme_is_dark(array $t): bool {
    return srgb_luminance($t['bg']) < 0.35;
}

/**
 * สีตัวอักษรที่อ่านออกบนพื้นสีหลัก — ขาวหรือดำ แล้วแต่ว่าอันไหนต่างกว่า
 *
 * จำเป็นเพราะธีมโทนเข้มใช้สีหลักที่สว่าง (ฟ้าอ่อน) ถ้ายังใส่ตัวหนังสือขาวบนปุ่ม
 * ค่าความต่างจะเหลือราว 2.5 : 1 ซึ่งตกเกณฑ์ WCAG และอ่านไม่ออกจริงๆ บนจอกลางแดด
 */
function on_color(string $bg): string {
    return contrast_ratio('#FFFFFF', $bg) >= contrast_ratio('#0B0F14', $bg) ? '#FFFFFF' : '#0B0F14';
}

/**
 * สีแบรนด์ที่การันตีว่าอ่านออกบน "พื้นขาว" เสมอ
 * ใช้กับกล่องที่พื้นเข้มตายตัวไม่ว่าธีมจะเป็นแบบไหน (เช่น กล่องอิสระพื้นเข้ม)
 * ค่อยๆ ทำให้เข้มขึ้นทีละขั้นจนผ่านเกณฑ์ 4.5 : 1
 */
function brand_ink(string $hex): string {
    $c = $hex;
    for ($i = 0; $i < 12 && contrast_ratio($c, '#FFFFFF') < 4.5; $i++) {
        $c = strtoupper(shade_hex($c, 0.86));
    }
    return $c;
}

/* ─────────────────────────────────────────────────────────────
   ชุดธีมสำเร็จรูป
   ───────────────────────────────────────────────────────────── */

/**
 * ธีมสำเร็จรูป — กดเลือกแล้วได้ทั้งชุด (สี + ฟอนต์ + ความโค้ง + เงา + หัวเว็บ)
 * แต่ละชุดยังแก้รายตัวต่อได้ภายหลัง ธีมเป็นแค่ "จุดตั้งต้น" ไม่ใช่กรงขัง
 *
 * ทุกชุดผ่านการวัดค่าความต่างของสีตามเกณฑ์ WCAG AA แล้ว
 * (ดู tools/check-theme-contrast.php — รันซ้ำได้ทุกเมื่อ)
 */
function theme_presets(): array {
    return [
        'default' => [
            'name' => 'น้ำเงินมาตรฐาน',
            'desc' => 'ค่าเริ่มต้นของระบบ สุภาพ อ่านง่าย เหมาะกับทุกหน่วยงาน',
            'tokens' => [],   /* ว่าง = ใช้ค่าเริ่มต้นทั้งหมด */
        ],
        'gov-classic' => [
            'name' => 'ราชการคลาสสิก',
            'desc' => 'กรมท่าเข้ม มุมเหลี่ยม ไม่มีเงา เรียบแบบหนังสือราชการ',
            'tokens' => [
                'color' => '#0F4C9C', 'dark' => '#093567',
                'ink' => '#10203A', 'text' => '#1E2D42', 'muted' => '#5A6B82',
                'bg' => '#F2F5F9', 'card' => '#FFFFFF',
                'font' => 'Sarabun', 'radius' => 'sharp', 'shadow' => 'flat', 'glow' => '0',
            ],
        ],
        'clean' => [
            'name' => 'สะอาดสมัยใหม่',
            'desc' => 'ขาวสว่าง มุมโค้งมาก เงานุ่ม โลโก้กลาง — สไตล์เว็บองค์กรสากล',
            'tokens' => [
                'color' => '#0B63CE', 'dark' => '#08468F',
                'ink' => '#0A0F16', 'text' => '#2A3340', 'muted' => '#6C7787',
                'bg' => '#FAFBFD', 'card' => '#FFFFFF',
                'font' => 'IBM Plex Sans Thai', 'radius' => 'lg', 'shadow' => 'soft',
                'header' => 'center', 'width' => 'wide',
            ],
        ],
        'warm' => [
            'name' => 'อบอุ่นเป็นมิตร',
            'desc' => 'ส้มอิฐบนพื้นครีม มุมมน ให้ความรู้สึกเข้าถึงง่าย',
            'tokens' => [
                'color' => '#C2410C', 'dark' => '#8A2D06',
                'ink' => '#26150C', 'text' => '#3C2A1E', 'muted' => '#7A6455',
                'bg' => '#FBF6F0', 'card' => '#FFFFFF',
                'font' => 'Prompt', 'radius' => 'xl', 'shadow' => 'soft',
            ],
        ],
        'green' => [
            'name' => 'เขียวยั่งยืน',
            'desc' => 'เขียวป่าบนพื้นขาวอมเขียว เหมาะกับงานสิ่งแวดล้อม สาธารณสุข เกษตร',
            'tokens' => [
                'color' => '#15803D', 'dark' => '#0E5C2B',
                'ink' => '#0B1A11', 'text' => '#1E3025', 'muted' => '#5C7266',
                'bg' => '#F3F8F4', 'card' => '#FFFFFF',
                'font' => 'Noto Sans Thai', 'radius' => 'md', 'shadow' => 'soft',
            ],
        ],
        'academic' => [
            'name' => 'ม่วงวิชาการ',
            'desc' => 'ม่วงเข้มบนพื้นเทาอมม่วง เหมาะกับงานวิชาการ ฝึกอบรม',
            'tokens' => [
                'color' => '#6D28D9', 'dark' => '#4C1D95',
                'ink' => '#160B29', 'text' => '#2C2140', 'muted' => '#6B6382',
                'bg' => '#F7F5FC', 'card' => '#FFFFFF',
                'font' => 'Kanit', 'radius' => 'lg', 'shadow' => 'soft',
            ],
        ],
        'maroon' => [
            'name' => 'เลือดหมูทางการ',
            'desc' => 'แดงเข้มบนพื้นครีมอ่อน ดูมีอำนาจและน่าเชื่อถือ',
            'tokens' => [
                'color' => '#9F1239', 'dark' => '#6E0B27',
                'ink' => '#210810', 'text' => '#3A2028', 'muted' => '#7C6169',
                'bg' => '#FAF5F6', 'card' => '#FFFFFF',
                'font' => 'Sarabun', 'radius' => 'sm', 'shadow' => 'flat',
            ],
        ],
        'midnight' => [
            'name' => 'โทนเข้มพรีเมียม',
            'desc' => 'พื้นเข้มทั้งเว็บ ตัวอักษรสว่าง เหมาะกับงานนิทรรศการและจอใหญ่',
            'tokens' => [
                'color' => '#5AA9FF', 'dark' => '#8CC4FF',
                'ink' => '#F2F6FC', 'text' => '#D5DEEA', 'muted' => '#96A3B5',
                'bg' => '#0D1117', 'card' => '#161C25',
                'font' => 'Prompt', 'radius' => 'lg', 'shadow' => 'deep',
            ],
        ],
    ];
}

/** รวมค่าเริ่มต้นเข้ากับ token ของธีมสำเร็จรูปหนึ่งชุด */
function theme_preset_tokens(string $key): array {
    $p = theme_presets()[$key] ?? null;
    return $p ? array_merge(theme_defaults(), $p['tokens']) : theme_defaults();
}

/* ─────────────────────────────────────────────────────────────
   อ่านค่าที่ใช้งานอยู่จริง
   ───────────────────────────────────────────────────────────── */

/** token ที่เว็บกำลังใช้อยู่ — อ่านจากตารางตั้งค่า ถ้าค่าไหนเพี้ยนใช้ค่าเริ่มต้นแทน */
function theme_tokens(): array {
    $d = theme_defaults();
    $hex = function (string $key, string $fallback): string {
        $v = strtoupper(trim(setting($key, '')));
        return valid_hex($v) ? $v : $fallback;
    };
    $fonts = site_fonts();
    $font  = setting('font_family', $d['font']);

    return [
        'color'  => $hex('theme_color',      $d['color']),
        'dark'   => $hex('theme_color_dark', $d['dark']),
        'ink'    => $hex('theme_ink',        $d['ink']),
        'text'   => $hex('theme_text',       $d['text']),
        'muted'  => $hex('theme_muted',      $d['muted']),
        'bg'     => $hex('theme_bg',         $d['bg']),
        'card'   => $hex('theme_card',       $d['card']),
        'font'   => isset($fonts[$font]) ? $font : $d['font'],
        'radius' => isset(theme_radius_sets()[setting('theme_radius', '')]) ? setting('theme_radius') : $d['radius'],
        'shadow' => isset(theme_shadow_sets()[setting('theme_shadow', '')]) ? setting('theme_shadow') : $d['shadow'],
        'glow'   => setting('theme_glow', $d['glow']) === '0' ? '0' : '1',
        'width'  => in_array(setting('layout_width', ''), ['normal','wide','full'], true) ? setting('layout_width') : $d['width'],
        'header' => setting('header_layout', '') === 'center' ? 'center' : 'left',
    ];
}

/* ─────────────────────────────────────────────────────────────
   แปลง token เป็น CSS
   ───────────────────────────────────────────────────────────── */

/**
 * รายการตัวแปร CSS ของธีม (ไม่รวมวงเล็บปีกกา) — ใช้ได้ทั้งใน :root และใน style="" ของตัวอย่าง
 * @param bool $scoped true = ไม่ใส่ --max (ตัวอย่างย่อในหน้า admin ไม่ควรไปแตะความกว้างหน้า)
 */
function theme_css_vars(array $t, bool $scoped = false): string {
    $dark = theme_is_dark($t);
    [$r16, $r24] = theme_radius_sets()[$t['radius']] ?? theme_radius_sets()['md'];

    /* เส้นขอบต้องมองเห็นบนพื้นทั้งสว่างและมืด — โทนเข้มใช้เส้นขาวจางแทนเส้นดำจาง */
    $border = $dark ? 'rgba(255,255,255,.12)' : hex_rgba($t['ink'], .10);

    /* เงาอิงสีตัวอักษรเข้มของธีมเอง เงาบนพื้นครีมจึงไม่กลายเป็นเทาสกปรก */
    $sh = [
        'flat' => ['0 1px 2px ' . hex_rgba($t['ink'], .07),
                   '0 1px 2px ' . hex_rgba($t['ink'], .05),
                   '0 2px 10px ' . hex_rgba($t['color'], .18)],
        'soft' => ['0 10px 30px ' . hex_rgba($t['ink'], $dark ? .45 : .08),
                   '0 8px 20px '  . hex_rgba($t['ink'], $dark ? .35 : .06),
                   '0 14px 26px ' . hex_rgba($t['color'], .22)],
        'deep' => ['0 18px 48px ' . hex_rgba($t['ink'], $dark ? .60 : .16),
                   '0 12px 28px ' . hex_rgba($t['ink'], $dark ? .48 : .10),
                   '0 20px 40px ' . hex_rgba($t['color'], .32)],
    ][$t['shadow']] ?? null;
    if ($sh === null) $sh = ['0 10px 30px ' . hex_rgba($t['ink'], .08), '0 8px 20px ' . hex_rgba($t['ink'], .06), '0 14px 26px ' . hex_rgba($t['color'], .22)];

    /* แสงเรืองหลังหน้าเว็บ — เดิมเขียนสีน้ำเงินตายตัวไว้ใน theme-kit.css
       ธีมเขียว/ส้มจึงมีฟ้าเรืองอยู่มุมบนแบบไม่มีใครตั้งใจ ตอนนี้อิงสีธีมแล้ว */
    $glow = $t['glow'] === '0'
        ? $t['bg']
        : 'radial-gradient(1200px 700px at 15% 0%, ' . hex_rgba($t['color'], $dark ? .20 : .14) . ', transparent 58%),'
        . 'radial-gradient(900px 520px at 85% 10%, ' . hex_rgba($t['color'], $dark ? .15 : .11) . ', transparent 55%),'
        . $t['bg'];

    $v = [
        '--blue'        => $t['color'],
        '--blue-700'    => $t['dark'],
        /* ตัวอักษรบนพื้นสีหลัก และสีแบรนด์ที่อ่านออกบนพื้นขาวเสมอ — ดูคำอธิบายที่ on_color()/brand_ink() */
        '--on-blue'     => on_color($t['color']),
        '--on-blue-700' => on_color($t['dark']),
        '--blue-ink'    => brand_ink($t['color']),
        '--ink'         => $t['ink'],
        '--text'        => $t['text'],
        '--muted'       => $t['muted'],
        '--bg'          => $t['bg'],
        '--card'        => $t['card'],
        '--border'      => $border,
        '--shadow'      => $sh[0],
        '--shadow-soft' => $sh[1],
        '--shadow-blue' => $sh[2],
        '--r16'         => $r16,
        '--r24'         => $r24,
        '--page-bg'     => $glow,
        /* ใช้แทน #fff ที่เคยเขียนตายตัว เวลาต้องการ "พื้นการ์ดจางลงนิดหน่อย" */
        '--card-soft'   => $dark ? hex_rgba('#FFFFFF', .05) : $t['card'],
    ];
    if (!$scoped) $v['--max'] = layout_max_width();

    $out = '';
    foreach ($v as $k => $val) $out .= $k . ':' . $val . ';';
    return $out;
}

/* ─────────────────────────────────────────────────────────────
   ภาพฉากหลัง
   ───────────────────────────────────────────────────────────── */

/**
 * ค่าภาพฉากหลังที่ตั้งไว้ — คืน null ถ้าไม่ได้เปิดใช้หรือไฟล์หาย
 *
 * ไม่ใช้ background-attachment: fixed เพราะ Safari บน iOS เรนเดอร์กระตุก
 * และบางรุ่นภาพยืดผิดสัดส่วน — ใช้ชั้นภาพ position: fixed แยกออกมาแทน
 */
function theme_bg_image(): ?array {
    $scope = setting('bg_scope', 'off');
    $path  = trim(setting('bg_image', ''));
    if ($path === '' || !in_array($scope, ['page', 'top'], true)) return null;
    if (!is_file(APP_ROOT . '/' . ltrim($path, '/'))) return null;

    return [
        'path'    => $path,
        'scope'   => $scope,
        'overlay' => max(0, min(95, (int)setting('bg_overlay', '70'))) / 100,
        'blur'    => max(0, min(20, (int)setting('bg_blur', '0'))),
        'x'       => max(0, min(100, (int)setting('bg_focus_x', '50'))),
        'y'       => max(0, min(100, (int)setting('bg_focus_y', '50'))),
    ];
}

/** ตัวแปร CSS ของชั้นภาพฉากหลัง (ไม่รวมวงเล็บปีกกา) */
function theme_bg_css_vars(array $bg, array $t): string {
    /* ม่านบังต้องเป็นสีเดียวกับพื้นหน้าของธีม ธีมสว่างจึงได้ม่านขาว ธีมเข้มได้ม่านดำ
       โดยอัตโนมัติ — ตัวอักษรอ่านออกเสมอไม่ว่าภาพจะสว่างหรือมืด */
    $o  = $bg['overlay'];
    $top = min(1, $o + .10);   /* บนทึบกว่าล่างเล็กน้อย ช่วยให้เมนูด้านบนอ่านชัด */
    return '--bgimg:url("' . addcslashes(url($bg['path']), '"\\') . '");'
         . '--bgpos:' . $bg['x'] . '% ' . $bg['y'] . '%;'
         . '--bgblur:' . $bg['blur'] . 'px;'
         . '--bgveil:linear-gradient(to bottom,' . hex_rgba($t['bg'], $top) . ',' . hex_rgba($t['bg'], $o) . ');';
}

/** คลาสของ <body> ที่มาจากระบบธีม (ต่อท้ายคลาสเดิม เช่น anim-full) */
function theme_body_classes(array $t, ?array $bg): string {
    $c = [];
    if (theme_is_dark($t)) $c[] = 'theme-dark';
    if ($bg) { $c[] = 'has-bgimg'; $c[] = 'bg-' . $bg['scope']; }
    return $c ? ' ' . implode(' ', $c) : '';
}
