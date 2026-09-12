<?php
/** admin/alert.php — แถบประกาศด่วนบนสุดของเว็บ (ปิดหน่วยงาน/ภัยพิบัติ/ประกาศสำคัญ) */
require dirname(__DIR__) . '/includes/init.php';
require __DIR__ . '/_auth.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    setting_set('alert_enabled', !empty($_POST['alert_enabled']) ? '1' : '0');
    setting_set('alert_text', mb_substr(trim((string)($_POST['alert_text'] ?? '')), 0, 300));
    $lnk = trim((string)($_POST['alert_link'] ?? ''));
    setting_set('alert_link', ($lnk === '' || preg_match('#^(https?://|/|[a-z0-9_\-]+\.php)#i', $lnk)) ? mb_substr($lnk, 0, 500) : '');
    $style = in_array($_POST['alert_style'] ?? '', ['urgent', 'warning', 'info'], true) ? $_POST['alert_style'] : 'urgent';
    setting_set('alert_style', $style);
    log_action('ตั้งค่าแถบประกาศด่วน', $style);
    flash_set('success', 'บันทึกแถบประกาศด่วนแล้ว');
    redirect('admin/alert.php');
}

$enabled = setting('alert_enabled', '0') === '1';
$text    = setting('alert_text', '');
$link    = setting('alert_link', '');
$style   = setting('alert_style', 'urgent');
$admin_title = 'แถบประกาศด่วน';
require __DIR__ . '/_top.php';
?>
<div class="card mb-2">
  <div class="section-head" style="margin-bottom:8px;">
    <div><span class="tag"><span class="material-symbols-rounded icon-sm">campaign</span> ALERT</span><h3>แถบประกาศด่วน</h3>
    <p>แสดงแถบแจ้งเตือนเด่นบนสุดของทุกหน้า เช่น ปิดทำการ ภัยพิบัติ หรือประกาศเร่งด่วน</p></div>
  </div>
  <form method="post" action="">
    <?= csrf_field() ?>
    <label class="inline-check mb-2"><input type="checkbox" name="alert_enabled" value="1" <?= $enabled ? 'checked' : '' ?>><b>เปิดแสดงแถบประกาศด่วน</b></label>
    <label>ข้อความ</label>
    <input type="text" name="alert_text" value="<?= e($text) ?>" class="mb-2" maxlength="300" placeholder="เช่น วันที่ 15 มิ.ย. ปิดทำการเนื่องในวันหยุดราชการ">
    <div class="form-row">
      <div><label>ลิงก์ (ไม่บังคับ)</label>
        <input type="text" name="alert_link" value="<?= e($link) ?>" class="mb-2" placeholder="page.php?slug=... หรือ https://..."></div>
      <div><label>โทนสี</label>
        <select name="alert_style" class="mb-2" style="width:100%;">
          <option value="urgent" <?= $style === 'urgent' ? 'selected' : '' ?>>แดง — เร่งด่วน/อันตราย</option>
          <option value="warning" <?= $style === 'warning' ? 'selected' : '' ?>>ส้ม — แจ้งเตือน</option>
          <option value="info" <?= $style === 'info' ? 'selected' : '' ?>>น้ำเงิน — ข้อมูลทั่วไป</option>
        </select></div>
    </div>
    <button class="btn primary" type="submit"><span class="material-symbols-rounded icon-sm">save</span>บันทึก</button>
  </form>

  <?php if ($enabled && $text !== ''): ?>
  <div class="mt-2"><label>ตัวอย่าง</label>
    <div class="alertbar ab-<?= e($style) ?>" style="position:static;border-radius:10px;">
      <span class="material-symbols-rounded">campaign</span><span><?= e($text) ?></span>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/_bottom.php'; ?>
