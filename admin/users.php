<?php
/** admin/users.php — จัดการผู้ใช้งานระบบ (เฉพาะผู้ดูแลระบบสูงสุด) */
require dirname(__DIR__) . '/includes/init.php';
require __DIR__ . '/_auth.php';
require_admin();

$roles = ['admin' => 'ผู้ดูแลระบบสูงสุด', 'editor' => 'เจ้าหน้าที่ (แก้เนื้อหา)'];
$errors = [];
$edit = null;

/** นับจำนวน admin ที่เหลือ — กันลบ/ลดสิทธิ์ admin คนสุดท้าย */
function count_admins(): int {
    return (int)db()->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    /* ── ลบผู้ใช้ ── */
    if (isset($_POST['delete_id'])) {
        $id = (int)$_POST['delete_id'];
        if ($id === (int)$ADMIN['id']) {
            flash_set('danger', 'ลบบัญชีตัวเองไม่ได้');
        } else {
            $st = db()->prepare('SELECT username, role FROM users WHERE id = ?');
            $st->execute([$id]);
            $u = $st->fetch();
            if ($u && $u['role'] === 'admin' && count_admins() <= 1) {
                flash_set('danger', 'ต้องมีผู้ดูแลระบบสูงสุดอย่างน้อย 1 คน');
            } elseif ($u) {
                db()->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
                log_action('ลบผู้ใช้', $u['username']);
                flash_set('success', 'ลบผู้ใช้เรียบร้อยแล้ว');
            }
        }
        redirect('admin/users.php');
    }

    /* ── รีเซ็ต 2FA ของผู้ใช้ (กรณีทำมือถือหาย/ล็อกเอาต์) ── */
    if (isset($_POST['reset_2fa_id'])) {
        $id = (int)$_POST['reset_2fa_id'];
        $st = db()->prepare('SELECT username FROM users WHERE id = ?');
        $st->execute([$id]);
        if ($u = $st->fetch()) {
            db()->prepare("UPDATE users SET totp_secret = '', totp_enabled = 0, backup_codes = NULL WHERE id = ?")->execute([$id]);
            log_action('รีเซ็ต 2FA ผู้ใช้', $u['username']);
            flash_set('success', 'รีเซ็ตการยืนยันสองชั้นของ ' . $u['username'] . ' แล้ว — ผู้ใช้เข้าระบบด้วยรหัสผ่านได้ทันที');
        }
        redirect('admin/users.php');
    }

    $id       = (int)($_POST['id'] ?? 0);
    $username = trim((string)($_POST['username'] ?? ''));
    $display  = mb_substr(trim((string)($_POST['display_name'] ?? '')), 0, 150);
    $email    = mb_substr(trim((string)($_POST['email'] ?? '')), 0, 150);
    $role     = isset($roles[$_POST['role'] ?? '']) ? $_POST['role'] : 'editor';
    $password = (string)($_POST['password'] ?? '');

    if ($id === 0 && !preg_match('/^[A-Za-z0-9_.@-]{4,50}$/', $username)) {
        $errors[] = 'ชื่อผู้ใช้ต้องยาว 4–50 ตัว (อังกฤษ/ตัวเลข/_.@-)';
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'รูปแบบอีเมลไม่ถูกต้อง';
    }
    if ($id === 0 && mb_strlen($password) < 8) {
        $errors[] = 'รหัสผ่านต้องยาวอย่างน้อย 8 ตัวอักษร';
    }
    if ($id > 0 && $password !== '' && mb_strlen($password) < 8) {
        $errors[] = 'รหัสผ่านใหม่ต้องยาวอย่างน้อย 8 ตัวอักษร';
    }

    /* กันลดสิทธิ์ admin คนสุดท้าย */
    if ($id > 0 && $role === 'editor') {
        $st = db()->prepare('SELECT role FROM users WHERE id = ?');
        $st->execute([$id]);
        if ($st->fetchColumn() === 'admin' && count_admins() <= 1) {
            $errors[] = 'ต้องมีผู้ดูแลระบบสูงสุดอย่างน้อย 1 คน';
        }
    }

    if (!$errors) {
        if ($id > 0) {
            if ($password !== '') {
                db()->prepare('UPDATE users SET display_name=?, email=?, role=?, password_hash=? WHERE id=?')
                    ->execute([$display, $email, $role, password_hash($password, PASSWORD_BCRYPT), $id]);
            } else {
                db()->prepare('UPDATE users SET display_name=?, email=?, role=? WHERE id=?')
                    ->execute([$display, $email, $role, $id]);
            }
            log_action('แก้ไขผู้ใช้', $display ?: ('#' . $id));
            flash_set('success', 'บันทึกผู้ใช้เรียบร้อยแล้ว');
        } else {
            try {
                db()->prepare('INSERT INTO users (username, display_name, email, role, password_hash) VALUES (?,?,?,?,?)')
                    ->execute([$username, $display ?: $username, $email, $role, password_hash($password, PASSWORD_BCRYPT)]);
                log_action('เพิ่มผู้ใช้', $username);
                flash_set('success', 'เพิ่มผู้ใช้เรียบร้อยแล้ว');
            } catch (PDOException $e) {
                $errors[] = 'ชื่อผู้ใช้นี้มีอยู่แล้ว';
            }
        }
        if (!$errors) redirect('admin/users.php');
    }
}

