<?php
/**
 * doc-book.php — สารบัญแบบหนังสือของหมวดเอกสาร (เช่น ประมวลระเบียบการตำรวจไม่เกี่ยวกับคดี)
 *
 * เอกสารบางหมวดไม่ใช่ไฟล์ที่วางเรียงกันเฉยๆ แต่เป็น "เล่ม" ที่มีโครงสร้าง
 *   ลักษณะ (part) → บท (chapter) → ฉบับแก้ไขเพิ่มเติม (amend)
 * ถ้าแสดงเป็นรายการไฟล์แบนๆ คนอ่านจะไม่รู้ว่าฉบับไหนแก้บทไหน และลักษณะไหนถูกยกเลิกไปแล้ว
 *
 * ตาราง doc_toc เก็บหัวข้อของสารบัญ (ผูกตัวเองด้วย parent_id) — แต่ละหัวข้อจะชี้ไปที่
 * เอกสารที่อัปโหลดไว้แล้วในหมวด (document_id) หรือลิงก์ภายนอก (url) หรือไม่มีลิงก์ก็ได้
 * (บทส่วนใหญ่อยู่ในไฟล์ของลักษณะ ไม่มีไฟล์แยก)
 *
 * โครงสร้างเป็น 3 ชั้นตายตัวในฐานข้อมูล (kind = part / chapter / amend) แต่ "ชื่อเรียก" ของแต่ละชั้น
 * เป็นของแต่ละหมวด (doc_categories.book_conf) — เอกสารแต่ละประเภทเรียกต่างกัน
 *   ประมวลระเบียบ  ลักษณะที่ → บทที่ → ฉบับแก้ไขเพิ่มเติม
 *   กฎหมาย         ชื่อ พ.ร.บ. → หมวด → ฉบับแก้ไข / กฎหมายลำดับรอง
 *   วารสาร         ปีที่ → ฉบับที่ → บทความ
 * ชั้นที่ 3 (amend) คือ "รายการประกอบ" ที่แนบได้ทั้งใต้ชั้น 1 และชั้น 2
 */
if (!defined('APP_ROOT')) exit('Forbidden');

/**
 * แม่แบบรูปแบบเล่ม — key => [ชื่อแม่แบบ, ตัวอย่างลำดับชั้น, ค่าตั้ง]
 *   l1/l2/l3 = ชื่อเรียกชั้น · p1/p2/p3 = คำนำหน้าเลข (ว่าง = ไม่มีเลข ใช้ชื่อเรื่องอย่างเดียว)
 *   icon3 = ไอคอนของรายการประกอบ · status = ใช้สถานะ "ยกเลิกแล้ว" หรือไม่ · s_on/s_off = คำเรียกสถานะ
 */
