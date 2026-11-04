<?php
/**
 * admin/doc-book.php — จัดการสารบัญแบบหนังสือของหมวดเอกสาร
 *
 * โครงสร้าง 3 ชั้น (ชั้น 1 → ชั้น 2 → รายการประกอบ) ชื่อเรียกแต่ละชั้นตั้งได้ต่อหมวดที่ "รูปแบบเล่ม"
 * เช่น ประมวล: ลักษณะ → บท → ฉบับแก้ไข · วารสาร: ปี → ฉบับ → บทความ · กฎหมาย: ชื่อ พ.ร.บ. → หมวด → กฎหมายลำดับรอง
 *
 * ใส่ข้อมูลได้ 2 ทาง
 *   1) ทีละหัวข้อด้วยฟอร์มในหน้านี้
 *   2) นำเข้าไฟล์ JSON ทีละมากๆ (รูปแบบไฟล์ดู docs/doc-book-format.md) — ตรวจทั้งไฟล์ก่อน
 *      ถ้ามีจุดผิดแม้แต่จุดเดียวจะไม่นำเข้าเลย และก่อนนำเข้าแบบแทนที่จะสำรองของเดิมไว้ให้กู้คืนได้
 */
require dirname(__DIR__) . '/includes/init.php';
require __DIR__ . '/_auth.php';
require_once APP_ROOT . '/includes/doc-book.php';

$catId = (int)($_GET['cat'] ?? $_POST['cat'] ?? 0);
$cat = docbook_category($catId);
if (!$cat) { flash_set('danger', 'ไม่พบหมวดเอกสาร'); redirect('admin/documents.php'); }
$self = 'admin/doc-book.php?cat=' . $catId;
$conf  = docbook_conf($cat);
$kinds = docbook_kinds($conf);

