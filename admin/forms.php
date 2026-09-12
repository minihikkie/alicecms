<?php
/** admin/forms.php — ตัวสร้างแบบฟอร์ม/บริการออนไลน์ + ดูคำตอบ + ส่งออก CSV */
require dirname(__DIR__) . '/includes/init.php';
require __DIR__ . '/_auth.php';
require dirname(__DIR__) . '/includes/forms.php';

$errors = [];
$edit = null;

/* ── ส่งออก CSV ── */
if (isset($_GET['export'])) {
    $fid = (int)$_GET['export'];
    $st = db()->prepare('SELECT title FROM forms WHERE id = ?'); $st->execute([$fid]); $ftitle = $st->fetchColumn();
    $st = db()->prepare('SELECT data, created_at FROM form_responses WHERE form_id = ? ORDER BY created_at ASC'); $st->execute([$fid]);
    $rows = $st->fetchAll();
    $cols = [];
    foreach ($rows as $r) { $d = json_decode($r['data'], true) ?: []; foreach (array_keys($d) as $k) if (!in_array($k, $cols, true)) $cols[] = $k; }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="responses-' . $fid . '-' . date('Ymd') . '.csv"');
    echo "\xEF\xBB\xBF"; // BOM ให้ Excel อ่านไทยได้
    $out = fopen('php://output', 'w');
    fputcsv($out, array_merge(['เวลา'], $cols));
    foreach ($rows as $r) {
        $d = json_decode($r['data'], true) ?: [];
        $line = [$r['created_at']];
        foreach ($cols as $c) $line[] = $d[$c] ?? '';
        fputcsv($out, $line);
    }
    fclose($out);
    log_action('ส่งออกคำตอบฟอร์ม', (string)$ftitle);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['delete_id'])) {
        $id = (int)$_POST['delete_id'];
        /* เก็บทั้งแบบฟอร์มและคำตอบทั้งหมดลงถังขยะ (กู้กลับได้ทั้งชุด) */
        if (trash_delete('forms', $id, $ADMIN['username'] ?? '')) {
            log_action('ลบแบบฟอร์ม (ลงถังขยะ)', '#' . $id);
            flash_set('success', 'ย้ายแบบฟอร์มและคำตอบลงถังขยะแล้ว — กู้คืนได้ที่เมนู "ถังขยะ"');
        }
        redirect('admin/forms.php');
    }
    if (isset($_POST['del_response'])) {
        db()->prepare('DELETE FROM form_responses WHERE id = ?')->execute([(int)$_POST['del_response']]);
        flash_set('success', 'ลบคำตอบแล้ว');
        redirect('admin/forms.php?responses=' . (int)($_POST['form_id'] ?? 0));
    }

    $id      = (int)($_POST['id'] ?? 0);
    $title   = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 200);
    $slug    = slugify((string)($_POST['slug'] ?? '') !== '' ? (string)$_POST['slug'] : $title);
    $desc    = mb_substr(trim((string)($_POST['description'] ?? '')), 0, 500);
    $fields  = sanitize_form_fields((string)($_POST['fields'] ?? ''));
    $notify  = mb_substr(trim((string)($_POST['notify_email'] ?? '')), 0, 150);
    if ($notify !== '' && !filter_var($notify, FILTER_VALIDATE_EMAIL)) $notify = '';
    $success = mb_substr(trim((string)($_POST['success_msg'] ?? '')), 0, 500);
    $status  = ($_POST['status'] ?? 'open') === 'closed' ? 'closed' : 'open';

    if ($title === '') $errors[] = 'กรุณากรอกชื่อแบบฟอร์ม';
    if (!$errors) {
        $chk = db()->prepare('SELECT id FROM forms WHERE slug = ? AND id <> ?'); $chk->execute([$slug, $id]);
        if ($chk->fetch()) $slug .= '-' . substr(bin2hex(random_bytes(2)), 0, 3);
    }
    if (!$errors) {
        if ($id > 0) {
            db()->prepare('UPDATE forms SET title=?, slug=?, description=?, fields=?, notify_email=?, success_msg=?, status=? WHERE id=?')
                ->execute([$title, $slug, $desc, $fields, $notify, $success, $status, $id]);
        } else {
            db()->prepare('INSERT INTO forms (title, slug, description, fields, notify_email, success_msg, status) VALUES (?,?,?,?,?,?,?)')
                ->execute([$title, $slug, $desc, $fields, $notify, $success, $status]);
        }
        log_action('จัดการแบบฟอร์ม', $title);
        flash_set('success', 'บันทึกแบบฟอร์มแล้ว');
        redirect('admin/forms.php');
    }
}

