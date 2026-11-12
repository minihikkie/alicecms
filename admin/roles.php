<?php
/** admin/roles.php — กลุ่มสิทธิ์: กำหนดว่าแต่ละกลุ่มเข้าเมนูไหน ทำอะไรได้บ้าง (เฉพาะผู้ดูแลระบบสูงสุด) */
require dirname(__DIR__) . '/includes/init.php';
require __DIR__ . '/_auth.php';
require_admin();
perm_require_schema();

$errors = [];
$edit = null;
$mods = perm_modules();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    /* ── ลบกลุ่ม (ต้องไม่มีผู้ใช้อยู่ในกลุ่ม) ── */
    if (isset($_POST['delete_role'])) {
        $id = (int)$_POST['delete_role'];
        $st = db()->prepare('SELECT name FROM roles WHERE id = ?');
        $st->execute([$id]);
        $name = $st->fetchColumn();
        $st = db()->prepare('SELECT COUNT(*) FROM users WHERE role_id = ? AND role = \'editor\'');
        $st->execute([$id]);
        if ($name === false) {
            flash_set('danger', 'ไม่พบกลุ่มสิทธิ์');
        } elseif ((int)$st->fetchColumn() > 0) {
            flash_set('danger', 'ยังมีผู้ใช้อยู่ในกลุ่ม "' . $name . '" — ย้ายผู้ใช้ไปกลุ่มอื่นก่อนจึงจะลบได้');
        } else {
            db()->prepare('DELETE FROM roles WHERE id = ?')->execute([$id]);
            db()->prepare('UPDATE users SET role_id = NULL WHERE role_id = ?')->execute([$id]);
            log_action('ลบกลุ่มสิทธิ์', mb_substr((string)$name, 0, 100));
            flash_set('success', 'ลบกลุ่มสิทธิ์เรียบร้อยแล้ว');
        }
        redirect('admin/roles.php');
    }

    $id   = (int)($_POST['id'] ?? 0);
    $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 100);
    $desc = mb_substr(trim((string)($_POST['description'] ?? '')), 0, 255);
    $perms = perm_clean((array)($_POST['perm'] ?? []));
    if ($name === '') $errors[] = 'กรุณาตั้งชื่อกลุ่มสิทธิ์';
    if (!$errors) {
        $st = db()->prepare('SELECT COUNT(*) FROM roles WHERE name = ? AND id <> ?');
        $st->execute([$name, $id]);
        if ((int)$st->fetchColumn() > 0) $errors[] = 'มีกลุ่มชื่อนี้อยู่แล้ว';
    }
    if (!$errors) {
        $json = json_encode($perms ?: new stdClass());
        if ($id > 0) {
            db()->prepare('UPDATE roles SET name = ?, description = ?, perms = ? WHERE id = ?')->execute([$name, $desc, $json, $id]);
            log_action('แก้ไขกลุ่มสิทธิ์', $name . ' · ' . count($perms) . ' เมนู');
        } else {
            db()->prepare('INSERT INTO roles (name, description, perms) VALUES (?, ?, ?)')->execute([$name, $desc, $json]);
            log_action('เพิ่มกลุ่มสิทธิ์', $name . ' · ' . count($perms) . ' เมนู');
        }
        flash_set('success', 'บันทึกกลุ่มสิทธิ์ "' . $name . '" แล้ว — มีผลกับผู้ใช้ในกลุ่มทันที');
        redirect('admin/roles.php');
    }
}

if (isset($_GET['edit'])) {
    $st = db()->prepare('SELECT * FROM roles WHERE id = ?');
    $st->execute([(int)$_GET['edit']]);
    $edit = $st->fetch() ?: null;
}
$copyFrom = null;
if (!$edit && isset($_GET['copy'])) {
    $st = db()->prepare('SELECT * FROM roles WHERE id = ?');
    $st->execute([(int)$_GET['copy']]);
    $copyFrom = $st->fetch() ?: null;
}
$cur = $_SERVER['REQUEST_METHOD'] === 'POST' ? perm_clean((array)($_POST['perm'] ?? []))
     : perm_clean($edit['perms'] ?? $copyFrom['perms'] ?? '');

