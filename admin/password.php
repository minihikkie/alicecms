<?php
/** admin/password.php — เปลี่ยนรหัสผ่าน */
require dirname(__DIR__) . '/includes/init.php';
require __DIR__ . '/_auth.php';

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current = (string)($_POST['current_password'] ?? '');
    $new     = (string)($_POST['new_password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');

    $st = db()->prepare('SELECT password_hash FROM users WHERE id = ?');
    $st->execute([$ADMIN['id']]);
    $hash = $st->fetchColumn();

    if (!password_verify($current, (string)$hash)) $errors[] = 'รหัสผ่านปัจจุบันไม่ถูกต้อง';
    if (mb_strlen($new) < 8)  $errors[] = 'รหัสผ่านใหม่ต้องยาวอย่างน้อย 8 ตัวอักษร';
    if ($new !== $confirm)    $errors[] = 'รหัสผ่านใหม่ทั้งสองช่องไม่ตรงกัน';

    if (!$errors) {
        db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($new, PASSWORD_BCRYPT), $ADMIN['id']]);
        flash_set('success', 'เปลี่ยนรหัสผ่านเรียบร้อยแล้ว');
        redirect('admin/password.php');
    }
}

$admin_title = 'เปลี่ยนรหัสผ่าน';
require __DIR__ . '/_top.php';
?>
<div class="card" style="max-width:520px;">
  <div class="section-head" style="margin-bottom:8px;">
    <div><span class="tag"><span class="material-symbols-rounded icon-sm">key</span> PASSWORD</span><h3>เปลี่ยนรหัสผ่าน</h3>
    <p>บัญชี: <b><?= e($ADMIN['username']) ?></b></p></div>
  </div>

  <?php if ($errors): ?>
  <div class="alert danger"><span class="material-symbols-rounded">error</span>
    <div><?php foreach ($errors as $er): ?><div><?= e($er) ?></div><?php endforeach; ?></div>
  </div>
  <?php endif; ?>

  <form method="post" action="">
    <?= csrf_field() ?>
    <label>รหัสผ่านปัจจุบัน</label>
    <input type="password" name="current_password" required autocomplete="current-password" class="mb-2">
    <label>รหัสผ่านใหม่ <span class="text-muted">(อย่างน้อย 8 ตัวอักษร)</span></label>
    <input type="password" name="new_password" required minlength="8" autocomplete="new-password" class="mb-2">
    <label>ยืนยันรหัสผ่านใหม่</label>
    <input type="password" name="confirm_password" required minlength="8" autocomplete="new-password" class="mb-2">
    <button class="btn primary" type="submit"><span class="material-symbols-rounded icon-sm">save</span>เปลี่ยนรหัสผ่าน</button>
  </form>
</div>
<?php require __DIR__ . '/_bottom.php'; ?>