if (isset($_GET['edit'])) {
    $st = db()->prepare('SELECT * FROM users WHERE id = ?');
    $st->execute([(int)$_GET['edit']]);
    $edit = $st->fetch();
}

$users = db()->query('SELECT id, username, display_name, email, role, totp_enabled, created_at FROM users ORDER BY id ASC')->fetchAll();
$n_no_email = count(array_filter($users, fn($u) => trim((string)$u['email']) === ''));
$admin_title = 'ผู้ใช้งานระบบ';
require __DIR__ . '/_top.php';
?>
<?php if ($errors): ?>
<div class="alert danger"><span class="material-symbols-rounded">error</span>
  <div><?php foreach ($errors as $er): ?><div><?= e($er) ?></div><?php endforeach; ?></div>
</div>
<?php endif; ?>

<div class="card mb-2">
  <div class="section-head" style="margin-bottom:8px;">
    <div><span class="tag">USER</span><h3><?= $edit ? 'แก้ไขผู้ใช้' : 'เพิ่มผู้ใช้ใหม่' ?></h3>
    <p><b>ผู้ดูแลระบบสูงสุด</b> = เข้าถึงทุกเมนู · <b>เจ้าหน้าที่</b> = แก้เนื้อหาได้ แต่ตั้งค่าระบบ/ธีม/ผู้ใช้/สำรองข้อมูลไม่ได้</p></div>
    <?php if ($edit): ?><a class="btn small" href="<?= e(url('admin/users.php')) ?>">ยกเลิก</a><?php endif; ?>
  </div>
  <form method="post" action="">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
    <div class="form-row">
      <div>
        <label>ชื่อผู้ใช้ (login) <span style="color:var(--danger);">*</span></label>
        <?php if ($edit): ?>
        <input type="text" value="<?= e($edit['username']) ?>" disabled class="mb-2" style="opacity:.7;">
        <?php else: ?>
        <input type="text" name="username" value="<?= e($_POST['username'] ?? '') ?>" required class="mb-2" placeholder="4–50 ตัว (อังกฤษ/ตัวเลข)">
        <?php endif; ?>
        <label>ชื่อที่แสดง</label>
        <input type="text" name="display_name" value="<?= e($_POST['display_name'] ?? $edit['display_name'] ?? '') ?>" class="mb-2" placeholder="เช่น นายตัวอย่าง (เจ้าหน้าที่ฝ่ายข่าว)">
        <label>อีเมล <span class="text-muted" style="font-weight:400;font-size:11.5px;">(ใช้กู้รหัสผ่านเมื่อลืม — แนะนำให้กรอก)</span></label>
        <input type="email" name="email" value="<?= e($_POST['email'] ?? $edit['email'] ?? '') ?>" class="mb-2" placeholder="name@domain.go.th">
      </div>
      <div>
        <label>บทบาท</label>
        <select name="role" class="mb-2">
          <?php foreach ($roles as $rk => $rv): ?>
          <option value="<?= e($rk) ?>" <?= ($edit['role'] ?? 'editor') === $rk ? 'selected' : '' ?>><?= e($rv) ?></option>
          <?php endforeach; ?>
        </select>
        <label>รหัสผ่าน <?= $edit ? '<span class="text-muted">(เว้นว่าง = ไม่เปลี่ยน)</span>' : '<span style="color:var(--danger);">*</span>' ?></label>
        <input type="password" name="password" <?= $edit ? '' : 'required' ?> autocomplete="new-password" class="mb-2" placeholder="อย่างน้อย 8 ตัวอักษร">
      </div>
    </div>
    <button class="btn primary" type="submit"><span class="material-symbols-rounded icon-sm">save</span><?= $edit ? 'บันทึกการแก้ไข' : 'เพิ่มผู้ใช้' ?></button>
  </form>
