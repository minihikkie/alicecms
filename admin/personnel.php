<?php
/** admin/personnel.php — จัดการโครงสร้างผู้บริหาร/บุคลากร */
require dirname(__DIR__) . '/includes/init.php';
require __DIR__ . '/_auth.php';

$errors = [];
$edit = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['delete_id'])) {
        $id = (int)$_POST['delete_id'];
        if (trash_delete('personnel', $id, $ADMIN['username'] ?? '')) {
            log_action('ลบบุคลากร (ลงถังขยะ)', '#' . $id);
            flash_set('success', 'ย้ายรายชื่อลงถังขยะแล้ว — กู้คืนได้ที่เมนู "ถังขยะ"');
        }
        redirect('admin/personnel.php');
    }

    $id       = (int)($_POST['id'] ?? 0);
    $name     = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 200);
    $position = mb_substr(trim((string)($_POST['position'] ?? '')), 0, 250);
    $level    = max(1, min(20, (int)($_POST['level'] ?? 1)));
    $phone    = mb_substr(trim((string)($_POST['phone'] ?? '')), 0, 50);
    $email    = mb_substr(trim((string)($_POST['email'] ?? '')), 0, 150);
    $sort     = (int)($_POST['sort_order'] ?? 0);
    $status   = ($_POST['status'] ?? '') === 'draft' ? 'draft' : 'published';

    if ($name === '') $errors[] = 'กรุณากรอกชื่อ';

    $photo = null;
    if (!$errors) {
        try { $photo = handle_upload('photo', 'personnel', upload_image_exts(), 5); }
        catch (RuntimeException $ex) { $errors[] = $ex->getMessage(); }
    }

    if (!$errors) {
        if ($id > 0) {
            $st = db()->prepare('SELECT photo FROM personnel WHERE id = ?');
            $st->execute([$id]);
            $old = $st->fetch();
            if ($photo && $old) delete_upload($old['photo']);
            if (!empty($_POST['remove_photo']) && $old) { delete_upload($old['photo']); }
            $ph = $photo['path'] ?? (!empty($_POST['remove_photo']) ? null : ($old['photo'] ?? null));
            db()->prepare('UPDATE personnel SET name=?, position=?, level=?, photo=?, phone=?, email=?, sort_order=?, status=? WHERE id=?')
                ->execute([$name, $position, $level, $ph, $phone, $email, $sort, $status, $id]);
            log_action('แก้ไขบุคลากร', $name);
            flash_set('success', 'บันทึกรายชื่อเรียบร้อยแล้ว');
        } else {
            db()->prepare('INSERT INTO personnel (name, position, level, photo, phone, email, sort_order, status) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([$name, $position, $level, $photo['path'] ?? null, $phone, $email, $sort, $status]);
            log_action('เพิ่มบุคลากร', $name);
            flash_set('success', 'เพิ่มรายชื่อเรียบร้อยแล้ว');
        }
        redirect('admin/personnel.php');
    }
}

if (isset($_GET['edit'])) {
    $st = db()->prepare('SELECT * FROM personnel WHERE id = ?');
    $st->execute([(int)$_GET['edit']]);
    $edit = $st->fetch();
}

$people = db()->query('SELECT * FROM personnel ORDER BY level ASC, sort_order ASC, id ASC')->fetchAll();
$admin_title = 'โครงสร้างผู้บริหาร';
require __DIR__ . '/_top.php';
?>
<?php if ($errors): ?>
<div class="alert danger"><span class="material-symbols-rounded">error</span>
  <div><?php foreach ($errors as $er): ?><div><?= e($er) ?></div><?php endforeach; ?></div>
</div>
<?php endif; ?>

<!-- สถานะการแสดงผล + ลิงก์ไปจัดการที่ส่วนกลาง -->
<div class="card mb-2">
  <div class="flex items-center gap-2" style="flex-wrap:wrap;">
    <span class="badge <?= section_in_menu('personnel') ? 'success' : '' ?>" style="white-space:nowrap;">
      <span class="material-symbols-rounded icon-sm"><?= section_in_menu('personnel') ? 'visibility' : 'visibility_off' ?></span>
      <?= section_in_menu('personnel') ? 'เมนู "ผู้บริหาร" กำลังแสดงบนเว็บ' : 'เมนู "ผู้บริหาร" ถูกซ่อนอยู่' ?>
    </span>
    <div class="text-muted" style="flex:1;min-width:160px;font-size:13px;">เข้าถึงผ่าน<b>เมนูด้านบน</b> ไม่แสดงบนหน้าแรก — เปิด/ปิดได้ที่หน้าการแสดงผลหน้าแรก</div>
    <a class="btn small" href="<?= e(url('admin/homepage.php')) ?>"><span class="material-symbols-rounded icon-sm">tune</span>เปิด/ปิดการแสดงผล</a>
    <?php if (section_in_menu('personnel')): ?><a class="btn small" href="<?= e(url('personnel.php')) ?>" target="_blank" rel="noopener"><span class="material-symbols-rounded icon-sm">open_in_new</span>ดูหน้าจริง</a><?php endif; ?>
  </div>
</div>

