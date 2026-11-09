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
        'stats'    => docshelf_stats_clean($raw['stats'] ?? null),
        'custom'   => docshelf_custom_clean($raw['custom'] ?? null),
    ];
}

/* ─────────────────────────────────────────────
   กล่องตัวเลขบนหัวหมวด — ผู้ดูแลเลือกเองว่าจะแสดงอะไร เรียงอย่างไร และเพิ่มกล่องกำหนดเองได้
   ───────────────────────────────────────────── */

/** ตัวเลขที่ระบบคำนวณได้: key => [ชื่อในหน้าจัดการ, รูปแบบหมวดที่มีค่านี้] */
function docshelf_stat_catalog(): array {
    $all = ['list', 'book', 'shelf'];
    return [
        'l1'        => ['จำนวนหัวข้อชั้นที่ 1 (เช่น ลักษณะ)', ['book']],
        'l2'        => ['จำนวนหัวข้อชั้นที่ 2 (เช่น บท)', ['book']],
        'l3'        => ['จำนวนรายการประกอบ (เช่น ฉบับแก้ไข)', ['book']],
        'active'    => ['จำนวนที่มีผลใช้บังคับ', ['book']],
        'repealed'  => ['จำนวนที่ยกเลิกแล้ว', ['book']],
        'files'     => ['จำนวนไฟล์ / ฉบับในหมวด', $all],
        'groups'    => ['จำนวนปี / กลุ่ม', ['shelf']],
        'latest_no' => ['เลขฉบับล่าสุด', ['shelf']],
        'opens'     => ['ยอดเปิดอ่านรวม (ทุกไฟล์ในหมวด)', $all],
        'views'     => ['ผู้เข้าชมหน้าหมวดนี้', $all],
        'updated'   => ['ปรับปรุงล่าสุด (วันที่)', $all],
    ];
}

/** ค่าเริ่มต้นของแต่ละรูปแบบ (ใช้เมื่อผู้ดูแลยังไม่เคยเลือก) — ตรงกับที่แสดงก่อนมีตัวเลือกนี้ */
function docshelf_stat_defaults(string $layout): array {
    return match ($layout) {
        'book'  => ['l1', 'l2', 'l3', 'active', 'repealed'],
        'shelf' => ['files', 'groups', 'latest_no', 'opens'],
        default => ['files'],
    };
}

/** stats ที่เก็บ = {key: ลำดับ} ของกล่องที่เปิด · null = ยังไม่เคยตั้ง (ใช้ค่าเริ่มต้น) */
function docshelf_stats_clean($raw): ?array {
    if (!is_array($raw)) return null;
    $cat = docshelf_stat_catalog();
    $out = [];
    foreach ($raw as $k => $o) if (isset($cat[$k])) $out[$k] = max(1, min(99, (int)$o));
    asort($out);
    return $out;
}

/** กล่องกำหนดเอง สูงสุด 3 กล่อง: [label, value, tone] · tone = '' | ok | rep | blue */
function docshelf_custom_clean($raw): array {
    if (!is_array($raw)) return [];
    $out = [];
    foreach (array_slice(array_values($raw), 0, 3) as $c) {
        if (!is_array($c)) continue;
        $label = mb_substr(trim((string)($c['label'] ?? '')), 0, 30);
        $value = mb_substr(trim((string)($c['value'] ?? '')), 0, 20);
        if ($label === '' || $value === '') continue;
        $tone = in_array($c['tone'] ?? '', ['ok', 'rep', 'blue'], true) ? $c['tone'] : '';
        $out[] = ['label' => $label, 'value' => $value, 'tone' => $tone, 'order' => max(1, min(99, (int)($c['order'] ?? 50)))];
    }
    return $out;
}

/**
 * รวมกล่องที่จะแสดง: ตัวเลขของระบบที่มีค่า (ตามที่เลือก/ค่าเริ่มต้น) + กล่องกำหนดเอง เรียงตามลำดับ
 * $values = [key => [label, value, tone]] — key ที่ไม่มีค่า (null) จะไม่แสดง เช่น ยอดเปิด 0 ในหมวดใหม่
 */
function docshelf_stat_items(array $conf, string $layout, array $values): array {
    $pick = $conf['stats'] ?? null;
    if ($pick === null) {
        $pick = [];
        foreach (docshelf_stat_defaults($layout) as $i => $k) $pick[$k] = $i + 1;
    }
    $items = [];
    foreach ($pick as $k => $o) {
        if (!isset($values[$k]) || $values[$k] === null) continue;
        $items[] = $values[$k] + [3 => $o];
    }
    foreach ($conf['custom'] ?? [] as $c) $items[] = [$c['label'], $c['value'], $c['tone'], $c['order']];
    usort($items, fn($a, $b) => $a[3] <=> $b[3]);
    return array_slice($items, 0, 8);
}

/** วาดกล่องตัวเลข (ใช้ class bk-stats เดียวกับหน้าสารบัญ) */
function docshelf_stat_boxes(array $items): string {
    if (!$items) return '';
    $h = '<dl class="bk-stats">';
    foreach ($items as [$label, $value, $tone]) {
        $h .= '<div><dt>' . e($label) . '</dt><dd' . ($tone !== '' ? ' class="' . e($tone) . '"' : '') . '>' . e($value) . '</dd></div>';
    }
    return $h . '</dl>';
}

/** ยอดผู้เข้าชมหน้าหมวด (นับตั้งแต่เวอร์ชันที่เริ่มเก็บ แยกต่อหมวดด้วยชื่อหน้า doccat-<id>) */
function docshelf_cat_views(int $catId): int {
    try {
        $st = db()->prepare('SELECT COALESCE(SUM(views), 0) FROM page_views WHERE page = ?');
        $st->execute(['doccat-' . $catId]);
        return (int)$st->fetchColumn();
    } catch (Throwable $e) { return 0; }
}

/** ค่าตั้งจากฟอร์ม admin → JSON ที่เก็บ */
function docshelf_conf_from_post(array $in): string {
    /* กล่องตัวเลข: เก็บเฉพาะที่ติ๊ก พร้อมลำดับที่กรอก · ไม่มีส่วนนี้ในฟอร์ม = คงค่าเดิมไม่ได้ จึงเก็บ null = ค่าเริ่มต้น */
    $stats = null;
    if (isset($in['stat_on']) || isset($in['stat_order'])) {
        $stats = [];
        foreach ((array)($in['stat_on'] ?? []) as $k => $on) if ($on) $stats[$k] = (int)(($in['stat_order'][$k] ?? 0) ?: 50);
        /* ยังเป็นค่าเริ่มต้นของรูปแบบที่แสดงในฟอร์ม (ผู้ดูแลไม่ได้แตะ) → เก็บ null
           เพื่อให้เปลี่ยนรูปแบบหมวดภายหลังแล้วได้กล่องเริ่มต้นของรูปแบบใหม่ ไม่ติดค่าของรูปแบบเก่า */
        $def = [];
        foreach (docshelf_stat_defaults((string)($in['stat_layout'] ?? '')) as $i => $k) $def[$k] = $i + 1;
        if (isset($in['stat_layout']) && $stats == $def) $stats = null;
    }
    $c = docshelf_conf(['view_conf' => json_encode([
        'open' => $in['open'] ?? null, 'group' => $in['group'] ?? null, 'size' => $in['size'] ?? null,
        'sort' => $in['sort'] ?? null, 'featured' => !empty($in['featured']) ? 1 : 0,
        'stats' => $stats, 'custom' => $in['custom'] ?? [],
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
