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
 * ชื่อเรียกแต่ละชั้นเป็นข้อความอิสระ (label) หมวดอื่นจะใช้ "หมวด/ส่วน" แทนก็ได้
 */
if (!defined('APP_ROOT')) exit('Forbidden');

/** ชนิดหัวข้อ: [ชื่อเรียก, คำนำหน้าเลข (ใช้เติมป้ายอัตโนมัติ)] */
function docbook_kinds(): array {
    return [
        'part'    => ['ลักษณะ', 'ลักษณะที่'],
        'chapter' => ['บท', 'บทที่'],
        'amend'   => ['ฉบับแก้ไขเพิ่มเติม', 'ฉบับที่'],
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
    $s = ['part' => 0, 'chapter' => 0, 'amend' => 0, 'repealed' => 0, 'active' => 0, 'linked' => 0];
    $walk = function (array $n) use (&$walk, &$s) {
        $s[$n['kind']]++;
        if ($n['kind'] === 'part') $s[$n['status'] === 'repealed' ? 'repealed' : 'active']++;
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

/** ป้ายถัดไป: "ลักษณะที่ 4" → "ลักษณะที่ 5" — ถ้าไม่มีตัวก่อนหน้า ใช้คำนำหน้าตามชนิด */
function docbook_next_label(?string $prev, string $kind): string {
    $kinds = docbook_kinds();
    $prefix = $kinds[$kind][1] ?? '';
    if ($prev !== null && preg_match('/^(.*?)([0-9๐-๙]+)\s*$/u', trim($prev), $m)) {
        return $m[1] . ((int)docbook_digits($m[2]) + 1);
    }
    return $prefix . ' ' . ($kind === 'amend' ? 2 : 1);
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
    return ['format' => DOCBOOK_FORMAT, 'version' => 1, 'category' => $cat['name'],
            'exported_at' => date('c'), 'items' => array_map($conv, $tree)];
}

/**
 * ตรวจไฟล์ JSON ก่อนนำเข้า — คืน [รายการแบนพร้อมลำดับชั้น, ข้อผิดพลาด, คำเตือน]
 * ตรวจครบทั้งไฟล์ก่อนเขียนอะไรลงฐานข้อมูล ถ้ามีข้อผิดพลาดแม้แต่จุดเดียวจะไม่นำเข้าเลย
 * (กันสารบัญครึ่งๆ กลางๆ บนเว็บจริง)
 */
function docbook_validate(string $json, int $catId): array {
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
    $kindTh = ['part' => 'ลักษณะ', 'chapter' => 'บท', 'amend' => 'ฉบับแก้ไข'];
    $walk = function (array $list, string $parentKind, int $parentIdx, string $path) use (&$walk, &$flat, &$errors, &$warn, $allowedUnder, $kindTh, $docIds) {
        foreach (array_values($list) as $i => $it) {
            $where = $path . '[' . ($i + 1) . ']';
            if (count($flat) >= DOCBOOK_MAX_ITEMS) { $errors[] = 'รายการเกิน ' . DOCBOOK_MAX_ITEMS . ' หัวข้อ'; return; }
            if (!is_array($it)) { $errors[] = "$where: ต้องเป็น object"; continue; }
            $kind = (string)($it['kind'] ?? '');
            if (!isset($kindTh[$kind])) { $errors[] = "$where: kind ต้องเป็น part / chapter / amend (พบ \"$kind\")"; continue; }
            if (!in_array($kind, $allowedUnder[$parentKind], true)) {
                $errors[] = "$where: {$kindTh[$kind]} อยู่ใต้" . ($parentKind === '' ? 'ระดับบนสุด' : $kindTh[$parentKind]) . 'ไม่ได้';
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

/** แถวฉบับแก้ไขเพิ่มเติม (ใช้ทั้งใต้ลักษณะและใต้บท) */
function docbook_amend_rows(array $amends): string {
    if (!$amends) return '';
    $h = '<ul class="bk-amends">';
    foreach ($amends as $a) {
        $h .= '<li class="bk-amend" id="t' . (int)$a['id'] . '" data-s="' . e(docbook_search_text($a)) . '">'
            . '<span class="material-symbols-rounded icon-sm bk-amend-ic" aria-hidden="true">edit_document</span>'
            . '<span class="bk-amend-t"><b>' . e($a['label']) . '</b> ' . e($a['title']) . '</span>'
            . docbook_link_btn($a, 'sm') . '</li>';
    }
    return $h . '</ul>';
}

/**
 * วาดสารบัญทั้งเล่ม — ใช้ทั้งหน้าเว็บจริงและหน้าตัวอย่างใน admin
 * ใช้ <details> ทุกชั้น จึงกางอ่านได้แม้ปิด JavaScript (JS เพิ่มแค่ค้นหา/ตัวกรอง/สารบัญด้านข้าง)
 */
function docbook_render(array $tree): string {
    $h = '';
    foreach ($tree as $p) {
        $pid = (int)$p['id'];
        $rep = $p['status'] === 'repealed';
        $nCh = count($p['chapters']);
        $nAm = count($p['amends']);
        foreach ($p['chapters'] as $c) $nAm += count($c['amends']);
        $num = preg_match('/([0-9๐-๙]+)\s*$/u', $p['label'], $m) ? $m[1] : '';
        $word = trim(preg_replace('/(ที่)?\s*[0-9๐-๙]+\s*$/u', '', $p['label']) ?? '');
        $meta = [];
        if ($nCh) $meta[] = $nCh . ' บท';
        if ($nAm) $meta[] = 'แก้ไขเพิ่มเติม ' . $nAm . ' ฉบับ';

        $h .= '<details class="bk-part' . ($rep ? ' is-repealed' : '') . '" id="t' . $pid . '" data-status="' . ($rep ? 'repealed' : 'active') . '"'
            . ' data-s="' . e(docbook_search_text($p)) . '" data-label="' . e($p['label']) . '" data-title="' . e($p['title']) . '">'
            . '<summary class="bk-sum">'
            . '<span class="bk-no" aria-hidden="true"><small>' . e($word !== '' ? $word : $p['label']) . '</small>' . ($num !== '' ? '<b>' . e($num) . '</b>' : '') . '</span>'
            . '<span class="bk-head"><span class="bk-label">' . e($p['label']) . '</span>'
            . '<span class="bk-title">' . e($p['title']) . '</span>'
            . '<span class="bk-meta">'
            . ($rep ? '<span class="bk-pill rep"><span class="material-symbols-rounded icon-sm" aria-hidden="true">block</span>ยกเลิกแล้ว</span>' : '')
            . ($meta ? '<span>' . e(implode(' · ', $meta)) . '</span>' : '')
            . '</span></span>'
            . docbook_link_btn($p)
            . '<span class="material-symbols-rounded bk-chev" aria-hidden="true">expand_more</span>'
            . '</summary><div class="bk-body">';

        if ($rep && $p['note'] !== '') {
            $h .= '<p class="bk-repnote"><span class="material-symbols-rounded icon-sm" aria-hidden="true">info</span>' . e($p['note']) . '</p>';
        }
        if ($p['amends']) {
            $h .= '<div class="bk-sub"><div class="bk-subhead">ฉบับแก้ไขเพิ่มเติม</div>' . docbook_amend_rows($p['amends']) . '</div>';
        }
        if ($p['chapters']) {
            $h .= '<div class="bk-sub"><div class="bk-subhead">สารบัญบท</div><ol class="bk-chaps">';
            foreach ($p['chapters'] as $c) {
                $crep = $c['status'] === 'repealed';
                $h .= '<li class="bk-chap' . ($crep ? ' is-repealed' : '') . '" id="t' . (int)$c['id'] . '" data-status="' . ($crep ? 'repealed' : 'active') . '" data-s="' . e(docbook_search_text($c)) . '">'
                    . '<div class="bk-chap-row"><span class="bk-chap-no">' . e($c['label']) . '</span>'
                    . '<span class="bk-chap-t">' . e($c['title'])
                    . ($crep ? ' <span class="bk-pill rep sm">ยกเลิก</span>' . ($c['note'] !== '' ? '<span class="bk-chap-note">' . e($c['note']) . '</span>' : '') : '')
                    . '</span>' . docbook_link_btn($c, 'sm') . '</div>'
                    . docbook_amend_rows($c['amends']) . '</li>';
            }
            $h .= '</ol></div>';
        }
        if (!$p['amends'] && !$p['chapters'] && !($rep && $p['note'] !== '')) {
            $h .= '<p class="bk-empty">ลักษณะนี้ไม่แบ่งเป็นบท' . (docbook_href($p) !== '' ? ' — กด "เปิดเอกสาร" เพื่ออ่านฉบับเต็ม' : '') . '</p>';
        }
        $h .= '</div></details>';
    }
    return $h;
}
