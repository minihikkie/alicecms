<?php
/**
 * reset.php — ตั้งรหัสผ่านใหม่จากลิงก์ที่ส่งทางอีเมล
 * โทเคนใช้ได้ครั้งเดียว หมดอายุตามเวลา และเทียบด้วย hash เท่านั้น
 */
require dirname(__DIR__) . '/includes/init.php';

if (!empty($_SESSION['admin_id'])) redirect('admin/index.php');

$token = (string)($_GET['token'] ?? $_POST['token'] ?? '');
$error = '';
$done  = false;
$user  = null;

/* ตรวจโทเคน */
if ($token !== '' && preg_match('/^[a-f0-9]{64}$/i', $token)) {
    $st = db()->prepare('SELECT r.id AS rid, r.user_id, u.username, u.display_name
                         FROM password_resets r JOIN users u ON u.id = r.user_id
                         WHERE r.token_hash = ? AND r.used_at IS NULL AND r.expires_at > NOW() LIMIT 1');
    $st->execute([hash('sha256', $token)]);
    $user = $st->fetch();
}
if (!$user) $error = 'ลิงก์ไม่ถูกต้อง หมดอายุ หรือถูกใช้ไปแล้ว — กรุณาขอลิงก์ใหม่';

if ($user && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $p1 = (string)($_POST['password'] ?? '');
    $p2 = (string)($_POST['password2'] ?? '');
    if (mb_strlen($p1) < 8) {
        $error = 'รหัสผ่านต้องยาวอย่างน้อย 8 ตัวอักษร';
    } elseif ($p1 !== $p2) {
        $error = 'รหัสผ่านทั้งสองช่องไม่ตรงกัน';
    } else {
        db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($p1, PASSWORD_BCRYPT), (int)$user['user_id']]);
        /* ปิดโทเคนใบนี้และใบอื่นที่ยังค้างของผู้ใช้คนเดียวกัน */
        db()->prepare('UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL')
            ->execute([(int)$user['user_id']]);
        log_action('ตั้งรหัสผ่านใหม่ผ่านลิงก์อีเมล', (string)$user['username']);
        $done = true;
    }
}

$theme    = valid_hex(setting('theme_color', '')) ? setting('theme_color') : '#1A73E8';
$theme700 = valid_hex(setting('theme_color_dark', '')) ? setting('theme_color_dark') : '#1557B0';
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ตั้งรหัสผ่านใหม่ — <?= e(setting('site_name', 'เว็บไซต์หน่วยงาน')) ?></title>
<meta name="robots" content="noindex, nofollow">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200&display=block" rel="stylesheet">
<link rel="stylesheet" href="<?= e(asset_url('theme-kit.css')) ?>">
<link rel="stylesheet" href="<?= e(asset_url('assets/css/site.css')) ?>">
<link rel="stylesheet" href="<?= e(asset_url('assets/css/admin.css')) ?>">
<style>:root { --blue: <?= e($theme) ?>; --blue-700: <?= e($theme700) ?>; }</style>
</head>
<body class="anim-full">
<?php
$logo = setting('logo'); $org = setting('site_name', 'เว็บไซต์หน่วยงาน'); $dept = setting('site_dept', '');
$markInner = $logo
    ? '<img src="' . e(url($logo)) . '" alt="โลโก้' . e($org) . '">'
    : '<span class="material-symbols-rounded filled">' . e(setting('logo_icon', 'account_balance')) . '</span>';
?>
<div class="auth-shell">
  <aside class="auth-brand">
    <div>
      <div class="auth-mark"><?= $markInner ?></div>
      <h1 class="auth-org"><?= e($org) ?></h1>
      <?php if ($dept): ?><p class="auth-dept"><?= e($dept) ?></p><?php endif; ?>
    </div>
    <p class="auth-foot">
      ระบบจัดการเนื้อหาสำหรับหน่วยงานราชการ<br>
      ขับเคลื่อนด้วย <a href="https://github.com/minihikkie/alicecms" target="_blank" rel="noopener">AliceCMS</a>
      <?= e(APP_VERSION) ?>
    </p>
  </aside>

  <main class="auth-main">
    <div class="auth-box animate-fadein">
      <div class="auth-mini">
        <div class="auth-mark"><?= $markInner ?></div>
        <div>
          <div class="auth-mini-name"><?= e($org) ?></div>
          <?php if ($dept): ?><div class="auth-mini-sub"><?= e($dept) ?></div><?php endif; ?>
        </div>
      </div>

      <h2 class="auth-title">ตั้งรหัสผ่านใหม่</h2>
      <p class="auth-sub">
        <?php if ($user && !$done): ?>สำหรับบัญชี <b><?= e($user['username']) ?></b>
        <?php else: ?>ตั้งรหัสผ่านใหม่สำหรับเข้าสู่ระบบจัดการ<?php endif; ?>
      </p>

    <?php if ($done): ?>
    <div class="alert success" style="font-size:14px;margin-bottom:18px;"><span class="material-symbols-rounded">check_circle</span>
      <div>ตั้งรหัสผ่านใหม่เรียบร้อยแล้ว — เข้าสู่ระบบด้วยรหัสผ่านใหม่ได้เลย</div></div>
    <a class="btn primary large auth-submit" href="<?= e(url('admin/login.php')) ?>">
      <span class="material-symbols-rounded">login</span>ไปหน้าเข้าสู่ระบบ</a>

    <?php else: ?>
      <?php if ($error): ?>
      <div class="alert danger" style="font-size:14px;margin-bottom:18px;"><span class="material-symbols-rounded">error</span><div><?= e($error) ?></div></div>
      <?php endif; ?>

      <?php if ($user): ?>
      <form method="post" action="">
        <?= csrf_field() ?>
        <input type="hidden" name="token" value="<?= e($token) ?>">
        <div class="auth-field">
          <label for="password">รหัสผ่านใหม่ (อย่างน้อย 8 ตัวอักษร)</label>
          <input type="password" id="password" name="password" required autofocus autocomplete="new-password" minlength="8">
        </div>
        <div class="auth-field">
          <label for="password2">ยืนยันรหัสผ่านใหม่</label>
          <input type="password" id="password2" name="password2" required autocomplete="new-password" minlength="8">
        </div>
        <button class="btn primary large auth-submit" type="submit">
          <span class="material-symbols-rounded">save</span>บันทึกรหัสผ่านใหม่
        </button>
      </form>
      <?php else: ?>
      <a class="btn primary large auth-submit" href="<?= e(url('admin/forgot.php')) ?>">
        <span class="material-symbols-rounded">lock_reset</span>ขอลิงก์ใหม่</a>
      <?php endif; ?>
    <?php endif; ?>
    </div>
  </main>
</div>
</body>
</html>
