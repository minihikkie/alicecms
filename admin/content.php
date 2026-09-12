<?php
/** admin/content.php — จัดการรายการของประเภทเนื้อหา (ตาม ?type=slug) */
require dirname(__DIR__) . '/includes/init.php';
require __DIR__ . '/_auth.php';
require dirname(__DIR__) . '/includes/content.php';

$type_slug = preg_replace('/[^\p{L}\p{N}\-_]/u', '', (string)($_GET['type'] ?? ''));
$type = $type_slug !== '' ? content_type_by_slug($type_slug, false) : null;
if (!$type) { flash_set('danger', 'ไม่พบประเภทเนื้อหา'); redirect('admin/content-types.php'); }

$fields = content_type_fields($type);
$errors = [];
$edit = null;
$base = 'admin/content.php?type=' . $type['slug'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['delete_id'])) {
        /* ตรวจว่ารายการอยู่ในประเภทนี้จริงก่อน แล้วย้ายลงถังขยะ */
        $st = db()->prepare('SELECT COUNT(*) FROM content_items WHERE id = ? AND type_id = ?');
        $st->execute([(int)$_POST['delete_id'], (int)$type['id']]);
        if ((int)$st->fetchColumn() > 0 && trash_delete('content_items', (int)$_POST['delete_id'], $ADMIN['username'] ?? '')) {
            log_action('ลบรายการเนื้อหา (ลงถังขยะ)', '#' . (int)$_POST['delete_id']);
            flash_set('success', 'ย้ายรายการลงถังขยะแล้ว — กู้คืนได้ที่เมนู "ถังขยะ"');
        } else {
            flash_set('danger', 'ลบไม่สำเร็จ');
        }
        redirect($base);
    }

    $id    = (int)($_POST['id'] ?? 0);
    $title = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 250);
    $slug  = slugify((string)($_POST['slug'] ?? '') !== '' ? (string)$_POST['slug'] : ($title ?: 'item'));
    $status = ($_POST['status'] ?? 'published') === 'draft' ? 'draft' : 'published';
    $sort   = (int)($_POST['sort_order'] ?? 0);
    if ($title === '') $errors[] = 'กรุณากรอกชื่อรายการ';

    /* รูปปก */
    $image = null;
    if (!$errors && !empty($type['has_image'])) {
        try { $image = handle_upload('image', 'content', upload_image_exts(), 8); }
        catch (RuntimeException $ex) { $errors[] = $ex->getMessage(); }
    }

    /* เก็บค่าฟิลด์เป็น { label => value } */
    $data = [];
    $post = $_POST['field'] ?? [];
    foreach ($fields as $i => $f) {
        $ft = (string)($f['type'] ?? 'text'); $lb = (string)($f['label'] ?? ('ช่อง ' . ($i + 1)));
        if ($ft === 'file') {
            if (!empty($_FILES['ffile']['name'][$i])) {
                try {
                    $r = store_upload($_FILES['ffile']['name'][$i], $_FILES['ffile']['tmp_name'][$i], (int)$_FILES['ffile']['error'][$i], (int)$_FILES['ffile']['size'][$i], 'content', upload_all_exts(), 10);
                    $data[$lb] = $r['orig'] . ' (' . abs_url($r['path']) . ')';
                } catch (RuntimeException $ex) { $errors[] = $lb . ': ' . $ex->getMessage(); }
            } elseif ($id && isset($_POST['keep_file'][$i])) {
                $data[$lb] = (string)$_POST['keep_file'][$i];
            }
        } elseif ($ft === 'checkbox') {
            $arr = isset($post[$i]) && is_array($post[$i]) ? array_map('strval', $post[$i]) : [];
            $data[$lb] = implode(', ', array_map(fn($x) => mb_substr(trim($x), 0, 1000), $arr));
        } else {
            $data[$lb] = mb_substr(trim((string)($post[$i] ?? '')), 0, 8000);
        }
        if (!empty($f['required']) && trim((string)($data[$lb] ?? '')) === '') $errors[] = 'กรุณากรอก "' . $lb . '"';
    }

    if (!$errors) {
        $djson = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($id > 0) {
            $st = db()->prepare('SELECT image FROM content_items WHERE id = ?'); $st->execute([$id]); $old = $st->fetch();
            if ($image && $old) delete_upload($old['image']);
            $img = $image['path'] ?? ($old['image'] ?? '');
            db()->prepare('UPDATE content_items SET title=?, slug=?, image=?, data=?, status=?, sort_order=? WHERE id=? AND type_id=?')
                ->execute([$title, $slug, $img, $djson, $status, $sort, $id, (int)$type['id']]);
        } else {
            db()->prepare('INSERT INTO content_items (type_id, title, slug, image, data, status, sort_order) VALUES (?,?,?,?,?,?,?)')
                ->execute([(int)$type['id'], $title, $slug, $image['path'] ?? '', $djson, $status, $sort]);
        }
        log_action('จัดการรายการเนื้อหา', $type['name'] . ': ' . $title);
        flash_set('success', 'บันทึกรายการแล้ว');
        redirect($base);
    }
}

