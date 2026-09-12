<?php
/** admin/twofa.php — ยืนยันตัวตนสองชั้น (2FA/TOTP) ต่อผู้ใช้ — เปิด/ปิดเอง (สมัครใจ) */
require dirname(__DIR__) . '/includes/init.php';
require __DIR__ . '/_auth.php';
require dirname(__DIR__) . '/includes/totp.php';

$uid = (int)$ADMIN['id'];
$errors = [];
$new_backup = null;   /* รหัสสำรองที่เพิ่งสร้าง (โชว์ครั้งเดียว) */

/* โหลดสถานะปัจจุบันของผู้ใช้ */
function twofa_user(int $uid): array {
    $st = db()->prepare('SELECT username, totp_secret, totp_enabled, backup_codes FROM users WHERE id = ?');
    $st->execute([$uid]);
    return $st->fetch() ?: [];
}
$me = twofa_user($uid);

$action = $_POST['action'] ?? '';

/* ── เริ่มตั้งค่า: สร้าง secret ชั่วคราวเก็บใน session (ยังไม่บันทึกจนกว่าจะยืนยันสำเร็จ) ── */
if ($action === 'begin' && !(int)$me['totp_enabled']) {
    $_SESSION['2fa_setup_secret'] = totp_random_secret();
}

/* ── ยกเลิกการตั้งค่า ── */
if ($action === 'cancel_setup') {
    unset($_SESSION['2fa_setup_secret']);
    redirect('admin/twofa.php');
}

/* ── ยืนยันรหัสเพื่อเปิดใช้งาน ── */
if ($action === 'activate' && !empty($_SESSION['2fa_setup_secret'])) {
    $secret = (string)$_SESSION['2fa_setup_secret'];
    $code = trim((string)($_POST['code'] ?? ''));
    if (!totp_verify($secret, $code)) {
        $errors[] = 'รหัสยืนยันไม่ถูกต้อง — ตรวจว่าเวลาบนมือถือตรง และลองใหม่';
    } else {
        $bc = totp_generate_backup_codes(10);
        db()->prepare('UPDATE users SET totp_secret = ?, totp_enabled = 1, backup_codes = ? WHERE id = ?')
            ->execute([$secret, json_encode($bc['hashes']), $uid]);
        unset($_SESSION['2fa_setup_secret']);
        log_action('เปิดใช้งาน 2FA', '');
        $new_backup = $bc['plain'];
        $me = twofa_user($uid);
    }
}

/* ── ปิดใช้งาน (ต้องยืนยันด้วยรหัสผ่าน หรือรหัส 2FA ปัจจุบัน) ── */
if ($action === 'disable' && (int)$me['totp_enabled']) {
    if (!twofa_reauth_ok($uid, $me, (string)($_POST['confirm'] ?? ''))) {
        $errors[] = 'ยืนยันตัวตนไม่ผ่าน — กรอกรหัสผ่าน หรือรหัส 6 หลักจากแอป';
    } else {
        db()->prepare("UPDATE users SET totp_secret = '', totp_enabled = 0, backup_codes = NULL WHERE id = ?")
            ->execute([$uid]);
        log_action('ปิดใช้งาน 2FA', '');
        flash_set('success', 'ปิดการยืนยันสองชั้นแล้ว');
        redirect('admin/twofa.php');
    }
}

/* ── สร้างรหัสสำรองชุดใหม่ (ของเดิมใช้ไม่ได้) ── */
if ($action === 'regen' && (int)$me['totp_enabled']) {
    if (!twofa_reauth_ok($uid, $me, (string)($_POST['confirm'] ?? ''))) {
        $errors[] = 'ยืนยันตัวตนไม่ผ่าน — กรอกรหัสผ่าน หรือรหัส 6 หลักจากแอป';
    } else {
        $bc = totp_generate_backup_codes(10);
        db()->prepare('UPDATE users SET backup_codes = ? WHERE id = ?')->execute([json_encode($bc['hashes']), $uid]);
        log_action('สร้างรหัสสำรอง 2FA ใหม่', '');
        $new_backup = $bc['plain'];
        $me = twofa_user($uid);
    }
}

/** ยืนยันตัวตนซ้ำก่อนเปลี่ยนแปลงความปลอดภัย: รับได้ทั้งรหัสผ่าน หรือรหัส TOTP ปัจจุบัน */
function twofa_reauth_ok(int $uid, array $me, string $input): bool {
    if ($input === '') return false;
    if ($me['totp_secret'] && totp_verify($me['totp_secret'], $input)) return true;
    $st = db()->prepare('SELECT password_hash FROM users WHERE id = ?');
    $st->execute([$uid]);
    $h = (string)$st->fetchColumn();
    return $h !== '' && password_verify($input, $h);
}

