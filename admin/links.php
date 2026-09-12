<?php
/** admin/links.php — ลิงก์หน่วยงานที่เกี่ยวข้อง (เพิ่ม/ลบ/เรียง) */
require dirname(__DIR__) . '/includes/init.php';
require __DIR__ . '/_auth.php';

$errors = [];
$edit = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['delete_id'])) {
        $id = (int)$_POST['delete_id'];
        if (trash_delete('links', $id, $ADMIN['username'] ?? '')) {
            log_action('ลบลิงก์ (ลงถังขยะ)', '#' . $id);
            flash_set('success', 'ย้ายลิงก์ลงถังขยะแล้ว — กู้คืนได้ที่เมนู "ถังขยะ"');
        }
        redirect('admin/links.php');
    }

    $id       = (int)($_POST['id'] ?? 0);
    $title    = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 200);
    $subtitle = mb_substr(trim((string)($_POST['subtitle'] ?? '')), 0, 200);
    $url_     = safe_link_url((string)($_POST['url'] ?? ''));
    $icon     = preg_replace('/[^a-z0-9_]/', '', (string)($_POST['icon'] ?? 'link'));
    $style_in = (string)($_POST['style'] ?? '');
    $style    = in_array($style_in, ['', 'special', 'warning'], true) ? $style_in : '';
    $sort     = (int)($_POST['sort_order'] ?? 0);

    if ($title === '') $errors[] = 'กรุณากรอกชื่อลิงก์';
    if ($url_ === '' || !preg_match('#^https?://#', $url_)) $errors[] = 'กรุณากรอก URL ขึ้นต้นด้วย http:// หรือ https://';

    $image = null;
    if (!$errors) {
        try { $image = handle_upload('image', 'links', upload_image_exts(), 4); }
        catch (RuntimeException $ex) { $errors[] = $ex->getMessage(); }
    }

    if (!$errors) {
        if ($id > 0) {
            $st = db()->prepare('SELECT image FROM links WHERE id = ?');
            $st->execute([$id]);
            $old = $st->fetch();
            if ($image && $old) delete_upload($old['image']);
            if (!empty($_POST['remove_image']) && $old) { delete_upload($old['image']); }
            $img = $image['path'] ?? (!empty($_POST['remove_image']) ? null : ($old['image'] ?? null));
            db()->prepare('UPDATE links SET title=?, subtitle=?, url=?, icon=?, image=?, style=?, sort_order=? WHERE id=?')
                ->execute([$title, $subtitle, $url_, $icon, $img, $style, $sort, $id]);
        } else {
            db()->prepare('INSERT INTO links (title, subtitle, url, icon, image, style, sort_order) VALUES (?,?,?,?,?,?,?)')
                ->execute([$title, $subtitle, $url_, $icon, $image['path'] ?? null, $style, $sort]);
        }
        flash_set('success', 'บันทึกลิงก์เรียบร้อยแล้ว');
        redirect('admin/links.php');
    }
}

if (isset($_GET['edit'])) {
    $st = db()->prepare('SELECT * FROM links WHERE id = ?');
    $st->execute([(int)$_GET['edit']]);
    $edit = $st->fetch();
}

$links = db()->query('SELECT * FROM links ORDER BY sort_order ASC, id ASC')->fetchAll();
$admin_title = 'ลิงก์ที่เกี่ยวข้อง';
require __DIR__ . '/_top.php';
?>
<?php if ($errors): ?>
<div class="alert danger"><span class="material-symbols-rounded">error</span>
  <div><?php foreach ($errors as $er): ?><div><?= e($er) ?></div><?php endforeach; ?></div>
</div>
<?php endif; ?>

