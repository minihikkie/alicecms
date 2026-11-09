<?php
/**
 * doc-shelf.php — เอกสารแบบ "เล่มมีปก" (วารสาร รายงานประจำปี จุลสาร ฯลฯ)
 *
 * หมวดที่ตั้งรูปแบบเป็น "ชั้นวางแบบมีปก" (doc_categories.layout = 'shelf') แสดงเป็นชั้นหนังสือ:
 *   ฉบับล่าสุดเด่นด้านบน → ชั้นแยกตามกลุ่ม (ปีที่ 62 / ปีงบประมาณ 2568 …) → การ์ดปกแต่ละฉบับ
 * และเอกสารทุกฉบับมีหน้ารายละเอียดของตัวเอง (doc.php)
 *
 * ตั้งค่าได้ต่อหมวด (doc_categories.view_conf เป็น JSON) — ไม่มีคำของหน่วยงานใดฝังในโค้ด:
 *   open     page = กดแล้วไปหน้ารายละเอียด · file = เปิดไฟล์ทันที
 *   group    auto = ใช้ช่อง "กลุ่ม" ของเอกสาร ถ้าว่างอ่านจากชื่อเรื่อง (ปีที่ 62 / ปีงบประมาณ 2568) ไม่เจอใช้ปี พ.ศ.
 *            year = ปี พ.ศ. ของวันที่เผยแพร่ · none = ไม่แบ่งกลุ่ม
 *   size     ขนาดการ์ดปก sm / md / lg
 *   featured แสดงฉบับล่าสุดเด่นด้านบน
 *   sort     new = ใหม่ก่อน · old = เก่าก่อน
 */
if (!defined('APP_ROOT')) exit('Forbidden');

/** ค่าตั้งการแสดงผลของหมวด (รวมค่าเริ่มต้นตามรูปแบบหมวด) */
function docshelf_conf(array $cat): array {
    $raw = json_decode((string)($cat['view_conf'] ?? ''), true);
    $raw = is_array($raw) ? $raw : [];
    $isShelf = ($cat['layout'] ?? 'list') === 'shelf';
    $pick = fn(string $k, array $ok, string $def) => in_array($raw[$k] ?? null, $ok, true) ? $raw[$k] : $def;
    return [
        'open'     => $pick('open', ['page', 'file'], $isShelf ? 'page' : 'file'),
        'group'    => $pick('group', ['auto', 'year', 'none'], 'auto'),
        'size'     => $pick('size', ['sm', 'md', 'lg'], 'md'),
        'sort'     => $pick('sort', ['new', 'old'], 'new'),
        'featured' => isset($raw['featured']) ? (int)!empty($raw['featured']) : 1,
    ];
}

/** ค่าตั้งจากฟอร์ม admin → JSON ที่เก็บ */
function docshelf_conf_from_post(array $in): string {
    $c = docshelf_conf(['view_conf' => json_encode([
        'open' => $in['open'] ?? null, 'group' => $in['group'] ?? null, 'size' => $in['size'] ?? null,
        'sort' => $in['sort'] ?? null, 'featured' => !empty($in['featured']) ? 1 : 0,
    ]), 'layout' => 'shelf']);
    return json_encode($c, JSON_UNESCAPED_UNICODE);
}

/** วันที่ของฉบับ — วันที่เผยแพร่ที่ผู้ดูแลกรอก ถ้าไม่มีใช้วันที่อัปโหลด */
function docshelf_date(array $d): string {
    return !empty($d['pub_date']) ? (string)$d['pub_date'] : (string)($d['created_at'] ?? '');
}

/** เลขไทย → อารบิก */
function docshelf_digits(string $s): string {
    return strtr($s, ['๐' => '0', '๑' => '1', '๒' => '2', '๓' => '3', '๔' => '4', '๕' => '5', '๖' => '6', '๗' => '7', '๘' => '8', '๙' => '9']);
}

/**
 * กลุ่มของฉบับ (หัวชั้น) — ช่อง "กลุ่ม" ที่ผู้ดูแลกรอกมาก่อน
 * ไม่กรอก → อ่านจากชื่อเรื่อง: "ปีที่ 62" / "ปีงบประมาณ 2568" / "พ.ศ. 2568" → ไม่เจอ → ปี พ.ศ. ของวันที่
 */