/* ── ส่งออก / ดาวน์โหลดไฟล์สำรอง (ก่อนพิมพ์หน้า) ── */
if (isset($_GET['export'])) {
    $json = json_encode(docbook_export($cat, docbook_tree(docbook_rows($catId))), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="docbook-cat' . $catId . '-' . date('Ymd-His') . '.json"');
    header('Cache-Control: no-store');
    echo $json;
    exit;
}
if (isset($_GET['backup'])) {
    $name = basename((string)$_GET['backup']);
    $path = docbook_backup_dir() . '/' . $name;
    if (in_array($name, docbook_backups($catId), true) && is_file($path)) {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="docbook-backup-' . $name . '"');
        header('Cache-Control: no-store');
        readfile($path);
        exit;
    }
    flash_set('danger', 'ไม่พบไฟล์สำรอง');
    redirect($self);
}

$errors = [];
$importErrors = [];
$importWarn = [];

/** แถวเดียวของหมวดนี้ */
$getRow = function (int $id) use ($catId): ?array {
    $st = db()->prepare('SELECT * FROM doc_toc WHERE id = ? AND category_id = ?');
    $st->execute([$id, $catId]);
    return $st->fetch() ?: null;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = (string)($_POST['action'] ?? '');

    /* ── ตั้งค่าหมวด ── */
    if ($act === 'settings') {
        $layout = ($_POST['layout'] ?? 'book') === 'list' ? 'list' : 'book';
        $intro  = mb_substr(trim((string)($_POST['intro'] ?? '')), 0, 600);
        /* แม่แบบสำเร็จรูปเก็บแค่ชื่อแม่แบบ (คำเรียกตามแม่แบบล่าสุดเสมอ) · กำหนดเองเก็บทุกช่องที่กรอก */
        $preset = (string)($_POST['preset'] ?? '');
        if (!isset(docbook_presets()[$preset])) $preset = $conf['preset'];
        $bk = is_array($_POST['bk'] ?? null) ? $_POST['bk'] : [];
        $newConf = $preset === 'custom' ? docbook_conf_clean($bk, 'custom') : ['preset' => $preset];
        db()->prepare('UPDATE doc_categories SET layout = ?, intro = ?, book_conf = ? WHERE id = ?')
            ->execute([$layout, $intro, json_encode($newConf, JSON_UNESCAPED_UNICODE), $catId]);
        log_action('ตั้งค่าสารบัญเอกสาร', $cat['name']);
        flash_set('success', $layout === 'book' ? 'บันทึกแล้ว — หน้าเว็บแสดงหมวดนี้เป็นสารบัญแบบหนังสือ' : 'บันทึกแล้ว — หน้าเว็บกลับไปแสดงหมวดนี้เป็นรายการไฟล์');
        redirect($self);
    }

    /* ── ภาพปกเล่ม (แทนปกสีที่ระบบวาดให้บนหน้าเว็บ) ──
       แสดงเป็นกรอบแนวตั้ง 3:4 แบบปกหนังสือ ภาพสัดส่วนอื่นจะถูกครอปตรงกลาง จึงเตือนให้รู้ตั้งแต่ตอนอัปโหลด */
    if ($act === 'cover') {
        if (!empty($_POST['remove_cover'])) {
            docbook_cover_remove($catId, $cat['cover'] ?? null);
            log_action('ลบภาพปกเล่ม', $cat['name']);
            flash_set('success', 'ลบภาพปกแล้ว — หน้าเว็บกลับไปใช้ปกสีที่ระบบวาดให้');
            redirect($self);
        }
        try {
            $tips = docbook_cover_save($catId, $cat['cover'] ?? null, 'cover');
        } catch (RuntimeException $ex) {
            flash_set('danger', $ex->getMessage());
            redirect($self);
        }
        if ($tips === null) { flash_set('danger', 'เลือกไฟล์ภาพก่อน (JPG / PNG / WebP)'); redirect($self); }
        log_action('เปลี่ยนภาพปกเล่ม', $cat['name']);
        flash_set('success', 'บันทึกภาพปกแล้ว' . ($tips ? ' · ' . implode(' · ', $tips) : ''));
        redirect($self);
    }

    /* ── ลบ (ลงถังขยะ พร้อมหัวข้อย่อยทั้งหมด) ── */
    if ($act === 'delete') {
        $row = $getRow((int)($_POST['id'] ?? 0));
        if ($row && trash_delete('doc_toc', (int)$row['id'], $ADMIN['username'] ?? '')) {
            log_action('ลบหัวข้อสารบัญ (ลงถังขยะ)', trim($row['label'] . ' ' . $row['title']));
            flash_set('success', 'ย้าย "' . trim($row['label'] . ' ' . $row['title']) . '" และหัวข้อย่อยลงถังขยะแล้ว — กู้คืนได้ที่เมนู "ถังขยะ"');
        }
        redirect($self . '#t' . (int)($row['parent_id'] ?? 0));
    }

    /* ── เลื่อนขึ้น/ลง (สลับลำดับกับหัวข้อข้างเคียงที่อยู่ใต้พ่อเดียวกันและชนิดเดียวกัน) ── */
    if ($act === 'move') {
        $row = $getRow((int)($_POST['id'] ?? 0));
        $up  = ($_POST['dir'] ?? '') === 'up';
        if ($row) {
            $st = db()->prepare('SELECT id, sort_order FROM doc_toc WHERE category_id = ? AND parent_id <=> ? AND kind = ?
                                 ORDER BY sort_order ' . ($up ? 'DESC' : 'ASC') . ', id ' . ($up ? 'DESC' : 'ASC'));
            $st->execute([$catId, $row['parent_id'], $row['kind']]);
            $sib = $st->fetchAll();
            $pos = array_search((int)$row['id'], array_map('intval', array_column($sib, 'id')), true);
            if ($pos !== false && isset($sib[$pos + 1])) {
                $other = $sib[$pos + 1];
                /* ลำดับเท่ากันสลับแล้วไม่ขยับ — ถ้าเท่ากันให้แยกด้วย ±1 */
                $a = (int)$row['sort_order']; $b = (int)$other['sort_order'];
                if ($a === $b) $b = $up ? $a - 1 : $a + 1;
                $upd = db()->prepare('UPDATE doc_toc SET sort_order = ? WHERE id = ?');
                $upd->execute([$b, (int)$row['id']]);
                $upd->execute([$a, (int)$other['id']]);
            }
        }
        redirect($self . '#t' . (int)($row['id'] ?? 0));
    }

    /* ── นำเข้า JSON / กู้คืนจากไฟล์สำรอง ── */
    if ($act === 'import' || $act === 'restore') {
        $json = '';
        $replace = true;
        if ($act === 'restore') {
            $name = basename((string)($_POST['backup'] ?? ''));
            if (in_array($name, docbook_backups($catId), true)) $json = (string)@file_get_contents(docbook_backup_dir() . '/' . $name);
            if ($json === '') $importErrors[] = 'ไม่พบไฟล์สำรอง';
        } else {
            $replace = ($_POST['mode'] ?? 'replace') !== 'append';
            $f = $_FILES['json_file'] ?? null;
            if ($f && ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                if ((int)$f['size'] > 5 * 1048576) $importErrors[] = 'ไฟล์ใหญ่เกิน 5 MB';
                else $json = (string)file_get_contents($f['tmp_name']);
            } else {
                $json = trim((string)($_POST['json_text'] ?? ''));
            }
            if ($json === '' && !$importErrors) $importErrors[] = 'เลือกไฟล์ .json หรือวางข้อความ JSON ก่อน';
        }
        if (!$importErrors) {
            $json = preg_replace('/^\xEF\xBB\xBF/', '', $json) ?? '';      /* BOM จาก Notepad */
            [$flat, $importErrors, $importWarn] = docbook_validate($json, $catId, $conf);
        }
        if (!$importErrors) {
            $bak = $replace ? docbook_backup($cat) : '';
            try {
                $n = docbook_import($catId, $flat, $replace);
                log_action($act === 'restore' ? 'กู้คืนสารบัญเอกสาร' : 'นำเข้าสารบัญเอกสาร', $cat['name'] . " ($n หัวข้อ" . ($replace ? ', แทนที่' : ', ต่อท้าย') . ')');
                $msg = ($act === 'restore' ? 'กู้คืน' : 'นำเข้า') . "สำเร็จ $n หัวข้อ" . ($bak !== '' ? ' — สารบัญเดิมสำรองไว้แล้ว กู้คืนได้จากรายการ "ไฟล์สำรอง"' : '');
                if ($importWarn) $msg .= ' · คำเตือน ' . count($importWarn) . ' จุด: ' . implode(' / ', array_slice($importWarn, 0, 3)) . (count($importWarn) > 3 ? ' …' : '');
                flash_set($importWarn ? 'warning' : 'success', $msg);
                redirect($self);
            } catch (Throwable $e) {
                $importErrors[] = 'บันทึกไม่สำเร็จ ไม่มีอะไรถูกเปลี่ยน: ' . $e->getMessage();
            }
        }
    }

    /* ── เพิ่ม/แก้ไขหัวข้อ ── */
    if ($act === 'save') {
        $id     = (int)($_POST['id'] ?? 0);
        $old    = $id > 0 ? $getRow($id) : null;
        if ($id > 0 && !$old) { flash_set('danger', 'ไม่พบหัวข้อ'); redirect($self); }
        $kind   = isset($kinds[$_POST['kind'] ?? '']) ? $_POST['kind'] : 'chapter';
        $parent = (int)($_POST['parent_id'] ?? 0) ?: null;
        $label  = mb_substr(trim((string)($_POST['label'] ?? '')), 0, 80);
        $title  = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 500);
        $status = ($_POST['status'] ?? 'active') === 'repealed' ? 'repealed' : 'active';
        $note   = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 500);
        $docId  = (int)($_POST['document_id'] ?? 0) ?: null;
        $urlIn  = trim((string)($_POST['url'] ?? ''));
        $link   = ext_link_clean($urlIn);

        if ($label === '' && $title === '') $errors[] = 'กรอกป้ายเลขหรือชื่อเรื่องอย่างน้อยหนึ่งช่อง';
        /* หมวดที่ไม่ใช้สถานะ: ฟอร์มไม่มีช่องสถานะ → คงค่าเดิมไว้ ไม่เผลอล้าง */
        if (empty($conf['status'])) $status = $old['status'] ?? 'active';
        if ($urlIn !== '' && $link === '') $errors[] = 'ลิงก์ต้องขึ้นต้นด้วย https:// — คัดลอกจากช่องที่อยู่ของเบราว์เซอร์มาวางให้ครบ';

        /* โครงสร้างที่อนุญาต: ชั้น 1 อยู่บนสุด · ชั้น 2 อยู่ใต้ชั้น 1 · รายการประกอบอยู่ใต้ชั้น 1 หรือชั้น 2 */
        if ($kind === 'part') {
            $parent = null;
        } else {
            $p = $parent ? $getRow($parent) : null;
            $okParent = $p && ($kind === 'chapter' ? $p['kind'] === 'part' : in_array($p['kind'], ['part', 'chapter'], true));
            if (!$okParent) $errors[] = $kind === 'chapter'
                ? 'เลือก' . $conf['l1'] . 'ที่' . $conf['l2'] . 'นี้อยู่'
                : 'เลือก' . $conf['l1'] . 'หรือ' . $conf['l2'] . 'ที่' . $conf['l3'] . 'นี้อยู่ใต้';
        }
        if ($old && $old['kind'] !== $kind) {
            $c = db()->prepare('SELECT COUNT(*) FROM doc_toc WHERE parent_id = ?');
            $c->execute([$id]);
            if ((int)$c->fetchColumn() > 0) $errors[] = 'หัวข้อนี้มีหัวข้อย่อยอยู่ จึงเปลี่ยนชนิดไม่ได้ — ย้ายหรือลบหัวข้อย่อยก่อน';
        }
        if ($docId !== null) {
            $c = db()->prepare('SELECT COUNT(*) FROM documents WHERE id = ? AND category_id = ?');
            $c->execute([$docId, $catId]);
            if ((int)$c->fetchColumn() === 0) $docId = null;
        }

        if (!$errors) {
            if ($old) {
                db()->prepare('UPDATE doc_toc SET kind=?, parent_id=?, label=?, title=?, status=?, note=?, document_id=?, url=? WHERE id=?')
                    ->execute([$kind, $parent, $label, $title, $status, $note, $docId, $link, $id]);
                log_action('แก้ไขหัวข้อสารบัญ', trim("$label $title"));
            } else {
                $mx = db()->prepare('SELECT COALESCE(MAX(sort_order), 0) FROM doc_toc WHERE category_id = ?');
                $mx->execute([$catId]);
                db()->prepare('INSERT INTO doc_toc (category_id, parent_id, kind, label, title, status, note, document_id, url, sort_order) VALUES (?,?,?,?,?,?,?,?,?,?)')
                    ->execute([$catId, $parent, $kind, $label, $title, $status, $note, $docId, $link, (int)$mx->fetchColumn() + 10]);
                $id = (int)db()->lastInsertId();
                log_action('เพิ่มหัวข้อสารบัญ', trim("$label $title"));
            }
            flash_set('success', 'บันทึก "' . trim("$label $title") . '" แล้ว');
            /* เพิ่มต่อเนื่อง: กลับมาฟอร์มเพิ่มชนิดเดิมใต้พ่อเดิม พร้อมป้ายเลขถัดไป */
            if (!empty($_POST['again'])) redirect($self . '&add=' . $kind . ($parent ? '&parent=' . $parent : '') . '#form');
            redirect($self . '#t' . $id);
        }
    }
}

