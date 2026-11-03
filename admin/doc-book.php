<?php
/**
 * admin/doc-book.php — จัดการสารบัญแบบหนังสือของหมวดเอกสาร (ลักษณะ → บท → ฉบับแก้ไขเพิ่มเติม)
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
$kinds = docbook_kinds();

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
        db()->prepare('UPDATE doc_categories SET layout = ?, intro = ? WHERE id = ?')->execute([$layout, $intro, $catId]);
        log_action('ตั้งค่าสารบัญเอกสาร', $cat['name']);
        flash_set('success', $layout === 'book' ? 'บันทึกแล้ว — หน้าเว็บแสดงหมวดนี้เป็นสารบัญแบบหนังสือ' : 'บันทึกแล้ว — หน้าเว็บกลับไปแสดงหมวดนี้เป็นรายการไฟล์');
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
            [$flat, $importErrors, $importWarn] = docbook_validate($json, $catId);
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

        if ($label === '' && $title === '') $errors[] = 'กรอกป้าย (เช่น บทที่ 1) หรือชื่อเรื่องอย่างน้อยหนึ่งช่อง';
        if ($urlIn !== '' && $link === '') $errors[] = 'ลิงก์ต้องขึ้นต้นด้วย https:// — คัดลอกจากช่องที่อยู่ของเบราว์เซอร์มาวางให้ครบ';

        /* โครงสร้างที่อนุญาต: ลักษณะอยู่บนสุด · บทอยู่ใต้ลักษณะ · ฉบับแก้ไขอยู่ใต้ลักษณะหรือบท */
        if ($kind === 'part') {
            $parent = null;
        } else {
            $p = $parent ? $getRow($parent) : null;
            $okParent = $p && ($kind === 'chapter' ? $p['kind'] === 'part' : in_array($p['kind'], ['part', 'chapter'], true));
            if (!$okParent) $errors[] = $kind === 'chapter' ? 'เลือกลักษณะที่บทนี้อยู่' : 'เลือกลักษณะหรือบทที่ฉบับแก้ไขนี้แก้';
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
foreach ($rows as $r) if (!empty($r['document_id'])) $linkedDocs[(int)$r['document_id']] = true;

/* ฟอร์ม: แก้ไข (?edit=) หรือเพิ่ม (?add=kind&parent=) */
$edit = isset($_GET['edit']) ? ($byId[(int)$_GET['edit']] ?? null) : null;
$addKind = isset($kinds[$_GET['add'] ?? '']) ? $_GET['add'] : '';
$addParent = (int)($_GET['parent'] ?? 0) ?: null;
if (!$edit && $addKind === '') $addKind = $tree ? 'chapter' : 'part';
if (!$edit && $addKind !== 'part' && $addParent === null) {
    /* ค่าเริ่มต้น: ใต้ลักษณะสุดท้าย */
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
    'label'  => old('label', $edit['label'] ?? docbook_next_label($prevLabel, $fKind)),
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
$rowActions = function (array $n) use ($self): string {
    $id = (int)$n['id'];
    $h = '<span class="dbk-acts">';
    foreach (['up' => 'arrow_upward', 'down' => 'arrow_downward'] as $dir => $ic) {
        $h .= '<form method="post" action="">' . csrf_field() . '<input type="hidden" name="action" value="move"><input type="hidden" name="id" value="' . $id . '">'
            . '<input type="hidden" name="dir" value="' . $dir . '"><button class="btn small ghost" type="submit" title="' . ($dir === 'up' ? 'เลื่อนขึ้น' : 'เลื่อนลง') . '"><span class="material-symbols-rounded icon-sm">' . $ic . '</span></button></form>';
    }
    $h .= '<a class="btn small" href="' . e(url($self . '&edit=' . $id . '#form')) . '">แก้ไข</a>';
    if ($n['kind'] === 'part') $h .= '<a class="btn small" href="' . e(url($self . '&add=chapter&parent=' . $id . '#form')) . '" title="เพิ่มบทในลักษณะนี้">+ บท</a>';
    if ($n['kind'] !== 'amend') $h .= '<a class="btn small" href="' . e(url($self . '&add=amend&parent=' . $id . '#form')) . '" title="เพิ่มฉบับแก้ไขเพิ่มเติม">+ ฉบับแก้ไข</a>';
    $nKids = count($n['chapters']) + count($n['amends']);
    $h .= '<form method="post" action="">' . csrf_field() . '<input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="' . $id . '">'
        . '<button class="btn small danger" type="submit" data-confirm="' . e('ลบ «' . trim($n['label'] . ' ' . $n['title']) . '»' . ($nKids ? ' พร้อมหัวข้อย่อยทั้งหมด' : '') . '? (กู้คืนได้จากถังขยะ)') . '">ลบ</button></form>';
    return $h . '</span>';
};
/** ป้ายสถานะ/ลิงก์ของแถว */
$rowBadges = function (array $n): string {
    $h = '';
    if ($n['status'] === 'repealed') $h .= '<span class="badge danger" style="font-size:11px;">ยกเลิก</span>';
    if (!empty($n['document_id']) && isset($n['doc_title'])) $h .= '<span class="badge" style="font-size:11px;" title="' . e($n['doc_title']) . '"><span class="material-symbols-rounded icon-sm">description</span>เอกสาร</span>';
    elseif ($n['url'] !== '') $h .= '<span class="badge" style="font-size:11px;"><span class="material-symbols-rounded icon-sm">link</span>ลิงก์</span>';
    return $h;
};

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
  <div>หมวดนี้ยังแสดงบนหน้าเว็บเป็น <b>รายการไฟล์</b> — สารบัญที่จัดไว้จะยังไม่ปรากฏจนกว่าจะเลือก "สารบัญแบบหนังสือ" ในช่องตั้งค่าด้านล่าง</div>
</div>
<?php endif; ?>

<div class="dbk-top mb-2">
  <!-- ภาพรวม -->
  <div class="card">
    <div class="section-head" style="margin-bottom:10px;">
      <div><span class="tag">TABLE OF CONTENTS</span><h3><?= e($cat['name']) ?></h3></div>
      <a class="btn small" href="<?= e(url('documents.php?cat=' . $catId)) ?>" target="_blank" rel="noopener"><span class="material-symbols-rounded icon-sm">open_in_new</span>ดูหน้าเว็บ</a>
    </div>
    <div class="dbk-stats">
      <div><b><?= $stats['part'] ?></b><span>ลักษณะ</span></div>
      <div><b><?= $stats['chapter'] ?></b><span>บท</span></div>
      <div><b><?= $stats['amend'] ?></b><span>ฉบับแก้ไข</span></div>
      <div><b><?= $stats['repealed'] ?></b><span>ลักษณะที่ยกเลิก</span></div>
      <div><b><?= count($linkedDocs) ?>/<?= count($docs) ?></b><span>เอกสารในหมวดที่ผูกแล้ว</span></div>
    </div>
    <form method="post" action="" class="mt-2">
      <?= csrf_field() ?><input type="hidden" name="action" value="settings">
      <label>คำอธิบายใต้ชื่อเล่ม <span class="text-muted">(ไม่บังคับ · ไม่เกิน 600 ตัวอักษร)</span></label>
      <textarea name="intro" rows="2" maxlength="600" class="mb-2" placeholder="เช่น รวบรวมประมวลระเบียบการตำรวจไม่เกี่ยวกับคดีทุกลักษณะ พร้อมฉบับแก้ไขเพิ่มเติม จัดเรียงตามโครงสร้างของเล่ม"><?= e($cat['intro'] ?? '') ?></textarea>
      <div class="flex gap-1 items-center" style="flex-wrap:wrap;">
        <label class="dbk-radio"><input type="radio" name="layout" value="book" <?= ($cat['layout'] ?? '') === 'book' ? 'checked' : '' ?>> แสดงเป็นสารบัญแบบหนังสือ</label>
        <label class="dbk-radio"><input type="radio" name="layout" value="list" <?= ($cat['layout'] ?? 'list') !== 'book' ? 'checked' : '' ?>> แสดงเป็นรายการไฟล์ (แบบเดิม)</label>
        <button class="btn small primary" type="submit" style="margin-left:auto;"><span class="material-symbols-rounded icon-sm">save</span>บันทึก</button>
      </div>
    </form>
  </div>

  <!-- นำเข้า / ส่งออก -->
  <div class="card">
    <div class="section-head" style="margin-bottom:10px;">
      <div><span class="tag">IMPORT · EXPORT</span><h3>นำเข้าทีละมากๆ (JSON)</h3>
      <p>ใช้เมื่อมีหัวข้อจำนวนมาก — ให้เครื่องมือสร้างไฟล์ตามรูปแบบใน <code>docs/doc-book-format.md</code> แล้วนำเข้าที่นี่ ระบบตรวจทั้งไฟล์ก่อน ถ้าผิดแม้จุดเดียวจะไม่นำเข้าเลย</p></div>
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
      <div><span class="tag">STRUCTURE</span><h3>โครงสร้างเล่ม</h3>
      <p>ลักษณะ → บท → ฉบับแก้ไขเพิ่มเติม · ปุ่มลูกศรเลื่อนลำดับภายในกลุ่มเดียวกัน</p></div>
      <a class="btn small primary" href="<?= e(url($self . '&add=part#form')) ?>"><span class="material-symbols-rounded icon-sm">add</span>เพิ่มลักษณะ</a>
    </div>
    <?php if (!$tree): ?>
    <div class="text-center text-muted" style="padding:40px 16px;">
      <span class="material-symbols-rounded icon-xl" style="color:var(--border);">menu_book</span>
      <p>ยังไม่มีสารบัญ — เริ่มจาก "เพิ่มลักษณะ" หรือนำเข้าไฟล์ JSON</p>
    </div>
    <?php else: ?>
    <div class="dbk-tree">
      <?php foreach ($tree as $p): ?>
      <details class="dbk-part" id="t<?= (int)$p['id'] ?>" <?= ($edit && ((int)$edit['id'] === (int)$p['id'] || (int)($edit['parent_id'] ?? 0) === (int)$p['id'])) || (int)($addParent ?? 0) === (int)$p['id'] ? 'open' : '' ?>>
        <summary class="dbk-row is-part">
          <span class="material-symbols-rounded icon-sm dbk-chev">chevron_right</span>
          <span class="dbk-lbl"><?= e($p['label']) ?></span>
          <span class="dbk-ttl"><?= e($p['title']) ?> <span class="text-muted" style="font-size:12px;"><?= count($p['chapters']) ? count($p['chapters']) . ' บท' : '' ?></span></span>
          <?= $rowBadges($p) ?><?= $rowActions($p) ?>
        </summary>
        <div class="dbk-kids">
          <?php foreach ($p['amends'] as $a): ?>
          <div class="dbk-row is-amend" id="t<?= (int)$a['id'] ?>"><span class="material-symbols-rounded icon-sm">edit_document</span>
            <span class="dbk-lbl"><?= e($a['label']) ?></span><span class="dbk-ttl"><?= e($a['title']) ?></span><?= $rowBadges($a) ?><?= $rowActions($a) ?></div>
          <?php endforeach; ?>
          <?php foreach ($p['chapters'] as $c): ?>
          <div class="dbk-row is-chap" id="t<?= (int)$c['id'] ?>"><span class="dbk-lbl"><?= e($c['label']) ?></span><span class="dbk-ttl"><?= e($c['title']) ?></span><?= $rowBadges($c) ?><?= $rowActions($c) ?></div>
            <?php foreach ($c['amends'] as $a): ?>
            <div class="dbk-row is-amend deep" id="t<?= (int)$a['id'] ?>"><span class="material-symbols-rounded icon-sm">edit_document</span>
              <span class="dbk-lbl"><?= e($a['label']) ?></span><span class="dbk-ttl"><?= e($a['title']) ?></span><?= $rowBadges($a) ?><?= $rowActions($a) ?></div>
            <?php endforeach; ?>
          <?php endforeach; ?>
          <?php if (!$p['amends'] && !$p['chapters']): ?><div class="text-muted" style="font-size:13px;padding:6px 10px;">ยังไม่มีบท — กด "+ บท" ที่แถวลักษณะ</div><?php endif; ?>
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
        <?php foreach ($kinds as $k => [$kn]): ?><option value="<?= $k ?>" <?= $f['kind'] === $k ? 'selected' : '' ?>><?= e($kn) ?></option><?php endforeach; ?>
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

      <div class="grid" style="grid-template-columns:150px 1fr;gap:10px;">
        <div><label>ป้าย</label><input type="text" name="label" value="<?= e($f['label']) ?>" maxlength="80" placeholder="บทที่ 1"></div>
        <div><label>ชื่อเรื่อง</label><input type="text" name="title" value="<?= e($f['title']) ?>" maxlength="500" placeholder="เช่น การบรรจุ พ.ศ. 2556"></div>
      </div>

      <label class="mt-2">สถานะ</label>
      <div class="flex gap-1 mb-1" style="flex-wrap:wrap;">
        <label class="dbk-radio"><input type="radio" name="status" value="active" <?= $f['status'] !== 'repealed' ? 'checked' : '' ?>> มีผลใช้บังคับ</label>
        <label class="dbk-radio"><input type="radio" name="status" value="repealed" <?= $f['status'] === 'repealed' ? 'checked' : '' ?>> ยกเลิกแล้ว</label>
      </div>
      <label>หมายเหตุ <span class="text-muted">(เช่น ยกเลิกโดยคำสั่ง ตร.ที่ 267/2556 ลง 26 เม.ย. 2556)</span></label>
      <input type="text" name="note" value="<?= e($f['note']) ?>" maxlength="500" class="mb-2">

      <label>เอกสารของหัวข้อนี้ <span class="text-muted">(เลือกจากเอกสารที่อัปโหลดไว้ในหมวดนี้)</span></label>
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
