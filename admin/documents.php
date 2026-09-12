<?php
/** admin/documents.php — เอกสารเผยแพร่ + จัดการหมวดเอกสาร */
require dirname(__DIR__) . '/includes/init.php';
require __DIR__ . '/_auth.php';

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
            if ($name !== '') {
                if ($act === 'edit' && $cid > 0) {
                    db()->prepare('UPDATE doc_categories SET name=?, icon=?, style=?, sort_order=? WHERE id=?')
                        ->execute([$name, $icon, $style, $sort, $cid]);
                } else {
                    db()->prepare('INSERT INTO doc_categories (name, icon, style, sort_order) VALUES (?,?,?,?)')
                        ->execute([$name, $icon, $style, $sort]);
                }
                flash_set('success', 'บันทึกหมวดเอกสารเรียบร้อยแล้ว');
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
            flash_set('success', 'บันทึกเอกสารเรียบร้อยแล้ว');
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
            flash_set('success', 'เพิ่มเอกสารเรียบร้อยแล้ว');
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
      <button class="btn primary" type="submit"><span class="material-symbols-rounded icon-sm">save</span><?= $edit ? 'บันทึกการแก้ไข' : 'เพิ่มเอกสาร' ?></button>
    </form>
  </div>

  <!-- จัดการหมวด -->
  <div class="card">
    <div class="section-head" style="margin-bottom:8px;">
      <div><span class="tag">CATEGORIES</span><h3>หมวดเอกสาร</h3>
      <p>คลิกช่องไอคอนเพื่อเลือกจากรายการ หรือพิมพ์ชื่อไอคอนจาก Material Symbols เองก็ได้</p></div>
    </div>
    <?php foreach ($cats as $c): ?>
    <form method="post" action="" class="flex gap-1 items-center mb-1" style="flex-wrap:wrap;">
      <?= csrf_field() ?>
      <input type="hidden" name="cat_id" value="<?= (int)$c['id'] ?>">
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
      <input type="number" name="cat_sort" value="<?= (int)$c['sort_order'] ?>" class="sort-input" title="ลำดับ">
      <button class="btn small" type="submit" name="cat_action" value="edit">บันทึก</button>
      <button class="btn small danger" type="submit" name="cat_action" value="delete" data-confirm="ลบหมวด «<?= e($c['name']) ?>»? เอกสาร <?= (int)$c['n'] ?> รายการจะถูกย้ายเป็นไม่ระบุหมวด">ลบ</button>
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