<div class="card mb-2">
  <div class="section-head" style="margin-bottom:8px;">
    <div><span class="tag">PERSON</span><h3><?= $edit ? 'แก้ไขรายชื่อ' : 'เพิ่มผู้บริหาร/บุคลากร' ?></h3>
    <p><b>ระดับชั้น</b> 1 = สูงสุด (อยู่บนสุดของผัง) ไล่ลง 2, 3, ... คนระดับเดียวกันเรียงด้วย "ลำดับ"</p></div>
    <?php if ($edit): ?><a class="btn small" href="<?= e(url('admin/personnel.php')) ?>">ยกเลิก</a><?php endif; ?>
  </div>
  <form method="post" action="" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
    <div class="form-row">
      <div>
        <label>ชื่อ-นามสกุล (พร้อมยศ/คำนำหน้า) <span style="color:var(--danger);">*</span></label>
        <input type="text" name="name" value="<?= e(old('name', $edit['name'] ?? '')) ?>" required class="mb-2" placeholder="เช่น พล.ต.ต.ตัวอย่าง นามสมมุติ">
        <label>ตำแหน่ง</label>
        <input type="text" name="position" value="<?= e(old('position', $edit['position'] ?? '')) ?>" class="mb-2" placeholder="เช่น ผู้บังคับการ...">
        <div class="form-row">
          <div><label>ระดับชั้น</label><input type="number" name="level" value="<?= (int)($edit['level'] ?? 1) ?>" min="1" max="20" class="mb-2"></div>
          <div><label>ลำดับ (ในระดับ)</label><input type="number" name="sort_order" value="<?= (int)($edit['sort_order'] ?? 0) ?>" class="mb-2"></div>
        </div>
        <div class="form-row">
          <div><label>โทรศัพท์</label><input type="text" name="phone" value="<?= e(old('phone', $edit['phone'] ?? '')) ?>" class="mb-2"></div>
          <div><label>อีเมล</label><input type="text" name="email" value="<?= e(old('email', $edit['email'] ?? '')) ?>" class="mb-2"></div>
        </div>
        <label>สถานะ</label>
        <select name="status" class="mb-2">
          <option value="published" <?= ($edit['status'] ?? 'published') === 'published' ? 'selected' : '' ?>>เผยแพร่</option>
          <option value="draft" <?= ($edit['status'] ?? '') === 'draft' ? 'selected' : '' ?>>ร่าง</option>
        </select>
      </div>
      <div>
        <label>รูปถ่าย <span class="text-muted">(แนะนำจัตุรัส เช่น 400×400)</span></label>
        <?php if (!empty($edit['photo'])): ?>
        <div class="current-file"><img src="<?= e(url($edit['photo'])) ?>" alt="">รูปปัจจุบัน
          <label class="inline-check" style="margin:0;"><input type="checkbox" name="remove_photo" value="1">ลบรูป</label></div>
        <?php endif; ?>
        <div class="dropzone" style="padding:28px;">
          <input type="file" name="photo" accept=".jpg,.jpeg,.png,.webp">
          <span class="material-symbols-rounded icon-lg" style="color:var(--blue)">add_photo_alternate</span>
          <p style="margin:4px 0 0;font-size:13px;">คลิกเลือกหรือลากรูปมาวาง</p>
          <div class="dz-filename"></div>
        </div>
      </div>
    </div>
    <button class="btn primary mt-2" type="submit"><span class="material-symbols-rounded icon-sm">save</span><?= $edit ? 'บันทึกการแก้ไข' : 'เพิ่มรายชื่อ' ?></button>
  </form>
</div>

<div class="card">
  <div class="section-head" style="margin-bottom:8px;">
    <div><span class="tag">ALL</span><h3>รายชื่อทั้งหมด (<?= count($people) ?>)</h3></div>
  </div>
  <?php if ($people): ?>
  <table class="admin-table">
    <tr><th>ระดับ</th><th>รูป</th><th>ชื่อ</th><th>ตำแหน่ง</th><th>สถานะ</th><th></th></tr>
    <?php foreach ($people as $p): ?>
    <tr>
      <td><span class="badge" style="font-size:11px;">ระดับ <?= (int)$p['level'] ?></span></td>
      <td><?php if ($p['photo']): ?><img class="thumb-sm" style="width:38px;height:38px;border-radius:50%;object-fit:cover;" src="<?= e(url($p['photo'])) ?>" alt=""><?php else: ?><span class="material-symbols-rounded" style="color:var(--muted);">person</span><?php endif; ?></td>
      <td><?= e($p['name']) ?></td>
      <td class="lr-date" style="max-width:220px;"><?= e(mb_strimwidth((string)$p['position'], 0, 50, '…')) ?></td>
      <td><?= $p['status'] === 'published' ? '<span class="badge success" style="font-size:11px;">เผยแพร่</span>' : '<span class="badge" style="font-size:11px;">ร่าง</span>' ?></td>
      <td style="white-space:nowrap;">
        <a class="btn small" href="<?= e(url('admin/personnel.php?edit=' . $p['id'])) ?>">แก้ไข</a>
        <form method="post" action="" style="display:inline;">
          <?= csrf_field() ?>
          <input type="hidden" name="delete_id" value="<?= (int)$p['id'] ?>">
          <button class="btn small danger" type="submit" data-confirm="ยืนยันลบรายชื่อนี้?">ลบ</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php else: ?>
  <p class="text-muted text-center" style="padding:24px;">ยังไม่มีรายชื่อ</p>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/_bottom.php'; ?>
