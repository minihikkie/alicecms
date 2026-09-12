<?php
/** admin/org-chart.php — ผังโครงสร้างหน่วยงาน (สร้าง/แก้ไขเอง ไม่ต้องอัปโหลดรูปจากข้างนอก) */
require dirname(__DIR__) . '/includes/init.php';
require __DIR__ . '/_auth.php';
require dirname(__DIR__) . '/includes/orgchart.php';

$errors = [];
$edit = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['delete_id'])) {
        $id = (int)$_POST['delete_id'];
        $st = db()->prepare('SELECT parent_id FROM org_chart_nodes WHERE id = ?');
        $st->execute([$id]);
        $row = $st->fetch();
        if ($row) {
            /* ลูกของ node ที่ลบ ให้เลื่อนขึ้นไปอยู่ใต้ "พ่อแม่" ของ node นั้นแทน (คงรูปผังไว้ให้มากที่สุด
               ไม่ใช่เด้งไปบนสุดทั้งหมดเหมือน menu_items เพราะผังนี้อาจลึกหลายชั้น) */
            db()->prepare('UPDATE org_chart_nodes SET parent_id = ? WHERE parent_id = ?')
                ->execute([$row['parent_id'], $id]);
            if (trash_delete('org_chart_nodes', $id, $ADMIN['username'] ?? '')) {
                log_action('ลบหน่วยในผังโครงสร้าง (ลงถังขยะ)', '#' . $id);
                flash_set('success', 'ย้ายหน่วยงานลงถังขยะแล้ว — หน่วยย่อยเลื่อนขึ้นแทนที่ กู้คืนได้ที่เมนู "ถังขยะ"');
            }
        }
        redirect('admin/org-chart.php');
    }

    $id     = (int)($_POST['id'] ?? 0);
    $label  = mb_substr(trim((string)($_POST['label'] ?? '')), 0, 200);
    $parent = (int)($_POST['parent_id'] ?? 0) ?: null;
    $sort   = (int)($_POST['sort_order'] ?? 0);

    if ($label === '') $errors[] = 'กรุณากรอกชื่อหน่วยงาน/ฝ่าย';
    /* กันเลือกตัวเองหรือลูกหลานตัวเองเป็นพ่อแม่ (จะทำให้ผังวนเป็นวง) */
    if (!$errors && $parent && $id) {
        if ($parent === $id) {
            $errors[] = 'เลือกตัวเองเป็นหน่วยแม่ไม่ได้';
        } elseif (in_array($parent, org_chart_descendant_ids($id), true)) {
            $errors[] = 'เลือกหน่วยย่อยของตัวเองเป็นหน่วยแม่ไม่ได้ (จะทำให้ผังวนเป็นวง)';
        }
    }

    if (!$errors) {
        if ($id > 0) {
            db()->prepare('UPDATE org_chart_nodes SET label=?, parent_id=?, sort_order=? WHERE id=?')
                ->execute([$label, $parent, $sort, $id]);
            flash_set('success', 'บันทึกแล้ว');
        } else {
            db()->prepare('INSERT INTO org_chart_nodes (label, parent_id, sort_order) VALUES (?,?,?)')
                ->execute([$label, $parent, $sort]);
            flash_set('success', 'เพิ่มหน่วยงานแล้ว');
        }
        redirect('admin/org-chart.php');
    }
}

if (isset($_GET['edit'])) {
    $st = db()->prepare('SELECT * FROM org_chart_nodes WHERE id = ?');
    $st->execute([(int)$_GET['edit']]);
    $edit = $st->fetch();
}

$flat = org_chart_flat_with_depth();
$tree = org_chart_tree();
$excludeIds = $edit ? array_merge([(int)$edit['id']], org_chart_descendant_ids((int)$edit['id'])) : [];

$admin_title = 'ผังโครงสร้างหน่วยงาน';
require __DIR__ . '/_top.php';

/** เรนเดอร์ node แบบ recursive เป็นผัง HTML (ใช้ร่วมกับ about.php — โครงเดียวกัน) */
function org_chart_render(array $nodes, bool $isRoot = true): void {
    if (!$nodes) return;
    echo '<div class="' . ($isRoot ? 'org-root-row' : 'org-children') . '">';
    foreach ($nodes as $n) {
        echo '<div class="org-node">';
        echo '<div class="org-box' . ($isRoot ? ' org-box-root' : '') . '">' . e($n['label']) . '</div>';
        if ($n['children']) org_chart_render($n['children'], false);
        echo '</div>';
    }
    echo '</div>';
}
?>
<?php if ($errors): ?>
<div class="alert danger"><span class="material-symbols-rounded">error</span>
  <div><?php foreach ($errors as $er): ?><div><?= e($er) ?></div><?php endforeach; ?></div>
