<?php
/** admin/content-types.php — สร้าง/จัดการ "ประเภทเนื้อหา" เอง (Custom Content Types) */
require dirname(__DIR__) . '/includes/init.php';
require __DIR__ . '/_auth.php';
require_admin();
require dirname(__DIR__) . '/includes/content.php';

$errors = [];
$edit = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['delete_id'])) {
        $id = (int)$_POST['delete_id'];
        /* เก็บทั้งประเภทและรายการในประเภทนั้น (พร้อมรูป) ลงถังขยะ — เดิมลบทิ้งพร้อมไฟล์กำพร้า */
        if (trash_delete('content_types', $id, $ADMIN['username'] ?? '')) {
            log_action('ลบประเภทเนื้อหา (ลงถังขยะ)', '#' . $id);
            flash_set('success', 'ย้ายประเภทเนื้อหาและรายการทั้งหมดลงถังขยะแล้ว — กู้คืนได้ที่เมนู "ถังขยะ"');
        }
        redirect('admin/content-types.php');
    }
    $id      = (int)($_POST['id'] ?? 0);
    $name    = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 120);
    $plural  = mb_substr(trim((string)($_POST['name_plural'] ?? '')), 0, 120) ?: $name;
    $slug    = slugify((string)($_POST['slug'] ?? '') !== '' ? (string)$_POST['slug'] : ($name !== '' ? $name : 'type'));
    $icon    = preg_replace('/[^a-z0-9_]/', '', (string)($_POST['icon'] ?? 'category')) ?: 'category';
    $fields  = sanitize_form_fields((string)($_POST['fields'] ?? ''));
    $has_img = !empty($_POST['has_image']) ? 1 : 0;
    $status  = ($_POST['status'] ?? 'on') === 'off' ? 'off' : 'on';
    $sort    = (int)($_POST['sort_order'] ?? 0);

    if ($name === '') $errors[] = 'กรุณากรอกชื่อประเภท';
    /* slug ห้ามชนกับหน้าระบบ */
    if (in_array($slug, ['index','news','post','documents','ita','procurement','about','contact','complaint','search','personnel','page','form','content'], true)) {
        $errors[] = 'slug นี้สงวนไว้ กรุณาใช้ชื่ออื่น';
    }
    if (!$errors) {
        $chk = db()->prepare('SELECT id FROM content_types WHERE slug = ? AND id <> ?'); $chk->execute([$slug, $id]);
        if ($chk->fetch()) $slug .= '-' . substr(bin2hex(random_bytes(2)), 0, 3);
    }
    if (!$errors) {
        if ($id > 0) {
            db()->prepare('UPDATE content_types SET name=?, name_plural=?, slug=?, icon=?, fields=?, has_image=?, status=?, sort_order=? WHERE id=?')
                ->execute([$name, $plural, $slug, $icon, $fields, $has_img, $status, $sort, $id]);
        } else {
            db()->prepare('INSERT INTO content_types (name, name_plural, slug, icon, fields, has_image, status, sort_order) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([$name, $plural, $slug, $icon, $fields, $has_img, $status, $sort]);
        }
        log_action('จัดการประเภทเนื้อหา', $name);
        flash_set('success', 'บันทึกประเภทเนื้อหาแล้ว');
        redirect('admin/content-types.php');
    }
}

if (isset($_GET['edit'])) {
    $st = db()->prepare('SELECT * FROM content_types WHERE id = ?'); $st->execute([(int)$_GET['edit']]); $edit = $st->fetch();
}

$view = ($edit || isset($_GET['new'])) ? 'edit' : 'list';
$admin_title = 'ประเภทเนื้อหา';
require __DIR__ . '/_top.php';
?>
<?php if ($view === 'edit'):
    $init = [];
    if ($edit && !empty($edit['fields']) && ($dec = json_decode($edit['fields'], true)) && is_array($dec)) $init = $dec;
