<?php
/** admin/documents.php — เอกสารเผยแพร่ + จัดการหมวดเอกสาร */
require dirname(__DIR__) . '/includes/init.php';
require __DIR__ . '/_auth.php';
require_once APP_ROOT . '/includes/doc-book.php';
require_once APP_ROOT . '/includes/doc-shelf.php';

/**
 * ข้อมูลเสริมของเอกสารรายฉบับ (ใช้กับหมวดแบบชั้นวาง/หน้ารายละเอียด): ปก · กลุ่ม · วันที่เผยแพร่ · คำอธิบาย
 * คืนข้อความเตือน (ภาพปกผิด/สัดส่วนไม่ตรง) — ข้อมูลอื่นบันทึกได้แม้ภาพปกมีปัญหา
 */
function doc_extra_save(int $id, bool $withFields): array {
    $warn = [];
    if ($withFields) {
        $grp  = mb_substr(trim((string)($_POST['doc_grp'] ?? '')), 0, 80);
        $pd   = (string)($_POST['doc_pub_date'] ?? '');
        $pd   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $pd) && strtotime($pd) ? $pd : null;
        $sum  = mb_substr(trim((string)($_POST['doc_summary'] ?? '')), 0, 600);
        db()->prepare('UPDATE documents SET grp = ?, pub_date = ?, summary = ? WHERE id = ?')->execute([$grp, $pd, $sum, $id]);
    }
    $st = db()->prepare('SELECT cover FROM documents WHERE id = ?');
    $st->execute([$id]);
    $old = $st->fetchColumn() ?: null;
    if (!empty($_POST['remove_doc_cover'])) {
        db()->prepare('UPDATE documents SET cover = NULL WHERE id = ?')->execute([$id]);
        if ($old) delete_upload($old);
        return $warn;
    }
    foreach (['doc_cover', 'quick_cover'] as $field) {
        try { $up = handle_upload($field, 'doccovers', upload_image_exts(), 5); }
        catch (RuntimeException $ex) { $warn[] = 'ภาพปกไม่ถูกบันทึก: ' . $ex->getMessage(); continue; }
        if (!$up) continue;
        db()->prepare('UPDATE documents SET cover = ? WHERE id = ?')->execute([$up['path'], $id]);
        if ($old) delete_upload($old);
        $warn = array_merge($warn, docbook_cover_tips($up['path']));
        break;
    }
    return $warn;
}

