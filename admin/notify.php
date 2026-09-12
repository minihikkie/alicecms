<?php
/** admin/notify.php — ตั้งค่าแจ้งเตือนอีเมล (SMTP) + ทดสอบส่ง (เฉพาะ admin) */
require dirname(__DIR__) . '/includes/init.php';
require __DIR__ . '/_auth.php';
require_admin();
require_once APP_ROOT . '/includes/mailer.php';

$test_result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fields = [
        'notify_email' => 150, 'smtp_host' => 150, 'smtp_port' => 6,
        'smtp_user' => 150, 'smtp_from' => 150, 'smtp_from_name' => 150,
    ];
    foreach ($fields as $f => $max) {
        if (isset($_POST[$f])) setting_set($f, mb_substr(trim((string)$_POST[$f]), 0, $max));
    }
    setting_set('smtp_secure', in_array($_POST['smtp_secure'] ?? '', ['none','tls','ssl'], true) ? $_POST['smtp_secure'] : 'tls');
    setting_set('notify_enabled', !empty($_POST['notify_enabled']) ? '1' : '0');
    /* รหัสผ่าน SMTP — อัปเดตเฉพาะเมื่อกรอกใหม่ (เว้นว่าง = คงเดิม) */
    if (isset($_POST['smtp_pass']) && $_POST['smtp_pass'] !== '') {
        setting_set('smtp_pass', (string)$_POST['smtp_pass']);
    }
    log_action('แก้ไขตั้งค่าแจ้งเตือนอีเมล', '');

    /* ปุ่มทดสอบส่ง */
    if (isset($_POST['test_send'])) {
        $to = setting('notify_email');
        if ($to === '') {
            $test_result = ['ok' => false, 'msg' => 'กรุณากรอกอีเมลผู้รับก่อนทดสอบ'];
        } else {
            $err = '';
            $ok = send_mail($to, '[ทดสอบ] แจ้งเตือนจาก ' . setting('site_name', 'เว็บไซต์'),
                "นี่คืออีเมลทดสอบจากระบบเว็บไซต์ของท่าน\nหากได้รับอีเมลนี้ แสดงว่าการตั้งค่า SMTP ถูกต้อง", $err);
            $test_result = ['ok' => $ok, 'msg' => $ok ? 'ส่งอีเมลทดสอบสำเร็จ — ตรวจกล่องจดหมาย ' . e($to) : 'ส่งไม่สำเร็จ: ' . e($err)];
        }
    } else {
        flash_set('success', 'บันทึกการตั้งค่าแจ้งเตือนเรียบร้อยแล้ว');
        redirect('admin/notify.php');
    }
}

$admin_title = 'แจ้งเตือนอีเมล';
require __DIR__ . '/_top.php';
?>
<?php if ($test_result): ?>
<div class="alert <?= $test_result['ok'] ? 'success' : 'danger' ?>">
  <span class="material-symbols-rounded"><?= $test_result['ok'] ? 'check_circle' : 'error' ?></span>
  <div><?= $test_result['msg'] ?></div>
</div>
<?php endif; ?>

<form method="post" action="">
  <?= csrf_field() ?>
  <div class="card mb-2">
    <div class="section-head" style="margin-bottom:8px;">
      <div><span class="tag"><span class="material-symbols-rounded icon-sm">mark_email_unread</span> NOTIFY</span><h3>แจ้งเตือนอีเมลเมื่อมีเรื่องร้องเรียนใหม่</h3>
      <p>ส่งอีเมลถึงเจ้าหน้าที่อัตโนมัติเมื่อมีประชาชนส่งเรื่องร้องเรียนเข้ามา</p></div>
    </div>
    <label class="inline-check mb-2">
      <input type="checkbox" name="notify_enabled" value="1" <?= setting('notify_enabled', '0') === '1' ? 'checked' : '' ?>>
      <span>เปิดใช้การแจ้งเตือนอีเมล</span>
    </label>
    <label>อีเมลผู้รับแจ้งเตือน</label>
    <input type="email" name="notify_email" value="<?= e(setting('notify_email')) ?>" class="mb-2" placeholder="saraban@example.go.th">
  </div>

  <div class="card mb-2">
    <div class="section-head" style="margin-bottom:8px;">
      <div><span class="tag">SMTP</span><h3>ตั้งค่าเซิร์ฟเวอร์ส่งอีเมล (SMTP)</h3>
      <p>ใช้บัญชีอีเมลของหน่วยงานหรือ Gmail (ต้องสร้าง App Password) — เซิร์ฟเวอร์ส่วนใหญ่ไม่มีระบบส่งเมลในตัว จึงต้องตั้ง SMTP</p></div>
    </div>
    <div class="form-row">
      <div><label>SMTP Host</label><input type="text" name="smtp_host" value="<?= e(setting('smtp_host')) ?>" class="mb-2" placeholder="เช่น smtp.gmail.com"></div>
      <div><label>Port</label><input type="text" name="smtp_port" value="<?= e(setting('smtp_port', '587')) ?>" class="mb-2" placeholder="587 (TLS) / 465 (SSL)"></div>
    </div>
    <div class="form-row">
      <div><label>การเข้ารหัส</label>
        <select name="smtp_secure" class="mb-2">
          <option value="tls" <?= setting('smtp_secure','tls')==='tls'?'selected':'' ?>>STARTTLS (พอร์ต 587)</option>
          <option value="ssl" <?= setting('smtp_secure','tls')==='ssl'?'selected':'' ?>>SSL/TLS (พอร์ต 465)</option>
          <option value="none" <?= setting('smtp_secure','tls')==='none'?'selected':'' ?>>ไม่เข้ารหัส</option>
        </select>
      </div>
      <div><label>อีเมลผู้ส่ง (From)</label><input type="text" name="smtp_from" value="<?= e(setting('smtp_from')) ?>" class="mb-2" placeholder="noreply@example.go.th"></div>
    </div>
    <div class="form-row">
      <div><label>ชื่อผู้ส่ง</label><input type="text" name="smtp_from_name" value="<?= e(setting('smtp_from_name', setting('site_name'))) ?>" class="mb-2"></div>
      <div><label>SMTP Username</label><input type="text" name="smtp_user" value="<?= e(setting('smtp_user')) ?>" class="mb-2" autocomplete="off"></div>
    </div>
    <label>SMTP Password <span class="text-muted">(เว้นว่าง = ไม่เปลี่ยน<?= setting('smtp_pass') ? ' · ตั้งค่าไว้แล้ว' : '' ?>)</span></label>
    <input type="password" name="smtp_pass" value="" class="mb-2" autocomplete="new-password" placeholder="<?= setting('smtp_pass') ? '••••••••' : 'รหัสผ่าน / App Password' ?>">
  </div>

  <div class="flex gap-1">
    <button class="btn primary large" type="submit"><span class="material-symbols-rounded">save</span>บันทึกการตั้งค่า</button>
    <button class="btn large" type="submit" name="test_send" value="1"><span class="material-symbols-rounded">send</span>บันทึก + ส่งอีเมลทดสอบ</button>
  </div>
</form>
<?php require __DIR__ . '/_bottom.php'; ?>
