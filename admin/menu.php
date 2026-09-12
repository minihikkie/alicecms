<?php
/** admin/menu.php — จัดการเมนูนำทาง (เมนูย่อย + ลากเรียง + เลือกปลายทาง + โหมดคุมเอง) */
require dirname(__DIR__) . '/includes/init.php';
require __DIR__ . '/_auth.php';
require_admin();

/* หน้าระบบที่ใส่เมนูได้ */
$sys_targets = [
    'index.php' => 'หน้าแรก', 'news.php' => 'ข่าวสาร', 'documents.php' => 'เอกสาร',
    'procurement.php' => 'จัดซื้อจัดจ้าง', 'ita.php' => 'ITA', 'personnel.php' => 'ผู้บริหาร',
    'faq.php' => 'คำถามที่พบบ่อย', 'complaint.php' => 'ร้องเรียน', 'about.php' => 'เกี่ยวกับ', 'contact.php' => 'ติดต่อ',
];

$errors = [];
$edit = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    /* ── ลากเรียง (fetch JSON) ── */
    if (($_POST['action'] ?? '') === 'reorder') {
        header('Content-Type: application/json');
        $parent = (int)($_POST['parent'] ?? 0) ?: null;
        $ids = json_decode((string)($_POST['order'] ?? '[]'), true) ?: [];
        $i = 0;
        foreach ($ids as $id) {
            $id = (int)$id;
            /* กันซ้อนเกิน 1 ชั้น: ถ้า item นี้มีเมนูย่อยอยู่ ห้ามเอาไปเป็นเมนูย่อย */
            $p = $parent;
            if ($p !== null) {
                $has = db()->prepare('SELECT COUNT(*) FROM menu_items WHERE parent_id = ?');
                $has->execute([$id]);
                if ((int)$has->fetchColumn() > 0) $p = null;
            }
            db()->prepare('UPDATE menu_items SET sort_order = ?, parent_id = ? WHERE id = ?')->execute([$i++, $p, $id]);
        }
        echo json_encode(['ok' => true]);
        exit;
    }
    /* ── โหมดคุมเมนูเอง ── */
    if (isset($_POST['save_mode'])) {
        setting_set('nav_custom', !empty($_POST['nav_custom']) ? '1' : '0');
        flash_set('success', 'บันทึกโหมดเมนูแล้ว');
        redirect('admin/menu.php');
    }
    /* ── นำเข้าเมนูปัจจุบันจากระบบ ── */
    if (isset($_POST['seed_menu'])) {
        $n = (int)db()->query('SELECT COUNT(*) FROM menu_items')->fetchColumn();
        if ($n === 0) {
            $seed = [['index.php', 'หน้าแรก']];
            foreach (nav_section_defs() as $nd) {
                foreach ($nd['keys'] as $k) {
                    if (section_in_menu($k)) { $seed[] = [$nd['file'], $nd['label']]; break; }
                }
            }
            $seed[] = ['about.php', 'เกี่ยวกับ'];
            $seed[] = ['contact.php', 'ติดต่อ'];
            $st = db()->prepare('INSERT INTO menu_items (label, url, sort_order, enabled) VALUES (?,?,?,1)');
            foreach ($seed as $i => $s) $st->execute([$s[1], $s[0], $i]);
            flash_set('success', 'นำเข้าเมนูจากระบบแล้ว ' . count($seed) . ' รายการ — ลากจัดเรียง/เพิ่มเมนูย่อยได้เลย');
        } else {
            flash_set('danger', 'มีเมนูอยู่แล้ว ไม่นำเข้าซ้ำ');
        }
        redirect('admin/menu.php');
    }
    if (isset($_POST['delete_id'])) {
        $id = (int)$_POST['delete_id'];
        db()->prepare('UPDATE menu_items SET parent_id = NULL WHERE parent_id = ?')->execute([$id]); // เมนูย่อยเลื่อนขึ้นเป็นหลัก
        if (trash_delete('menu_items', $id, $ADMIN['username'] ?? '')) {
            log_action('ลบเมนู (ลงถังขยะ)', '#' . $id);
            flash_set('success', 'ย้ายเมนูลงถังขยะแล้ว — กู้คืนได้ที่เมนู "ถังขยะ"');
        }
        redirect('admin/menu.php');
    }
    if (isset($_POST['toggle_id'])) {
        db()->prepare('UPDATE menu_items SET enabled = 1 - enabled WHERE id = ?')->execute([(int)$_POST['toggle_id']]);
        redirect('admin/menu.php');
    }

    /* ── เพิ่ม/แก้ไข ── */
    $id      = (int)($_POST['id'] ?? 0);
    $label   = mb_substr(trim((string)($_POST['label'] ?? '')), 0, 120);
    $u       = safe_link_url((string)($_POST['url'] ?? ''));
    $parent  = (int)($_POST['parent_id'] ?? 0) ?: null;
    $new_tab = !empty($_POST['new_tab']) ? 1 : 0;
    $enabled = !empty($_POST['enabled']) ? 1 : 0;

    if ($label === '') $errors[] = 'กรุณากรอกชื่อเมนู';
    if ($u === '')     $errors[] = 'กรุณาเลือกหรือกรอกปลายทาง';
    if ($u !== '' && !preg_match('#^(https?://|/|[a-z0-9_\-]+\.php)#i', $u)) $errors[] = 'ปลายทางไม่ถูกต้อง';
    /* เมนูแม่ต้องเป็นเมนูหลัก (กันซ้อนเกิน 1 ชั้น) และต้องไม่ใช่ตัวเอง */
    if ($parent) {
        if ($parent === $id) { $parent = null; }
        else {
            $pc = db()->prepare('SELECT parent_id FROM menu_items WHERE id = ?');
            $pc->execute([$parent]);
            $pp = $pc->fetch();
            if (!$pp || $pp['parent_id'] !== null) $errors[] = 'เมนูแม่ต้องเป็นเมนูหลัก';
        }
    }
    /* ถ้าตัวเองมีเมนูย่อยอยู่ จะเป็นเมนูย่อยของใครไม่ได้ */
    if (!$errors && $parent && $id) {
        $hc = db()->prepare('SELECT COUNT(*) FROM menu_items WHERE parent_id = ?');
        $hc->execute([$id]);
        if ((int)$hc->fetchColumn() > 0) $errors[] = 'เมนูนี้มีเมนูย่อยอยู่ จึงเป็นเมนูย่อยไม่ได้';
    }

    if (!$errors) {
        if ($id > 0) {
            db()->prepare('UPDATE menu_items SET label=?, url=?, parent_id=?, new_tab=?, enabled=? WHERE id=?')
                ->execute([$label, $u, $parent, $new_tab, $enabled, $id]);
        } else {
            $mx = (int)db()->query('SELECT COALESCE(MAX(sort_order),0) FROM menu_items')->fetchColumn();
            db()->prepare('INSERT INTO menu_items (label, url, parent_id, sort_order, new_tab, enabled) VALUES (?,?,?,?,?,?)')
                ->execute([$label, $u, $parent, $mx + 1, $new_tab, $enabled]);
        }
        log_action('จัดการเมนูนำทาง', $label);
        flash_set('success', 'บันทึกเมนูแล้ว');
        redirect('admin/menu.php');
    }
}