function docshelf_group(array $d, array $conf): string {
    if ($conf['group'] === 'none') return '';
    $y = ($t = strtotime(docshelf_date($d))) ? 'ปี พ.ศ. ' . ((int)date('Y', $t) + 543) : '';
    if ($conf['group'] === 'year') return $y;
    if (trim((string)($d['grp'] ?? '')) !== '') return trim((string)$d['grp']);
    $title = docshelf_digits((string)($d['title'] ?? ''));
    if (preg_match('/ปีที่\s*(\d{1,3})/u', $title, $m)) return 'ปีที่ ' . (int)$m[1];
    if (preg_match('/ปีงบประมาณ\s*(?:พ\.\s*ศ\.\s*)?(25\d\d)/u', $title, $m)) return 'ปีงบประมาณ ' . $m[1];
    if (preg_match('/(?:พ\.\s*ศ\.|ปี)\s*(25\d\d)/u', $title, $m)) return 'ปี พ.ศ. ' . $m[1];
    return $y;
}

/** เลขฉบับสำหรับปกที่ระบบวาด: "ฉบับ 487" → ['ฉบับที่', '487'] · "รายงานประจำปี 2568" → ['ปี', '2568'] */
function docshelf_issue(string $title): array {
    $t = docshelf_digits($title);
    if (preg_match('/ฉบับ(?:ที่)?\s*(\d{1,5})/u', $t, $m)) return ['ฉบับที่', $m[1]];
    if (preg_match('/(?:ปีงบประมาณ|พ\.\s*ศ\.|ประจำปี)\s*(?:พ\.\s*ศ\.\s*)?(25\d\d)/u', $t, $m)) return ['ปี', $m[1]];
    if (preg_match('/\b(25\d\d)\b/u', $t, $m)) return ['ปี', $m[1]];
    return ['', ''];
}

/** ลิงก์เมื่อกดเอกสาร — หน้ารายละเอียดหรือไฟล์ ตามค่าตั้งของหมวด */
function docshelf_href(array $d, array $conf): string {
    return url(($conf['open'] === 'page' ? 'doc.php?id=' : 'download.php?id=') . (int)$d['id']);
}

/**
 * ปกของฉบับ — ภาพปกที่อัปโหลด หรือปกที่ระบบวาดให้ (สีตามหมวด + เลขฉบับตัวใหญ่ + ชื่อเรื่อง)
 * วาดเองแทนการใช้ปกหมวดซ้ำ เพราะทุกฉบับจะหน้าตาเหมือนกันจนแยกไม่ออก
 */
function docshelf_cover(array $d, array $cat, string $cls = ''): string {
    if (!empty($d['cover'])) {
        return '<span class="dcv has-img' . ($cls ? ' ' . $cls : '') . '"><img src="' . e(url($d['cover'])) . '" alt="" width="900" height="1200" loading="lazy" decoding="async"></span>';
    }
    [$word, $num] = docshelf_issue((string)$d['title']);
    $style = in_array($cat['style'] ?? '', ['special', 'warning'], true) ? $cat['style'] : 'blue';
    return '<span class="dcv gen s-' . $style . ($cls ? ' ' . $cls : '') . '" aria-hidden="true">'
         . '<span class="dcv-top">' . e(mb_strimwidth((string)($cat['name'] ?? ''), 0, 40, '…')) . '</span>'
         . ($num !== '' ? '<span class="dcv-no"><small>' . e($word) . '</small><b>' . e($num) . '</b></span>'
                        : '<span class="dcv-ic material-symbols-rounded">' . e($cat['icon'] ?: 'menu_book') . '</span>')
         . '<span class="dcv-t">' . e((string)$d['title']) . '</span></span>';
}

/** ข้อความชนิด/ขนาดไฟล์ */
function docshelf_filemeta(array $d): string {
    if (!empty($d['ext_url'])) return 'ลิงก์ภายนอก';
    return strtoupper((string)$d['ext']) . ((int)$d['file_size'] > 0 ? ' · ' . format_bytes((int)$d['file_size']) : '');
}

/**
 * จัดเอกสารของหมวดเป็นกลุ่มตามค่าตั้ง — คืน [[label, [docs...]], ...]
 * เรียงกลุ่มตามวันที่ล่าสุดในกลุ่ม · เรียงฉบับในกลุ่มตามวันที่ (ใหม่/เก่าก่อน ตาม sort)
 */
function docshelf_groups(array $docs, array $conf): array {
    $by = [];
    foreach ($docs as $d) {
        $g = docshelf_group($d, $conf);
        $by[$g][] = $d;
    }
    $sign = $conf['sort'] === 'old' ? 1 : -1;
    $cmp = fn($a, $b) => $sign * strcmp(docshelf_date($a) . sprintf('%010d', $a['id']), docshelf_date($b) . sprintf('%010d', $b['id']));
    $out = [];
    foreach ($by as $g => $list) {
        usort($list, $cmp);
        $out[] = [$g, $list];
    }
    usort($out, fn($a, $b) => $cmp($a[1][0], $b[1][0]));
    return $out;
}