function docbook_presets(): array {
    $st = ['status' => 1, 's_on' => 'มีผลใช้บังคับ', 's_off' => 'ยกเลิกแล้ว'];
    $no = ['status' => 0, 's_on' => 'ใช้งานอยู่', 's_off' => 'ยกเลิกแล้ว'];
    return [
        'regulation' => ['ประมวล / ระเบียบที่แบ่งเป็นลักษณะ', 'ลักษณะที่ 4 › บทที่ 1 › ฉบับที่ 2',
            ['l1' => 'ลักษณะ', 'p1' => 'ลักษณะที่', 'l2' => 'บท', 'p2' => 'บทที่', 'l3' => 'ฉบับแก้ไขเพิ่มเติม', 'p3' => 'ฉบับที่', 'icon3' => 'edit_document'] + $st],
        'law' => ['กฎหมาย (พ.ร.บ. · พ.ร.ก. · กฎกระทรวง)', 'พ.ร.บ.ตำรวจแห่งชาติ พ.ศ. 2565 › หมวด 1 › กฎ ก.ตร.',
            ['l1' => 'กฎหมาย', 'p1' => '', 'l2' => 'หมวด', 'p2' => 'หมวด', 'l3' => 'ฉบับแก้ไข / กฎหมายลำดับรอง', 'p3' => '', 'icon3' => 'edit_document'] + $st],
        'rule' => ['ระเบียบ · ข้อบังคับ · คำสั่ง', 'ระเบียบ ตร. ว่าด้วย… › หมวด 2 › ฉบับที่ 3',
            ['l1' => 'ระเบียบ', 'p1' => '', 'l2' => 'หมวด', 'p2' => 'หมวด', 'l3' => 'ฉบับแก้ไขเพิ่มเติม', 'p3' => 'ฉบับที่', 'icon3' => 'edit_document'] + $st],
        'journal' => ['วารสาร · จดหมายข่าว', 'ปีที่ 12 › ฉบับที่ 1 (ม.ค.–มี.ค. 2569) › บทความ',
            ['l1' => 'ปี', 'p1' => 'ปีที่', 'l2' => 'ฉบับ', 'p2' => 'ฉบับที่', 'l3' => 'บทความ', 'p3' => '', 'icon3' => 'article'] + $no],
        'report' => ['รายงาน (ประจำปี · ผลการดำเนินงาน)', 'ปีงบประมาณ 2569 › รายงานประจำปี › เอกสารประกอบ',
            ['l1' => 'ปีงบประมาณ', 'p1' => 'ปีงบประมาณ', 'l2' => 'รายงาน', 'p2' => '', 'l3' => 'เอกสารประกอบ', 'p3' => '', 'icon3' => 'attach_file'] + $no],
        'manual' => ['คู่มือ · หนังสือ · ตำรา', 'ส่วนที่ 1 › บทที่ 3 › ภาคผนวก ก',
            ['l1' => 'ส่วน', 'p1' => 'ส่วนที่', 'l2' => 'บท', 'p2' => 'บทที่', 'l3' => 'ภาคผนวก / เอกสารแนบ', 'p3' => '', 'icon3' => 'attach_file'] + $no],
        'general' => ['ทั่วไป', 'หัวข้อ › หัวข้อย่อย › เอกสารแนบ',
            ['l1' => 'หัวข้อ', 'p1' => '', 'l2' => 'หัวข้อย่อย', 'p2' => '', 'l3' => 'เอกสารแนบ', 'p3' => '', 'icon3' => 'attach_file'] + $no],
        'custom' => ['กำหนดเอง', 'ตั้งชื่อเรียกแต่ละชั้นเอง', []],
    ];
}

/** เดาแม่แบบจากชื่อหมวด — ใช้กับหมวดที่ยังไม่เคยตั้งรูปแบบเล่ม (หมวด "วารสาร" จะได้ ปีที่/ฉบับที่ ทันที) */
function docbook_guess_preset(string $name): string {
    $map = ['journal' => ['วารสาร', 'จดหมายข่าว', 'จุลสาร'], 'report' => ['รายงาน'],
            'manual' => ['คู่มือ', 'หนังสือ', 'ตำรา'], 'regulation' => ['ประมวล'],
            'law' => ['พ.ร.บ', 'พระราชบัญญัติ', 'กฎหมาย', 'พ.ร.ก', 'กฎกระทรวง'],
            'rule' => ['ระเบียบ', 'ข้อบังคับ', 'คำสั่ง', 'ประกาศ']];
    foreach ($map as $k => $words) foreach ($words as $w) if (mb_strpos($name, $w) !== false) return $k;
    return 'general';
}

/** ทำความสะอาดค่าตั้ง (มาจากฐานข้อมูลหรือฟอร์ม) — ทับบนค่าของแม่แบบ ช่องที่ขาด/ผิดรูปใช้ค่าแม่แบบ */
function docbook_conf_clean(array $in, string $preset): array {
    $presets = docbook_presets();
    if (!isset($presets[$preset])) $preset = 'regulation';
    $base = $presets[$preset][2] ?: $presets['regulation'][2];
    $c = ['preset' => $preset];
    foreach (['l1' => 40, 'l2' => 40, 'l3' => 40, 'p1' => 30, 'p2' => 30, 'p3' => 30, 's_on' => 30, 's_off' => 30] as $k => $max) {
        $v = isset($in[$k]) ? trim(preg_replace('/\s+/u', ' ', (string)$in[$k]) ?? '') : null;
        /* ชื่อเรียกชั้นว่างไม่ได้ (หน้าเว็บต้องมีคำเรียก) — คำนำหน้าเลขว่างได้ */
        $c[$k] = ($v === null || ($v === '' && $k[0] !== 'p')) ? $base[$k] : mb_substr($v, 0, $max);
    }
    $icon = (string)($in['icon3'] ?? '');
    $c['icon3'] = preg_match('/^[a-z0-9_]{1,40}$/', $icon) ? $icon : $base['icon3'];
    $c['status'] = isset($in['status']) ? (int)!empty($in['status']) : (int)$base['status'];
    return $c;
}