if (isset($_GET['edit'])) {
    $st = db()->prepare('SELECT * FROM menu_items WHERE id = ?');
    $st->execute([(int)$_GET['edit']]);
    $edit = $st->fetch();
}

$all   = db()->query('SELECT * FROM menu_items ORDER BY sort_order ASC, id ASC')->fetchAll();
$tops  = array_values(array_filter($all, fn($m) => empty($m['parent_id'])));
$kids  = [];
foreach ($all as $m) if (!empty($m['parent_id'])) $kids[(int)$m['parent_id']][] = $m;
$pages = db()->query("SELECT slug, title FROM pages WHERE status='published' ORDER BY sort_order ASC")->fetchAll();
$nav_custom = setting('nav_custom', '0') === '1';

/* ป้ายปลายทางอ่านง่าย */
$label_target = function ($u) use ($sys_targets) {
    if (preg_match('#^https?://#', $u)) return 'ลิงก์ภายนอก';
    if (preg_match('#page\.php\?slug=#', $u)) return 'หน้าเพจ';
    return $sys_targets[$u] ?? $u;
};

$admin_title = 'เมนูนำทาง';
require __DIR__ . '/_top.php';
?>
<!-- โหมดเมนู -->
<div class="card mb-2">
  <div class="section-head" style="margin-bottom:8px;">
    <div><span class="tag"><span class="material-symbols-rounded icon-sm">tune</span> MODE</span><h3>โหมดเมนูนำทาง</h3>
    <p>เลือกว่าจะให้ระบบจัดเมนูอัตโนมัติ หรือคุณคุมเองทั้งหมด (รองรับเมนูย่อย)</p></div>
  </div>
  <form method="post" action="" class="flex gap-2 items-center" style="flex-wrap:wrap;">
    <?= csrf_field() ?>
    <input type="hidden" name="save_mode" value="1">
    <label class="inline-check" style="margin:0;"><input type="checkbox" name="nav_custom" value="1" <?= $nav_custom ? 'checked' : '' ?>><b>คุมเมนูเองทั้งหมด</b> (ใช้รายการด้านล่างแทนเมนูอัตโนมัติ)</label>
    <button class="btn small primary" type="submit"><span class="material-symbols-rounded icon-sm">save</span>บันทึก</button>
    <?php if (!$all): ?>
    <button class="btn small" type="submit" name="seed_menu" value="1"><span class="material-symbols-rounded icon-sm">download</span>นำเข้าเมนูปัจจุบันจากระบบ</button>
    <?php endif; ?>
  </form>
  <?php if ($nav_custom && !$all): ?>
  <div class="alert info mt-2" style="font-size:13px;"><span class="material-symbols-rounded">info</span><div>เปิดโหมดคุมเองแล้วแต่ยังไม่มีเมนู — กด "นำเข้าเมนูปัจจุบัน" เพื่อเริ่มต้น ไม่งั้นเว็บจะกลับไปใช้เมนูอัตโนมัติชั่วคราว</div></div>
  <?php endif; ?>