$roles = db()->query("SELECT r.*, (SELECT COUNT(*) FROM users u WHERE u.role_id = r.id AND u.role = 'editor') AS n_users
                      FROM roles r ORDER BY r.name")->fetchAll();
$levels = perm_level_names();
$groups = [];
foreach ($mods as $k => $m) $groups[$m[1]][$k] = $m;

$admin_title = 'กลุ่มสิทธิ์';
require __DIR__ . '/_top.php';
?>
<?php if ($errors): ?>
<div class="alert danger"><span class="material-symbols-rounded">error</span>
  <div><?php foreach ($errors as $er): ?><div><?= e($er) ?></div><?php endforeach; ?></div>
</div>
<?php endif; ?>

<div class="card mb-2">
  <div class="section-head" style="margin-bottom:8px;">
    <div><span class="tag">ROLES</span><h3>กลุ่มสิทธิ์ (<?= count($roles) ?>)</h3>
    <p>กำหนดว่าเจ้าหน้าที่แต่ละกลุ่ม <b>เห็นเมนูไหน</b> และ <b>ทำอะไรได้บ้าง</b> · แก้กลุ่มแล้วมีผลกับทุกคนในกลุ่มทันที ·
       ผู้ดูแลระบบสูงสุดทำได้ทุกอย่างเสมอ ไม่ต้องอยู่ในกลุ่มใด</p></div>
    <a class="btn small primary" href="<?= e(url('admin/roles.php')) ?>#role-form"><span class="material-symbols-rounded icon-sm">add</span>สร้างกลุ่มใหม่</a>
  </div>
  <?php if (!$roles): ?>
  <p class="text-muted">ยังไม่มีกลุ่มสิทธิ์ — สร้างกลุ่มแรกด้านล่าง (เลือกแม่แบบสำเร็จรูปแล้วปรับได้)</p>
  <?php else: ?>
  <table class="admin-table">
    <tr><th>ชื่อกลุ่ม</th><th>สิทธิ์โดยสรุป</th><th style="text-align:center;white-space:nowrap;">ผู้ใช้</th><th></th></tr>
    <?php foreach ($roles as $r): $p = perm_clean($r['perms']); $cnt = array_count_values($p); ?>
    <tr>
      <td><b><?= e($r['name']) ?></b><?php if ($r['description'] !== ''): ?><div class="text-muted" style="font-size:12px;"><?= e($r['description']) ?></div><?php endif; ?></td>
      <td style="font-size:12.5px;">
        <?php if (!$p): ?><span class="text-muted">ยังไม่มีสิทธิ์ใดๆ (เห็นแค่แดชบอร์ด)</span><?php endif; ?>
        <?php foreach ([PERM_FULL => 'danger', PERM_EDIT => 'special', PERM_VIEW => ''] as $lv => $cls): if (!empty($cnt[$lv])): ?>
        <span class="badge <?= $cls ?>" style="font-size:11px;" title="<?= e(implode(', ', array_map(fn($k) => $mods[$k][0], array_keys(array_filter($p, fn($x) => $x === $lv))))) ?>"><?= e($levels[$lv]) ?> <?= (int)$cnt[$lv] ?> เมนู</span>
        <?php endif; endforeach; ?>
        <?php if (isset($p['users'])): ?><span class="badge warning" style="font-size:11px;"><span class="material-symbols-rounded icon-sm">manage_accounts</span>จัดการผู้ใช้ได้</span><?php endif; ?>
      </td>
      <td style="text-align:center;"><?= (int)$r['n_users'] ?></td>
      <td style="white-space:nowrap;">
        <a class="btn small" href="<?= e(url('admin/roles.php?edit=' . $r['id'])) ?>#role-form">แก้ไข</a>
        <a class="btn small" href="<?= e(url('admin/roles.php?copy=' . $r['id'])) ?>#role-form" title="สร้างกลุ่มใหม่โดยตั้งต้นจากสิทธิ์ของกลุ่มนี้">ทำสำเนา</a>
        <?php if ((int)$r['n_users'] === 0): ?>
        <form method="post" action="" style="display:inline;">
          <?= csrf_field() ?><input type="hidden" name="delete_role" value="<?= (int)$r['id'] ?>">
          <button class="btn small danger" type="submit" data-confirm="ลบกลุ่มสิทธิ์ «<?= e($r['name']) ?>»?">ลบ</button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>
</div>

<div class="card" id="role-form">
  <div class="section-head" style="margin-bottom:8px;">
    <div><span class="tag"><?= $edit ? 'EDIT' : 'NEW' ?></span><h3><?= $edit ? 'แก้ไขกลุ่ม "' . e($edit['name']) . '"' : 'สร้างกลุ่มสิทธิ์ใหม่' ?></h3>
    <p>เลือกระดับสิทธิ์ของแต่ละเมนู — <b>ไม่เห็นเมนู</b> · <b>ดูอย่างเดียว</b> · <b>เพิ่ม · แก้ไข</b> · <b>เพิ่ม · แก้ไข · ลบ</b>
       (ของที่ลบไปอยู่ในถังขยะ กู้คืนได้ 30 วัน)</p></div>
    <?php if ($edit || $copyFrom): ?><a class="btn small" href="<?= e(url('admin/roles.php')) ?>">ยกเลิก</a><?php endif; ?>
  </div>

  <div class="perm-tpl">
    <span class="text-muted" style="font-size:12.5px;">เริ่มจากแม่แบบ:</span>
    <?php foreach (perm_templates() as $tk => [$tn, $td, $tp]): ?>
    <button type="button" class="btn small" data-perm-tpl='<?= e(json_encode(perm_clean($tp))) ?>' data-perm-name="<?= e($tn) ?>" data-perm-desc="<?= e($td) ?>" title="<?= e($td) ?>"><?= e($tn) ?></button>
    <?php endforeach; ?>
    <button type="button" class="btn small" data-perm-tpl='{}' title="ล้างสิทธิ์ทั้งหมด">ล้างทั้งหมด</button>
  </div>

  <form method="post" action="" data-perm-form>
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
    <div class="form-row">
      <div><label>ชื่อกลุ่ม <span style="color:var(--danger);">*</span></label>
        <input type="text" name="name" maxlength="100" required value="<?= e($_POST['name'] ?? $edit['name'] ?? ($copyFrom ? $copyFrom['name'] . ' (สำเนา)' : '')) ?>" placeholder="เช่น ฝ่ายข่าวประชาสัมพันธ์"></div>
      <div><label>คำอธิบาย</label>
        <input type="text" name="description" maxlength="255" value="<?= e($_POST['description'] ?? $edit['description'] ?? $copyFrom['description'] ?? '') ?>" placeholder="กลุ่มนี้ทำอะไร ใครอยู่บ้าง"></div>
    </div>

    <div class="perm-matrix">
      <div class="perm-head"><span>เมนู</span><?php foreach ($levels as $lv => $ln): ?><span><?= e($ln) ?></span><?php endforeach; ?></div>
      <?php foreach ($groups as $gname => $gm): ?>
      <div class="perm-group">
        <b><?= e($gname) ?></b>
        <span class="perm-all text-muted">ทั้งกลุ่ม:
          <?php foreach ($levels as $lv => $ln): ?><button type="button" data-perm-col="<?= $lv ?>"><?= e($ln) ?></button><?php endforeach; ?>
        </span>
      </div>
      <?php foreach ($gm as $k => [$label, , $max, $note]): $v = (int)($cur[$k] ?? 0); ?>
      <div class="perm-row<?= $k === 'users' || $k === 'complaints' ? ' is-sensitive' : '' ?>">
        <span class="perm-name"><?= e($label) ?><?php if ($note !== ''): ?><small><?= e($note) ?></small><?php endif; ?></span>
        <?php foreach ($levels as $lv => $ln): ?>
        <label class="perm-cell<?= $lv > $max ? ' na' : '' ?>" title="<?= $lv > $max ? 'เมนูนี้ไม่มีระดับนี้' : e($label . ' — ' . $ln) ?>">
          <?php if ($lv <= $max): ?>
          <input type="radio" name="perm[<?= e($k) ?>]" value="<?= $lv ?>" <?= $v === $lv ? 'checked' : '' ?> aria-label="<?= e($label . ' — ' . $ln) ?>">
          <?php else: ?><span aria-hidden="true">—</span><?php endif; ?>
          <span class="perm-mob"><?= e($ln) ?></span>
        </label>
        <?php endforeach; ?>
      </div>
      <?php endforeach; endforeach; ?>
    </div>
    <p class="text-muted" style="font-size:12px;margin:10px 0;">
      <span class="material-symbols-rounded icon-sm" style="vertical-align:-3px;">lock</span>
      เมนูที่มอบให้ไม่ได้ (เฉพาะผู้ดูแลระบบสูงสุด): กลุ่มสิทธิ์ · สำรองข้อมูล · โอนย้ายเว็บ · แจ้งเตือนอีเมล · อัปเดตระบบ · ล้างถังขยะทั้งหมด
    </p>
    <button class="btn primary" type="submit"><span class="material-symbols-rounded icon-sm">save</span><?= $edit ? 'บันทึกการแก้ไข' : 'สร้างกลุ่ม' ?></button>
  </form>
</div>
<?php require __DIR__ . '/_bottom.php'; ?>