/* ── ข้อมูลสำหรับแสดง ── */
$rows  = docbook_rows($catId);
$tree  = docbook_tree($rows);
$stats = docbook_stats($tree);
$byId  = [];
foreach ($rows as $r) $byId[(int)$r['id']] = $r;

$st = db()->prepare('SELECT id, title, ext, ext_url FROM documents WHERE category_id = ? ORDER BY sort_order ASC, id DESC');
$st->execute([$catId]);
$docs = $st->fetchAll();
$linkedDocs = [];
/* นับเฉพาะเอกสารที่ยังมีอยู่ (หัวข้อที่ชี้เอกสารซึ่งถูกลบไปแล้วไม่นับ) */
foreach ($rows as $r) if (!empty($r['document_id']) && isset($r['doc_title'])) $linkedDocs[(int)$r['document_id']] = true;

/* ฟอร์ม: แก้ไข (?edit=) หรือเพิ่ม (?add=kind&parent=) */
$edit = isset($_GET['edit']) ? ($byId[(int)$_GET['edit']] ?? null) : null;
$addKind = isset($kinds[$_GET['add'] ?? '']) ? $_GET['add'] : '';
$addParent = (int)($_GET['parent'] ?? 0) ?: null;
if (!$edit && $addKind === '') $addKind = $tree ? 'chapter' : 'part';
if (!$edit && $addKind !== 'part' && $addParent === null) {
    /* ค่าเริ่มต้น: ใต้หัวข้อชั้น 1 ตัวสุดท้าย */
    $lastPart = end($tree);
    $addParent = $lastPart ? (int)$lastPart['id'] : null;
    if (!$lastPart) $addKind = 'part';
}
$fKind = $edit['kind'] ?? $addKind;
$fParent = $edit ? ($edit['parent_id'] !== null ? (int)$edit['parent_id'] : null) : ($fKind === 'part' ? null : $addParent);
/* ป้ายถัดไปอัตโนมัติ = ต่อจากพี่น้องตัวสุดท้ายชนิดเดียวกัน */
$prevLabel = null;
foreach ($rows as $r) {
    if ($r['kind'] === $fKind && ($r['parent_id'] !== null ? (int)$r['parent_id'] : null) === $fParent) $prevLabel = $r['label'];
}
$f = [
    'kind'   => old('kind', $fKind),
    'parent' => (int)old('parent_id', (string)($fParent ?? 0)),
    'label'  => old('label', $edit['label'] ?? docbook_next_label($prevLabel, $fKind, $conf)),
    'title'  => old('title', $edit['title'] ?? ''),
    'status' => old('status', $edit['status'] ?? 'active'),
    'note'   => old('note', $edit['note'] ?? ''),
    'doc'    => (int)old('document_id', (string)($edit['document_id'] ?? 0)),
    'url'    => old('url', $edit['url'] ?? ''),
];
if (!isset($kinds[$f['kind']])) $f['kind'] = 'chapter';
$hasKids = false;
if ($edit) foreach ($rows as $r) if ((int)($r['parent_id'] ?? 0) === (int)$edit['id']) { $hasKids = true; break; }
$backups = docbook_backups($catId);