/** ค่าตั้งรูปแบบเล่มของหมวด (+ ไอคอนของหมวด ใช้แทนเลขบนการ์ดเมื่อหัวข้อไม่มีเลข) */
function docbook_conf(array $cat): array {
    $raw = json_decode((string)($cat['book_conf'] ?? ''), true);
    if (is_array($raw)) {
        $preset = (string)($raw['preset'] ?? 'custom');
        /* แม่แบบสำเร็จรูป = ใช้ค่าล่าสุดของแม่แบบเสมอ (ปรับคำในแม่แบบทีหลังได้ทุกหมวดพร้อมกัน) */
        $c = $preset === 'custom' ? docbook_conf_clean($raw, 'custom') : docbook_conf_clean([], $preset);
    } else {
        $c = docbook_conf_clean([], docbook_guess_preset((string)($cat['name'] ?? '')));
        $c['guessed'] = true;
    }
    $c['cat_icon'] = preg_match('/^[a-z0-9_]{1,50}$/', (string)($cat['icon'] ?? '')) ? $cat['icon'] : 'menu_book';
    return $c;
}

/** ชื่อเรียกแบบสั้นสำหรับปุ่ม/ตัวเลข: "ฉบับแก้ไข / กฎหมายลำดับรอง" → "ฉบับแก้ไข" · "ฉบับแก้ไขเพิ่มเติม" → "ฉบับแก้ไข" */
function docbook_short(string $name): string {
    $s = trim(explode('/', $name)[0]);
    $t = preg_replace('/เพิ่มเติม$/u', '', $s) ?? $s;
    return $t !== '' ? $t : $s;
}

/** ชนิดหัวข้อ: [ชื่อเรียก, คำนำหน้าเลข] ตามรูปแบบเล่มของหมวด */
function docbook_kinds(array $conf): array {
    return [
        'part'    => [$conf['l1'], $conf['p1']],
        'chapter' => [$conf['l2'], $conf['p2']],
        'amend'   => [$conf['l3'], $conf['p3']],
    ];
}

/** เลขไทย → เลขอารบิก (ใช้ตอนเทียบคำค้น/หาเลขถัดไป ไม่ได้แก้ข้อความที่แสดง) */
function docbook_digits(string $s): string {
    return strtr($s, ['๐' => '0', '๑' => '1', '๒' => '2', '๓' => '3', '๔' => '4',
                      '๕' => '5', '๖' => '6', '๗' => '7', '๘' => '8', '๙' => '9']);
}

