<?php
/**
 * admin/users.php — จัดการผู้ใช้งานระบบ
 *
 * ผู้ดูแลระบบสูงสุด: ทำได้ทุกอย่าง
 * ผู้ที่ได้สิทธิ์เมนู "ผู้ใช้งานระบบ" จากกลุ่มสิทธิ์ (มอบหมายงานดูแลบัญชี):
 *   - เพิ่ม/แก้/ระงับ/ลบ ได้เฉพาะบัญชีเจ้าหน้าที่ที่สิทธิ์ไม่เกินตัวเอง
 *   - เลือกได้เฉพาะกลุ่มสิทธิ์ที่ไม่เกินสิทธิ์ของตัวเอง (กันการมอบสิทธิ์ให้คนอื่นเกินตัว แล้วใช้บัญชีนั้นแทน)
 *   - แตะบัญชีผู้ดูแลระบบสูงสุดและบัญชีของตัวเองไม่ได้
 */
require dirname(__DIR__) . '/includes/init.php';
require __DIR__ . '/_auth.php';
/* สิทธิ์เข้าเมนูตรวจที่ _auth.php (perm_enforce) แล้ว */
perm_require_schema();

$isSuper = is_admin_role();
$errors = [];
$edit = null;

$roles = db()->query('SELECT id, name, description, perms FROM roles ORDER BY name')->fetchAll();
$roleById = [];
foreach ($roles as $r) { $r['p'] = perm_clean($r['perms']); $roleById[(int)$r['id']] = $r; }

