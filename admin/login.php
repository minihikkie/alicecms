<?php
/**
 * login.php — เข้าสู่ระบบจัดการ
 * ความปลอดภัย: ล็อก 15 นาทีเมื่อผิด 5 ครั้ง, บันทึก log ทุกครั้ง,
 * regenerate session ID หลัง login สำเร็จ, ยืนยันสองชั้น (2FA/TOTP) ถ้าผู้ใช้เปิดใช้
 */
require dirname(__DIR__) . '/includes/init.php';
require dirname(__DIR__) . '/includes/totp.php';

if (!empty($_SESSION['admin_id'])) redirect('admin/index.php');

$error = '';
$show_2fa = false;
if (isset($_GET['timeout'])) $error = 'Session หมดอายุ กรุณาเข้าสู่ระบบใหม่';

/* ยกเลิกการยืนยัน 2FA (กลับไปกรอกรหัสผ่านใหม่) */
if (isset($_GET['cancel'])) {
    unset($_SESSION['2fa_uid'], $_SESSION['2fa_user'], $_SESSION['2fa_time']);
    redirect('admin/login.php');
}

const TWOFA_TTL = 300;   /* ยืนยัน 2FA ภายใน 5 นาทีหลังกรอกรหัสผ่าน */

/* แสดง captcha หลังเข้าผิดอย่างน้อย 1 ครั้งในเซสชันนี้ (กันบอตเดารหัส) */
$need_captcha = (int)($_SESSION['login_fails'] ?? 0) >= 1;

/* ───────── ขั้นที่ 2: ยืนยันรหัส 2FA ───────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['totp_step'])) {
    $uid   = (int)($_SESSION['2fa_uid'] ?? 0);
    $uname = (string)($_SESSION['2fa_user'] ?? '');
    if (!$uid || (time() - (int)($_SESSION['2fa_time'] ?? 0)) > TWOFA_TTL) {
        unset($_SESSION['2fa_uid'], $_SESSION['2fa_user'], $_SESSION['2fa_time']);
        $error = 'หมดเวลายืนยัน กรุณาเข้าสู่ระบบใหม่';
    } else {
        $show_2fa = true;
        $lock = login_lock_remaining($uname);
        if ($lock > 0) {
            $error = 'ยืนยันผิดหลายครั้ง — ถูกล็อกอีก ' . ceil($lock / 60) . ' นาที';
        } else {
            $st = db()->prepare('SELECT * FROM users WHERE id = ?');
            $st->execute([$uid]);
            $user = $st->fetch();
            $code = trim((string)($_POST['totp_code'] ?? ''));
            $ok = false; $usedBackup = -1;
            if ($user && (int)$user['totp_enabled'] === 1) {
                if (totp_verify($user['totp_secret'], $code)) {
                    $ok = true;
                } else {
                    $codes = json_decode((string)($user['backup_codes'] ?? ''), true) ?: [];
                    $usedBackup = totp_check_backup_code($code, $codes);
                    if ($usedBackup >= 0) $ok = true;
                }
            }
            if ($ok) {
                /* ใช้รหัสสำรองแล้ว → ลบทิ้ง (ใช้ครั้งเดียว) */
                if ($usedBackup >= 0) {
                    $codes = json_decode((string)($user['backup_codes'] ?? ''), true) ?: [];
                    unset($codes[$usedBackup]);
                    db()->prepare('UPDATE users SET backup_codes = ? WHERE id = ?')
                        ->execute([json_encode(array_values($codes)), $uid]);
                    login_log($uname . ' (รหัสสำรอง)', true);
                } else {
                    login_log($uname, true);
                }
                session_regenerate_id(true);
                $_SESSION['admin_id'] = $uid;
                $_SESSION['last_activity'] = time();
                unset($_SESSION['2fa_uid'], $_SESSION['2fa_user'], $_SESSION['2fa_time'],
                      $_SESSION['csrf_token'], $_SESSION['login_fails'], $_SESSION['login_captcha']);
                redirect('admin/index.php');
            }
            login_log($uname, false);
            $_SESSION['login_fails'] = (int)($_SESSION['login_fails'] ?? 0) + 1;
            $error = 'รหัสยืนยันไม่ถูกต้อง กรุณาลองใหม่';
        }
    }
}