/** ปุ่มจัดการของแถวในต้นไม้ */
$rowActions = function (array $n) use ($self, $conf): string {
    $id = (int)$n['id'];
    $h = '<span class="dbk-acts">';
    foreach (['up' => 'arrow_upward', 'down' => 'arrow_downward'] as $dir => $ic) {
        $h .= '<form method="post" action="">' . csrf_field() . '<input type="hidden" name="action" value="move"><input type="hidden" name="id" value="' . $id . '">'
            . '<input type="hidden" name="dir" value="' . $dir . '"><button class="btn small ghost" type="submit" title="' . ($dir === 'up' ? 'เลื่อนขึ้น' : 'เลื่อนลง') . '"><span class="material-symbols-rounded icon-sm">' . $ic . '</span></button></form>';
    }
    $h .= '<a class="btn small" href="' . e(url($self . '&edit=' . $id . '#form')) . '">แก้ไข</a>';
    if ($n['kind'] === 'part') $h .= '<a class="btn small" href="' . e(url($self . '&add=chapter&parent=' . $id . '#form')) . '" title="' . e('เพิ่ม' . $conf['l2'] . 'ใน' . $conf['l1'] . 'นี้') . '">+ ' . e($conf['l2']) . '</a>';
    if ($n['kind'] !== 'amend') $h .= '<a class="btn small" href="' . e(url($self . '&add=amend&parent=' . $id . '#form')) . '" title="' . e('เพิ่ม' . $conf['l3']) . '">+ ' . e(docbook_short($conf['l3'])) . '</a>';
    $nKids = count($n['chapters']) + count($n['amends']);
    $h .= '<form method="post" action="">' . csrf_field() . '<input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="' . $id . '">'
        . '<button class="btn small danger" type="submit" data-confirm="' . e('ลบ «' . trim($n['label'] . ' ' . $n['title']) . '»' . ($nKids ? ' พร้อมหัวข้อย่อยทั้งหมด' : '') . '? (กู้คืนได้จากถังขยะ)') . '">ลบ</button></form>';
    return $h . '</span>';
};
/** ป้ายสถานะ/ลิงก์ของแถว */
$rowBadges = function (array $n) use ($conf): string {
    $h = '';
    if (!empty($conf['status']) && $n['status'] === 'repealed') $h .= '<span class="badge danger" style="font-size:11px;">' . e($conf['s_off']) . '</span>';
    if (!empty($n['document_id']) && isset($n['doc_title'])) $h .= '<span class="badge" style="font-size:11px;" title="' . e($n['doc_title']) . '"><span class="material-symbols-rounded icon-sm">description</span>เอกสาร</span>';
    elseif ($n['url'] !== '') $h .= '<span class="badge" style="font-size:11px;"><span class="material-symbols-rounded icon-sm">link</span>ลิงก์</span>';
    return $h;
};

$presets = docbook_presets();
/* ค่าของแม่แบบทั้งหมด ส่งให้ JS เติมช่องเมื่อเลือกแม่แบบ (กำหนดเอง = ค่าปัจจุบันของหมวด) */
$presetJs = [];
foreach (array_keys($presets) as $k) $presetJs[$k] = $k === 'custom' ? array_diff_key($conf, ['preset' => 1, 'cat_icon' => 1, 'guessed' => 1]) : docbook_conf_clean([], $k);
$useStatus = !empty($conf['status']);
$levelRows = [
    ['l1', 'p1', 'ชั้นที่ 1', 'หัวข้อหลักของเล่ม'],
    ['l2', 'p2', 'ชั้นที่ 2', 'หัวข้อย่อยใต้ชั้นที่ 1'],
    ['l3', 'p3', 'รายการประกอบ', 'แนบใต้ชั้นที่ 1 หรือ 2 เช่น ฉบับแก้ไข บทความ เอกสารแนบ'],
];
/** ตัวอย่างป้ายของแต่ละชั้น ("ลักษณะที่ 1" หรือ "«ชื่อกฎหมาย»" ถ้าไม่มีเลข) */
$sample = fn(string $l, string $p): string => $p !== '' ? $p . ' 1' : '«ชื่อ' . $l . '»';

$admin_title = 'สารบัญ: ' . $cat['name'];
require __DIR__ . '/_top.php';
?>
<div class="crumb mb-2" style="font-size:13px;"><a href="<?= e(url('admin/documents.php')) ?>">เอกสารเผยแพร่</a> › สารบัญแบบหนังสือ</div>

<?php if ($errors): ?>
<div class="alert danger"><span class="material-symbols-rounded">error</span>
  <div><?php foreach ($errors as $er): ?><div><?= e($er) ?></div><?php endforeach; ?></div>
</div>
<?php endif; ?>
<?php if ($importErrors): ?>
<div class="alert danger"><span class="material-symbols-rounded">error</span>
  <div><b>นำเข้าไม่สำเร็จ — ยังไม่มีอะไรถูกเปลี่ยน</b> (พบ <?= count($importErrors) ?> จุด)
    <?php foreach (array_slice($importErrors, 0, 15) as $er): ?><div style="font-size:13px;">• <?= e($er) ?></div><?php endforeach; ?>
    <?php if (count($importErrors) > 15): ?><div style="font-size:13px;">… และอีก <?= count($importErrors) - 15 ?> จุด</div><?php endif; ?>
  </div>
</div>
<?php endif; ?>

