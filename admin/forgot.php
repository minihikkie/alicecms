<?php
/**
 * forgot.php — ขอลิงก์ตั้งรหัสผ่านใหม่ทางอีเมล
 *
 * ความปลอดภัย:
 *  - ไม่บอกว่ามีบัญชีนั้นอยู่จริงหรือไม่ (ข้อความตอบกลับเหมือนกันทุกกรณี) กันการไล่เดาชื่อผู้ใช้
 *  - โทเคนสุ่ม 32 ไบต์ เก็บเฉพาะค่า hash ในฐานข้อมูล ใช้ได้ครั้งเดียว หมดอายุใน 60 นาที
 *  - จำกัดจำนวนคำขอต่อ IP กันการยิงถล่ม
 *  - ผู้ที่เปิด 2FA ไว้ ยังต้องกรอกรหัส 2FA ตอนเข้าสู่ระบบอยู่ดี (รีเซ็ตรหัสผ่านไม่ข้าม 2FA)
 */
require dirname(__DIR__) . '/includes/init.php';
require dirname(__DIR__) . '/includes/mailer.php';

if (!empty($_SESSION['admin_id'])) redirect('admin/index.php');

const RESET_TTL_MIN   = 60;   /* ลิงก์หมดอายุ (นาที) */
const RESET_MAX_PER_IP = 5;   /* ขอได้กี่ครั้งต่อชั่วโมงต่อ IP */