</div>

<div class="card">
  <div class="section-head" style="margin-bottom:8px;">
    <div><span class="tag">ALL USERS</span><h3>ผู้ใช้ทั้งหมด (<?= count($users) ?>)</h3></div>
  </div>
  <table class="admin-table">
    <tr><th>ชื่อผู้ใช้</th><th>ชื่อที่แสดง</th><th>อีเมล</th><th>บทบาท</th><th>2FA</th><th>สร้างเมื่อ</th><th></th></tr>
    <?php foreach ($users as $u): ?>
    <tr>
      <td><b><?= e($u['username']) ?></b><?= (int)$u['id'] === (int)$ADMIN['id'] ? ' <span class="badge" style="font-size:10px;">คุณ</span>' : '' ?></td>
      <td><?= e($u['display_name']) ?></td>
      <td><?php if (trim((string)$u['email']) !== ''): ?><span style="font-size:12.5px;"><?= e($u['email']) ?></span>
          <?php else: ?><span class="badge" style="font-size:10px;" title="ไม่มีอีเมล จะกู้รหัสผ่านเองไม่ได้">ยังไม่มี</span><?php endif; ?></td>
      <td><span class="badge <?= $u['role'] === 'admin' ? 'special' : '' ?>" style="font-size:11px;"><?= e($roles[$u['role']] ?? $u['role']) ?></span></td>
      <td><?php if ((int)$u['totp_enabled'] === 1): ?><span class="badge success" style="font-size:10px;"><span class="material-symbols-rounded icon-sm">verified_user</span>เปิด</span><?php else: ?><span class="lr-date">—</span><?php endif; ?></td>
      <td class="lr-date" style="white-space:nowrap;"><?= e(thai_date($u['created_at'])) ?></td>
      <td style="white-space:nowrap;">
        <a class="btn small" href="<?= e(url('admin/users.php?edit=' . $u['id'])) ?>">แก้ไข</a>
        <?php if ((int)$u['totp_enabled'] === 1): ?>
        <form method="post" action="" style="display:inline;">
          <?= csrf_field() ?>
          <input type="hidden" name="reset_2fa_id" value="<?= (int)$u['id'] ?>">
          <button class="btn small" type="submit" data-confirm="รีเซ็ต 2FA ของ «<?= e($u['username']) ?>»? ผู้ใช้จะเข้าระบบด้วยรหัสผ่านได้ทันที (ใช้กรณีทำมือถือหาย)">รีเซ็ต 2FA</button>
        </form>
        <?php endif; ?>
        <?php if ((int)$u['id'] !== (int)$ADMIN['id']): ?>
        <form method="post" action="" style="display:inline;">
          <?= csrf_field() ?>
          <input type="hidden" name="delete_id" value="<?= (int)$u['id'] ?>">
          <button class="btn small danger" type="submit" data-confirm="ยืนยันลบผู้ใช้ «<?= e($u['username']) ?>»?">ลบ</button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
</div>
<?php require __DIR__ . '/_bottom.php'; ?>