<?php if (($cat['layout'] ?? 'list') !== 'book'): ?>
<div class="alert warning"><span class="material-symbols-rounded">visibility_off</span>
  <div>หมวดนี้ยังแสดงบนหน้าเว็บเป็น <b>รายการไฟล์</b> — สารบัญที่จัดไว้จะยังไม่ปรากฏจนกว่าจะเลือก "แสดงเป็นสารบัญแบบหนังสือ" ในการ์ดรูปแบบเล่มด้านล่าง</div>
</div>
<?php endif; ?>

<!-- ภาพรวมเล่ม -->
<div class="card mb-2">
  <div class="section-head dbk-head">
    <div class="dbk-head-t">
      <span class="tag">TABLE OF CONTENTS</span>
      <h3><?= e($cat['name']) ?></h3>
      <p class="dbk-path"><span class="material-symbols-rounded icon-sm">account_tree</span>
        <?= e($presets[$conf['preset']][0]) ?> · <b><?= e($conf['l1']) ?></b> › <b><?= e($conf['l2']) ?></b> › <b><?= e($conf['l3']) ?></b></p>
    </div>
    <a class="btn small" href="<?= e(url('documents.php?cat=' . $catId)) ?>" target="_blank" rel="noopener"><span class="material-symbols-rounded icon-sm">open_in_new</span>ดูหน้าเว็บ</a>
  </div>
  <div class="dbk-stats">
    <div><b><?= number_format($stats['part']) ?></b><span><?= e($conf['l1']) ?></span></div>
    <div><b><?= number_format($stats['chapter']) ?></b><span><?= e($conf['l2']) ?></span></div>
    <div><b><?= number_format($stats['amend']) ?></b><span><?= e(docbook_short($conf['l3'])) ?></span></div>
    <?php if ($useStatus): ?><div><b><?= number_format($stats['rep_any']) ?></b><span><?= e($conf['s_off']) ?></span></div><?php endif; ?>
    <div><b><?= count($linkedDocs) ?><small>/<?= count($docs) ?></small></b><span>ไฟล์ในหมวดที่ผูกแล้ว</span></div>
  </div>

  <!-- ภาพปกเล่ม -->
  <div class="dbk-cover">
    <div class="dbk-cover-pv<?= !empty($cat['cover']) ? ' has-img' : '' ?>">
      <?php if (!empty($cat['cover'])): ?><img src="<?= e(url($cat['cover'])) ?>" alt="ภาพปกปัจจุบัน">
      <?php else: ?><span class="material-symbols-rounded"><?= e($conf['cat_icon']) ?></span><small>ปกสีที่ระบบวาดให้</small><?php endif; ?>
    </div>
    <div class="dbk-cover-t">
      <b>ภาพปกเล่ม</b> <span class="text-muted">(ไม่บังคับ — แสดงแทนปกสีทางซ้ายของหัวข้อบนหน้าเว็บ)</span>
      <ul class="dbk-spec">
        <li>แนวตั้ง <b>3 : 4</b> แบบปกหนังสือ · แนะนำ <b>900 × 1200 px</b> (อย่างน้อย 600 × 800)</li>
        <li>JPG หรือ WebP ไม่เกิน ~500 KB (ระบบรับสูงสุด 5 MB) · PNG ใช้เมื่อเป็นกราฟิกสีเรียบ</li>
        <li>เว้นขอบทุกด้านราว 6% และด้านซ้าย 8% (มีเงาสันปก) — ตัวหนังสือสำคัญไม่ควรชิดขอบ</li>
        <li>บนจอแสดงกว้างราว 190 px กดที่ภาพเพื่อดูขนาดเต็มได้ · ภาพสัดส่วนอื่นจะถูกครอปตรงกลาง</li>
      </ul>
      <form method="post" action="" enctype="multipart/form-data" class="flex gap-1 items-center" style="flex-wrap:wrap;">
        <?= csrf_field() ?><input type="hidden" name="action" value="cover">
        <input type="file" name="cover" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" required style="max-width:280px;">
        <button class="btn small primary" type="submit"><span class="material-symbols-rounded icon-sm">upload</span><?= !empty($cat['cover']) ? 'เปลี่ยนภาพปก' : 'อัปโหลดภาพปก' ?></button>
      </form>
      <?php if (!empty($cat['cover'])): ?>
      <form method="post" action="" class="mt-1"><?= csrf_field() ?><input type="hidden" name="action" value="cover"><input type="hidden" name="remove_cover" value="1">
        <button class="btn small ghost" type="submit" data-confirm="ลบภาพปก แล้วกลับไปใช้ปกสีที่ระบบวาดให้?"><span class="material-symbols-rounded icon-sm">delete</span>ลบภาพปก</button></form>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="dbk-top mb-2">
  <!-- รูปแบบเล่ม -->
  <div class="card">
    <div class="section-head" style="margin-bottom:10px;">
      <div><span class="tag">STRUCTURE</span><h3>รูปแบบเล่ม</h3>
      <p>เลือกให้ตรงกับประเภทเอกสาร — ชื่อเรียกแต่ละชั้นจะใช้ทั้งบนหน้าเว็บ ตัวเลขสรุป ปุ่ม และช่องค้นหา</p></div>
    </div>
    <?php if (!empty($conf['guessed'])): ?>
    <div class="alert info" style="font-size:13px;margin-top:0;"><span class="material-symbols-rounded">auto_awesome</span>
      <div>ระบบเลือก "<?= e($presets[$conf['preset']][0]) ?>" ให้จากชื่อหมวด — ถ้าไม่ตรง เลือกแบบอื่นแล้วกดบันทึก</div></div>
    <?php endif; ?>
    <form method="post" action="" data-dbk-conf data-presets="<?= e(json_encode($presetJs, JSON_UNESCAPED_UNICODE)) ?>">
      <?= csrf_field() ?><input type="hidden" name="action" value="settings">
      <div class="dbk-presets" role="radiogroup" aria-label="แม่แบบ">
        <?php foreach ($presets as $k => [$pt, $pe]): ?>
        <label class="dbk-preset">
          <input type="radio" name="preset" value="<?= e($k) ?>" <?= $conf['preset'] === $k ? 'checked' : '' ?>>
          <span><b><?= e($pt) ?></b><small><?= e($pe) ?></small></span>
        </label>
        <?php endforeach; ?>
      </div>

      <details class="dbk-names" <?= $conf['preset'] === 'custom' ? 'open' : '' ?>>
        <summary>ชื่อเรียกแต่ละชั้น <span class="text-muted">(แก้ช่องใดก็ได้ ระบบเปลี่ยนเป็น "กำหนดเอง" ให้)</span></summary>
        <table class="dbk-ntable">
          <tr><th></th><th>ชื่อเรียก</th><th>คำนำหน้าเลข <span class="text-muted">(ว่าง = ไม่มีเลข)</span></th></tr>
          <?php foreach ($levelRows as [$lk, $pk, $lt, $ld]): ?>
          <tr><td><b><?= e($lt) ?></b><small><?= e($ld) ?></small></td>
            <td><input type="text" name="bk[<?= $lk ?>]" value="<?= e($conf[$lk]) ?>" maxlength="40" required data-bk-field></td>
            <td><input type="text" name="bk[<?= $pk ?>]" value="<?= e($conf[$pk]) ?>" maxlength="30" data-bk-field placeholder="เช่น <?= e($lk === 'l1' ? 'ลักษณะที่' : ($lk === 'l2' ? 'บทที่' : 'ฉบับที่')) ?>"></td></tr>
          <?php endforeach; ?>
          <tr><td><b>ไอคอนรายการประกอบ</b></td>
            <td colspan="2"><div class="icon-pick-wrap">
              <span class="material-symbols-rounded icon-pick-preview" data-icon-preview><?= e($conf['icon3']) ?></span>
              <input type="text" name="bk[icon3]" value="<?= e($conf['icon3']) ?>" data-icon-input data-icon-default="edit_document" data-bk-field style="width:170px;" autocomplete="off">
            </div></td></tr>
          <tr><td><b>สถานะ</b><small>สำหรับกฎหมาย/ระเบียบที่ถูกยกเลิกได้</small></td>
            <td colspan="2"><input type="hidden" name="bk[status]" value="0">
              <label class="dbk-radio"><input type="checkbox" name="bk[status]" value="1" <?= $useStatus ? 'checked' : '' ?> data-bk-field> ใช้สถานะ</label>
              <span class="flex gap-1 mt-1" style="flex-wrap:wrap;">
                <input type="text" name="bk[s_on]" value="<?= e($conf['s_on']) ?>" maxlength="30" style="width:150px;" data-bk-field title="คำเรียกสถานะปกติ">
                <input type="text" name="bk[s_off]" value="<?= e($conf['s_off']) ?>" maxlength="30" style="width:150px;" data-bk-field title="คำเรียกสถานะยกเลิก">
              </span></td></tr>
        </table>
        <p class="dbk-sample">ตัวอย่างลำดับชั้น: <b data-bk-sample><?= e($sample($conf['l1'], $conf['p1']) . ' › ' . $sample($conf['l2'], $conf['p2']) . ' › ' . $sample($conf['l3'], $conf['p3'])) ?></b></p>
      </details>

      <label class="mt-2">คำอธิบายใต้ชื่อเล่ม <span class="text-muted">(ไม่บังคับ · ไม่เกิน 600 ตัวอักษร)</span></label>
      <textarea name="intro" rows="2" maxlength="600" class="mb-2" placeholder="อธิบายสั้นๆ ว่าเล่มนี้รวบรวมอะไร ให้ผู้อ่านเข้าใจก่อนเลือกหัวข้อ"><?= e($cat['intro'] ?? '') ?></textarea>
      <div class="flex gap-1 items-center" style="flex-wrap:wrap;">
        <label class="dbk-radio"><input type="radio" name="layout" value="book" <?= ($cat['layout'] ?? '') === 'book' ? 'checked' : '' ?>> แสดงเป็นสารบัญแบบหนังสือ</label>
        <label class="dbk-radio"><input type="radio" name="layout" value="list" <?= ($cat['layout'] ?? 'list') !== 'book' ? 'checked' : '' ?>> แสดงเป็นรายการไฟล์ (แบบเดิม)</label>
        <button class="btn small primary" type="submit" style="margin-left:auto;"><span class="material-symbols-rounded icon-sm">save</span>บันทึกรูปแบบเล่ม</button>
      </div>
    </form>
  </div>

  <!-- นำเข้า / ส่งออก -->
  <div class="card">
    <div class="section-head" style="margin-bottom:10px;">
      <div><span class="tag">IMPORT · EXPORT</span><h3>นำเข้าทีละมากๆ (JSON)</h3>
      <p>ให้เครื่องมือสร้างไฟล์ตามรูปแบบใน <code>docs/doc-book-format.md</code> แล้วนำเข้าที่นี่ ระบบตรวจทั้งไฟล์ก่อน ถ้าผิดแม้จุดเดียวจะไม่นำเข้าเลย</p>
      <p style="margin-top:4px;">ในไฟล์ <code>part</code> = <?= e($conf['l1']) ?> · <code>chapter</code> = <?= e($conf['l2']) ?> · <code>amend</code> = <?= e($conf['l3']) ?></p></div>
    </div>
    <form method="post" action="" enctype="multipart/form-data">
      <?= csrf_field() ?><input type="hidden" name="action" value="import">
      <input type="file" name="json_file" accept=".json,application/json" class="mb-1">
      <details class="mb-1"><summary class="text-muted" style="font-size:13px;cursor:pointer;">หรือวางข้อความ JSON</summary>
        <textarea name="json_text" rows="5" style="font-family:ui-monospace,Consolas,monospace;font-size:12px;" placeholder='{"format":"alicecms-docbook","version":1,"items":[...]}'></textarea>
      </details>
      <div class="flex gap-1 items-center" style="flex-wrap:wrap;">
        <label class="dbk-radio"><input type="radio" name="mode" value="replace" checked> แทนที่สารบัญเดิมทั้งหมด</label>
        <label class="dbk-radio"><input type="radio" name="mode" value="append"> ต่อท้ายของเดิม</label>
        <button class="btn small primary" type="submit" style="margin-left:auto;" <?= $tree ? 'data-confirm="นำเข้าแทนที่สารบัญเดิม? (ระบบจะสำรองของเดิมไว้ก่อน กู้คืนได้)"' : '' ?>><span class="material-symbols-rounded icon-sm">upload</span>นำเข้า</button>
      </div>
    </form>
    <div class="flex gap-1 mt-2" style="flex-wrap:wrap;border-top:1px solid var(--border);padding-top:12px;">
      <a class="btn small" href="<?= e(url($self . '&export=1')) ?>"><span class="material-symbols-rounded icon-sm">download</span>ส่งออกสารบัญปัจจุบัน (.json)</a>
    </div>
    <?php if ($backups): ?>
    <details class="mt-2"><summary style="font-size:13px;cursor:pointer;"><b>ไฟล์สำรองก่อนนำเข้า</b> (<?= count($backups) ?>)</summary>
      <ul class="dbk-baks">
        <?php foreach (array_slice($backups, 0, 10) as $b):
          $ts = preg_match('/-(\d{8})-(\d{6})\.json$/', $b, $m) ? DateTime::createFromFormat('Ymd His', $m[1] . ' ' . $m[2]) : null; ?>
        <li><span><?= $ts ? e(thai_date($ts->format('Y-m-d H:i:s')) . ' ' . $ts->format('H:i')) : e($b) ?></span>
          <a class="btn small" href="<?= e(url($self . '&backup=' . rawurlencode($b))) ?>">ดาวน์โหลด</a>
          <form method="post" action="" style="display:inline;"><?= csrf_field() ?><input type="hidden" name="action" value="restore"><input type="hidden" name="backup" value="<?= e($b) ?>">
            <button class="btn small" type="submit" data-confirm="กู้คืนสารบัญจากไฟล์สำรองนี้? (สารบัญปัจจุบันจะถูกสำรองไว้ก่อน)">กู้คืน</button></form></li>
        <?php endforeach; ?>
      </ul>
    </details>
    <?php endif; ?>
  </div>