/** กลุ่มที่ผู้ใช้ปัจจุบันมอบให้คนอื่นได้ */
function users_role_assignable(array $role): bool {
    return is_admin_role() || perm_subset($role['p'], perm_current());
}
/** บัญชีนี้ผู้ใช้ปัจจุบันจัดการได้หรือไม่ */
function users_manageable(array $u, array $roleById): bool {
    if (is_admin_role()) return true;
    if ((int)$u['id'] === (int)$GLOBALS['ADMIN']['id'] || $u['role'] === 'admin') return false;
    $rp = $roleById[(int)($u['role_id'] ?? 0)]['p'] ?? [];
    return perm_subset($rp, perm_current());
}
function users_load(int $id): ?array {
    $st = db()->prepare('SELECT * FROM users WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}
/** นับผู้ดูแลระบบสูงสุดที่ยังใช้งานได้ — กันลบ/ลดสิทธิ์/ระงับคนสุดท้าย */
function count_admins(): int {
    return (int)db()->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1")->fetchColumn();
}
function users_deny(string $msg): void {
    log_action('ถูกปฏิเสธสิทธิ์', mb_substr('ผู้ใช้งานระบบ · ' . $msg, 0, 200));
    flash_set('danger', $msg);
    redirect('admin/users.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    /* ── ลบผู้ใช้ ── */
    if (isset($_POST['delete_id'])) {
        $u = users_load((int)$_POST['delete_id']);
        if (!$u) redirect('admin/users.php');
        if ((int)$u['id'] === (int)$ADMIN['id']) users_deny('ลบบัญชีตัวเองไม่ได้');
        if (!users_manageable($u, $roleById)) users_deny('คุณไม่มีสิทธิ์จัดการบัญชี «' . $u['username'] . '»');
        if ($u['role'] === 'admin' && (int)$u['is_active'] === 1 && count_admins() <= 1) users_deny('ต้องมีผู้ดูแลระบบสูงสุดอย่างน้อย 1 คน');
        db()->prepare('DELETE FROM users WHERE id = ?')->execute([(int)$u['id']]);
        log_action('ลบผู้ใช้', $u['username']);
        flash_set('success', 'ลบผู้ใช้เรียบร้อยแล้ว');
        redirect('admin/users.php');
    }

    /* ── ระงับ / เปิดใช้งานบัญชี ── */
    if (isset($_POST['toggle_active_id'])) {
        $u = users_load((int)$_POST['toggle_active_id']);
        if (!$u) redirect('admin/users.php');
        if ((int)$u['id'] === (int)$ADMIN['id']) users_deny('ระงับบัญชีตัวเองไม่ได้');
        if (!users_manageable($u, $roleById)) users_deny('คุณไม่มีสิทธิ์จัดการบัญชี «' . $u['username'] . '»');
        $to = (int)$u['is_active'] === 1 ? 0 : 1;
        if ($to === 0 && $u['role'] === 'admin' && count_admins() <= 1) users_deny('ต้องมีผู้ดูแลระบบสูงสุดที่ใช้งานได้อย่างน้อย 1 คน');
        db()->prepare('UPDATE users SET is_active = ? WHERE id = ?')->execute([$to, (int)$u['id']]);
        log_action($to ? 'เปิดใช้งานบัญชี' : 'ระงับบัญชี', $u['username']);
        flash_set('success', ($to ? 'เปิดใช้งานบัญชี ' : 'ระงับบัญชี ') . $u['username'] . ' แล้ว' . ($to ? '' : ' — ผู้ใช้ถูกออกจากระบบทันที'));
        redirect('admin/users.php');
    }

    /* ── รีเซ็ต 2FA ของผู้ใช้ (กรณีทำมือถือหาย/ล็อกเอาต์) ── */
    if (isset($_POST['reset_2fa_id'])) {
        $u = users_load((int)$_POST['reset_2fa_id']);
        if ($u) {
            if (!users_manageable($u, $roleById)) users_deny('คุณไม่มีสิทธิ์จัดการบัญชี «' . $u['username'] . '»');
            db()->prepare("UPDATE users SET totp_secret = '', totp_enabled = 0, backup_codes = NULL WHERE id = ?")->execute([(int)$u['id']]);
            log_action('รีเซ็ต 2FA ผู้ใช้', $u['username']);
            flash_set('success', 'รีเซ็ตการยืนยันสองชั้นของ ' . $u['username'] . ' แล้ว — ผู้ใช้เข้าระบบด้วยรหัสผ่านได้ทันที');
        }
        redirect('admin/users.php');
    }

    /* ── เพิ่ม / แก้ไข ── */
    $id       = (int)($_POST['id'] ?? 0);
    $username = trim((string)($_POST['username'] ?? ''));
    $display  = mb_substr(trim((string)($_POST['display_name'] ?? '')), 0, 150);
    $email    = mb_substr(trim((string)($_POST['email'] ?? '')), 0, 150);
    $password = (string)($_POST['password'] ?? '');
    $pick     = (string)($_POST['role_pick'] ?? '');

    $target = $id > 0 ? users_load($id) : null;
    if ($id > 0 && !$target) redirect('admin/users.php');
    if ($target && !users_manageable($target, $roleById)) users_deny('คุณไม่มีสิทธิ์แก้ไขบัญชี «' . $target['username'] . '»');

    /* บทบาท: admin = ผู้ดูแลระบบสูงสุด · r<ID> = เจ้าหน้าที่ในกลุ่มสิทธิ์นั้น */
    $role = 'editor'; $roleId = null;
    if ($pick === 'admin') {
        if (!$isSuper) users_deny('เฉพาะผู้ดูแลระบบสูงสุดเท่านั้นที่ตั้งผู้ดูแลระบบสูงสุดได้');
        $role = 'admin';
    } elseif (preg_match('/^r(\d+)$/', $pick, $m) && isset($roleById[(int)$m[1]])) {
        if (!users_role_assignable($roleById[(int)$m[1]])) users_deny('กลุ่มสิทธิ์ที่เลือกมีสิทธิ์มากกว่าของคุณ — มอบให้ผู้อื่นไม่ได้');
        $roleId = (int)$m[1];
    } else {
        $errors[] = 'กรุณาเลือกกลุ่มสิทธิ์';
    }

    if ($id === 0 && !preg_match('/^[A-Za-z0-9_.@-]{4,50}$/', $username)) {
        $errors[] = 'ชื่อผู้ใช้ต้องยาว 4–50 ตัว (อังกฤษ/ตัวเลข/_.@-)';
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'รูปแบบอีเมลไม่ถูกต้อง';
    if ($id === 0 && mb_strlen($password) < 8) $errors[] = 'รหัสผ่านต้องยาวอย่างน้อย 8 ตัวอักษร';
    if ($id > 0 && $password !== '' && mb_strlen($password) < 8) $errors[] = 'รหัสผ่านใหม่ต้องยาวอย่างน้อย 8 ตัวอักษร';
    if ($target && $target['role'] === 'admin' && $role !== 'admin' && (int)$target['is_active'] === 1 && count_admins() <= 1) {
        $errors[] = 'ต้องมีผู้ดูแลระบบสูงสุดอย่างน้อย 1 คน';
    }
    if ($target && (int)$target['id'] === (int)$ADMIN['id'] && $role !== 'admin') {
        $errors[] = 'ลดสิทธิ์บัญชีของตัวเองไม่ได้ — ให้ผู้ดูแลระบบสูงสุดคนอื่นเป็นผู้ปรับ';
    }

    if (!$errors) {
        $roleLabel = $role === 'admin' ? 'ผู้ดูแลระบบสูงสุด' : $roleById[$roleId]['name'];
        if ($target) {
            $sql = 'UPDATE users SET display_name=?, email=?, role=?, role_id=?' . ($password !== '' ? ', password_hash=?' : '') . ' WHERE id=?';
            $args = [$display, $email, $role, $roleId];
            if ($password !== '') $args[] = password_hash($password, PASSWORD_BCRYPT);
            $args[] = $id;
            db()->prepare($sql)->execute($args);
            log_action('แก้ไขผู้ใช้', $target['username'] . ' → ' . $roleLabel);
            flash_set('success', 'บันทึกผู้ใช้เรียบร้อยแล้ว');
        } else {
            try {
                db()->prepare('INSERT INTO users (username, display_name, email, role, role_id, password_hash) VALUES (?,?,?,?,?,?)')
                    ->execute([$username, $display ?: $username, $email, $role, $roleId, password_hash($password, PASSWORD_BCRYPT)]);
                log_action('เพิ่มผู้ใช้', $username . ' · ' . $roleLabel);
                flash_set('success', 'เพิ่มผู้ใช้เรียบร้อยแล้ว');
            } catch (PDOException $e) {
                $errors[] = 'ชื่อผู้ใช้นี้มีอยู่แล้ว';
            }
        }
        if (!$errors) redirect('admin/users.php');
    }
}

if (isset($_GET['edit'])) {
    $edit = users_load((int)$_GET['edit']);
    if ($edit && !users_manageable($edit, $roleById)) {
        flash_set('danger', 'คุณไม่มีสิทธิ์แก้ไขบัญชีนี้');
        redirect('admin/users.php');
    }
}

$users = db()->query("SELECT id, username, display_name, email, role, role_id, is_active, last_login_at, totp_enabled, created_at
                      FROM users ORDER BY role = 'admin' DESC, is_active DESC, id ASC")->fetchAll();
$canEdit = can('users', PERM_EDIT);
$canDel  = can('users', PERM_FULL);
$assignable = array_filter($roleById, 'users_role_assignable');
$curPick = (string)($_POST['role_pick'] ?? ($edit ? ($edit['role'] === 'admin' ? 'admin' : 'r' . (int)$edit['role_id']) : ''));
$admin_title = 'ผู้ใช้งานระบบ';
require __DIR__ . '/_top.php';
?>
<?php if ($errors): ?>
<div class="alert danger"><span class="material-symbols-rounded">error</span>
  <div><?php foreach ($errors as $er): ?><div><?= e($er) ?></div><?php endforeach; ?></div>
</div>
<?php endif; ?>

<?php if ($canEdit): ?>
<div class="card mb-2" id="user-form">
  <div class="section-head" style="margin-bottom:8px;">
    <div><span class="tag">USER</span><h3><?= $edit ? 'แก้ไขผู้ใช้ «' . e($edit['username']) . '»' : 'เพิ่มผู้ใช้ใหม่' ?></h3>
    <p>เลือก <b>กลุ่มสิทธิ์</b> เพื่อกำหนดว่าผู้ใช้เห็นเมนูไหนและทำอะไรได้บ้าง<?php if ($isSuper): ?> · สร้าง/ปรับกลุ่มได้ที่ <a href="<?= e(url('admin/roles.php')) ?>">กลุ่มสิทธิ์</a><?php else: ?> · คุณเลือกได้เฉพาะกลุ่มที่สิทธิ์ไม่เกินของตัวเอง<?php endif; ?></p></div>
    <?php if ($edit): ?><a class="btn small" href="<?= e(url('admin/users.php')) ?>">ยกเลิก</a><?php endif; ?>
  </div>
  <?php if (!$assignable && !$isSuper): ?>
  <p class="text-muted">ยังไม่มีกลุ่มสิทธิ์ที่คุณมอบให้ผู้อื่นได้ — ติดต่อผู้ดูแลระบบสูงสุด</p>
  <?php else: ?>
  <form method="post" action="">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
    <div class="form-row">
      <div>
        <label>ชื่อผู้ใช้ (login) <span style="color:var(--danger);">*</span></label>
        <?php if ($edit): ?>
        <input type="text" value="<?= e($edit['username']) ?>" disabled class="mb-2" style="opacity:.7;">
        <?php else: ?>
        <input type="text" name="username" value="<?= e($_POST['username'] ?? '') ?>" required class="mb-2" placeholder="4–50 ตัว (อังกฤษ/ตัวเลข)" autocomplete="off">
        <?php endif; ?>
        <label>ชื่อที่แสดง</label>
        <input type="text" name="display_name" value="<?= e($_POST['display_name'] ?? $edit['display_name'] ?? '') ?>" class="mb-2" placeholder="เช่น นายตัวอย่าง (ฝ่ายข่าว)">
        <label>อีเมล <span class="text-muted" style="font-weight:400;font-size:11.5px;">(ใช้กู้รหัสผ่านเมื่อลืม — แนะนำให้กรอก)</span></label>
        <input type="email" name="email" value="<?= e($_POST['email'] ?? $edit['email'] ?? '') ?>" class="mb-2" placeholder="name@domain.go.th">
      </div>
      <div>
        <label>กลุ่มสิทธิ์ <span style="color:var(--danger);">*</span></label>
        <select name="role_pick" class="mb-2" required>
          <option value="">— เลือกกลุ่มสิทธิ์ —</option>
          <?php if ($isSuper): ?><option value="admin" <?= $curPick === 'admin' ? 'selected' : '' ?>>★ ผู้ดูแลระบบสูงสุด (ทุกเมนู)</option><?php endif; ?>
          <?php foreach ($assignable as $rid => $r): ?>
          <option value="r<?= (int)$rid ?>" <?= $curPick === 'r' . $rid ? 'selected' : '' ?>><?= e($r['name']) ?><?= $r['description'] !== '' ? ' — ' . e(mb_substr($r['description'], 0, 50)) : '' ?></option>
          <?php endforeach; ?>
        </select>
        <?php if ($isSuper && !$roles): ?><p class="text-muted" style="font-size:12px;margin:-4px 0 8px;">ยังไม่มีกลุ่มสิทธิ์ — <a href="<?= e(url('admin/roles.php')) ?>">สร้างกลุ่มแรก</a></p><?php endif; ?>
        <label>รหัสผ่าน <?= $edit ? '<span class="text-muted">(เว้นว่าง = ไม่เปลี่ยน)</span>' : '<span style="color:var(--danger);">*</span>' ?></label>
        <input type="password" name="password" <?= $edit ? '' : 'required' ?> autocomplete="new-password" class="mb-2" placeholder="อย่างน้อย 8 ตัวอักษร">
      </div>
    </div>
    <button class="btn primary" type="submit"><span class="material-symbols-rounded icon-sm">save</span><?= $edit ? 'บันทึกการแก้ไข' : 'เพิ่มผู้ใช้' ?></button>
  </form>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="card">
  <div class="section-head" style="margin-bottom:8px;">
    <div><span class="tag">ALL USERS</span><h3>ผู้ใช้ทั้งหมด (<?= count($users) ?>)</h3>
    <p><b>ระงับ</b> = เข้าระบบไม่ได้ทันทีแต่ไม่ลบบัญชี (เหมาะกับคนย้ายงาน/ลาพัก) · ทุกการกระทำถูกบันทึกในบันทึกการใช้งาน</p></div>
  </div>
  <div class="table-wrap">
  <table class="admin-table">
    <tr><th>ชื่อผู้ใช้</th><th>กลุ่มสิทธิ์</th><th>สถานะ</th><th>เข้าระบบล่าสุด</th><th>2FA</th><th></th></tr>
    <?php foreach ($users as $u): $mine = (int)$u['id'] === (int)$ADMIN['id']; $mg = users_manageable($u, $roleById); $off = (int)$u['is_active'] !== 1; ?>
    <tr<?= $off ? ' class="user-off"' : '' ?>>
      <td><b><?= e($u['username']) ?></b><?= $mine ? ' <span class="badge" style="font-size:10px;">คุณ</span>' : '' ?>
        <div class="text-muted" style="font-size:12px;"><?= e($u['display_name']) ?><?php if (trim((string)$u['email']) !== ''): ?> · <?= e($u['email']) ?><?php else: ?> · <span title="ไม่มีอีเมล จะกู้รหัสผ่านเองไม่ได้">ยังไม่มีอีเมล</span><?php endif; ?></div></td>
      <td><?php if ($u['role'] === 'admin'): ?><span class="badge special" style="font-size:11px;">★ ผู้ดูแลระบบสูงสุด</span>
          <?php elseif (isset($roleById[(int)$u['role_id']])): ?><span class="badge" style="font-size:11px;"><?= e($roleById[(int)$u['role_id']]['name']) ?></span>
          <?php else: ?><span class="badge warning" style="font-size:11px;" title="ไม่มีกลุ่มสิทธิ์ — เห็นแค่แดชบอร์ด">ยังไม่มีกลุ่ม</span><?php endif; ?></td>
      <td><?= $off ? '<span class="badge danger" style="font-size:10.5px;">ระงับ</span>' : '<span class="badge success" style="font-size:10.5px;">ใช้งาน</span>' ?></td>
      <td class="lr-date" style="white-space:nowrap;"><?= $u['last_login_at'] ? e(thai_date($u['last_login_at'])) . ' ' . e(substr((string)$u['last_login_at'], 11, 5)) : '—' ?></td>
      <td><?php if ((int)$u['totp_enabled'] === 1): ?><span class="badge success" style="font-size:10px;"><span class="material-symbols-rounded icon-sm">verified_user</span>เปิด</span><?php else: ?><span class="lr-date">—</span><?php endif; ?></td>
      <td style="white-space:nowrap;">
        <?php if ($mg && $canEdit): ?>
        <a class="btn small" href="<?= e(url('admin/users.php?edit=' . $u['id'])) ?>#user-form">แก้ไข</a>
        <?php if (!$mine): ?>
        <form method="post" action="" style="display:inline;"><?= csrf_field() ?>
          <input type="hidden" name="toggle_active_id" value="<?= (int)$u['id'] ?>">
          <button class="btn small" type="submit" data-confirm="<?= $off ? 'เปิดใช้งานบัญชี «' . e($u['username']) . '» อีกครั้ง?' : 'ระงับบัญชี «' . e($u['username']) . '»? ผู้ใช้จะถูกออกจากระบบทันทีและเข้าไม่ได้จนกว่าจะเปิดใหม่' ?>"><?= $off ? 'เปิดใช้งาน' : 'ระงับ' ?></button>
        </form>
        <?php endif; ?>
        <?php if ((int)$u['totp_enabled'] === 1): ?>
        <form method="post" action="" style="display:inline;"><?= csrf_field() ?>
          <input type="hidden" name="reset_2fa_id" value="<?= (int)$u['id'] ?>">
          <button class="btn small" type="submit" data-confirm="รีเซ็ต 2FA ของ «<?= e($u['username']) ?>»? ผู้ใช้จะเข้าระบบด้วยรหัสผ่านได้ทันที (ใช้กรณีทำมือถือหาย)">รีเซ็ต 2FA</button>
        </form>
        <?php endif; ?>
        <?php if (!$mine && $canDel): ?>
        <form method="post" action="" style="display:inline;"><?= csrf_field() ?>
          <input type="hidden" name="delete_id" value="<?= (int)$u['id'] ?>">
          <button class="btn small danger" type="submit" data-confirm="ยืนยันลบผู้ใช้ «<?= e($u['username']) ?>»? ลบแล้วกู้คืนไม่ได้ — ถ้าแค่ไม่ให้เข้าระบบ ใช้ 'ระงับ' แทน">ลบ</button>
        </form>
        <?php endif; ?>
        <?php elseif (!$mine): ?><span class="text-muted" style="font-size:12px;" title="บัญชีนี้มีสิทธิ์มากกว่าคุณ หรือเป็นผู้ดูแลระบบสูงสุด"><span class="material-symbols-rounded icon-sm" style="vertical-align:-3px;">lock</span> นอกขอบเขต</span>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
  </div>
</div>
<?php require __DIR__ . '/_bottom.php'; ?>