?>
<div class="card mb-2">
  <div class="section-head" style="margin-bottom:8px;">
    <div><span class="tag"><span class="material-symbols-rounded icon-sm">category</span> TYPE</span><h3><?= $edit ? 'แก้ไขประเภทเนื้อหา' : 'สร้างประเภทเนื้อหาใหม่' ?></h3>
    <p>เช่น งานวิจัย / โครงการ / ผลิตภัณฑ์ / สถานที่ — กำหนดช่องข้อมูลเองได้ แล้วเพิ่มรายการได้ไม่จำกัด</p></div>
    <a class="btn small" href="<?= e(url('admin/content-types.php')) ?>">ยกเลิก</a>
  </div>
  <?php if ($errors): ?><div class="alert danger"><span class="material-symbols-rounded">error</span><div><?php foreach ($errors as $er): ?><div><?= e($er) ?></div><?php endforeach; ?></div></div><?php endif; ?>
  <form method="post" action="" id="pageForm">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
    <div class="form-row">
      <div><label>ชื่อประเภท (เอกพจน์) <span style="color:var(--danger);">*</span></label>
        <input type="text" name="name" value="<?= e($edit['name'] ?? '') ?>" required class="mb-2" placeholder="เช่น งานวิจัย"></div>
      <div><label>ชื่อพหูพจน์/หัวข้อหน้า</label>
        <input type="text" name="name_plural" value="<?= e($edit['name_plural'] ?? '') ?>" class="mb-2" placeholder="เช่น คลังงานวิจัย"></div>
    </div>
    <div class="form-row">
      <div><label>slug (URL)</label>
        <input type="text" name="slug" value="<?= e($edit['slug'] ?? '') ?>" class="mb-2" placeholder="research"></div>
      <div><label>ไอคอน</label>
        <div class="icon-pick-wrap mb-2">
          <span class="material-symbols-rounded icon-pick-preview" data-icon-preview><?= e($edit['icon'] ?? 'category') ?></span>
          <input type="text" name="icon" value="<?= e($edit['icon'] ?? 'category') ?>" data-icon-input data-icon-default="category" style="width:160px;" placeholder="เช่น science, folder" autocomplete="off">
        </div></div>
    </div>
    <label>ช่องข้อมูลของประเภทนี้</label>
    <div id="blockEditor" class="mb-2">
      <div class="blocks-list" id="blocksList">
        <?php foreach ($init as $f) if (is_array($f)) echo field_editor_item((string)($f['type'] ?? ''), $f); ?>
      </div>
      <div class="block-add">
        <span class="text-muted" style="font-size:13px;">+ เพิ่มช่อง:</span>
        <?php foreach (field_types() as $t => [$lbl, $ic, $ho]): ?>
        <button type="button" class="btn small" data-addblock="<?= e($t) ?>"><span class="material-symbols-rounded icon-sm"><?= e($ic) ?></span><?= e($lbl) ?></button>
        <?php endforeach; ?>
      </div>
      <input type="hidden" name="fields" id="blocksJson">
      <template id="blockTpls"><?php foreach (field_types() as $t => $_) echo '<div data-tpl="' . e($t) . '">' . field_editor_item($t, []) . '</div>'; ?></template>
    </div>
    <div class="flex gap-2 items-center" style="flex-wrap:wrap;">
      <label class="inline-check" style="margin:0;"><input type="checkbox" name="has_image" value="1" <?= !isset($edit) || !empty($edit['has_image']) ? 'checked' : '' ?>>มีรูปปกในแต่ละรายการ</label>
      <div><label>สถานะ</label>
        <select name="status">
          <option value="on" <?= ($edit['status'] ?? 'on') === 'on' ? 'selected' : '' ?>>เปิดใช้</option>
          <option value="off" <?= ($edit['status'] ?? '') === 'off' ? 'selected' : '' ?>>ปิด</option>
        </select></div>
      <div><label>ลำดับ</label><input type="number" name="sort_order" value="<?= (int)($edit['sort_order'] ?? 0) ?>" class="sort-input"></div>
      <button class="btn primary" type="submit" style="margin-top:20px;"><span class="material-symbols-rounded icon-sm">save</span>บันทึกประเภท</button>
    </div>
  </form>
</div>

<?php else:
    $types = db()->query('SELECT t.*, (SELECT COUNT(*) FROM content_items i WHERE i.type_id=t.id) AS n FROM content_types t ORDER BY t.sort_order ASC, t.id ASC')->fetchAll();
?>
<div class="card">
  <div class="section-head" style="margin-bottom:8px;">
    <div><span class="tag"><span class="material-symbols-rounded icon-sm">category</span> CONTENT TYPES</span><h3>ประเภทเนื้อหา (<?= count($types) ?>)</h3>
    <p>สร้างประเภทเนื้อหาเองแบบ WordPress Custom Post Types — ไม่ต้องเขียนโค้ด</p></div>
    <a class="btn primary" href="<?= e(url('admin/content-types.php?new=1')) ?>"><span class="material-symbols-rounded icon-sm">add</span>สร้างประเภท</a>
  </div>
  <?php if ($types): ?>
  <table class="admin-table">
    <tr><th>ประเภท</th><th>ลิงก์หน้าเว็บ</th><th>รายการ</th><th>สถานะ</th><th></th></tr>
    <?php foreach ($types as $t): ?>
    <tr>
      <td><span class="material-symbols-rounded icon-sm" style="color:var(--blue);vertical-align:-4px;"><?= e($t['icon'] ?: 'category') ?></span> <?= e($t['name']) ?></td>
      <td class="lr-date"><a href="<?= e(url('content.php?type=' . $t['slug'])) ?>" target="_blank" rel="noopener">/content.php?type=<?= e($t['slug']) ?></a></td>
      <td><a class="btn small primary" href="<?= e(url('admin/content.php?type=' . $t['slug'])) ?>"><span class="material-symbols-rounded icon-sm">list</span>จัดการ (<?= (int)$t['n'] ?>)</a></td>
      <td><?= $t['status'] === 'on' ? '<span class="badge success" style="font-size:11px;">เปิด</span>' : '<span class="badge" style="font-size:11px;">ปิด</span>' ?></td>
      <td style="white-space:nowrap;">
        <a class="btn small" href="<?= e(url('admin/content-types.php?edit=' . $t['id'])) ?>">แก้ไข</a>
        <form method="post" style="display:inline;"><?= csrf_field() ?><input type="hidden" name="delete_id" value="<?= (int)$t['id'] ?>"><button class="btn small danger" data-confirm="ลบประเภท «<?= e($t['name']) ?>» และรายการทั้งหมด?">ลบ</button></form>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php else: ?><p class="text-muted text-center" style="padding:28px;">ยังไม่มีประเภทเนื้อหา — กด "สร้างประเภท"</p><?php endif; ?>
</div>
<?php endif; ?>
<?php require __DIR__ . '/_bottom.php'; ?>