$enabled     = (int)($me['totp_enabled'] ?? 0) === 1;
$in_setup    = !$enabled && !empty($_SESSION['2fa_setup_secret']);
$backup_left = $enabled ? count(json_decode((string)($me['backup_codes'] ?? ''), true) ?: []) : 0;
$issuer      = setting('site_name', 'ระบบจัดการเว็บไซต์');
$admin_title = 'ยืนยันตัวตน 2 ชั้น';
require __DIR__ . '/_top.php';
?>

<?php if ($errors): ?>
<div class="alert danger"><span class="material-symbols-rounded">error</span>
  <div><?php foreach ($errors as $er): ?><div><?= e($er) ?></div><?php endforeach; ?></div>
</div>
<?php endif; ?>

<?php if ($new_backup): /* ───── โชว์รหัสสำรองครั้งเดียว ───── */ ?>
<div class="card mb-2">
  <div class="section-head" style="margin-bottom:8px;">
    <div><span class="tag"><span class="material-symbols-rounded icon-sm">key</span> รหัสสำรอง</span><h3>เก็บรหัสสำรองเหล่านี้ให้ปลอดภัย</h3>
    <p>ใช้แทนรหัสจากแอปได้กรณีทำมือถือหาย — <b>แต่ละรหัสใช้ได้ครั้งเดียว</b> และจะไม่แสดงอีก</p></div>
  </div>
  <div class="alert danger" style="font-size:13px;"><span class="material-symbols-rounded">warning</span>
    <div>บันทึก/พิมพ์เก็บไว้ตอนนี้เลย — ปิดหน้านี้แล้วจะดูซ้ำไม่ได้ (ต้องสร้างชุดใหม่)</div></div>
  <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:8px;max-width:420px;margin:10px 0;font-family:monospace;font-size:16px;letter-spacing:.05em;">
    <?php foreach ($new_backup as $c): ?>
    <div style="background:var(--surface,#f5f7fb);border:1px solid var(--border);border-radius:8px;padding:8px 12px;text-align:center;"><?= e($c) ?></div>
    <?php endforeach; ?>
  </div>
  <a class="btn primary" href="<?= e(url('admin/twofa.php')) ?>"><span class="material-symbols-rounded icon-sm">check</span>บันทึกแล้ว เสร็จสิ้น</a>
</div>

<?php elseif ($in_setup): /* ───── ขั้นตั้งค่า: ใส่คีย์ในแอป + ยืนยันรหัส ───── */
  $secret = (string)$_SESSION['2fa_setup_secret'];
?>
<div class="card mb-2">
  <div class="section-head" style="margin-bottom:8px;">
    <div><span class="tag"><span class="material-symbols-rounded icon-sm">phonelink_lock</span> ตั้งค่า 2FA</span><h3>เชื่อมต่อแอป Authenticator</h3>
    <p>ใช้แอป <b>Google Authenticator</b> / <b>Microsoft Authenticator</b> / <b>Authy</b> (ดาวน์โหลดฟรี)</p></div>
  </div>

  <ol style="font-size:14px;line-height:2;padding-left:20px;margin:0 0 12px;">
    <li>เปิดแอป Authenticator → กด <b>เพิ่มบัญชี (+)</b> → เลือก <b>“ป้อนคีย์การตั้งค่า / Enter a setup key”</b></li>
    <li>ตั้งชื่อบัญชี เช่น <code><?= e($issuer . ' (' . $me['username'] . ')') ?></code></li>
    <li>ใส่คีย์ด้านล่างนี้ แล้วเลือกประเภท <b>ตามเวลา (Time based)</b></li>
  </ol>

  <label style="font-weight:600;">คีย์ตั้งค่า (Setup key)</label>
  <div class="flex items-center gap-1 mb-2" style="flex-wrap:wrap;">
    <code id="totpKey" style="font-size:17px;letter-spacing:.12em;background:var(--surface,#f5f7fb);border:1px solid var(--border);border-radius:8px;padding:10px 14px;user-select:all;"><?= e(totp_format_secret($secret)) ?></code>
    <button type="button" class="btn small" data-copy="<?= e($secret) ?>"><span class="material-symbols-rounded icon-sm">content_copy</span>คัดลอกคีย์</button>
  </div>

  <form method="post" action="" class="mt-2" style="max-width:340px;">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="activate">
    <label for="code">กรอกรหัส 6 หลักที่แอปแสดง เพื่อยืนยัน</label>
    <input type="text" id="code" name="code" required autofocus inputmode="numeric" maxlength="6" pattern="[0-9]*"
           placeholder="123456" class="mb-2" style="letter-spacing:.3em;text-align:center;font-size:20px;">
    <button class="btn primary" type="submit"><span class="material-symbols-rounded icon-sm">verified_user</span>ยืนยันและเปิดใช้งาน</button>
    <a class="btn" href="<?= e(url('admin/twofa.php?')) ?>" onclick="event.preventDefault();document.getElementById('cancelSetup').submit();">ยกเลิก</a>
  </form>
  <form id="cancelSetup" method="post" action="" style="display:none;"><?= csrf_field() ?><input type="hidden" name="action" value="cancel_setup"></form>