</div>

<!-- ฟอร์มเพิ่ม/แก้ไข -->
<div class="card mb-2">
  <div class="section-head" style="margin-bottom:8px;">
    <div><span class="tag">MENU</span><h3><?= $edit ? 'แก้ไขเมนู' : 'เพิ่มเมนู' ?></h3></div>
    <?php if ($edit): ?><a class="btn small" href="<?= e(url('admin/menu.php')) ?>">ยกเลิก</a><?php endif; ?>
  </div>
  <?php if ($errors): ?>
  <div class="alert danger"><span class="material-symbols-rounded">error</span><div><?php foreach ($errors as $er): ?><div><?= e($er) ?></div><?php endforeach; ?></div></div>
  <?php endif; ?>
  <form method="post" action="">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
    <div class="form-row">
      <div><label>ชื่อเมนู <span style="color:var(--danger);">*</span></label>
        <input type="text" name="label" value="<?= e($edit['label'] ?? '') ?>" required class="mb-2" placeholder="เช่น เกี่ยวกับเรา"></div>
      <div><label>เมนูแม่</label>
        <select name="parent_id" class="mb-2">
          <option value="0">— เมนูหลัก (บนสุด) —</option>
          <?php foreach ($tops as $t): if ((int)($edit['id'] ?? 0) === (int)$t['id']) continue; ?>
          <option value="<?= (int)$t['id'] ?>" <?= (int)($edit['parent_id'] ?? 0) === (int)$t['id'] ? 'selected' : '' ?>><?= e($t['label']) ?></option>
          <?php endforeach; ?>
        </select></div>
    </div>
    <?php dest_picker_field(old('url', $edit['url'] ?? ''), $sys_targets, $pages); ?>
    <div class="flex gap-2 items-center" style="flex-wrap:wrap;">
      <label class="inline-check" style="margin:0;"><input type="checkbox" name="new_tab" value="1" <?= !empty($edit['new_tab']) ? 'checked' : '' ?>>เปิดแท็บใหม่</label>
      <label class="inline-check" style="margin:0;"><input type="checkbox" name="enabled" value="1" <?= !isset($edit) || !empty($edit['enabled']) ? 'checked' : '' ?>>แสดงบนเมนู</label>
      <button class="btn primary" type="submit"><span class="material-symbols-rounded icon-sm">save</span><?= $edit ? 'บันทึก' : 'เพิ่มเมนู' ?></button>
    </div>
  </form>
