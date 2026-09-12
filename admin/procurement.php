<?php
/** admin/procurement.php — จัดการประกาศจัดซื้อจัดจ้าง */
require dirname(__DIR__) . '/includes/init.php';
require __DIR__ . '/_auth.php';

$ptypes = proc_types();
$errors = [];
$edit = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['delete_id'])) {
        $id = (int)$_POST['delete_id'];
        if (trash_delete('procurements', $id, $ADMIN['username'] ?? '')) {
            log_action('ลบจัดซื้อจัดจ้าง (ลงถังขยะ)', '#' . $id);
            flash_set('success', 'ย้ายประกาศลงถังขยะแล้ว — กู้คืนได้ที่เมนู "ถังขยะ"');
        }
        redirect('admin/procurement.php');
    }

    $id     = (int)($_POST['id'] ?? 0);
    $title  = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 250);
    $ptype  = isset($ptypes[$_POST['ptype'] ?? '']) ? $_POST['ptype'] : 'other';
    $pdate  = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['pdate'] ?? '')) ? $_POST['pdate'] : date('Y-m-d');
    $extUrl = ext_link_clean((string)($_POST['ext_url'] ?? ''));

    if ($title === '') $errors[] = 'กรุณากรอกชื่อเรื่อง';

    $file = null;
    if (!$errors) {
        try { $file = handle_upload('file', 'proc', null, 30); }
        catch (RuntimeException $ex) { $errors[] = $ex->getMessage(); }
    }

    if (!$errors) {
        if ($id > 0) {
            $st = db()->prepare('SELECT file FROM procurements WHERE id = ?');
            $st->execute([$id]);
            $old = $st->fetch();
            if ($file) {
                if ($old && !empty($old['file'])) delete_upload($old['file']);
                db()->prepare("UPDATE procurements SET title=?, ptype=?, pdate=?, file=?, ext_url='', file_size=? WHERE id=?")
                    ->execute([$title, $ptype, $pdate, $file['path'], $file['size'], $id]);
            } elseif ($extUrl !== '') {
                if ($old && !empty($old['file'])) delete_upload($old['file']);
                db()->prepare("UPDATE procurements SET title=?, ptype=?, pdate=?, file=NULL, ext_url=?, file_size=0 WHERE id=?")
                    ->execute([$title, $ptype, $pdate, $extUrl, $id]);
            } else {
                db()->prepare('UPDATE procurements SET title=?, ptype=?, pdate=? WHERE id=?')
                    ->execute([$title, $ptype, $pdate, $id]);
            }
            flash_set('success', 'บันทึกประกาศเรียบร้อยแล้ว');
        } else {
            db()->prepare('INSERT INTO procurements (title, ptype, pdate, file, ext_url, file_size) VALUES (?,?,?,?,?,?)')
                ->execute([$title, $ptype, $pdate, $file['path'] ?? null, $extUrl, $file['size'] ?? 0]);
            flash_set('success', 'เพิ่มประกาศเรียบร้อยแล้ว');
        }
        redirect('admin/procurement.php');
    }
}

if (isset($_GET['edit'])) {
    $st = db()->prepare('SELECT * FROM procurements WHERE id = ?');
    $st->execute([(int)$_GET['edit']]);
    $edit = $st->fetch();
}