if (isset($_GET['edit'])) {
    $st = db()->prepare('SELECT * FROM forms WHERE id = ?'); $st->execute([(int)$_GET['edit']]); $edit = $st->fetch();
}

$view = isset($_GET['responses']) ? 'responses' : (($edit || isset($_GET['new'])) ? 'edit' : 'list');
$admin_title = 'แบบฟอร์ม/บริการ';
require __DIR__ . '/_top.php';
?>
<?php if ($view === 'responses'):
    $fid = (int)$_GET['responses'];
    $st = db()->prepare('SELECT * FROM forms WHERE id = ?'); $st->execute([$fid]); $rf = $st->fetch();
    $st = db()->prepare('SELECT * FROM form_responses WHERE form_id = ? ORDER BY created_at DESC'); $st->execute([$fid]);
    $resps = $st->fetchAll();
    $cols = [];
    foreach ($resps as $r) { $d = json_decode($r['data'], true) ?: []; foreach (array_keys($d) as $k) if (!in_array($k, $cols, true)) $cols[] = $k; }
?>
<div class="card">
  <div class="section-head" style="margin-bottom:8px;">
    <div><span class="tag"><span class="material-symbols-rounded icon-sm">inbox</span> RESPONSES</span><h3>คำตอบ: <?= e($rf['title'] ?? '') ?> (<?= count($resps) ?>)</h3></div>
    <div class="flex gap-1">
      <a class="btn small" href="<?= e(url('admin/forms.php')) ?>">← กลับ</a>
      <?php if ($resps): ?><a class="btn small primary" href="<?= e(url('admin/forms.php?export=' . $fid)) ?>"><span class="material-symbols-rounded icon-sm">download</span>ส่งออก CSV</a><?php endif; ?>
    </div>
  </div>
  <?php if ($resps): ?>
  <div style="overflow-x:auto;">
  <table class="admin-table">
    <tr><th>เวลา</th><?php foreach ($cols as $c): ?><th><?= e($c) ?></th><?php endforeach; ?><th></th></tr>
    <?php foreach ($resps as $r): $d = json_decode($r['data'], true) ?: []; ?>
    <tr>
      <td class="lr-date" style="white-space:nowrap;"><?= e(date('d/m/Y H:i', strtotime($r['created_at']))) ?></td>
      <?php foreach ($cols as $c): ?><td><?= nl2br(e(mb_strimwidth((string)($d[$c] ?? ''), 0, 120, '…'))) ?></td><?php endforeach; ?>
      <td><form method="post" style="display:inline;"><?= csrf_field() ?><input type="hidden" name="del_response" value="<?= (int)$r['id'] ?>"><input type="hidden" name="form_id" value="<?= $fid ?>"><button class="btn small danger" data-confirm="ลบคำตอบนี้?"><span class="material-symbols-rounded icon-sm">delete</span></button></form></td>
    </tr>
    <?php endforeach; ?>
  </table>
  </div>
  <?php else: ?><p class="text-muted text-center" style="padding:28px;">ยังไม่มีผู้ส่งแบบฟอร์มนี้</p><?php endif; ?>
</div>

<?php elseif ($view === 'edit'):
    $init = [];
    if ($edit && !empty($edit['fields']) && ($dec = json_decode($edit['fields'], true)) && is_array($dec)) $init = $dec;