/** หมวดเอกสาร (ต้องการคอลัมน์ layout/intro จาก v1.58.0) */
function docbook_category(int $id): ?array {
    if ($id <= 0) return null;
    $st = db()->prepare('SELECT * FROM doc_categories WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** หัวข้อทั้งหมดของหมวด (เรียงแล้ว) พร้อมข้อมูลเอกสารที่ผูกไว้ */
function docbook_rows(int $catId): array {
    try {
        $st = db()->prepare('SELECT t.*, d.title AS doc_title, d.ext AS doc_ext, d.ext_url AS doc_ext_url, d.file_size AS doc_size
                             FROM doc_toc t LEFT JOIN documents d ON d.id = t.document_id
                             WHERE t.category_id = ? ORDER BY t.sort_order ASC, t.id ASC');
        $st->execute([$catId]);
        return $st->fetchAll();
    } catch (Throwable $e) {
        return [];      /* ยังไม่ได้รัน migration ของ v1.58.0 */
    }
}

/**
 * ประกอบเป็นต้นไม้: [part + 'chapters' => [chapter + 'amends'], 'amends' => [...]]
 * หัวข้อที่พ่อหายไป (เช่น พ่อถูกลบ) จะไม่ถูกแสดง แทนที่จะลอยไปอยู่ผิดที่
 */
function docbook_tree(array $rows): array {
    $by = [];
    foreach ($rows as $r) { $r['chapters'] = []; $r['amends'] = []; $by[(int)$r['id']] = $r; }
    $kids = [];
    foreach ($by as $id => $r) {
        if ($r['parent_id'] !== null) $kids[(int)$r['parent_id']][] = $id;
    }
    $build = function (int $id) use (&$build, &$by, $kids): array {
        $n = $by[$id];
        foreach ($kids[$id] ?? [] as $cid) {
            $c = $build($cid);
            if ($c['kind'] === 'amend') $n['amends'][] = $c; else $n['chapters'][] = $c;
        }
        return $n;
    };
    $tree = [];
    foreach ($by as $id => $r) {
        if ($r['parent_id'] === null && $r['kind'] === 'part') $tree[] = $build($id);
    }
    return $tree;
}

/** สถิติของเล่ม: จำนวนลักษณะ/บท/ฉบับแก้ไข และที่ยกเลิกแล้ว */
function docbook_stats(array $tree): array {
    /* repealed/active = นับเฉพาะชั้นบนสุด · rep_any = ทุกชั้นที่ถูกยกเลิก (ใช้ตัดสินว่าจะโชว์ตัวกรองสถานะไหม) */
    $s = ['part' => 0, 'chapter' => 0, 'amend' => 0, 'repealed' => 0, 'active' => 0, 'linked' => 0, 'rep_any' => 0];
    $walk = function (array $n) use (&$walk, &$s) {
        $s[$n['kind']]++;
        if ($n['kind'] === 'part') $s[$n['status'] === 'repealed' ? 'repealed' : 'active']++;
        if ($n['status'] === 'repealed' && $n['kind'] !== 'amend') $s['rep_any']++;
        if (docbook_href($n) !== '') $s['linked']++;
        foreach ($n['chapters'] as $c) $walk($c);
        foreach ($n['amends'] as $c) $walk($c);
    };
    foreach ($tree as $p) $walk($p);
    return $s;
}

/** ลิงก์ของหัวข้อ — เอกสารในเว็บไปผ่าน download.php (นับยอดเปิด) ไม่งั้นใช้ลิงก์ภายนอก */
function docbook_href(array $n): string {
    if (!empty($n['document_id']) && isset($n['doc_title'])) return url('download.php?id=' . (int)$n['document_id']);
    return ext_link_clean((string)($n['url'] ?? ''));
}

/**
 * ป้ายถัดไป: "ลักษณะที่ 4" → "ลักษณะที่ 5" · "ปีงบประมาณ 2568" → "ปีงบประมาณ 2569"
 * ไม่มีตัวก่อนหน้า → คำนำหน้าของชั้น + 1 · ชั้นที่ไม่มีคำนำหน้า (เช่น ชื่อ พ.ร.บ.) → ว่าง ให้พิมพ์ชื่อเอง
 */
function docbook_next_label(?string $prev, string $kind, array $conf): string {
    $prefix = docbook_kinds($conf)[$kind][1] ?? '';
    if ($prev !== null && preg_match('/^(.*?)([0-9๐-๙]+)\s*$/u', trim($prev), $m)) {
        return $m[1] . ((int)docbook_digits($m[2]) + 1);
    }
    if ($prefix === '') return '';
    /* ฉบับแก้ไขเริ่มที่ฉบับที่ 2 (ฉบับที่ 1 คือตัวเล่มเอง) — ชั้นอื่นเริ่มที่ 1 */
    return $prefix . ' ' . ($kind === 'amend' ? 2 : 1);
}

/** ส่วนเลขท้ายป้าย ("ลักษณะที่ 4" → ["ลักษณะ", "4"]) ใช้วาดป้ายเลขบนการ์ด · ไม่มีเลข → [ป้าย, ''] */
function docbook_label_parts(string $label): array {
    if (preg_match('/^(.*?)\s*([0-9๐-๙]+)\s*$/u', trim($label), $m)) {
        return [trim(preg_replace('/\s*ที่$/u', '', $m[1]) ?? ''), $m[2]];
    }
    return [trim($label), ''];
}

/* ─────────────────────────────────────────────
   นำเข้า/ส่งออก JSON — ทางใส่ข้อมูลทีละมากๆ (ให้เครื่องมือ/AI สร้างไฟล์มา แล้วผู้ดูแลกดนำเข้า)
   รูปแบบไฟล์ดู docs/doc-book-format.md
   ───────────────────────────────────────────── */

const DOCBOOK_FORMAT = 'alicecms-docbook';
const DOCBOOK_MAX_ITEMS = 5000;

/** ต้นไม้ → โครงสร้าง JSON (ใช้ทั้งปุ่มส่งออกและสำรองก่อนนำเข้าทับ) */
function docbook_export(array $cat, array $tree): array {
    $conv = function (array $n) use (&$conv): array {
        $o = ['kind' => $n['kind'], 'label' => $n['label'], 'title' => $n['title']];
        if ($n['status'] === 'repealed') $o['status'] = 'repealed';
        if ($n['note'] !== '') $o['note'] = $n['note'];
        if (!empty($n['document_id'])) $o['document_id'] = (int)$n['document_id'];
        if ($n['url'] !== '') $o['url'] = $n['url'];
        $kids = array_merge($n['amends'], $n['chapters']);
        if ($kids) $o['children'] = array_map($conv, $kids);
        return $o;
    };
    /* structure = ชื่อเรียกชั้นของหมวดนี้ ไว้ให้คน/เครื่องมือที่อ่านไฟล์รู้ว่า part/chapter/amend หมายถึงอะไร
       (ตอนนำเข้าไม่ได้ใช้ — รูปแบบเล่มตั้งที่หน้าจัดการสารบัญ) */
    $c = docbook_conf($cat);
    $structure = ['preset' => $c['preset'], 'part' => $c['l1'], 'chapter' => $c['l2'], 'amend' => $c['l3'], 'uses_status' => (bool)$c['status']];
    return ['format' => DOCBOOK_FORMAT, 'version' => 1, 'category' => $cat['name'], 'structure' => $structure,
            'exported_at' => date('c'), 'items' => array_map($conv, $tree)];
}

/**
 * ตรวจไฟล์ JSON ก่อนนำเข้า — คืน [รายการแบนพร้อมลำดับชั้น, ข้อผิดพลาด, คำเตือน]
 * ตรวจครบทั้งไฟล์ก่อนเขียนอะไรลงฐานข้อมูล ถ้ามีข้อผิดพลาดแม้แต่จุดเดียวจะไม่นำเข้าเลย
 * (กันสารบัญครึ่งๆ กลางๆ บนเว็บจริง)
 */
function docbook_validate(string $json, int $catId, array $conf): array {
    $errors = []; $warn = []; $flat = [];
    $data = json_decode($json, true);
    if (!is_array($data)) return [[], ['ไฟล์ไม่ใช่ JSON ที่ถูกต้อง (' . json_last_error_msg() . ')'], []];
    /* รับได้ทั้ง {"items":[...]} และ array ของหัวข้อตรงๆ (array_is_list มีตั้งแต่ PHP 8.1 — ระบบรองรับ 8.0) */
    $items = $data['items'] ?? (array_keys($data) === range(0, count($data) - 1) ? $data : null);
    if (!is_array($items) || !$items) return [[], ['ไม่พบรายการใน "items"'], []];
    if (isset($data['format']) && $data['format'] !== DOCBOOK_FORMAT) $warn[] = 'format ไม่ใช่ "' . DOCBOOK_FORMAT . '" — ลองนำเข้าตามโครงสร้างที่พบ';

    /* เอกสารที่อ้างถึงด้วย document_id ต้องอยู่ในหมวดเดียวกัน (กันผูกไปเอกสารหมวดอื่นโดยไม่ตั้งใจ) */
    $st = db()->prepare('SELECT id FROM documents WHERE category_id = ?');
    $st->execute([$catId]);
    $docIds = array_flip(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)));

    $allowedUnder = ['' => ['part'], 'part' => ['chapter', 'amend'], 'chapter' => ['amend'], 'amend' => []];
    $kindTh = ['part' => $conf['l1'] . ' (part)', 'chapter' => $conf['l2'] . ' (chapter)', 'amend' => $conf['l3'] . ' (amend)'];
    $walk = function (array $list, string $parentKind, int $parentIdx, string $path) use (&$walk, &$flat, &$errors, &$warn, $allowedUnder, $kindTh, $docIds) {
        foreach (array_values($list) as $i => $it) {
            $where = $path . '[' . ($i + 1) . ']';
            if (count($flat) >= DOCBOOK_MAX_ITEMS) { $errors[] = 'รายการเกิน ' . DOCBOOK_MAX_ITEMS . ' หัวข้อ'; return; }
            if (!is_array($it)) { $errors[] = "$where: ต้องเป็น object"; continue; }
            $kind = (string)($it['kind'] ?? '');
            if (!isset($kindTh[$kind])) { $errors[] = "$where: kind ต้องเป็น part / chapter / amend (พบ \"$kind\")"; continue; }
            if (!in_array($kind, $allowedUnder[$parentKind], true)) {
                $errors[] = "$where: {$kindTh[$kind]} อยู่" . ($parentKind === '' ? 'ระดับบนสุด' : 'ใต้' . $kindTh[$parentKind]) . 'ไม่ได้';
                continue;
            }
            $label = trim((string)($it['label'] ?? ''));
            $title = trim((string)($it['title'] ?? ''));
            if ($label === '' && $title === '') { $errors[] = "$where: ต้องมี label หรือ title อย่างน้อยหนึ่งอย่าง"; continue; }
            if (mb_strlen($label) > 80)  $errors[] = "$where: label ยาวเกิน 80 ตัวอักษร";
            if (mb_strlen($title) > 500) $errors[] = "$where: title ยาวเกิน 500 ตัวอักษร";
            $status = (string)($it['status'] ?? 'active');
            if (!in_array($status, ['active', 'repealed'], true)) { $errors[] = "$where: status ต้องเป็น active หรือ repealed"; $status = 'active'; }
            $note = trim((string)($it['note'] ?? ''));
            if (mb_strlen($note) > 500) $errors[] = "$where: note ยาวเกิน 500 ตัวอักษร";
            $url = trim((string)($it['url'] ?? ''));
            if ($url !== '' && ext_link_clean($url) === '') { $errors[] = "$where: url ต้องขึ้นต้นด้วย http:// หรือ https:// (และยาวไม่เกิน 500)"; $url = ''; }
            $docId = isset($it['document_id']) && $it['document_id'] !== null ? (int)$it['document_id'] : null;
            if ($docId !== null && !isset($docIds[$docId])) {
                $warn[] = "$where ($label): ไม่พบเอกสาร #$docId ในหมวดนี้ — นำเข้าโดยไม่ผูกเอกสาร";
                $docId = null;
            }
            $idx = count($flat);
            $flat[] = ['parent' => $parentIdx, 'kind' => $kind, 'label' => $label, 'title' => $title,
                       'status' => $status, 'note' => $note, 'url' => $url, 'document_id' => $docId];
            if (!empty($it['children'])) {
                if (!is_array($it['children'])) { $errors[] = "$where: children ต้องเป็น array"; continue; }
                $walk($it['children'], $kind, $idx, $where . '.children');
            }
        }
    };
    $walk($items, '', -1, 'items');
    return [$flat, $errors, $warn];
}

/** เขียนรายการที่ตรวจแล้วลงฐานข้อมูล (ในทรานแซกชันเดียว) — $replace=true ลบของเดิมของหมวดก่อน */
function docbook_import(int $catId, array $flat, bool $replace): int {
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $base = 0;
        if ($replace) {
            $pdo->prepare('DELETE FROM doc_toc WHERE category_id = ?')->execute([$catId]);
        } else {
            $st = $pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) FROM doc_toc WHERE category_id = ?');
            $st->execute([$catId]);
            $base = (int)$st->fetchColumn();
        }
        $ins = $pdo->prepare('INSERT INTO doc_toc (category_id, parent_id, kind, label, title, status, note, document_id, url, sort_order)
                              VALUES (?,?,?,?,?,?,?,?,?,?)');
        $ids = [];
        foreach ($flat as $i => $r) {
            $ins->execute([$catId, $r['parent'] >= 0 ? $ids[$r['parent']] : null, $r['kind'], $r['label'], $r['title'],
                           $r['status'], $r['note'], $r['document_id'], $r['url'], $base + ($i + 1) * 10]);
            $ids[$i] = (int)$pdo->lastInsertId();
        }
        $pdo->commit();
        return count($flat);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/** โฟลเดอร์สำรองสารบัญก่อนนำเข้าทับ (อยู่ใน storage/ ซึ่งเว็บเข้าถึงตรงไม่ได้) */
function docbook_backup_dir(): string {
    $d = APP_ROOT . '/storage/docbook-backups';
    if (!is_dir($d)) @mkdir($d, 0775, true);
    return $d;
}

/** สำรองสารบัญปัจจุบันของหมวดเป็นไฟล์ JSON — คืนชื่อไฟล์ หรือ '' ถ้าไม่มีอะไรให้สำรอง */
function docbook_backup(array $cat): string {
    $tree = docbook_tree(docbook_rows((int)$cat['id']));
    if (!$tree) return '';
    $name = 'cat' . (int)$cat['id'] . '-' . date('Ymd-His') . '.json';
    $ok = @file_put_contents(docbook_backup_dir() . '/' . $name,
        json_encode(docbook_export($cat, $tree), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    return $ok ? $name : '';
}

/** ไฟล์สำรองของหมวด (ใหม่สุดก่อน) */
function docbook_backups(int $catId): array {
    $files = glob(docbook_backup_dir() . '/cat' . $catId . '-*.json') ?: [];
    rsort($files);
    return array_map('basename', $files);
}

/** ข้อความสำหรับค้นหาในหน้าเว็บ (เลขไทย→อารบิก ตัวเล็ก) */
function docbook_search_text(array $n): string {
    return mb_strtolower(docbook_digits(trim($n['label'] . ' ' . $n['title'] . ' ' . $n['note'])));
}

/** ปุ่มเปิดเอกสารของหัวข้อ */
function docbook_link_btn(array $n, string $cls = ''): string {
    $href = docbook_href($n);
    if ($href === '') return '';
    $ext = !empty($n['doc_title']) ? strtolower((string)$n['doc_ext']) : '';
    $icon = !empty($n['doc_title']) && empty($n['doc_ext_url']) && $ext === 'pdf' ? 'picture_as_pdf' : 'open_in_new';
    $tip = 'เปิดเอกสาร ' . trim($n['label'] . ' ' . $n['title']);
    return '<a class="bk-open' . ($cls ? ' ' . $cls : '') . '" href="' . e($href) . '" target="_blank" rel="noopener" title="' . e($tip) . '" aria-label="' . e($tip) . '">'
         . '<span class="material-symbols-rounded icon-sm" aria-hidden="true">' . $icon . '</span><span class="bk-open-t">เปิดเอกสาร</span></a>';
}

/** หมายเหตุของหัวข้อ (ที่ไม่ใช่เหตุยกเลิก) — วารสารใช้เป็นชื่อผู้เขียน กฎหมายใช้เป็นวันประกาศ ฯลฯ */
function docbook_note(array $n, string $cls = 'bk-note'): string {
    return $n['note'] !== '' ? '<span class="' . $cls . '">' . e($n['note']) . '</span>' : '';
}

/** แถวรายการประกอบ (ฉบับแก้ไข / บทความ / เอกสารแนบ) ใช้ทั้งใต้ชั้น 1 และชั้น 2 */
function docbook_amend_rows(array $amends, array $conf): string {
    if (!$amends) return '';
    $h = '<ul class="bk-amends">';
    foreach ($amends as $a) {
        $h .= '<li class="bk-amend" id="t' . (int)$a['id'] . '" data-s="' . e(docbook_search_text($a)) . '">'
            . '<span class="material-symbols-rounded icon-sm bk-amend-ic" aria-hidden="true">' . e($conf['icon3']) . '</span>'
            . '<span class="bk-amend-t">' . ($a['label'] !== '' ? '<b>' . e($a['label']) . '</b> ' : '') . e($a['title'])
            . docbook_note($a) . '</span>'
            . docbook_link_btn($a, 'sm') . '</li>';
    }
    return $h . '</ul>';
}

/**
 * วาดสารบัญทั้งเล่ม — ใช้ทั้งหน้าเว็บจริงและหน้าตัวอย่างใน admin
 * ใช้ <details> ทุกชั้น จึงกางอ่านได้แม้ปิด JavaScript (JS เพิ่มแค่ค้นหา/ตัวกรอง/สารบัญด้านข้าง)
 * ชื่อเรียกชั้นและคำสถานะมาจากรูปแบบเล่มของหมวด ($conf) — ไม่มีคำของเอกสารประเภทใดฝังตายตัวในนี้
 */
function docbook_render(array $tree, array $conf): string {
    $useStatus = !empty($conf['status']);
    $h = '';
    foreach ($tree as $p) {
        $pid = (int)$p['id'];
        $rep = $useStatus && $p['status'] === 'repealed';
        $nCh = count($p['chapters']);
        $nAm = count($p['amends']);
        foreach ($p['chapters'] as $c) $nAm += count($c['amends']);
        [$word, $num] = docbook_label_parts($p['label']);
        $meta = [];
        if ($nCh) $meta[] = $nCh . ' ' . $conf['l2'];
        if ($nAm) $meta[] = $conf['l3'] . ' ' . $nAm . ' รายการ';
        /* ป้ายบนการ์ด: มีเลข → คำ + เลข (เลขยาวอย่าง 2569 ใช้ตัวเล็กลง) · ไม่มีเลข → ไอคอนของหมวด */
        $tile = $num !== ''
            ? '<span class="bk-no' . (mb_strlen($num) > 2 ? ' long' : '') . '" aria-hidden="true">' . ($word !== '' ? '<small>' . e($word) . '</small>' : '') . '<b>' . e($num) . '</b></span>'
            : '<span class="bk-no ic" aria-hidden="true"><span class="material-symbols-rounded">' . e($conf['cat_icon']) . '</span></span>';

        $h .= '<details class="bk-part' . ($rep ? ' is-repealed' : '') . '" id="t' . $pid . '" data-status="' . ($rep ? 'repealed' : 'active') . '"'
            . ' data-s="' . e(docbook_search_text($p)) . '" data-label="' . e($p['label']) . '" data-title="' . e($p['title']) . '">'
            . '<summary class="bk-sum">' . $tile
            . '<span class="bk-head">' . ($p['label'] !== '' && $p['title'] !== '' ? '<span class="bk-label">' . e($p['label']) . '</span>' : '')
            . '<span class="bk-title">' . e($p['title'] !== '' ? $p['title'] : $p['label']) . '</span>'
            . '<span class="bk-meta">'
            . ($rep ? '<span class="bk-pill rep"><span class="material-symbols-rounded icon-sm" aria-hidden="true">block</span>' . e($conf['s_off']) . '</span>' : '')
            . ($meta ? '<span>' . e(implode(' · ', $meta)) . '</span>' : '')
            . '</span></span>'
            . docbook_link_btn($p)
            . '<span class="material-symbols-rounded bk-chev" aria-hidden="true">expand_more</span>'
            . '</summary><div class="bk-body">';

        if ($p['note'] !== '') {
            $h .= $rep
                ? '<p class="bk-repnote"><span class="material-symbols-rounded icon-sm" aria-hidden="true">info</span>' . e($p['note']) . '</p>'
                : '<p class="bk-partnote">' . e($p['note']) . '</p>';
        }
        if ($p['amends']) {
            $h .= '<div class="bk-sub"><div class="bk-subhead">' . e($conf['l3']) . '</div>' . docbook_amend_rows($p['amends'], $conf) . '</div>';
        }
        if ($p['chapters']) {
            $h .= '<div class="bk-sub"><div class="bk-subhead">' . e($conf['l2']) . 'ทั้งหมด (' . $nCh . ')</div><ol class="bk-chaps">';
            foreach ($p['chapters'] as $c) {
                $crep = $useStatus && $c['status'] === 'repealed';
                $h .= '<li class="bk-chap' . ($crep ? ' is-repealed' : '') . '" id="t' . (int)$c['id'] . '" data-status="' . ($crep ? 'repealed' : 'active') . '" data-s="' . e(docbook_search_text($c)) . '">'
                    . '<div class="bk-chap-row' . ($c['label'] === '' ? ' nolabel' : '') . '">' . ($c['label'] !== '' ? '<span class="bk-chap-no">' . e($c['label']) . '</span>' : '')
                    . '<span class="bk-chap-t">' . e($c['title'])
                    . ($crep ? ' <span class="bk-pill rep sm">' . e($conf['s_off']) . '</span>' : '')
                    . docbook_note($c, 'bk-chap-note')
                    . '</span>' . docbook_link_btn($c, 'sm') . '</div>'
                    . docbook_amend_rows($c['amends'], $conf) . '</li>';
            }
            $h .= '</ol></div>';
        }
        if (!$p['amends'] && !$p['chapters'] && $p['note'] === '') {
            $h .= '<p class="bk-empty">' . e($conf['l1']) . 'นี้ไม่แบ่งเป็น' . e($conf['l2']) . (docbook_href($p) !== '' ? ' — กด "เปิดเอกสาร" เพื่ออ่านฉบับเต็ม' : '') . '</p>';
        }
        $h .= '</div></details>';
    }
    return $h;
}