if (isset($_GET['edit'])) {
    $st = db()->prepare('SELECT * FROM content_items WHERE id = ? AND type_id = ?');
    $st->execute([(int)$_GET['edit'], (int)$type['id']]); $edit = $st->fetch();
}
$edit_data = $edit ? (json_decode((string)$edit['data'], true) ?: []) : [];

$items = db()->prepare('SELECT * FROM content_items WHERE type_id = ? ORDER BY sort_order ASC, id DESC');
$items->execute([(int)$type['id']]); $items = $items->fetchAll();

$view = ($edit || isset($_GET['new'])) ? 'edit' : 'list';
$admin_title = $type['name'];
require __DIR__ . '/_top.php';
?>
<?php if ($view === 'edit'): ?>
<div class="card mb-2">
  <div class="section-head" style="margin-bottom:8px;">
    <div><span class="tag"><span class="material-symbols-rounded icon-sm"><?= e($type['icon'] ?: 'category') ?></span> <?= e(mb_strtoupper($type['slug'])) ?></span><h3><?= $edit ? 'แก้ไข' : 'เพิ่ม' ?><?= e($type['name']) ?></h3></div>
    <a class="btn small" href="<?= e(url($base)) ?>">ยกเลิก</a>
  </div>
  <?php if ($errors): ?><div class="alert danger"><span class="material-symbols-rounded">error</span><div><?php foreach ($errors as $er): ?><div><?= e($er) ?></div><?php endforeach; ?></div></div><?php endif; ?>
  <form method="post" action="<?= e(url($base)) ?>" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
    <div class="form-row">
      <div><label>ชื่อรายการ <span style="color:var(--danger);">*</span></label>
        <input type="text" name="title" value="<?= e(old('title', $edit['title'] ?? '')) ?>" required class="mb-2"></div>
      <div><label>slug</label><input type="text" name="slug" value="<?= e(old('slug', $edit['slug'] ?? '')) ?>" class="mb-2"></div>
    </div>
    <?php if (!empty($type['has_image'])): ?>
    <label>รูปปก</label>
    <?php if (!empty($edit['image'])): ?><div class="current-file"><img src="<?= e(url($edit['image'])) ?>" alt="">รูปปัจจุบัน</div><?php endif; ?>
    <div class="dropzone mb-2" style="padding:16px;"><input type="file" name="image" accept=".jpg,.jpeg,.png,.webp"><span class="material-symbols-rounded icon-lg" style="color:var(--blue)">add_photo_alternate</span><p style="margin:4px 0 0;font-size:13px;">เลือกรูปปก</p><div class="dz-filename"></div></div>
    <?php endif; ?>
    <?php foreach ($fields as $i => $f):
      $lb = (string)($f['label'] ?? '');
      $cur = $edit_data[$lb] ?? null;
      if (($f['type'] ?? '') === 'checkbox' && is_string($cur)) $cur = array_map('trim', explode(',', $cur));
      echo render_form_field($f, $i, $cur);
      if (($f['type'] ?? '') === 'file' && is_string($edit_data[$lb] ?? null) && $edit_data[$lb] !== '') {
        echo '<div class="current-file" style="margin-top:-8px;margin-bottom:12px;"><span class="material-symbols-rounded icon-sm">attach_file</span>' . e(mb_strimwidth($edit_data[$lb], 0, 50, '…')) . '<input type="hidden" name="keep_file[' . $i . ']" value="' . e($edit_data[$lb]) . '"></div>';
      }
    endforeach; ?>
    <div class="flex gap-2 items-center" style="flex-wrap:wrap;">
      <div><label>สถานะ</label>
        <select name="status"><option value="published" <?= ($edit['status'] ?? 'published') === 'published' ? 'selected' : '' ?>>เผยแพร่</option><option value="draft" <?= ($edit['status'] ?? '') === 'draft' ? 'selected' : '' ?>>ร่าง</option></select></div>
      <div><label>ลำดับ</label><input type="number" name="sort_order" value="<?= (int)($edit['sort_order'] ?? 0) ?>" class="sort-input"></div>
      <button class="btn primary" type="submit" style="margin-top:20px;"><span class="material-symbols-rounded icon-sm">save</span>บันทึก</button>
    </div>
  </form>