<div class="card mb-2">
  <div class="section-head" style="margin-bottom:8px;">
    <div><span class="tag">LINK</span><h3><?= $edit ? 'แก้ไขลิงก์' : 'เพิ่มลิงก์ใหม่' ?></h3>
    <p>อัปโหลดโลโก้หน่วยงานได้ — ถ้าไม่อัปจะใช้ไอคอน Material Symbols แทน (เช่น account_balance, local_police, gavel)</p></div>
    <?php if ($edit): ?><a class="btn small" href="<?= e(url('admin/links.php')) ?>">ยกเลิก</a><?php endif; ?>
  </div>
  <form method="post" action="" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
    <div class="form-row">
      <div><label>ชื่อหน่วยงาน/บริการ <span style="color:var(--danger);">*</span></label>
        <input type="text" name="title" value="<?= e(old('title', $edit['title'] ?? '')) ?>" required class="mb-2"></div>
      <div><label>คำอธิบายสั้น</label>
        <input type="text" name="subtitle" value="<?= e(old('subtitle', $edit['subtitle'] ?? '')) ?>" placeholder="เช่น ชื่อเว็บไซต์" class="mb-2"></div>
    </div>
    <label>URL <span style="color:var(--danger);">*</span></label>
    <input type="text" name="url" value="<?= e(old('url', $edit['url'] ?? '')) ?>" required placeholder="https://..." class="mb-2">
    <label>โลโก้หน่วยงาน <span class="text-muted">(ไม่บังคับ — JPG, PNG, WebP ≤ 4 MB)</span></label>
    <?php if (!empty($edit['image'])): ?>
    <div class="current-file"><img src="<?= e(url($edit['image'])) ?>" alt="">โลโก้ปัจจุบัน
      <label class="inline-check" style="margin:0;"><input type="checkbox" name="remove_image" value="1">ลบโลโก้</label></div>
    <?php endif; ?>
    <div class="dropzone mb-2" style="padding:16px;">
      <input type="file" name="image" accept=".jpg,.jpeg,.png,.webp">
      <span class="material-symbols-rounded icon-lg" style="color:var(--blue)">add_photo_alternate</span>
      <p style="margin:4px 0 0;font-size:13px;">คลิกเลือกหรือลากโลโก้มาวาง</p>
      <div class="dz-filename"></div>
    </div>
    <div class="flex gap-2 items-center" style="flex-wrap:wrap;">
      <div><label>ไอคอน (เมื่อไม่มีโลโก้)</label>
        <div class="icon-pick-wrap">
          <span class="material-symbols-rounded icon-pick-preview" data-icon-preview><?= e($edit['icon'] ?? 'link') ?></span>
          <input type="text" name="icon" value="<?= e($edit['icon'] ?? 'link') ?>" data-icon-input data-icon-default="link" style="width:160px;" autocomplete="off">
        </div></div>
      <div><label>สี</label>
        <select name="style" style="width:120px;">
          <option value="" <?= ($edit['style'] ?? '') === '' ? 'selected' : '' ?>>น้ำเงิน</option>
          <option value="special" <?= ($edit['style'] ?? '') === 'special' ? 'selected' : '' ?>>ม่วง</option>
          <option value="warning" <?= ($edit['style'] ?? '') === 'warning' ? 'selected' : '' ?>>ส้ม</option>
        </select>
      </div>
      <div><label>ลำดับ</label><input type="number" name="sort_order" value="<?= (int)($edit['sort_order'] ?? (count($links) + 1)) ?>" class="sort-input"></div>
      <button class="btn primary" type="submit" style="margin-top:20px;"><span class="material-symbols-rounded icon-sm">save</span><?= $edit ? 'บันทึก' : 'เพิ่มลิงก์' ?></button>
    </div>
  </form>
</div>

<div class="card">
  <div class="section-head" style="margin-bottom:8px;">
    <div><span class="tag">ALL LINKS</span><h3>ลิงก์ทั้งหมด (<?= count($links) ?>)</h3></div>
  </div>
  <?php if ($links): ?>
  <table class="admin-table">
    <tr><th>ลำดับ</th><th>ชื่อ</th><th>URL</th><th></th></tr>
    <?php foreach ($links as $l): ?>
    <tr>
      <td><?= (int)$l['sort_order'] ?></td>
      <td><?php if (!empty($l['image'])): ?><img src="<?= e(url($l['image'])) ?>" alt="" style="width:24px;height:24px;object-fit:contain;vertical-align:-7px;border-radius:4px;"> <?php else: ?><span class="material-symbols-rounded icon-sm" style="color:var(--blue);vertical-align:-4px;"><?= e($l['icon'] ?: 'link') ?></span> <?php endif; ?><?= e($l['title']) ?></td>
      <td class="lr-date" style="max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= e($l['url']) ?></td>
      <td style="white-space:nowrap;">
        <a class="btn small" href="<?= e(url('admin/links.php?edit=' . $l['id'])) ?>">แก้ไข</a>
        <form method="post" action="" style="display:inline;">
          <?= csrf_field() ?>
          <input type="hidden" name="delete_id" value="<?= (int)$l['id'] ?>">
          <button class="btn small danger" type="submit" data-confirm="ยืนยันลบลิงก์นี้?">ลบ</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php else: ?>
  <p class="text-muted text-center" style="padding:24px;">ยังไม่มีลิงก์</p>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/_bottom.php'; ?>