$errors = [];
$edit = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    /* ── ลากจัดลำดับเอกสารภายในหมวดที่กำลังดูอยู่ (fetch JSON) ── */
    if (($_POST['action'] ?? '') === 'reorder') {
        header('Content-Type: application/json');
        $catRaw = (string)($_POST['cat'] ?? '');
        $catId  = $catRaw === 'none' ? null : ((int)$catRaw ?: null);
        $ids = json_decode((string)($_POST['order'] ?? '[]'), true) ?: [];
        /* category_id <=> ? กันคนอื่นสั่งลำดับข้ามหมวด (NULL-safe เทียบได้ทั้งกรณีไม่ระบุหมวด) */
        $upd = db()->prepare('UPDATE documents SET sort_order = ? WHERE id = ? AND category_id <=> ?');
        $i = 0;
        foreach ($ids as $id) { $upd->execute([$i++, (int)$id, $catId]); }
        echo json_encode(['ok' => true]);
        exit;
    }

    /* ── จัดการหมวด ── */
    if (isset($_POST['cat_action'])) {
        $act = $_POST['cat_action'];
        if ($act === 'add' || $act === 'edit') {
            $cid  = (int)($_POST['cat_id'] ?? 0);
            $name = mb_substr(trim((string)($_POST['cat_name'] ?? '')), 0, 150);
            $icon = preg_replace('/[^a-z0-9_]/', '', (string)($_POST['cat_icon'] ?? 'folder'));
            $cstyle_in = (string)($_POST['cat_style'] ?? '');
            $style = in_array($cstyle_in, ['', 'special', 'warning'], true) ? $cstyle_in : '';
            $sort = (int)($_POST['cat_sort'] ?? 0);
            $layout = in_array($_POST['cat_layout'] ?? '', ['book', 'shelf'], true) ? $_POST['cat_layout'] : 'list';
            if ($name !== '') {
                $oldCover = null;
                if ($act === 'edit' && $cid > 0) {
                    $st = db()->prepare('SELECT cover FROM doc_categories WHERE id = ?');
                    $st->execute([$cid]);
                    $oldCover = $st->fetchColumn() ?: null;
                    db()->prepare('UPDATE doc_categories SET name=?, icon=?, style=?, layout=?, sort_order=? WHERE id=?')
                        ->execute([$name, $icon, $style, $layout, $sort, $cid]);
                } else {
                    db()->prepare('INSERT INTO doc_categories (name, icon, style, layout, sort_order) VALUES (?,?,?,?,?)')
                        ->execute([$name, $icon, $style, $layout, $sort]);
                    $cid = (int)db()->lastInsertId();
                }
                /* คำอธิบายหมวด — แสดงใต้ชื่อหมวดบนหน้าเว็บ (ฟอร์มแถวเดิมที่ไม่มีช่องนี้ ไม่ทับค่าเดิม) */
                if (isset($_POST['cat_intro'])) {
                    db()->prepare('UPDATE doc_categories SET intro = ? WHERE id = ?')
                        ->execute([mb_substr(trim((string)$_POST['cat_intro']), 0, 600), $cid]);
                }
                /* การแสดงผล (เมื่อกดเอกสาร · จัดกลุ่ม · ขนาดปก · ฉบับเด่น · การเรียง) */
                if (isset($_POST['vc']) && is_array($_POST['vc'])) {
                    db()->prepare('UPDATE doc_categories SET view_conf = ? WHERE id = ?')->execute([docshelf_conf_from_post($_POST['vc']), $cid]);
                }
                /* ภาพปกของหมวด — ใช้ได้ทั้งแบบรายการและแบบสารบัญ */
                $msg = 'บันทึกหมวดเอกสารเรียบร้อยแล้ว';
                $type = 'success';
                if (!empty($_POST['remove_cover'])) {
                    docbook_cover_remove($cid, $oldCover);
                    $msg .= ' · ลบภาพปกแล้ว';
                } else {
                    try {
                        $tips = docbook_cover_save($cid, $oldCover, 'cat_cover');
                        if ($tips !== null) $msg .= ' · บันทึกภาพปกแล้ว' . ($tips ? ' — ' . implode(' · ', $tips) : '');
                    } catch (RuntimeException $ex) {
                        $msg .= ' · แต่ภาพปกไม่ถูกบันทึก: ' . $ex->getMessage();
                        $type = 'danger';
                    }
                }
                flash_set($type, $msg);
            }
        } elseif ($act === 'delete') {
            $cid = (int)($_POST['cat_id'] ?? 0);
            /* เอกสารในหมวดจะกลายเป็น "ทั่วไป" (ไม่ลบไฟล์) */
            db()->prepare('UPDATE documents SET category_id = NULL WHERE category_id = ?')->execute([$cid]);
            if (trash_delete('doc_categories', $cid, $ADMIN['username'] ?? '')) {
                log_action('ลบหมวดเอกสาร (ลงถังขยะ)', '#' . $cid);
                flash_set('success', 'ย้ายหมวดลงถังขยะแล้ว (เอกสารในหมวดถูกย้ายเป็นไม่ระบุหมวด) — กู้คืนได้ที่เมนู "ถังขยะ"');
            }
        }
        redirect('admin/documents.php');
    }

    /* ── อัปโหลดปกเร็ว (แถวในมุมมองแยกหมวด) ── */
    if (($_POST['action'] ?? '') === 'doc_cover') {
        $cid = (int)($_POST['doc_id'] ?? 0);
        $st = db()->prepare('SELECT title FROM documents WHERE id = ?');
        $st->execute([$cid]);
        if ($t = $st->fetchColumn()) {
            $w = doc_extra_save($cid, false);
            log_action('เปลี่ยนภาพปกเอกสาร', (string)$t);
            flash_set($w && str_starts_with($w[0], 'ภาพปกไม่ถูก') ? 'danger' : 'success', 'บันทึกภาพปก "' . mb_strimwidth((string)$t, 0, 50, '…') . '"' . ($w ? ' · ' . implode(' · ', $w) : ''));
        }
        redirect('admin/documents.php' . (($_POST['back_qs'] ?? '') !== '' ? '?' . $_POST['back_qs'] : ''));
    }

    /* ── ลบเอกสาร ── */
    if (isset($_POST['delete_id'])) {
        $id = (int)$_POST['delete_id'];
        if (trash_delete('documents', $id, $ADMIN['username'] ?? '')) {
            log_action('ลบเอกสาร (ลงถังขยะ)', '#' . $id);
            flash_set('success', 'ย้ายเอกสารลงถังขยะแล้ว — กู้คืนได้ที่เมนู "ถังขยะ"');
        }
        redirect('admin/documents.php' . (($_POST['back_qs'] ?? '') !== '' ? '?' . $_POST['back_qs'] : ''));
    }

    /* ── เพิ่ม/แก้ไขเอกสาร ── */
    $id     = (int)($_POST['id'] ?? 0);
    $title  = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 250);
    $catId  = (int)($_POST['category_id'] ?? 0) ?: null;
    $extUrl = ext_link_clean((string)($_POST['ext_url'] ?? ''));

    if ($title === '') $errors[] = 'กรุณากรอกชื่อเอกสาร';

    $file = null;
    if (!$errors) {
        try { $file = handle_upload('file', 'docs', null, 30); }
        catch (RuntimeException $ex) { $errors[] = $ex->getMessage(); }
    }
    if (!$errors && $id === 0 && !$file && $extUrl === '') $errors[] = 'กรุณาแนบไฟล์ หรือใส่ลิงก์ภายนอก (เช่น Google Drive)';

    if (!$errors) {
        if ($id > 0) {
            $st = db()->prepare('SELECT file FROM documents WHERE id = ?');
            $st->execute([$id]);
            $old = $st->fetch();
            if ($file) {
                /* อัปโหลดไฟล์ใหม่ → ใช้ไฟล์ (ลบของเดิม, ล้างลิงก์ภายนอก) */
                if ($old) delete_upload($old['file']);
                db()->prepare("UPDATE documents SET title=?, category_id=?, file=?, ext_url='', file_size=?, ext=? WHERE id=?")
                    ->execute([$title, $catId, $file['path'], $file['size'], $file['ext'], $id]);
            } elseif ($extUrl !== '') {
                /* ใส่ลิงก์ภายนอก → ใช้ลิงก์ (ลบไฟล์เดิมถ้ามี) */
                if ($old && !empty($old['file'])) delete_upload($old['file']);
                db()->prepare("UPDATE documents SET title=?, category_id=?, file='', ext_url=?, file_size=0, ext=? WHERE id=?")
                    ->execute([$title, $catId, $extUrl, ext_from_url($extUrl), $id]);
            } else {
                db()->prepare('UPDATE documents SET title=?, category_id=? WHERE id=?')
                    ->execute([$title, $catId, $id]);
            }
            $xw = doc_extra_save($id, true);
            flash_set('success', 'บันทึกเอกสารเรียบร้อยแล้ว' . ($xw ? ' · ' . implode(' · ', $xw) : ''));
        } else {
            /* เอกสารใหม่ขึ้นบนสุดเสมอ (เลขน้อยกว่าที่มีอยู่ทั้งหมด) — เหมือนพฤติกรรมเดิมก่อนมีลำดับเอง
               ผู้ดูแลลากจัดใหม่ทีหลังได้ที่มุมมองแยกตามหมวด */
            $minSort = (int)db()->query('SELECT COALESCE(MIN(sort_order),1) FROM documents')->fetchColumn();
            $newSort = $minSort - 1;
            if ($file) {
                db()->prepare("INSERT INTO documents (title, category_id, file, ext_url, file_size, ext, sort_order) VALUES (?,?,?,'',?,?,?)")
                    ->execute([$title, $catId, $file['path'], $file['size'], $file['ext'], $newSort]);
            } else {
                db()->prepare("INSERT INTO documents (title, category_id, file, ext_url, file_size, ext, sort_order) VALUES (?,?,'',?,0,?,?)")
                    ->execute([$title, $catId, $extUrl, ext_from_url($extUrl), $newSort]);
            }
            $xw = doc_extra_save((int)db()->lastInsertId(), true);
            flash_set('success', 'เพิ่มเอกสารเรียบร้อยแล้ว' . ($xw ? ' · ' . implode(' · ', $xw) : ''));
        }
        redirect('admin/documents.php' . (($_POST['back_qs'] ?? '') !== '' ? '?' . $_POST['back_qs'] : ''));
    }
}