/* ───────── ขั้นที่ 1: ตรวจรหัสผ่าน ───────── */
elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    $lock = login_lock_remaining($username);
    if ($lock > 0) {
        $error = 'เข้าสู่ระบบผิดเกิน 5 ครั้ง — ถูกล็อกอีก ' . ceil($lock / 60) . ' นาที';
    } elseif ($username === '' || $password === '') {
        $error = 'กรุณากรอกชื่อผู้ใช้และรหัสผ่าน';
    } elseif ($need_captcha && (int)($_POST['captcha'] ?? -1) !== (int)($_SESSION['login_captcha'] ?? 0)) {
        $_SESSION['login_fails'] = (int)($_SESSION['login_fails'] ?? 0) + 1;
        $error = 'ผลรวมตัวเลขยืนยันไม่ถูกต้อง กรุณาลองใหม่';
    } else {
        $st = db()->prepare('SELECT * FROM users WHERE username = ?');
        $st->execute([$username]);
        $user = $st->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            if ((int)$user['totp_enabled'] === 1 && $user['totp_secret'] !== '') {
                /* รหัสผ่านถูก → ไปขั้นยืนยัน 2FA (ยังไม่ล็อกอิน) */
                session_regenerate_id(true);
                $_SESSION['2fa_uid']  = (int)$user['id'];
                $_SESSION['2fa_user'] = $username;
                $_SESSION['2fa_time'] = time();
                unset($_SESSION['login_fails'], $_SESSION['login_captcha']);
                $show_2fa = true;
            } else {
                login_log($username, true);
                session_regenerate_id(true);
                $_SESSION['admin_id'] = (int)$user['id'];
                $_SESSION['last_activity'] = time();
                unset($_SESSION['csrf_token'], $_SESSION['login_fails'], $_SESSION['login_captcha']);
                redirect('admin/index.php');
            }
        } else {
            login_log($username, false);
            $_SESSION['login_fails'] = (int)($_SESSION['login_fails'] ?? 0) + 1;
            $error = 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง';
        }
    }
}

/* โหลดหน้าใหม่แต่ยังอยู่ระหว่างยืนยัน 2FA → คงฟอร์ม 2FA ไว้ */
if (!$show_2fa && !empty($_SESSION['2fa_uid']) && (time() - (int)($_SESSION['2fa_time'] ?? 0)) <= TWOFA_TTL) {
    $show_2fa = true;
}

/* สร้างเลข captcha ใหม่ถ้าต้องใช้ (เฉพาะขั้นรหัสผ่าน) */
$need_captcha = (int)($_SESSION['login_fails'] ?? 0) >= 1;
if ($need_captcha && !$show_2fa) {
    $ca = random_int(1, 9); $cb = random_int(1, 9);
    $_SESSION['login_captcha'] = $ca + $cb;
}

$theme    = valid_hex(setting('theme_color', '')) ? setting('theme_color') : '#1A73E8';
$theme700 = valid_hex(setting('theme_color_dark', '')) ? setting('theme_color_dark') : '#1557B0';
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>เข้าสู่ระบบจัดการ — <?= e(setting('site_name', 'เว็บไซต์หน่วยงาน')) ?></title>
<meta name="robots" content="noindex, nofollow">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200&display=block" rel="stylesheet">
<link rel="stylesheet" href="<?= e(asset_url('theme-kit.css')) ?>">
<link rel="stylesheet" href="<?= e(asset_url('assets/css/site.css')) ?>">
<link rel="stylesheet" href="<?= e(asset_url('assets/css/animations.css')) ?>">
<link rel="stylesheet" href="<?= e(asset_url('assets/css/admin.css')) ?>">
<style>:root { --blue: <?= e($theme) ?>; --blue-700: <?= e($theme700) ?>; }</style>
</head>
<body class="anim-full">
<?php
$logo     = setting('logo');
$org      = setting('site_name', 'เว็บไซต์หน่วยงาน');
$dept     = setting('site_dept', '');
$logoIcon = setting('logo_icon', 'account_balance');
/* ใช้โลโก้จริงของหน่วยงานถ้าอัปโหลดไว้ — ดูน่าเชื่อถือกว่าไอคอนกลางมาก */
$markInner = $logo
    ? '<img src="' . e(url($logo)) . '" alt="โลโก้' . e($org) . '">'
    : '<span class="material-symbols-rounded filled">' . e($logoIcon) . '</span>';
