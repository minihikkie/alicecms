<?php
/** admin/pages.php — จัดการหน้าเพจกำหนดเอง (ประวัติ/วิสัยทัศน์/อำนาจหน้าที่ ฯลฯ) */
require dirname(__DIR__) . '/includes/init.php';
require __DIR__ . '/_auth.php';
require dirname(__DIR__) . '/includes/blocks.php';

$errors = [];
$edit = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['delete_id'])) {
        if (trash_delete('pages', (int)$_POST['delete_id'], $ADMIN['username'] ?? '')) {
            log_action('ลบหน้าเพจ (ลงถังขยะ)', '#' . (int)$_POST['delete_id']);
            flash_set('success', 'ย้ายหน้าเพจลงถังขยะแล้ว — กู้คืนได้ที่เมนู "ถังขยะ"');
        }
        redirect('admin/pages.php');
    }
    /* เพิ่มลิงก์หน้านี้เข้าเมนูนำทาง */
    if (isset($_POST['add_to_menu'])) {
        $pid = (int)$_POST['add_to_menu'];
        $st = db()->prepare('SELECT slug, title FROM pages WHERE id = ?');
        $st->execute([$pid]);
        if ($p = $st->fetch()) {
            $mx = (int)db()->query('SELECT COALESCE(MAX(sort_order),0) FROM menu_items')->fetchColumn();
            db()->prepare('INSERT INTO menu_items (label, url, sort_order, enabled) VALUES (?,?,?,1)')
                ->execute([mb_substr($p['title'], 0, 120), 'page.php?slug=' . $p['slug'], $mx + 1]);
            log_action('เพิ่มหน้าเข้าเมนู', $p['title']);
            flash_set('success', 'เพิ่ม "' . $p['title'] . '" เข้าเมนูนำทางแล้ว');
        }
        redirect('admin/pages.php');
    }

    $id     = (int)($_POST['id'] ?? 0);
    $title  = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 250);
    $slug   = slugify((string)($_POST['slug'] ?? '') !== '' ? (string)$_POST['slug'] : $title);
    $blocks = sanitize_blocks((string)($_POST['blocks'] ?? ''));
    $body   = '';   /* เนื้อหาเก็บเป็นบล็อกแล้ว */
    $status = ($_POST['status'] ?? 'published') === 'draft' ? 'draft' : 'published';
    $sort   = (int)($_POST['sort_order'] ?? 0);

    if ($title === '') $errors[] = 'กรุณากรอกชื่อหน้า';
    if (!$errors) {
        /* กัน slug ซ้ำ */
        $chk = db()->prepare('SELECT id FROM pages WHERE slug = ? AND id <> ?');
        $chk->execute([$slug, $id]);
        if ($chk->fetch()) $slug .= '-' . substr(bin2hex(random_bytes(2)), 0, 3);
    }
    if (!$errors) {
        if ($id > 0) {
            db()->prepare('UPDATE pages SET title=?, slug=?, body=?, blocks=?, status=?, sort_order=? WHERE id=?')
                ->execute([$title, $slug, $body, $blocks, $status, $sort, $id]);
            log_action('แก้ไขหน้าเพจ', $title);
            flash_set('success', 'บันทึกหน้าเพจแล้ว');
        } else {
            db()->prepare('INSERT INTO pages (title, slug, body, blocks, status, sort_order) VALUES (?,?,?,?,?,?)')
                ->execute([$title, $slug, $body, $blocks, $status, $sort]);
            log_action('เพิ่มหน้าเพจ', $title);
            flash_set('success', 'เพิ่มหน้าเพจแล้ว');
        }
        redirect('admin/pages.php');
    }
}

if (isset($_GET['edit'])) {
    $st = db()->prepare('SELECT * FROM pages WHERE id = ?');
    $st->execute([(int)$_GET['edit']]);
    $edit = $st->fetch();
}

$pages = db()->query('SELECT * FROM pages ORDER BY sort_order ASC, id ASC')->fetchAll();

$view = ($edit || isset($_GET['new'])) ? 'edit' : 'list';
$admin_title = 'หน้าเพจ';
require __DIR__ . '/_top.php';
?>
<?php if ($view === 'edit'):
  /* ค่าเริ่มต้นของตัวแก้ไข: ใช้บล็อกเดิมถ้ามี ไม่งั้นแปลง body เก่า (ก่อนมีระบบบล็อก) เป็นบล็อกข้อความเดียว */
  $init_json = null;
  if ($edit && !empty($edit['blocks'])) {
      $init_json = $edit['blocks'];
  } elseif ($edit && !empty($edit['body'])) {
      $init_json = json_encode([['type' => 'text', 'text' => $edit['body']]], JSON_UNESCAPED_UNICODE);
  }