</div>

<?php elseif ($enabled): /* ───── เปิดใช้งานอยู่ ───── */ ?>
<div class="card mb-2">
  <div class="section-head" style="margin-bottom:8px;">
    <div><span class="tag"><span class="material-symbols-rounded icon-sm">verified_user</span> 2FA</span><h3>ยืนยันตัวตนสองชั้น</h3></div>
    <span class="badge success"><span class="material-symbols-rounded icon-sm">check_circle</span>เปิดใช้งานอยู่</span>
  </div>
  <p class="text-muted" style="font-size:13.5px;">บัญชี <b style="color:var(--ink);"><?= e($me['username']) ?></b> ต้องกรอกรหัสจากแอปทุกครั้งที่เข้าสู่ระบบ
    &nbsp;·&nbsp; รหัสสำรองเหลือ <b style="color:var(--ink);"><?= $backup_left ?></b> ชุด</p>

  <div class="grid grid-2 mt-1" style="max-width:640px;">
    <form method="post" action="">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="regen">
      <div class="slide-fieldset" style="height:100%;">
        <div class="sf-head"><span class="material-symbols-rounded icon-sm">key</span>สร้างรหัสสำรองชุดใหม่</div>
        <p class="text-muted" style="font-size:12.5px;">ของเดิมจะใช้ไม่ได้ทันที</p>
        <input type="text" name="confirm" placeholder="รหัสผ่าน หรือรหัส 6 หลัก" class="mb-2" autocomplete="off">
        <button class="btn small" type="submit">สร้างใหม่</button>
      </div>
    </form>
    <form method="post" action="">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="disable">
      <div class="slide-fieldset" style="height:100%;">
        <div class="sf-head" style="color:var(--danger);"><span class="material-symbols-rounded icon-sm">gpp_bad</span>ปิดใช้งาน 2FA</div>
        <p class="text-muted" style="font-size:12.5px;">บัญชีจะปลอดภัยน้อยลง</p>
        <input type="text" name="confirm" placeholder="รหัสผ่าน หรือรหัส 6 หลัก" class="mb-2" autocomplete="off">
        <button class="btn small danger" type="submit" data-confirm="ยืนยันปิดการยืนยันสองชั้น?">ปิดใช้งาน</button>
      </div>
    </form>
  </div>
</div>

<?php else: /* ───── ปิดอยู่ — เชิญเปิด ───── */ ?>
<div class="card mb-2">
  <div class="section-head" style="margin-bottom:8px;">
    <div><span class="tag"><span class="material-symbols-rounded icon-sm">phonelink_lock</span> 2FA</span><h3>ยืนยันตัวตนสองชั้น</h3></div>
    <span class="badge"><span class="material-symbols-rounded icon-sm">gpp_maybe</span>ยังไม่เปิดใช้งาน</span>
  </div>
  <p class="text-muted" style="font-size:13.5px;max-width:640px;">เพิ่มความปลอดภัยอีกชั้น — นอกจากรหัสผ่าน ต้องกรอกรหัส 6 หลักจากแอปในมือถือทุกครั้งที่เข้าสู่ระบบ
    ทำให้แม้รหัสผ่านหลุด ผู้อื่นก็เข้าบัญชีไม่ได้ถ้าไม่มีมือถือของคุณ</p>
  <form method="post" action="">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="begin">
    <button class="btn primary" type="submit"><span class="material-symbols-rounded icon-sm">add_moderator</span>เปิดใช้งาน 2FA</button>
  </form>
</div>
<?php endif; ?>
<?php require __DIR__ . '/_bottom.php'; ?>