$page = max(1, (int)($_GET['page'] ?? 1));
$per  = 20;
$total = (int)db()->query('SELECT COUNT(*) FROM procurements')->fetchColumn();
$procs = db()->query('SELECT * FROM procurements ORDER BY pdate DESC, id DESC LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per))->fetchAll();

$admin_title = 'จัดซื้อจัดจ้าง';
require __DIR__ . '/_top.php';
?>
<?php if ($errors): ?>
<div class="alert danger"><span class="material-symbols-rounded">error</span>
  <div><?php foreach ($errors as $er): ?><div><?= e($er) ?></div><?php endforeach; ?></div>
</div>
<?php endif; ?>

<div class="card mb-2">
  <div class="section-head" style="margin-bottom:8px;">
    <div><span class="tag">PROCUREMENT</span><h3><?= $edit ? 'แก้ไขประกาศ' : 'เพิ่มประกาศจัดซื้อจัดจ้าง' ?></h3></div>
    <?php if ($edit): ?><a class="btn small" href="<?= e(url('admin/procurement.php')) ?>">ยกเลิก</a><?php endif; ?>
  </div>
  <form method="post" action="" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
    <div class="form-row">
      <div>
        <label>ชื่อเรื่อง <span style="color:var(--danger);">*</span></label>
        <input type="text" name="title" value="<?= e(old('title', $edit['title'] ?? '')) ?>" required class="mb-2" placeholder="เช่น ประกวดราคาซื้อครุภัณฑ์...">
        <div class="form-row">
          <div>
            <label>ประเภท</label>
            <select name="ptype" class="mb-2">
              <?php foreach ($ptypes as $tk => $tv): ?>
              <option value="<?= e($tk) ?>" <?= ($edit['ptype'] ?? '') === $tk ? 'selected' : '' ?>><?= e($tv['label']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label>วันที่ประกาศ</label>
            <input type="date" name="pdate" value="<?= e($edit['pdate'] ?? date('Y-m-d')) ?>" class="mb-2" style="padding:11px 16px;">
          </div>
        </div>
      </div>
      <div>
        <label>ไฟล์แนบ <span class="text-muted">(อัปโหลด หรือใส่ลิงก์)</span></label>
        <?php if (!empty($edit['file'])): ?>
        <div class="current-file"><span class="material-symbols-rounded icon-sm" style="color:var(--danger);">description</span><?= e(basename($edit['file'])) ?> (<?= e(format_bytes((int)$edit['file_size'])) ?>)</div>
        <?php elseif (!empty($edit['ext_url'])): ?>
        <div class="current-file"><span class="material-symbols-rounded icon-sm" style="color:var(--blue);">link</span>ลิงก์ภายนอก: <?= e(mb_strimwidth($edit['ext_url'], 0, 50, '…')) ?></div>
        <?php endif; ?>
        <div class="dropzone" style="padding:18px;">
          <input type="file" name="file" accept=".jpg,.jpeg,.png,.webp,.pdf,.doc,.docx,.xls,.xlsx,.zip">
          <span class="material-symbols-rounded icon-lg" style="color:var(--blue)">upload_file</span>
          <p style="margin:4px 0 0;font-size:13px;"><b>ลากไฟล์มาวาง</b> หรือคลิกเลือก</p>
          <div class="dz-filename"></div>
        </div>
        <div class="or-divider"><span>หรือ</span></div>
        <label>ลิงก์ภายนอก <span class="text-muted">(Google Drive ฯลฯ)</span></label>
        <input type="url" name="ext_url" value="<?= e(old('ext_url', $edit['ext_url'] ?? '')) ?>" placeholder="https://drive.google.com/...">
      </div>
    </div>
    <button class="btn primary mt-2" type="submit"><span class="material-symbols-rounded icon-sm">save</span><?= $edit ? 'บันทึกการแก้ไข' : 'เพิ่มประกาศ' ?></button>
  </form>
</div>

<div class="card">
  <div class="section-head" style="margin-bottom:8px;">
    <div><span class="tag">ALL</span><h3>ประกาศทั้งหมด (<?= number_format($total) ?>)</h3></div>
  </div>
  <?php if ($procs): ?>
  <table class="admin-table">
    <tr><th>เรื่อง</th><th>ประเภท</th><th>วันที่</th><th>ไฟล์</th><th></th></tr>
    <?php foreach ($procs as $p): $pt = $ptypes[$p['ptype']] ?? $ptypes['other']; ?>
    <tr>
      <td style="max-width:320px;"><?= e(mb_strimwidth($p['title'], 0, 70, '…')) ?></td>
      <td><span class="badge <?= e($pt['badge']) ?>"><?= e($pt['label']) ?></span></td>
      <td class="lr-date" style="white-space:nowrap;"><?= e(thai_date($p['pdate'])) ?></td>
      <td class="lr-date"><?= $p['file'] ? e(format_bytes((int)$p['file_size'])) : '—' ?></td>
      <td style="white-space:nowrap;">
        <a class="btn small" href="<?= e(url('admin/procurement.php?edit=' . $p['id'])) ?>">แก้ไข</a>
        <form method="post" action="" style="display:inline;">
          <?= csrf_field() ?>
          <input type="hidden" name="delete_id" value="<?= (int)$p['id'] ?>">
          <button class="btn small danger" type="submit" data-confirm="ยืนยันลบประกาศนี้?">ลบ</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?= pagination_html($total, $per, $page, url('admin/procurement.php')) ?>
  <?php else: ?>
  <p class="text-muted text-center" style="padding:24px;">ยังไม่มีประกาศ</p>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/_bottom.php'; ?>