?>
<?php if ($errors): ?>
<div class="alert danger"><span class="material-symbols-rounded">error</span><div><?php foreach ($errors as $er): ?><div><?= e($er) ?></div><?php endforeach; ?></div></div>
<?php endif; ?>
<form method="post" action="" id="pageForm">
  <?= csrf_field() ?>
  <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">

  <!-- ── การ์ด 1: ตั้งค่าหน้า ── -->
  <div class="card mb-2">
    <div class="section-head" style="margin-bottom:10px;">
      <div><span class="tag"><span class="material-symbols-rounded icon-sm">description</span> หน้าเพจ</span>
        <h3><?= $edit ? 'แก้ไข: ' . e($edit['title']) : 'สร้างหน้าใหม่' ?></h3>
        <p>ตั้งชื่อหน้า แล้วใส่เนื้อหาด้วยบล็อกด้านล่าง — เสร็จแล้วนำลิงก์ไปใส่เมนูได้</p></div>
      <div class="flex gap-1">
        <a class="btn small" href="<?= e(url('admin/pages.php')) ?>">← กลับ</a>
        <?php if ($edit): ?><a class="btn small" href="<?= e(url('page.php?slug=' . $edit['slug'])) ?>" target="_blank" rel="noopener"><span class="material-symbols-rounded icon-sm">open_in_new</span>ดูหน้าจริง</a><?php endif; ?>
      </div>
    </div>
    <div class="form-row">
      <div><label>ชื่อหน้า <span style="color:var(--danger);">*</span></label>
        <input type="text" name="title" value="<?= e(old('title', $edit['title'] ?? '')) ?>" required class="mb-2" placeholder="เช่น วิสัยทัศน์และพันธกิจ"></div>
      <div><label>ลิงก์ (slug) <span class="text-muted">(เว้นว่าง = สร้างให้อัตโนมัติ)</span></label>
        <input type="text" name="slug" value="<?= e(old('slug', $edit['slug'] ?? '')) ?>" class="mb-2" placeholder="vision"></div>
    </div>
    <div class="flex gap-2 items-center" style="flex-wrap:wrap;">
      <div><label>สถานะ</label>
        <select name="status" style="width:150px;">
          <option value="published" <?= ($edit['status'] ?? 'published') === 'published' ? 'selected' : '' ?>>เผยแพร่</option>
          <option value="draft" <?= ($edit['status'] ?? '') === 'draft' ? 'selected' : '' ?>>ฉบับร่าง (ซ่อน)</option>
        </select></div>
      <div><label>ลำดับ</label><input type="number" name="sort_order" value="<?= (int)($edit['sort_order'] ?? (count($pages) + 1)) ?>" class="sort-input"></div>
    </div>
  </div>

  <!-- ── การ์ด 2: เนื้อหา (บล็อก) ── -->
  <div class="card mb-2">
    <div class="section-head" style="margin-bottom:10px;">
      <div><span class="tag"><span class="material-symbols-rounded icon-sm">dashboard</span> เนื้อหา</span><h3>เนื้อหาของหน้า</h3>
        <p>เลือก "เพิ่มบล็อก" ทีละชนิด — ใช้ลูกศร ↑↓ จัดลำดับ · ปุ่ม ✕ ลบ</p></div>
    </div>
    <?php block_editor_card('blocks', $init_json); ?>
  </div>

  <button class="btn primary large" type="submit"><span class="material-symbols-rounded">save</span><?= $edit ? 'บันทึกการแก้ไข' : 'สร้างหน้า' ?></button>
</form>

<?php else: /* ── list ── */ ?>
<div class="card">
  <div class="section-head" style="margin-bottom:8px;">
    <div><span class="tag"><span class="material-symbols-rounded icon-sm">description</span> PAGES</span><h3>หน้าเพจทั้งหมด (<?= count($pages) ?>)</h3>
    <p>สร้างหน้าเนื้อหา เช่น ประวัติ · วิสัยทัศน์ · อำนาจหน้าที่ · โครงสร้าง</p></div>
    <div class="flex gap-1">
      <a class="btn small" href="<?= e(url('admin/menu.php')) ?>"><span class="material-symbols-rounded icon-sm">menu</span>เมนูนำทาง</a>
      <a class="btn primary" href="<?= e(url('admin/pages.php?new=1')) ?>"><span class="material-symbols-rounded icon-sm">add</span>สร้างหน้าใหม่</a>
    </div>
  </div>
  <?php if ($pages): ?>
  <table class="admin-table">
    <tr><th>ลำดับ</th><th>ชื่อหน้า</th><th>ลิงก์</th><th>สถานะ</th><th></th></tr>
    <?php foreach ($pages as $p): ?>
    <tr>
      <td><?= (int)$p['sort_order'] ?></td>
      <td><b style="color:var(--ink);"><?= e($p['title']) ?></b></td>
      <td class="lr-date"><a href="<?= e(url('page.php?slug=' . $p['slug'])) ?>" target="_blank" rel="noopener">/page.php?slug=<?= e($p['slug']) ?></a></td>
      <td><?= $p['status'] === 'published' ? '<span class="badge success" style="font-size:11px;">เผยแพร่</span>' : '<span class="badge" style="font-size:11px;">ร่าง</span>' ?></td>
      <td style="white-space:nowrap;">
        <a class="btn small" href="<?= e(url('admin/pages.php?edit=' . $p['id'])) ?>"><span class="material-symbols-rounded icon-sm">edit</span>แก้ไข</a>
        <form method="post" action="" style="display:inline;">
          <?= csrf_field() ?><input type="hidden" name="add_to_menu" value="<?= (int)$p['id'] ?>">
          <button class="btn small" type="submit" title="เพิ่มเข้าเมนูนำทาง"><span class="material-symbols-rounded icon-sm">add_link</span>ใส่เมนู</button>
        </form>
        <form method="post" action="" style="display:inline;">
          <?= csrf_field() ?><input type="hidden" name="delete_id" value="<?= (int)$p['id'] ?>">
          <button class="btn small danger" type="submit" data-confirm="ยืนยันลบหน้า «<?= e($p['title']) ?>»?"><span class="material-symbols-rounded icon-sm">delete</span></button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php else: ?>
  <div class="text-center text-muted" style="padding:40px 20px;">
    <span class="material-symbols-rounded" style="font-size:52px;color:var(--border);">description</span>
    <p style="margin:10px 0 16px;">ยังไม่มีหน้าเพจ</p>
    <a class="btn primary" href="<?= e(url('admin/pages.php?new=1')) ?>"><span class="material-symbols-rounded icon-sm">add</span>สร้างหน้าแรก</a>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php require __DIR__ . '/_bottom.php'; ?>