</div>

<?php else: ?>
<div class="card">
  <div class="section-head" style="margin-bottom:8px;">
    <div><span class="tag"><span class="material-symbols-rounded icon-sm"><?= e($type['icon'] ?: 'category') ?></span> <?= e(mb_strtoupper($type['slug'])) ?></span><h3><?= e($type['name_plural'] ?: $type['name']) ?> (<?= count($items) ?>)</h3>
    <p><a href="<?= e(url('content.php?type=' . $type['slug'])) ?>" target="_blank" rel="noopener">ดูหน้าเว็บ →</a> · <a href="<?= e(url('admin/content-types.php?edit=' . $type['id'])) ?>">แก้ไขช่องข้อมูล</a></p></div>
    <a class="btn primary" href="<?= e(url($base . '&new=1')) ?>"><span class="material-symbols-rounded icon-sm">add</span>เพิ่ม<?= e($type['name']) ?></a>
  </div>
  <?php if ($items): ?>
  <table class="admin-table">
    <tr><th>ลำดับ</th><?php if (!empty($type['has_image'])): ?><th>รูป</th><?php endif; ?><th>ชื่อ</th><th>สถานะ</th><th></th></tr>
    <?php foreach ($items as $it): ?>
    <tr>
      <td><?= (int)$it['sort_order'] ?></td>
      <?php if (!empty($type['has_image'])): ?><td><?php if ($it['image']): ?><img class="thumb-sm" src="<?= e(url($it['image'])) ?>" alt=""><?php else: ?><span class="text-muted" style="font-size:12px;">—</span><?php endif; ?></td><?php endif; ?>
      <td><?= e($it['title']) ?></td>
      <td><?= $it['status'] === 'published' ? '<span class="badge success" style="font-size:11px;">เผยแพร่</span>' : '<span class="badge" style="font-size:11px;">ร่าง</span>' ?></td>
      <td style="white-space:nowrap;">
        <a class="btn small" href="<?= e(url($base . '&edit=' . $it['id'])) ?>">แก้ไข</a>
        <form method="post" action="<?= e(url($base)) ?>" style="display:inline;"><?= csrf_field() ?><input type="hidden" name="delete_id" value="<?= (int)$it['id'] ?>"><button class="btn small danger" data-confirm="ลบรายการนี้?">ลบ</button></form>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php else: ?><p class="text-muted text-center" style="padding:28px;">ยังไม่มีรายการ — กด "เพิ่ม<?= e($type['name']) ?>"</p><?php endif; ?>
</div>
<?php endif; ?>
<?php require __DIR__ . '/_bottom.php'; ?>