</div>

<!-- รายการ + ลากเรียง -->
<div class="card">
  <div class="section-head" style="margin-bottom:8px;">
    <div><span class="tag">ALL MENU</span><h3>เมนูทั้งหมด (<?= count($all) ?>)</h3>
    <p>ลากเพื่อจัดลำดับ — เมนูย่อยจะแสดงเป็น dropdown ใต้เมนูหลักบนเว็บ</p></div>
  </div>
  <?php if ($tops): ?>
  <div id="menuBuilder" data-csrf="<?= e(csrf_token()) ?>">
    <ul class="menu-sort" data-parent="0">
      <?php foreach ($tops as $m): ?>
      <li class="menu-li" draggable="true" data-id="<?= (int)$m['id'] ?>">
        <div class="menu-row<?= $m['enabled'] ? '' : ' is-off' ?>">
          <span class="menu-grip material-symbols-rounded">drag_indicator</span>
          <span class="menu-name"><?= e($m['label']) ?></span>
          <span class="badge" style="font-size:10px;"><?= e($label_target($m['url'])) ?></span>
          <?php if (!$m['enabled']): ?><span class="badge" style="font-size:10px;">ซ่อน</span><?php endif; ?>
          <span class="menu-actions">
            <a class="btn small" href="<?= e(url('admin/menu.php?edit=' . $m['id'])) ?>">แก้ไข</a>
            <form method="post" action="" style="display:inline;"><?= csrf_field() ?><input type="hidden" name="toggle_id" value="<?= (int)$m['id'] ?>"><button class="btn small" type="submit"><?= $m['enabled'] ? 'ซ่อน' : 'แสดง' ?></button></form>
            <form method="post" action="" style="display:inline;"><?= csrf_field() ?><input type="hidden" name="delete_id" value="<?= (int)$m['id'] ?>"><button class="btn small danger" type="submit" data-confirm="ลบเมนู «<?= e($m['label']) ?>»? เมนูย่อยจะเลื่อนขึ้นเป็นเมนูหลัก">ลบ</button></form>
          </span>
        </div>
        <ul class="menu-sort menu-sub" data-parent="<?= (int)$m['id'] ?>">
          <?php foreach ($kids[(int)$m['id']] ?? [] as $c): ?>
          <li class="menu-li" draggable="true" data-id="<?= (int)$c['id'] ?>">
            <div class="menu-row<?= $c['enabled'] ? '' : ' is-off' ?>">
              <span class="menu-grip material-symbols-rounded">drag_indicator</span>
              <span class="menu-name"><?= e($c['label']) ?></span>
              <span class="badge" style="font-size:10px;"><?= e($label_target($c['url'])) ?></span>
              <?php if (!$c['enabled']): ?><span class="badge" style="font-size:10px;">ซ่อน</span><?php endif; ?>
              <span class="menu-actions">
                <a class="btn small" href="<?= e(url('admin/menu.php?edit=' . $c['id'])) ?>">แก้ไข</a>
                <form method="post" action="" style="display:inline;"><?= csrf_field() ?><input type="hidden" name="toggle_id" value="<?= (int)$c['id'] ?>"><button class="btn small" type="submit"><?= $c['enabled'] ? 'ซ่อน' : 'แสดง' ?></button></form>
                <form method="post" action="" style="display:inline;"><?= csrf_field() ?><input type="hidden" name="delete_id" value="<?= (int)$c['id'] ?>"><button class="btn small danger" type="submit" data-confirm="ลบเมนูย่อยนี้?">ลบ</button></form>
              </span>
            </div>
          </li>
          <?php endforeach; ?>
        </ul>
      </li>
      <?php endforeach; ?>
    </ul>
  </div>
  <?php else: ?>
  <p class="text-muted text-center" style="padding:24px;">ยังไม่มีเมนู — เพิ่มด้านบน หรือกด "นำเข้าเมนูปัจจุบัน"</p>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/_bottom.php'; ?>