?>
<div class="card mb-2">
  <div class="section-head" style="margin-bottom:8px;">
    <div><span class="tag"><span class="material-symbols-rounded icon-sm">dynamic_form</span> FORM</span><h3><?= $edit ? 'แก้ไขแบบฟอร์ม' : 'สร้างแบบฟอร์มใหม่' ?></h3>
    <p>สร้างแบบฟอร์ม/คำร้อง/สำรวจ — เพิ่มช่องกรอกได้เอง คำตอบเก็บในระบบ + ส่งออก CSV</p></div>
    <a class="btn small" href="<?= e(url('admin/forms.php')) ?>">ยกเลิก</a>
  </div>
  <?php if ($errors): ?><div class="alert danger"><span class="material-symbols-rounded">error</span><div><?php foreach ($errors as $er): ?><div><?= e($er) ?></div><?php endforeach; ?></div></div><?php endif; ?>
  <form method="post" action="" id="pageForm">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
    <div class="form-row">
      <div><label>ชื่อแบบฟอร์ม <span style="color:var(--danger);">*</span></label>
        <input type="text" name="title" value="<?= e(old('title', $edit['title'] ?? '')) ?>" required class="mb-2" placeholder="เช่น แบบฟอร์มขอใช้บริการ"></div>
      <div><label>slug (URL)</label>
        <input type="text" name="slug" value="<?= e(old('slug', $edit['slug'] ?? '')) ?>" class="mb-2" placeholder="service-request"></div>
    </div>
    <label>คำอธิบาย</label>
    <input type="text" name="description" value="<?= e(old('description', $edit['description'] ?? '')) ?>" class="mb-2" placeholder="คำอธิบายสั้นๆ ใต้ชื่อฟอร์ม">

    <label>ช่องกรอกในฟอร์ม</label>
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

    <div class="form-row">
      <div><label>อีเมลรับแจ้งเตือน <span class="text-muted">(เมื่อมีผู้ส่ง — ต้องตั้งค่า SMTP)</span></label>
        <input type="email" name="notify_email" value="<?= e(old('notify_email', $edit['notify_email'] ?? '')) ?>" class="mb-2" placeholder="staff@gov.go.th"></div>
      <div><label>สถานะ</label>
        <select name="status" class="mb-2">
          <option value="open" <?= ($edit['status'] ?? 'open') === 'open' ? 'selected' : '' ?>>เปิดรับ</option>
          <option value="closed" <?= ($edit['status'] ?? '') === 'closed' ? 'selected' : '' ?>>ปิดรับ</option>
        </select></div>
    </div>
    <label>ข้อความเมื่อส่งสำเร็จ</label>
    <input type="text" name="success_msg" value="<?= e(old('success_msg', $edit['success_msg'] ?? '')) ?>" class="mb-2" placeholder="ขอบคุณ เจ้าหน้าที่จะติดต่อกลับ">
    <button class="btn primary large" type="submit"><span class="material-symbols-rounded icon-sm">save</span>บันทึกแบบฟอร์ม</button>
  </form>
</div>

<?php else: /* list */
    $forms = db()->query('SELECT f.*, (SELECT COUNT(*) FROM form_responses r WHERE r.form_id=f.id) AS n FROM forms f ORDER BY f.id DESC')->fetchAll();
?>
<div class="card">
  <div class="section-head" style="margin-bottom:8px;">
    <div><span class="tag"><span class="material-symbols-rounded icon-sm">dynamic_form</span> FORMS</span><h3>แบบฟอร์ม/บริการออนไลน์ (<?= count($forms) ?>)</h3>
    <p>สร้างฟอร์มแล้วนำลิงก์ไปใส่เมนู/หน้าเพจ — แทนปลั๊กอินฟอร์ม</p></div>
    <a class="btn primary" href="<?= e(url('admin/forms.php?new=1')) ?>"><span class="material-symbols-rounded icon-sm">add</span>สร้างแบบฟอร์ม</a>
  </div>
  <?php if ($forms): ?>
  <table class="admin-table">
    <tr><th>ชื่อ</th><th>ลิงก์</th><th>คำตอบ</th><th>สถานะ</th><th></th></tr>
    <?php foreach ($forms as $f): ?>
    <tr>
      <td><?= e($f['title']) ?></td>
      <td class="lr-date"><a href="<?= e(url('form.php?slug=' . $f['slug'])) ?>" target="_blank" rel="noopener">/form.php?slug=<?= e($f['slug']) ?></a></td>
      <td><a class="btn small" href="<?= e(url('admin/forms.php?responses=' . $f['id'])) ?>"><span class="material-symbols-rounded icon-sm">inbox</span><?= (int)$f['n'] ?></a></td>
      <td><?= $f['status'] === 'open' ? '<span class="badge success" style="font-size:11px;">เปิดรับ</span>' : '<span class="badge" style="font-size:11px;">ปิดรับ</span>' ?></td>
      <td style="white-space:nowrap;">
        <a class="btn small" href="<?= e(url('admin/forms.php?edit=' . $f['id'])) ?>">แก้ไข</a>
        <form method="post" style="display:inline;"><?= csrf_field() ?><input type="hidden" name="delete_id" value="<?= (int)$f['id'] ?>"><button class="btn small danger" data-confirm="ลบฟอร์ม «<?= e($f['title']) ?>» และคำตอบทั้งหมด?">ลบ</button></form>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php else: ?><p class="text-muted text-center" style="padding:28px;">ยังไม่มีแบบฟอร์ม — กด "สร้างแบบฟอร์ม"</p><?php endif; ?>
</div>
<?php endif; ?>
<?php require __DIR__ . '/_bottom.php'; ?>
