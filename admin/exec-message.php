<?php
/** admin/exec-message.php — สารจากหัวหน้าหน่วยงาน (ชื่อ/ตำแหน่ง/quote/ข้อความ/รูป)
 * เดิมชื่อ admin/chief.php — เปลี่ยนเพราะโฮสต์บางแห่งมี WAF (เช่น Imunify360) บล็อกไฟล์นี้เอง
 * ด้วย Error ID แบบเดียวกับที่เคยเจอกับ admin/settings.php (ดู $retired ใน migrations.php) */
require dirname(__DIR__) . '/includes/init.php';
require __DIR__ . '/_auth.php';

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $photo = handle_upload('photo', 'site', upload_image_exts(), 8);
        if ($photo) {
            delete_upload(setting('chief_photo'));
            setting_set('chief_photo', $photo['path']);
        }
        if (!empty($_POST['remove_photo'])) {
            delete_upload(setting('chief_photo'));
            setting_set('chief_photo', '');
        }
        setting_set('chief_name',     mb_substr(trim((string)($_POST['chief_name'] ?? '')), 0, 200));
        setting_set('chief_position', mb_substr(trim((string)($_POST['chief_position'] ?? '')), 0, 200));
        setting_set('chief_badge',    mb_substr(trim((string)($_POST['chief_badge'] ?? '')), 0, 100));
        setting_set('chief_quote',    mb_substr(trim((string)($_POST['chief_quote'] ?? '')), 0, 500));
        setting_set('chief_message',  trim((string)($_POST['chief_message'] ?? '')));

        /* ชื่อหัวข้อ section (เช่น "สารจากผู้อำนวยการ") */
        $st = db()->prepare('UPDATE sections SET custom_title = ? WHERE skey = ?');
        $st->execute([mb_substr(trim((string)($_POST['section_title'] ?? '')), 0, 150), 'chief']);

        flash_set('success', 'บันทึกข้อมูลเรียบร้อยแล้ว');
        redirect('admin/exec-message.php');
    } catch (RuntimeException $ex) {
        $errors[] = $ex->getMessage();
    }
}

$admin_title = 'สารหัวหน้าหน่วยงาน';
require __DIR__ . '/_top.php';
?>
<div class="card">
  <div class="section-head" style="margin-bottom:8px;">
    <div><span class="tag">CHIEF MESSAGE</span><h3>สารจากหัวหน้าหน่วยงาน</h3>
    <p>แสดงบนหน้าแรก — เปลี่ยนชื่อหัวข้อได้ตามตำแหน่งจริง เช่น "สารจากผู้บังคับการ" / "สารจากนายกเทศมนตรี"</p></div>
  </div>

  <?php if ($errors): ?>
  <div class="alert danger"><span class="material-symbols-rounded">error</span>
    <div><?php foreach ($errors as $er): ?><div><?= e($er) ?></div><?php endforeach; ?></div>
  </div>
  <?php endif; ?>

  <form method="post" action="" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <div class="form-row">
      <div>
        <label>ชื่อหัวข้อ section</label>
        <input type="text" name="section_title" value="<?= e(section_title('chief')) ?>" placeholder="เช่น สารจากผู้อำนวยการ" class="mb-2">
        <label>ชื่อ-นามสกุล (พร้อมยศ/คำนำหน้า)</label>
        <input type="text" name="chief_name" value="<?= e(setting('chief_name')) ?>" placeholder="เช่น นายตัวอย่าง นามสมมุติ" class="mb-2">
        <label>ตำแหน่ง</label>
        <input type="text" name="chief_position" value="<?= e(setting('chief_position')) ?>" placeholder="เช่น ผู้อำนวยการกอง..." class="mb-2">
        <label>ป้ายใต้รูป (badge)</label>
        <input type="text" name="chief_badge" value="<?= e(setting('chief_badge')) ?>" placeholder="เช่น ผู้อำนวยการ" class="mb-2">
      </div>
      <div>
        <label>รูปถ่าย <span class="text-muted">(แนะนำแนวตั้ง 420×500)</span></label>
        <?php if (setting('chief_photo')): ?>
        <div class="current-file">
          <img src="<?= e(url(setting('chief_photo'))) ?>" alt="">รูปปัจจุบัน
          <label class="inline-check" style="margin:0;"><input type="checkbox" name="remove_photo" value="1">ลบรูป</label>
        </div>
        <?php endif; ?>
        <div class="dropzone mb-2" style="padding:24px;">
          <input type="file" name="photo" accept=".jpg,.jpeg,.png,.webp">
          <span class="material-symbols-rounded icon-lg" style="color:var(--blue)">add_photo_alternate</span>
          <p style="margin:4px 0 0;font-size:13px;"><b>ลากรูปมาวาง</b> หรือคลิกเลือก</p>
          <div class="dz-filename"></div>
        </div>
      </div>
    </div>

    <label>คำขวัญ / Quote เด่น</label>
    <input type="text" name="chief_quote" value="<?= e(setting('chief_quote')) ?>" placeholder='เช่น มุ่งมั่นพัฒนางานอย่างมืออาชีพ โปร่งใส ยึดประชาชนเป็นศูนย์กลาง' class="mb-2">

    <label>ข้อความสาร</label>
    <textarea name="chief_message" rows="6" class="mb-2" placeholder="ข้อความต้อนรับ/สารถึงประชาชน..."><?= e(setting('chief_message')) ?></textarea>

    <div class="alert info" style="font-size:13px;">
      <span class="material-symbols-rounded">info</span>
      <div>หากไม่ต้องการแสดง section นี้บนหน้าแรก ปิดได้ที่เมนู <b>ตั้งค่าเว็บไซต์ → จัดการ section หน้าแรก</b></div>
    </div>

    <button class="btn primary large" type="submit"><span class="material-symbols-rounded">save</span>บันทึกข้อมูล</button>
  </form>
</div>
<?php require __DIR__ . '/_bottom.php'; ?>