?>
<div class="auth-shell">

  <!-- แผงแบรนด์ (จอ ≥920px) -->
  <aside class="auth-brand">
    <div>
      <div class="auth-mark"><?= $markInner ?></div>
      <h1 class="auth-org"><?= e($org) ?></h1>
      <?php if ($dept): ?><p class="auth-dept"><?= e($dept) ?></p><?php endif; ?>
    </div>
    <div class="auth-foot">
      <img src="<?= e(asset_url('assets/img/alicecms-mark.svg')) ?>" alt="" aria-hidden="true">
      <p>ระบบจัดการเนื้อหาสำหรับหน่วยงานราชการ<br>
        ขับเคลื่อนด้วย <a href="https://github.com/minihikkie/alicecms" target="_blank" rel="noopener">AliceCMS</a>
        <?= e(APP_VERSION) ?></p>
    </div>
  </aside>

  <!-- แผงฟอร์ม -->
  <main class="auth-main">
    <div class="auth-box animate-fadein">

      <!-- หัวแบรนด์ย่อสำหรับจอเล็ก -->
      <div class="auth-mini">
        <div class="auth-mark"><?= $markInner ?></div>
        <div>
          <div class="auth-mini-name"><?= e($org) ?></div>
          <?php if ($dept): ?><div class="auth-mini-sub"><?= e($dept) ?></div><?php endif; ?>
        </div>
      </div>

      <h2 class="auth-title"><?= $show_2fa ? 'ยืนยันตัวตนสองชั้น' : 'เข้าสู่ระบบจัดการ' ?></h2>
      <p class="auth-sub">
        <?= $show_2fa
            ? 'กรอกรหัส 6 หลักจากแอป Authenticator ของคุณ'
            : 'สำหรับเจ้าหน้าที่ผู้ดูแลเว็บไซต์เท่านั้น' ?>
      </p>

      <?php if ($error): ?>
      <div class="alert danger" style="font-size:14px;margin-bottom:18px;">
        <span class="material-symbols-rounded">error</span><div><?= e($error) ?></div>
      </div>
      <?php endif; ?>

      <?php if ($show_2fa): /* ───── ฟอร์มยืนยัน 2FA ───── */ ?>
      <form method="post" action="">
        <?= csrf_field() ?>
        <input type="hidden" name="totp_step" value="1">
        <div class="auth-field">
          <label for="totp_code">รหัสยืนยัน 6 หลัก</label>
          <input type="text" id="totp_code" name="totp_code" required autofocus inputmode="numeric"
                 autocomplete="one-time-code" pattern="[0-9A-Za-z\- ]*" maxlength="13"
                 placeholder="123456" class="auth-otp">
        </div>
        <button class="btn primary large auth-submit" type="submit">
          <span class="material-symbols-rounded">verified_user</span>ยืนยันและเข้าสู่ระบบ
        </button>
      </form>
      <p class="auth-links">
        เข้าแอปไม่ได้? กรอก<b>รหัสสำรอง</b>ในช่องด้านบนได้
      </p>
      <p class="auth-links" style="margin-top:8px;">
        <a href="<?= e(url('admin/login.php?cancel=1')) ?>">ยกเลิกและเข้าสู่ระบบใหม่</a>
      </p>

      <?php else: /* ───── ฟอร์มรหัสผ่าน ───── */ ?>
      <form method="post" action="">
        <?= csrf_field() ?>
        <div class="auth-field">
          <label for="username">ชื่อผู้ใช้</label>
          <input type="text" id="username" name="username" value="<?= e($_POST['username'] ?? '') ?>"
                 required autofocus autocomplete="username" placeholder="ชื่อผู้ใช้ของคุณ">
        </div>
        <div class="auth-field">
          <label for="password">รหัสผ่าน</label>
          <div class="auth-pw">
            <input type="password" id="password" name="password" required
                   autocomplete="current-password" placeholder="รหัสผ่าน">
            <button type="button" id="pwToggle" aria-label="แสดงรหัสผ่าน" aria-pressed="false">
              <span class="material-symbols-rounded" id="pwIcon">visibility</span>
            </button>
          </div>
        </div>
        <?php if ($need_captcha): ?>
        <div class="auth-field">
          <label for="captcha">ยืนยันว่าไม่ใช่บอท</label>
          <div class="captcha-box">
            <span class="captcha-q"><?= $ca ?> + <?= $cb ?> = ?</span>
            <input type="number" id="captcha" name="captcha" required placeholder="ผลรวม"
                   autocomplete="off" inputmode="numeric">
          </div>
        </div>
        <?php endif; ?>
        <button class="btn primary large auth-submit" type="submit">
          <span class="material-symbols-rounded">login</span>เข้าสู่ระบบ
        </button>
      </form>

      <p class="auth-links"><a href="<?= e(url('admin/forgot.php')) ?>">ลืมรหัสผ่าน?</a></p>
      <p class="auth-note">
        <span class="material-symbols-rounded">shield</span>
        ระบบล็อกอัตโนมัติ 15 นาที เมื่อเข้าสู่ระบบผิดเกิน 5 ครั้ง
      </p>
      <?php endif; ?>

    </div>
  </main>
</div>

<script nonce="<?= e(CSP_NONCE) ?>">
/* สลับแสดง/ซ่อนรหัสผ่าน — พิมพ์ผิดแล้วมองไม่เห็นคือสาเหตุหลักที่ทำให้โดนล็อก 15 นาที */
(function () {
  var btn = document.getElementById('pwToggle');
  if (!btn) return;
  var input = document.getElementById('password');
  var icon  = document.getElementById('pwIcon');
  btn.addEventListener('click', function () {
    var show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    icon.textContent = show ? 'visibility_off' : 'visibility';
    btn.setAttribute('aria-label', show ? 'ซ่อนรหัสผ่าน' : 'แสดงรหัสผ่าน');
    btn.setAttribute('aria-pressed', show ? 'true' : 'false');
    input.focus();
  });
})();
</script>
<script src="<?= e(asset_url('assets/js/dialog.js')) ?>"></script>
<script src="<?= e(asset_url('assets/js/admin.js')) ?>"></script>
</body>
</html>