if (isset($_GET['edit'])) {
    $st = db()->prepare('SELECT * FROM documents WHERE id = ?');
    $st->execute([(int)$_GET['edit']]);
    $edit = $st->fetch();
}

$cats = db()->query('SELECT c.*, (SELECT COUNT(*) FROM documents d WHERE d.category_id = c.id) AS n
                     FROM doc_categories c ORDER BY c.sort_order ASC, c.id ASC')->fetchAll();
$noCatCount = (int)db()->query('SELECT COUNT(*) FROM documents WHERE category_id IS NULL')->fetchColumn();

/* ── ตัวกรองหมวด: ว่าง=ทั้งหมด (แบ่งหน้าตามปกติ), 'none'=ไม่ระบุหมวด, ตัวเลข=หมวดนั้น
   เมื่อกรองแล้ว จะโชว์ "ทุกรายการในหมวดนั้น" แบบลากจัดลำดับได้ ไม่แบ่งหน้า (เพื่อให้ลากเทียบกันได้ทั้งหมวด) ── */
$filterCat   = (string)($_GET['cat'] ?? '');
$isFiltered  = $filterCat !== '';
$filterIsNone = $filterCat === 'none';
$filterCatId  = (!$filterIsNone && $isFiltered) ? (int)$filterCat : null;
$backQs = $isFiltered ? ('cat=' . urlencode($filterCat)) : '';

$total = (int)db()->query('SELECT COUNT(*) FROM documents')->fetchColumn();

if ($isFiltered) {
    if ($filterIsNone) {
        $catDocs = db()->query("SELECT d.*, NULL AS cat_name FROM documents d
                                WHERE d.category_id IS NULL ORDER BY d.sort_order ASC, d.id DESC")->fetchAll();
    } else {
        $st = db()->prepare("SELECT d.*, c.name AS cat_name FROM documents d
                             LEFT JOIN doc_categories c ON c.id = d.category_id
                             WHERE d.category_id = ? ORDER BY d.sort_order ASC, d.id DESC");
        $st->execute([$filterCatId]);
        $catDocs = $st->fetchAll();
    }
} else {
    $page = max(1, (int)($_GET['page'] ?? 1));
    $per  = 20;
    $docs = db()->query('SELECT d.*, c.name AS cat_name FROM documents d
                         LEFT JOIN doc_categories c ON c.id = d.category_id
                         ORDER BY d.sort_order ASC, d.id DESC LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per))->fetchAll();
}

$admin_title = 'เอกสารเผยแพร่';
require __DIR__ . '/_top.php';
?>
<?php if ($errors): ?>
<div class="alert danger"><span class="material-symbols-rounded">error</span>
  <div><?php foreach ($errors as $er): ?><div><?= e($er) ?></div><?php endforeach; ?></div>
</div>
<?php endif; ?>

<div class="grid grid-2 mb-2" style="align-items:start;">
  <!-- ฟอร์มเพิ่ม/แก้ไขเอกสาร -->
  <div class="card">
    <div class="section-head" style="margin-bottom:8px;">
      <div><span class="tag">DOCUMENT</span><h3><?= $edit ? 'แก้ไขเอกสาร' : 'เพิ่มเอกสารใหม่' ?></h3></div>
      <?php if ($edit): ?><a class="btn small" href="<?= e(url('admin/documents.php')) ?>">ยกเลิก</a><?php endif; ?>
    </div>
    <form method="post" action="" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
      <input type="hidden" name="back_qs" value="<?= e($backQs) ?>">
      <label>ชื่อเอกสาร <span style="color:var(--danger);">*</span></label>
      <input type="text" name="title" value="<?= e(old('title', $edit['title'] ?? '')) ?>" required class="mb-2">
      <label>หมวด</label>
      <select name="category_id" class="mb-2">
        <option value="0">— ไม่ระบุหมวด —</option>
        <?php foreach ($cats as $c): ?>
        <option value="<?= (int)$c['id'] ?>" <?= (int)($edit['category_id'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <label>ไฟล์เอกสาร <span class="text-muted">(เลือกอัปโหลดไฟล์ หรือใส่ลิงก์ภายนอกอย่างใดอย่างหนึ่ง)</span></label>
      <?php if (!empty($edit['file'])): ?>
      <div class="current-file"><span class="material-symbols-rounded icon-sm" style="color:var(--danger);">description</span><?= e(basename($edit['file'])) ?> (<?= e(format_bytes((int)$edit['file_size'])) ?>)</div>
      <?php elseif (!empty($edit['ext_url'])): ?>
      <div class="current-file"><span class="material-symbols-rounded icon-sm" style="color:var(--blue);">link</span>ลิงก์ภายนอก: <?= e(mb_strimwidth($edit['ext_url'], 0, 60, '…')) ?></div>
      <?php endif; ?>
      <div class="dropzone mb-2" style="padding:18px;">
        <input type="file" name="file" accept=".jpg,.jpeg,.png,.webp,.pdf,.doc,.docx,.xls,.xlsx,.zip">
        <span class="material-symbols-rounded icon-lg" style="color:var(--blue)">upload_file</span>
        <p style="margin:4px 0 0;font-size:13px;"><b>ลากไฟล์มาวาง</b> หรือคลิกเลือก <span class="text-muted">(≤ 30 MB)</span></p>
        <div class="dz-filename"></div>
      </div>
      <div class="or-divider"><span>หรือ</span></div>
      <label>ลิงก์ภายนอก <span class="text-muted">(Google Drive / OneDrive / URL ไฟล์ — ไม่ต้องอัปโหลด)</span></label>
      <input type="url" name="ext_url" value="<?= e(old('ext_url', $edit['ext_url'] ?? '')) ?>" class="mb-2" placeholder="https://drive.google.com/file/d/.../view">
      <?php $hasExtra = !empty($edit['cover']) || trim((string)($edit['grp'] ?? '')) !== '' || !empty($edit['pub_date']) || trim((string)($edit['summary'] ?? '')) !== ''; ?>
      <details class="dcat-more mb-2" <?= $hasExtra ? 'open' : '' ?>>
        <summary><span class="material-symbols-rounded icon-sm">auto_stories</span>ภาพปก · กลุ่ม · วันที่เผยแพร่ · คำอธิบาย <span class="text-muted">(สำหรับหมวดแบบชั้นวาง และหน้ารายละเอียด)</span></summary>
        <div class="dcat-more-in">
          <div class="doc-xcover">
            <?php if (!empty($edit['cover'])): ?><span class="dcat-thumb has-img" style="width:48px;height:64px;"><img src="<?= e(url($edit['cover'])) ?>" alt=""></span><?php endif; ?>
            <div style="flex:1;min-width:0;">
              <label>ภาพปกฉบับนี้ <span class="text-muted">(แนวตั้ง 3:4 · 900 × 1200 px · ไม่ใส่ = ระบบวาดปกจากเลขฉบับให้)</span></label>
              <input type="file" name="doc_cover" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
              <?php if (!empty($edit['cover'])): ?><label class="dbk-radio mt-1"><input type="checkbox" name="remove_doc_cover" value="1"> ลบภาพปก</label><?php endif; ?>
            </div>
          </div>
          <div class="grid" style="grid-template-columns:minmax(0,1fr) 170px;gap:10px;">
            <div><label>กลุ่ม <span class="text-muted">(เว้นว่าง = อ่านจากชื่อเรื่อง)</span></label>
              <input type="text" name="doc_grp" value="<?= e(old('doc_grp', $edit['grp'] ?? '')) ?>" maxlength="80" placeholder="เช่น ปีที่ 62 / ปีงบประมาณ 2568"></div>
            <div><label>วันที่เผยแพร่</label>
              <input type="date" name="doc_pub_date" value="<?= e(old('doc_pub_date', $edit['pub_date'] ?? '')) ?>"></div>
          </div>
          <div><label>คำอธิบาย <span class="text-muted">(ไม่เกิน 600 ตัวอักษร · เช่น เรื่องเด่นในฉบับ)</span></label>
            <textarea name="doc_summary" rows="2" maxlength="600"><?= e(old('doc_summary', $edit['summary'] ?? '')) ?></textarea></div>
        </div>
      </details>
      <button class="btn primary" type="submit"><span class="material-symbols-rounded icon-sm">save</span><?= $edit ? 'บันทึกการแก้ไข' : 'เพิ่มเอกสาร' ?></button>
    </form>
  </div>

  <!-- จัดการหมวด -->
  <div class="card">
    <div class="section-head" style="margin-bottom:8px;">
      <div><span class="tag">CATEGORIES</span><h3>หมวดเอกสาร</h3>
      <p>คลิกช่องไอคอนเพื่อเลือกจากรายการ หรือพิมพ์ชื่อไอคอนจาก Material Symbols เองก็ได้ ·
         หมวดที่เป็น "เล่ม" (เช่น ประมวลระเบียบ) เลือก <b>สารบัญแบบหนังสือ</b> แล้วกด <b>จัดการสารบัญ</b> เพื่อจัดเป็น ลักษณะ → บท → ฉบับแก้ไข</p></div>
    </div>
    <?php foreach ($cats as $c): ?>
    <form method="post" action="" enctype="multipart/form-data" class="flex gap-1 items-center mb-1 dcat-row" style="flex-wrap:wrap;">
      <?= csrf_field() ?>
      <input type="hidden" name="cat_id" value="<?= (int)$c['id'] ?>">
      <span class="dcat-thumb<?= !empty($c['cover']) ? ' has-img' : '' ?>" title="<?= !empty($c['cover']) ? 'มีภาพปก' : 'ยังไม่มีภาพปก' ?>"><?php if (!empty($c['cover'])): ?><img src="<?= e(url($c['cover'])) ?>" alt="" loading="lazy"><?php else: ?><span class="material-symbols-rounded icon-sm"><?= e($c['icon'] ?: 'folder') ?></span><?php endif; ?></span>
      <input type="text" name="cat_name" value="<?= e($c['name']) ?>" style="flex:1;min-width:120px;padding:8px 12px;">
      <div class="icon-pick-wrap">
        <span class="material-symbols-rounded icon-pick-preview" data-icon-preview><?= e($c['icon'] ?: 'folder') ?></span>
        <input type="text" name="cat_icon" value="<?= e($c['icon']) ?>" data-icon-input data-icon-default="folder" style="width:130px;padding:8px 12px;" placeholder="ไอคอน" autocomplete="off">
      </div>
      <select name="cat_style" style="width:100px;padding:8px;">
        <option value="" <?= $c['style'] === '' ? 'selected' : '' ?>>น้ำเงิน</option>
        <option value="special" <?= $c['style'] === 'special' ? 'selected' : '' ?>>ม่วง</option>
        <option value="warning" <?= $c['style'] === 'warning' ? 'selected' : '' ?>>ส้ม</option>
      </select>
      <select name="cat_layout" style="width:150px;padding:8px;" title="รูปแบบการแสดงบนหน้าเว็บ">
        <option value="list" <?= !in_array($c['layout'] ?? 'list', ['book', 'shelf'], true) ? 'selected' : '' ?>>แสดงเป็นรายการ</option>
        <option value="book" <?= ($c['layout'] ?? '') === 'book' ? 'selected' : '' ?>>สารบัญแบบหนังสือ</option>
        <option value="shelf" <?= ($c['layout'] ?? '') === 'shelf' ? 'selected' : '' ?>>ชั้นวางแบบมีปก</option>
      </select>
      <input type="number" name="cat_sort" value="<?= (int)$c['sort_order'] ?>" class="sort-input" title="ลำดับ">
      <button class="btn small" type="submit" name="cat_action" value="edit">บันทึก</button>
      <button class="btn small danger" type="submit" name="cat_action" value="delete" data-confirm="ลบหมวด «<?= e($c['name']) ?>»? เอกสาร <?= (int)$c['n'] ?> รายการจะถูกย้ายเป็นไม่ระบุหมวด">ลบ</button>
      <?php if (($c['layout'] ?? '') === 'book'): ?>
      <a class="btn small primary" href="<?= e(url('admin/doc-book.php?cat=' . (int)$c['id'])) ?>"><span class="material-symbols-rounded icon-sm">menu_book</span>จัดการสารบัญ</a>
      <?php endif; ?>
      <details class="dcat-more">
        <summary><span class="material-symbols-rounded icon-sm">tune</span>ภาพปก · คำอธิบาย · การแสดงผล<?= !empty($c['cover']) ? ' <span class="badge success" style="font-size:11px;">มีภาพปก</span>' : '' ?></summary>
        <div class="dcat-more-in">
          <?php $vc = docshelf_conf($c); ?>
          <div class="dcat-vc">
            <b>การแสดงผล</b>
            <label>เมื่อกดเอกสาร
              <select name="vc[open]"><option value="file" <?= $vc['open'] === 'file' ? 'selected' : '' ?>>เปิดไฟล์ทันที</option><option value="page" <?= $vc['open'] === 'page' ? 'selected' : '' ?>>ไปหน้ารายละเอียดก่อน</option></select></label>
            <label>จัดกลุ่มชั้นวาง
              <select name="vc[group]"><option value="auto" <?= $vc['group'] === 'auto' ? 'selected' : '' ?>>อัตโนมัติ (ช่องกลุ่ม / อ่านจากชื่อเรื่อง)</option><option value="year" <?= $vc['group'] === 'year' ? 'selected' : '' ?>>ตามปี พ.ศ. ที่เผยแพร่</option><option value="none" <?= $vc['group'] === 'none' ? 'selected' : '' ?>>ไม่แบ่งกลุ่ม</option></select></label>
            <label>ขนาดปก
              <select name="vc[size]"><option value="sm" <?= $vc['size'] === 'sm' ? 'selected' : '' ?>>เล็ก (เห็นหลายเล่ม)</option><option value="md" <?= $vc['size'] === 'md' ? 'selected' : '' ?>>กลาง</option><option value="lg" <?= $vc['size'] === 'lg' ? 'selected' : '' ?>>ใหญ่</option></select></label>
            <label>เรียง
              <select name="vc[sort]"><option value="new" <?= $vc['sort'] === 'new' ? 'selected' : '' ?>>ใหม่ → เก่า</option><option value="old" <?= $vc['sort'] === 'old' ? 'selected' : '' ?>>เก่า → ใหม่</option></select></label>
            <label class="dbk-radio"><input type="checkbox" name="vc[featured]" value="1" <?= $vc['featured'] ? 'checked' : '' ?>> ติดป้าย "ฉบับล่าสุด" บนชั้นวาง</label>
            <p class="text-muted" style="font-size:12px;margin:0;">จัดกลุ่ม/ขนาดปก/ป้ายฉบับล่าสุด ใช้กับรูปแบบ "ชั้นวางแบบมีปก" · "เมื่อกดเอกสาร" ใช้กับทุกรูปแบบ</p>
          </div>
          <?php
            /* กล่องตัวเลขบนหัวหมวด: ติ๊กเลือก + ลำดับ (ยังไม่เคยตั้ง = ค่าเริ่มต้นของรูปแบบปัจจุบัน) */
            $lay = $c['layout'] ?? 'list';
            $pick = $vc['stats'] ?? null;
            if ($pick === null) { $pick = []; foreach (docshelf_stat_defaults($lay) as $i => $k) $pick[$k] = $i + 1; }
            $layTh = ['list' => 'รายการ', 'book' => 'สารบัญ', 'shelf' => 'ชั้นวาง'];
          ?>
          <div class="dcat-stats">
            <b>กล่องตัวเลขบนหัวหมวด</b>
            <input type="hidden" name="vc[stat_layout]" value="<?= e($lay) ?>">
            <p class="text-muted" style="font-size:12px;margin:0;">ติ๊กกล่องที่ต้องการ แล้วใส่ลำดับ (1 = ซ้ายสุด) · กล่องที่ไม่มีค่าจะซ่อนเอง เช่น ยอดเปิด 0 · แสดงได้สูงสุด 8 กล่อง</p>
            <div class="dcat-stat-list">
              <?php foreach (docshelf_stat_catalog() as $sk => [$sl, $slays]): $on = isset($pick[$sk]); ?>
              <label class="dcat-stat<?= in_array($lay, $slays, true) ? '' : ' off' ?>">
                <input type="checkbox" name="vc[stat_on][<?= e($sk) ?>]" value="1" <?= $on ? 'checked' : '' ?>>
                <span><?= e($sl) ?><?php if (!in_array($lay, $slays, true)): ?> <small>(ใช้กับแบบ<?= e(implode('/', array_map(fn($x) => $layTh[$x], $slays))) ?>)</small><?php endif; ?></span>
                <input type="number" name="vc[stat_order][<?= e($sk) ?>]" value="<?= (int)($pick[$sk] ?? 50) ?>" min="1" max="99" class="sort-input" title="ลำดับ" aria-label="ลำดับของ <?= e($sl) ?>">
              </label>
              <?php endforeach; ?>
            </div>
            <b style="font-size:13px;margin-top:4px;">กล่องกำหนดเอง <span class="text-muted" style="font-weight:400;">(ไม่บังคับ · เช่น "กฎหมายที่เกี่ยวข้อง" = "12")</span></b>
            <?php $cus = $vc['custom'] ?? []; for ($ci = 0; $ci < 3; $ci++): $cc = $cus[$ci] ?? ['label' => '', 'value' => '', 'tone' => '', 'order' => 50 + $ci]; ?>
            <div class="dcat-custom">
              <input type="text" name="vc[custom][<?= $ci ?>][label]" value="<?= e($cc['label']) ?>" maxlength="30" placeholder="ชื่อกล่อง">
              <input type="text" name="vc[custom][<?= $ci ?>][value]" value="<?= e($cc['value']) ?>" maxlength="20" placeholder="ค่า">
              <select name="vc[custom][<?= $ci ?>][tone]" title="สี">
                <?php foreach (['' => 'สีปกติ', 'ok' => 'เขียว', 'rep' => 'แดง', 'blue' => 'สีธีม'] as $tk => $tl): ?><option value="<?= $tk ?>" <?= $cc['tone'] === $tk ? 'selected' : '' ?>><?= $tl ?></option><?php endforeach; ?>
              </select>
              <input type="number" name="vc[custom][<?= $ci ?>][order]" value="<?= (int)$cc['order'] ?>" min="1" max="99" class="sort-input" title="ลำดับ">
            </div>
            <?php endfor; ?>
          </div>
          <div>
            <label>ภาพปก <span class="text-muted">(แนวตั้ง 3:4 · แนะนำ 900 × 1200 px · JPG/WebP)</span></label>
            <input type="file" name="cat_cover" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
            <?php if (!empty($c['cover'])): ?><label class="dbk-radio mt-1"><input type="checkbox" name="remove_cover" value="1"> ลบภาพปก</label><?php endif; ?>
          </div>
          <div>
            <label>คำอธิบายใต้ชื่อหมวด <span class="text-muted">(ไม่บังคับ · ไม่เกิน 600 ตัวอักษร)</span></label>
            <textarea name="cat_intro" rows="2" maxlength="600" placeholder="อธิบายสั้นๆ ว่าหมวดนี้รวบรวมเอกสารอะไร"><?= e($c['intro'] ?? '') ?></textarea>
          </div>
          <p class="text-muted" style="font-size:12px;margin:0;">กด "บันทึก" ของแถวนี้เพื่อบันทึก · ภาพปกแสดงที่หัวหน้าหมวดและการ์ดหมวดบนหน้าแรก ทั้งแบบรายการและแบบสารบัญ</p>
        </div>
      </details>
    </form>
    <?php endforeach; ?>
    <form method="post" action="" class="flex gap-1 items-center mt-2" style="flex-wrap:wrap;border-top:1px solid var(--border);padding-top:12px;">
      <?= csrf_field() ?>
      <input type="text" name="cat_name" placeholder="ชื่อหมวดใหม่..." style="flex:1;min-width:120px;padding:8px 12px;">
      <div class="icon-pick-wrap">
        <span class="material-symbols-rounded icon-pick-preview" data-icon-preview>folder</span>
        <input type="text" name="cat_icon" value="folder" data-icon-input data-icon-default="folder" style="width:130px;padding:8px 12px;" autocomplete="off">
      </div>
      <select name="cat_style" style="width:100px;padding:8px;">
        <option value="">น้ำเงิน</option><option value="special">ม่วง</option><option value="warning">ส้ม</option>
      </select>
      <select name="cat_layout" style="width:150px;padding:8px;" title="รูปแบบการแสดงบนหน้าเว็บ">
        <option value="list">แสดงเป็นรายการ</option><option value="book">สารบัญแบบหนังสือ</option><option value="shelf">ชั้นวางแบบมีปก</option>
      </select>
      <input type="number" name="cat_sort" value="<?= count($cats) + 1 ?>" class="sort-input">
      <button class="btn small primary" type="submit" name="cat_action" value="add"><span class="material-symbols-rounded icon-sm">add</span>เพิ่มหมวด</button>
    </form>
  </div>
</div>

<!-- รายการเอกสาร -->
<div class="card">
  <div class="section-head" style="margin-bottom:8px;">
    <div><span class="tag">ALL DOCUMENTS</span><h3>เอกสารทั้งหมด (<?= number_format($total) ?>)</h3>
    <p>เลือกหมวดด้านล่างเพื่อลากจัดลำดับการแสดงของเอกสารในหมวดนั้น</p></div>
  </div>

  <div class="cats mb-2">
    <a class="cat<?= !$isFiltered ? ' active' : '' ?>" href="<?= e(url('admin/documents.php')) ?>">ทั้งหมด</a>
    <?php foreach ($cats as $c): ?>
    <a class="cat<?= (!$filterIsNone && $filterCatId === (int)$c['id']) ? ' active' : '' ?>" href="<?= e(url('admin/documents.php?cat=' . $c['id'])) ?>">
      <span class="material-symbols-rounded icon-sm"><?= e($c['icon'] ?: 'folder') ?></span><?= e($c['name']) ?> (<?= (int)$c['n'] ?>)
    </a>
    <?php endforeach; ?>
    <?php if ($noCatCount > 0): ?>
    <a class="cat<?= $filterIsNone ? ' active' : '' ?>" href="<?= e(url('admin/documents.php?cat=none')) ?>">ไม่ระบุหมวด (<?= $noCatCount ?>)</a>
    <?php endif; ?>
  </div>

  <?php if ($isFiltered): /* ───── โหมดลากจัดลำดับ (ทั้งหมวด ไม่แบ่งหน้า) ───── */
    $catLabel = $filterIsNone ? 'ไม่ระบุหมวด' : (array_values(array_filter($cats, fn($c) => (int)$c['id'] === $filterCatId))[0]['name'] ?? '');
  ?>
  <?php if ($catDocs): ?>
  <div id="docSort" data-sortlist data-endpoint="documents.php" data-csrf="<?= e(csrf_token()) ?>" data-cat="<?= e($filterCat) ?>">
    <p class="text-muted mb-1" style="font-size:13px;">หมวด «<?= e($catLabel) ?>» (<?= count($catDocs) ?> รายการ) — ลากไอคอน <span class="material-symbols-rounded icon-sm" style="vertical-align:-4px;">drag_indicator</span> เพื่อจัดลำดับ</p>
    <ul class="doc-sort">
      <?php foreach ($catDocs as $d): $isExt = !empty($d['ext_url']); ?>
      <li class="doc-li" draggable="true" data-id="<?= (int)$d['id'] ?>">
        <div class="doc-row">
          <span class="doc-grip material-symbols-rounded">drag_indicator</span>
          <form method="post" action="" enctype="multipart/form-data" class="doc-qcover" title="<?= !empty($d['cover']) ? 'เปลี่ยนภาพปก' : 'ใส่ภาพปก' ?>">
            <?= csrf_field() ?><input type="hidden" name="action" value="doc_cover"><input type="hidden" name="doc_id" value="<?= (int)$d['id'] ?>"><input type="hidden" name="back_qs" value="<?= e($backQs) ?>">
            <label class="dcat-thumb<?= !empty($d['cover']) ? ' has-img' : '' ?>">
              <?php if (!empty($d['cover'])): ?><img src="<?= e(url($d['cover'])) ?>" alt=""><?php else: ?><span class="material-symbols-rounded icon-sm" aria-hidden="true">add_photo_alternate</span><?php endif; ?>
              <input type="file" name="quick_cover" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" data-autosubmit class="sr-only" aria-label="ภาพปก <?= e($d['title']) ?>">
            </label>
          </form>
          <span class="doc-name"><?= e(mb_strimwidth($d['title'], 0, 70, '…')) ?></span>
          <span class="badge" style="font-size:11px;"><?= $isExt ? 'LINK' : e(strtoupper((string)$d['ext'])) ?></span>
          <span class="doc-actions">
            <a class="btn small" href="<?= e(url('download.php?id=' . $d['id'])) ?>"><span class="material-symbols-rounded icon-sm">download</span></a>
            <a class="btn small" href="<?= e(url('admin/documents.php?edit=' . $d['id'] . '&cat=' . $filterCat)) ?>">แก้ไข</a>
            <form method="post" action="" style="display:inline;">
              <?= csrf_field() ?>
              <input type="hidden" name="delete_id" value="<?= (int)$d['id'] ?>">
              <input type="hidden" name="back_qs" value="<?= e($backQs) ?>">
              <button class="btn small danger" type="submit" data-confirm="ยืนยันลบเอกสารนี้? ไฟล์จะถูกลบถาวร">ลบ</button>
            </form>
          </span>
        </div>
      </li>
      <?php endforeach; ?>
    </ul>
  </div>
  <?php else: ?>
  <p class="text-muted text-center" style="padding:24px;">ยังไม่มีเอกสารในหมวดนี้</p>
  <?php endif; ?>

  <?php else: /* ───── โหมดปกติ (ทั้งหมด แบ่งหน้า) ───── */ ?>
  <?php if ($docs): ?>
  <table class="admin-table">
    <tr><th>ชื่อเอกสาร</th><th>หมวด</th><th>ไฟล์</th><th>ดาวน์โหลด</th><th>วันที่</th><th></th></tr>
    <?php foreach ($docs as $d): ?>
    <tr>
      <td style="max-width:300px;"><?= e(mb_strimwidth($d['title'], 0, 70, '…')) ?></td>
      <td><span class="badge" style="font-size:11px;"><?= e($d['cat_name'] ?: 'ไม่ระบุ') ?></span></td>
      <td class="lr-date"><?= e(strtoupper((string)$d['ext'])) ?> · <?= e(format_bytes((int)$d['file_size'])) ?></td>
      <td class="lr-date"><?= number_format((int)$d['downloads']) ?></td>
      <td class="lr-date" style="white-space:nowrap;"><?= e(thai_date($d['created_at'])) ?></td>
      <td style="white-space:nowrap;">
        <a class="btn small" href="<?= e(url('download.php?id=' . $d['id'])) ?>"><span class="material-symbols-rounded icon-sm">download</span></a>
        <a class="btn small" href="<?= e(url('admin/documents.php?edit=' . $d['id'])) ?>">แก้ไข</a>
        <form method="post" action="" style="display:inline;">
          <?= csrf_field() ?>
          <input type="hidden" name="delete_id" value="<?= (int)$d['id'] ?>">
          <button class="btn small danger" type="submit" data-confirm="ยืนยันลบเอกสารนี้? ไฟล์จะถูกลบถาวร">ลบ</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?= pagination_html($total, $per, $page, url('admin/documents.php')) ?>
  <?php else: ?>
  <p class="text-muted text-center" style="padding:24px;">ยังไม่มีเอกสาร</p>
  <?php endif; ?>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/_bottom.php'; ?>