</div>

<div class="dbk-main">
  <!-- ต้นไม้สารบัญ -->
  <div class="card">
    <div class="section-head" style="margin-bottom:8px;">
      <div><span class="tag">CONTENTS</span><h3>โครงสร้างเล่ม</h3>
      <p><?= e($conf['l1'] . ' → ' . $conf['l2'] . ' → ' . $conf['l3']) ?> · ปุ่มลูกศรเลื่อนลำดับภายในกลุ่มเดียวกัน</p></div>
      <a class="btn small primary" href="<?= e(url($self . '&add=part#form')) ?>"><span class="material-symbols-rounded icon-sm">add</span>เพิ่ม<?= e($conf['l1']) ?></a>
    </div>
    <?php if (!$tree): ?>
    <div class="text-center text-muted" style="padding:40px 16px;">
      <span class="material-symbols-rounded icon-xl" style="color:var(--border);">menu_book</span>
      <p>ยังไม่มีสารบัญ — เริ่มจาก "เพิ่ม<?= e($conf['l1']) ?>" หรือนำเข้าไฟล์ JSON</p>
    </div>
    <?php else: ?>
    <div class="dbk-tree">
      <?php foreach ($tree as $p): ?>
      <details class="dbk-part" id="t<?= (int)$p['id'] ?>" <?= ($edit && ((int)$edit['id'] === (int)$p['id'] || (int)($edit['parent_id'] ?? 0) === (int)$p['id'])) || (int)($addParent ?? 0) === (int)$p['id'] ? 'open' : '' ?>>
        <summary class="dbk-row is-part">
          <span class="material-symbols-rounded icon-sm dbk-chev">chevron_right</span>
          <?php if ($p['label'] !== ''): ?><span class="dbk-lbl"><?= e($p['label']) ?></span><?php endif; ?>
          <span class="dbk-ttl"><?= e($p['title']) ?> <span class="text-muted" style="font-size:12px;"><?= count($p['chapters']) ? count($p['chapters']) . ' ' . e($conf['l2']) : '' ?></span></span>
          <?= $rowBadges($p) ?><?= $rowActions($p) ?>
        </summary>
        <div class="dbk-kids">
          <?php foreach ($p['amends'] as $a): ?>
          <div class="dbk-row is-amend" id="t<?= (int)$a['id'] ?>"><span class="material-symbols-rounded icon-sm"><?= e($conf['icon3']) ?></span>
            <?php if ($a['label'] !== ''): ?><span class="dbk-lbl"><?= e($a['label']) ?></span><?php endif; ?><span class="dbk-ttl"><?= e($a['title']) ?></span><?= $rowBadges($a) ?><?= $rowActions($a) ?></div>
          <?php endforeach; ?>
          <?php foreach ($p['chapters'] as $c): ?>
          <div class="dbk-row is-chap" id="t<?= (int)$c['id'] ?>"><?php if ($c['label'] !== ''): ?><span class="dbk-lbl"><?= e($c['label']) ?></span><?php endif; ?><span class="dbk-ttl"><?= e($c['title']) ?></span><?= $rowBadges($c) ?><?= $rowActions($c) ?></div>
            <?php foreach ($c['amends'] as $a): ?>
            <div class="dbk-row is-amend deep" id="t<?= (int)$a['id'] ?>"><span class="material-symbols-rounded icon-sm"><?= e($conf['icon3']) ?></span>
              <?php if ($a['label'] !== ''): ?><span class="dbk-lbl"><?= e($a['label']) ?></span><?php endif; ?><span class="dbk-ttl"><?= e($a['title']) ?></span><?= $rowBadges($a) ?><?= $rowActions($a) ?></div>
            <?php endforeach; ?>
          <?php endforeach; ?>
          <?php if (!$p['amends'] && !$p['chapters']): ?><div class="text-muted" style="font-size:13px;padding:6px 10px;">ยังไม่มี<?= e($conf['l2']) ?> — กด "+ <?= e($conf['l2']) ?>" ที่แถวนี้</div><?php endif; ?>
        </div>
      </details>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <!-- ฟอร์มเพิ่ม/แก้ไข -->
  <div class="card dbk-form" id="form">
    <div class="section-head" style="margin-bottom:8px;">
      <div><span class="tag"><?= $edit ? 'EDIT' : 'ADD' ?></span><h3><?= $edit ? 'แก้ไขหัวข้อ' : 'เพิ่ม' . e($kinds[$f['kind']][0]) ?></h3></div>
      <?php if ($edit): ?><a class="btn small" href="<?= e(url($self)) ?>">ยกเลิก</a><?php endif; ?>
    </div>
    <form method="post" action="">
      <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
      <label>ชนิด</label>
      <?php if ($hasKids): ?>
      <input type="hidden" name="kind" value="<?= e($f['kind']) ?>">
      <p class="mb-2" style="font-size:14px;"><b><?= e($kinds[$f['kind']][0]) ?></b> <span class="text-muted">(มีหัวข้อย่อย จึงเปลี่ยนชนิดไม่ได้)</span></p>
      <?php else: ?>
      <select name="kind" class="mb-2" data-dbk-kind>
        <?php foreach ($kinds as $k => [$kn]): ?><option value="<?= $k ?>" <?= $f['kind'] === $k ? 'selected' : '' ?>><?= e($kn) ?> (<?= ['part' => 'ชั้นที่ 1', 'chapter' => 'ชั้นที่ 2', 'amend' => 'รายการประกอบ'][$k] ?>)</option><?php endforeach; ?>
      </select>
      <?php endif; ?>

      <div data-dbk-parent-wrap <?= $f['kind'] === 'part' ? 'hidden' : '' ?>>
        <label>อยู่ใต้</label>
        <select name="parent_id" class="mb-2" data-dbk-parent>
          <option value="0">— เลือก —</option>
          <?php foreach ($tree as $p): ?>
          <option value="<?= (int)$p['id'] ?>" data-k="part" <?= $f['parent'] === (int)$p['id'] ? 'selected' : '' ?>><?= e(mb_strimwidth(trim($p['label'] . ' ' . $p['title']), 0, 70, '…')) ?></option>
            <?php foreach ($p['chapters'] as $c): ?>
            <option value="<?= (int)$c['id'] ?>" data-k="chapter" <?= $f['parent'] === (int)$c['id'] ? 'selected' : '' ?>>&nbsp;&nbsp;&nbsp;↳ <?= e(mb_strimwidth(trim($c['label'] . ' ' . $c['title']), 0, 64, '…')) ?></option>
            <?php endforeach; ?>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="grid" style="grid-template-columns:150px minmax(0,1fr);gap:10px;">
        <div><label>ป้ายเลข</label><input type="text" name="label" value="<?= e($f['label']) ?>" maxlength="80" placeholder="<?= e($kinds[$f['kind']][1] !== '' ? $kinds[$f['kind']][1] . ' 1' : 'ไม่บังคับ') ?>"></div>
        <div><label>ชื่อเรื่อง</label><input type="text" name="title" value="<?= e($f['title']) ?>" maxlength="500"></div>
      </div>

      <?php if ($useStatus): ?>
      <label class="mt-2">สถานะ</label>
      <div class="flex gap-1 mb-1" style="flex-wrap:wrap;">
        <label class="dbk-radio"><input type="radio" name="status" value="active" <?= $f['status'] !== 'repealed' ? 'checked' : '' ?>> <?= e($conf['s_on']) ?></label>
        <label class="dbk-radio"><input type="radio" name="status" value="repealed" <?= $f['status'] === 'repealed' ? 'checked' : '' ?>> <?= e($conf['s_off']) ?></label>
      </div>
      <?php endif; ?>
      <label class="<?= $useStatus ? '' : 'mt-2' ?>">หมายเหตุ / รายละเอียด <span class="text-muted">(เช่น เหตุที่ยกเลิก ชื่อผู้เขียน วันประกาศ)</span></label>
      <input type="text" name="note" value="<?= e($f['note']) ?>" maxlength="500" class="mb-2">

      <label>เอกสารของหัวข้อนี้ <span class="text-muted">(เลือกจากไฟล์ที่อัปโหลดไว้ในหมวดนี้)</span></label>
      <select name="document_id" class="mb-1">
        <option value="0">— ไม่ผูกเอกสาร —</option>
        <?php foreach ($docs as $d): ?>
        <option value="<?= (int)$d['id'] ?>" <?= $f['doc'] === (int)$d['id'] ? 'selected' : '' ?>><?= isset($linkedDocs[(int)$d['id']]) && $f['doc'] !== (int)$d['id'] ? '✓ ' : '' ?><?= e(mb_strimwidth($d['title'], 0, 90, '…')) ?></option>
        <?php endforeach; ?>
      </select>
      <p class="text-muted" style="font-size:12px;margin:0 0 8px;">✓ = ผูกกับหัวข้ออื่นแล้ว · ถ้าไม่มีในรายการ ใส่ลิงก์ด้านล่างแทนได้</p>
      <label>หรือลิงก์ภายนอก <span class="text-muted">(Google Drive ฯลฯ — ใช้เมื่อไม่ได้เลือกเอกสารด้านบน)</span></label>
      <input type="url" name="url" value="<?= e($f['url']) ?>" maxlength="500" class="mb-2" placeholder="https://drive.google.com/file/d/.../view">

      <div class="flex gap-1" style="flex-wrap:wrap;">
        <button class="btn primary" type="submit"><span class="material-symbols-rounded icon-sm">save</span><?= $edit ? 'บันทึกการแก้ไข' : 'เพิ่ม' ?></button>
        <?php if (!$edit): ?><button class="btn" type="submit" name="again" value="1" title="บันทึกแล้วเปิดฟอร์มเพิ่มหัวข้อถัดไปทันที">บันทึกแล้วเพิ่มต่อ</button><?php endif; ?>
      </div>
    </form>
  </div>
</div>
<?php require __DIR__ . '/_bottom.php'; ?>