$done  = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $who = trim((string)($_POST['who'] ?? ''));
    $ip  = (string)($_SERVER['REMOTE_ADDR'] ?? '');

    /* จำกัดจำนวนคำขอต่อ IP */
    $rl = db()->prepare('SELECT COUNT(*) FROM password_resets WHERE ip = ? AND created_at > (NOW() - INTERVAL 1 HOUR)');
    $rl->execute([$ip]);
    if ((int)$rl->fetchColumn() >= RESET_MAX_PER_IP) {
        $error = 'ขอลิงก์บ่อยเกินไป กรุณารออีก 1 ชั่วโมงแล้วลองใหม่';
    } elseif ($who === '') {
        $error = 'กรุณากรอกชื่อผู้ใช้หรืออีเมล';
    } else {
        /* หาโดยชื่อผู้ใช้ หรืออีเมล */
        $st = db()->prepare('SELECT id, username, email, display_name FROM users WHERE username = ? OR (email <> "" AND email = ?) LIMIT 1');
        $st->execute([$who, $who]);
        $user = $st->fetch();

        if ($user && trim((string)$user['email']) !== '') {
            /* ยกเลิกโทเคนเก่าที่ยังไม่ใช้ของคนนี้ แล้วออกใบใหม่ */
            db()->prepare('UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL')
                ->execute([(int)$user['id']]);

            $token = bin2hex(random_bytes(32));
            db()->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at, ip) VALUES (?,?,DATE_ADD(NOW(), INTERVAL ? MINUTE),?)')
                ->execute([(int)$user['id'], hash('sha256', $token), RESET_TTL_MIN, $ip]);

            $link = abs_url('admin/reset.php?token=' . $token);
            $site = setting('site_name', 'เว็บไซต์หน่วยงาน');
            $body = "เรียน " . ($user['display_name'] ?: $user['username']) . "\n\n"
                  . "มีการขอตั้งรหัสผ่านใหม่สำหรับบัญชีผู้ดูแลเว็บไซต์ \"$site\"\n"
                  . "กดลิงก์ด้านล่างเพื่อตั้งรหัสผ่านใหม่ (ลิงก์ใช้ได้ครั้งเดียว และหมดอายุใน " . RESET_TTL_MIN . " นาที)\n\n"
                  . $link . "\n\n"
                  . "หากคุณไม่ได้เป็นผู้ขอ ไม่ต้องทำอะไร รหัสผ่านเดิมยังใช้ได้ตามปกติ\n"
                  . "แจ้งผู้ดูแลระบบหากได้รับอีเมลนี้บ่อยผิดปกติ\n";
            $mailErr = '';
            send_mail((string)$user['email'], "ตั้งรหัสผ่านใหม่ — $site", $body, $mailErr);
            log_action('ขอลิงก์ตั้งรหัสผ่านใหม่', $user['username'] . ($mailErr ? " (ส่งเมลไม่สำเร็จ: $mailErr)" : ''));
        } else {
            /* ไม่พบบัญชี หรือบัญชีไม่มีอีเมล — บันทึกไว้เพื่อจำกัดอัตรา แต่ไม่บอกผู้ใช้ */
            db()->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at, ip) VALUES (0,?,NOW(),?)')
                ->execute([hash('sha256', 'noop-' . random_bytes(8)), $ip]);
            log_action('ขอลิงก์ตั้งรหัสผ่านใหม่ (ไม่พบบัญชี/ไม่มีอีเมล)', mb_substr($who, 0, 60));
        }
        $done = true;   /* ข้อความเดียวกันเสมอ ไม่เปิดเผยว่ามีบัญชีนี้ไหม */
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
<title>ลืมรหัสผ่าน — <?= e(setting('site_name', 'เว็บไซต์หน่วยงาน')) ?></title>
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
<div class="login-wrap">
  <div class="card card-spacious login-card">
    <div class="text-center mb-2">
      <div class="logo"><span class="material-symbols-rounded filled">lock_reset</span></div>
      <h2 style="margin-bottom:2px;">ลืมรหัสผ่าน</h2>
      <p class="text-muted" style="font-size:13px;margin:0;">ระบบจะส่งลิงก์ตั้งรหัสผ่านใหม่ไปที่อีเมลของบัญชีนั้น</p>
    </div>

    <?php if ($error): ?>
    <div class="alert danger" style="font-size:14px;"><span class="material-symbols-rounded">error</span><div><?= e($error) ?></div></div>
    <?php endif; ?>

    <?php if ($done): ?>
    <div class="alert success" style="font-size:14px;"><span class="material-symbols-rounded">mark_email_read</span>
      <div>หากมีบัญชีที่ตรงกับข้อมูลนี้และมีอีเมลบันทึกไว้ ระบบได้ส่งลิงก์ตั้งรหัสผ่านใหม่ไปแล้ว<br>
        <span class="text-muted" style="font-size:12.5px;">กรุณาตรวจกล่องจดหมาย (รวมโฟลเดอร์สแปม) — ลิงก์หมดอายุใน <?= RESET_TTL_MIN ?> นาที</span></div>
    </div>
    <a class="btn primary large" href="<?= e(url('admin/login.php')) ?>" style="width:100%;justify-content:center;">
      <span class="material-symbols-rounded">arrow_back</span>กลับหน้าเข้าสู่ระบบ</a>

    <?php else: ?>
    <form method="post" action="">
      <?= csrf_field() ?>
      <label for="who">ชื่อผู้ใช้ หรืออีเมล</label>
      <input type="text" id="who" name="who" required autofocus autocomplete="username" class="mb-2"
             value="<?= e($_POST['who'] ?? '') ?>" placeholder="เช่น admin หรือ name@domain.go.th">
      <button class="btn primary large" type="submit" style="width:100%;justify-content:center;">
        <span class="material-symbols-rounded">send</span>ส่งลิงก์ตั้งรหัสผ่านใหม่
      </button>
    </form>
    <p class="text-center mt-2" style="font-size:13px;margin:0;">
      <a href="<?= e(url('admin/login.php')) ?>">กลับหน้าเข้าสู่ระบบ</a>
    </p>
    <p class="text-muted text-center mt-1" style="font-size:11.5px;margin:0;">
      หากบัญชีของคุณยังไม่ได้บันทึกอีเมล กรุณาติดต่อผู้ดูแลระบบให้รีเซ็ตรหัสผ่านให้
    </p>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