</div>
<?php endif; ?>

<div class="grid grid-2 mb-2" style="align-items:start;">
  <!-- ฟอร์มเพิ่ม/แก้ไข -->
  <div class="card">
    <div class="section-head" style="margin-bottom:8px;">
      <div><span class="tag"><span class="material-symbols-rounded icon-sm">account_tree</span> UNIT</span><h3><?= $edit ? 'แก้ไขหน่วยงาน' : 'เพิ่มหน่วยงาน/ฝ่าย' ?></h3></div>
      <?php if ($edit): ?><a class="btn small" href="<?= e(url('admin/org-chart.php')) ?>">ยกเลิก</a><?php endif; ?>
    </div>
    <form method="post" action="">
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
      <label>ชื่อหน่วยงาน/ฝ่าย <span style="color:var(--danger);">*</span></label>
      <input type="text" name="label" value="<?= e($edit['label'] ?? '') ?>" required class="mb-2" placeholder="เช่น ฝ่ายกฎหมาย 1">
      <label>สังกัดอยู่ใต้หน่วยงาน</label>
      <select name="parent_id" class="mb-2">
        <option value="0">— ไม่มี (เป็นหน่วยงานบนสุด) —</option>
        <?php foreach ($flat as $f): if (in_array($f['id'], $excludeIds, true)) continue; ?>
        <option value="<?= (int)$f['id'] ?>" <?= (int)($edit['parent_id'] ?? 0) === $f['id'] ? 'selected' : '' ?>><?= str_repeat('— ', $f['depth']) . e($f['label']) ?></option>
        <?php endforeach; ?>
      </select>
      <label>ลำดับ (ในบรรดาหน่วยใต้แม่เดียวกัน เลขน้อยแสดงก่อน)</label>
      <input type="number" name="sort_order" value="<?= (int)($edit['sort_order'] ?? (count($flat) + 1)) ?>" class="mb-2">
      <button class="btn primary" type="submit"><span class="material-symbols-rounded icon-sm">save</span><?= $edit ? 'บันทึกการแก้ไข' : 'เพิ่มหน่วยงาน' ?></button>
    </form>
  </div>

  <!-- รายการทั้งหมด (ย่อหน้าตามชั้น) -->
  <div class="card">
    <div class="section-head" style="margin-bottom:8px;">
      <div><span class="tag">ALL UNITS</span><h3>หน่วยงานทั้งหมด (<?= count($flat) ?>)</h3></div>
    </div>
    <?php if ($flat): ?>
    <table class="admin-table">
      <tr><th>หน่วยงาน/ฝ่าย</th><th style="width:70px;">ลำดับ</th><th></th></tr>
      <?php foreach ($flat as $f): ?>
      <tr>
        <td><span style="color:var(--muted);"><?= str_repeat('—&nbsp;', $f['depth']) ?></span><?= e($f['label']) ?></td>
        <td class="lr-date"><?= (int)$f['sort_order'] ?></td>
        <td style="white-space:nowrap;">
          <a class="btn small" href="<?= e(url('admin/org-chart.php?edit=' . $f['id'])) ?>">แก้ไข</a>
          <form method="post" action="" style="display:inline;">
            <?= csrf_field() ?>
            <input type="hidden" name="delete_id" value="<?= (int)$f['id'] ?>">
            <button class="btn small danger" type="submit" data-confirm="ลบ «<?= e($f['label']) ?>»? หน่วยย่อย (ถ้ามี) จะเลื่อนขึ้นแทนที่อัตโนมัติ">ลบ</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>
    <?php else: ?>
    <p class="text-muted text-center" style="padding:24px;">ยังไม่มีหน่วยงานในผัง — เพิ่มด้านซ้ายเพื่อเริ่มต้น</p>
    <?php endif; ?>
  </div>
</div>

<!-- ตัวอย่างผังจริง -->
<?php if ($tree): ?>
<div class="card">
  <div class="section-head" style="margin-bottom:8px;">
    <div><span class="tag"><span class="material-symbols-rounded icon-sm">preview</span> PREVIEW</span><h3>ตัวอย่างที่จะแสดงบนหน้าเว็บ</h3>
    <p>หน้า "เกี่ยวกับ" จะแสดงผังนี้แทนรูปที่เคยอัปโหลด (ถ้ามีข้อมูลในนี้)</p></div>
  </div>
  <div style="overflow-x:auto;padding:12px 0;">
    <div class="org-chart">
      <?php org_chart_render($tree); ?>
    </div>
  </div>
</div>
<?php endif; ?>
<?php require __DIR__ . '/_bottom.php'; ?>
