<?php
/** admin/ita.php — จัดการรายการ ITA/OIT (O1–O43, ไฟล์หรือลิงก์) */
require dirname(__DIR__) . '/includes/init.php';
require __DIR__ . '/_auth.php';

$groups = ita_groups();
$errors = [];
$edit = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['delete_id'])) {
        $id = (int)$_POST['delete_id'];
        if (trash_delete('ita_items', $id, $ADMIN['username'] ?? '')) {
            log_action('ลบรายการ ITA (ลงถังขยะ)', '#' . $id);
            flash_set('success', 'ย้ายรายการลงถังขยะแล้ว — กู้คืนได้ที่เมนู "ถังขยะ"');
        }
        redirect('admin/ita.php');
    }

    $id    = (int)($_POST['id'] ?? 0);
    $grp   = isset($groups[(int)($_POST['grp'] ?? 0)]) ? (int)$_POST['grp'] : 1;
    $code  = mb_substr(trim((string)($_POST['code'] ?? '')), 0, 10);
    $title = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 250);
    $note  = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 500);
    $url_  = safe_link_url((string)($_POST['url'] ?? ''));
    $sort  = (int)($_POST['sort_order'] ?? 0);
    $fy    = (int)($_POST['fiscal_year'] ?? 0) ?: fiscal_year_now();
    if ($fy < 2500 || $fy > 2700) $fy = fiscal_year_now();
    $pub   = trim((string)($_POST['published_at'] ?? ''));
    $pub   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $pub) ? $pub : null;

    if ($title === '') $errors[] = 'กรุณากรอกชื่อรายการ';

    $file = null;
    if (!$errors) {
        try { $file = handle_upload('file', 'ita', null, 30); }
        catch (RuntimeException $ex) { $errors[] = $ex->getMessage(); }
    }

    if (!$errors) {
        if ($id > 0) {
            $st = db()->prepare('SELECT file FROM ita_items WHERE id = ?');
            $st->execute([$id]);
            $old = $st->fetch();
            if ($file && $old) delete_upload($old['file']);
            db()->prepare('UPDATE ita_items SET grp=?, fiscal_year=?, code=?, title=?, note=?, published_at=?, url=?, sort_order=?, file=COALESCE(?, file) WHERE id=?')
                ->execute([$grp, $fy, $code, $title, $note, $pub, $url_, $sort, $file['path'] ?? null, $id]);
            flash_set('success', 'บันทึกรายการเรียบร้อยแล้ว');
        } else {
            db()->prepare('INSERT INTO ita_items (grp, fiscal_year, code, title, note, published_at, url, file, sort_order) VALUES (?,?,?,?,?,?,?,?,?)')
                ->execute([$grp, $fy, $code, $title, $note, $pub, $url_, $file['path'] ?? null, $sort]);
            flash_set('success', 'เพิ่มรายการเรียบร้อยแล้ว');
        }
        redirect('admin/ita.php?grp=' . $grp . '&fy=' . $fy);
    }
}

if (isset($_GET['edit'])) {
    $st = db()->prepare('SELECT * FROM ita_items WHERE id = ?');
    $st->execute([(int)$_GET['edit']]);
    $edit = $st->fetch();
}

$cur_grp = (int)($_GET['grp'] ?? ($edit['grp'] ?? 1));
if (!isset($groups[$cur_grp])) $cur_grp = 1;

/* ปีงบประมาณที่กำลังดูอยู่ */
$years  = ita_years();
$cur_fy = (int)($_GET['fy'] ?? ($edit['fiscal_year'] ?? 0));
if ($cur_fy < 2500) $cur_fy = in_array(fiscal_year_now(), $years, true) ? fiscal_year_now() : (int)$years[0];

$st = db()->prepare('SELECT * FROM ita_items WHERE grp = ? AND fiscal_year = ? ORDER BY sort_order ASC, id ASC');
$st->execute([$cur_grp, $cur_fy]);
$items = $st->fetchAll();

/* จำนวนรายการต่อปี (โชว์ในตัวเลือกปี) */
$year_counts = [];
foreach (db()->query('SELECT fiscal_year, COUNT(*) c FROM ita_items GROUP BY fiscal_year') as $r) {
    $year_counts[(int)$r['fiscal_year']] = (int)$r['c'];
}
if (!in_array($cur_fy, $years, true)) { $years[] = $cur_fy; rsort($years); }

$admin_title = 'ITA / OIT';
require __DIR__ . '/_top.php';
?>
<?php if ($errors): ?>
<div class="alert danger"><span class="material-symbols-rounded">error</span>
  <div><?php foreach ($errors as $er): ?><div><?= e($er) ?></div><?php endforeach; ?></div>
</div>
<?php endif; ?>

<div class="card mb-2">
  <div class="section-head" style="margin-bottom:8px;">
    <div><span class="tag">ITA</span><h3><?= $edit ? 'แก้ไขรายการ' : 'เพิ่มรายการ ITA/OIT' ?></h3>
    <p>แต่ละรายการแนบไฟล์ <b>หรือ</b> ใส่ลิงก์ภายนอกก็ได้ (ถ้าใส่ทั้งคู่จะใช้ลิงก์ก่อน)</p></div>
    <?php if ($edit): ?><a class="btn small" href="<?= e(url('admin/ita.php?grp=' . $cur_grp)) ?>">ยกเลิก</a><?php endif; ?>
  </div>
  <form method="post" action="" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
    <div class="form-row">
      <div>
        <label>หมวด</label>
        <select name="grp" class="mb-2">
          <?php foreach ($groups as $gid => $g): ?>
          <option value="<?= $gid ?>" <?= (int)($edit['grp'] ?? $cur_grp) === $gid ? 'selected' : '' ?>><?= e($g['title']) ?> (<?= e($g['sub']) ?>)</option>
          <?php endforeach; ?>
        </select>
        <div class="flex gap-2">
          <div><label>ปีงบประมาณ</label>
            <input type="number" name="fiscal_year" value="<?= (int)old('fiscal_year', $edit['fiscal_year'] ?? $cur_fy) ?>"
                   min="2500" max="2700" style="width:110px;" class="mb-2" title="พ.ศ. เช่น 2569"></div>
          <div><label>รหัส O</label><input type="text" name="code" value="<?= e(old('code', $edit['code'] ?? '')) ?>" placeholder="เช่น O1" style="width:100px;" class="mb-2"></div>
          <div style="flex:1;"><label>ชื่อรายการ <span style="color:var(--danger);">*</span></label>
          <input type="text" name="title" value="<?= e(old('title', $edit['title'] ?? '')) ?>" required class="mb-2" placeholder="เช่น โครงสร้างหน่วยงาน"></div>
        </div>
        <label>คำอธิบายประกอบ <span class="text-muted" style="font-weight:400;font-size:11.5px;">(ไม่บังคับ — เกณฑ์ประเมินมักขอให้ระบุรายละเอียด)</span></label>
        <input type="text" name="note" value="<?= e(old('note', $edit['note'] ?? '')) ?>" class="mb-2" placeholder="เช่น ข้อมูล ณ วันที่ ... / ปรับปรุงล่าสุด ...">
        <div class="flex gap-2">
          <div style="flex:1;"><label>ลิงก์ภายนอก (ถ้ามี)</label>
            <input type="text" name="url" value="<?= e(old('url', $edit['url'] ?? '')) ?>" placeholder="https://..." class="mb-2"></div>
          <div><label>วันที่เผยแพร่</label>
            <input type="date" name="published_at" value="<?= e(old('published_at', $edit['published_at'] ?? '')) ?>" class="mb-2"></div>
        </div>
        <label>ลำดับ</label>
        <input type="number" name="sort_order" value="<?= (int)($edit['sort_order'] ?? (count($items) + 1)) ?>" class="sort-input">
      </div>
      <div>
        <label>ไฟล์แนบ</label>
        <?php if (!empty($edit['file'])): ?>
        <div class="current-file"><span class="material-symbols-rounded icon-sm" style="color:var(--danger);">description</span><?= e(basename($edit['file'])) ?></div>
        <?php endif; ?>
        <div class="dropzone" style="padding:28px;">
          <input type="file" name="file" accept=".jpg,.jpeg,.png,.webp,.pdf,.doc,.docx,.xls,.xlsx,.zip">
          <span class="material-symbols-rounded icon-lg" style="color:var(--blue)">upload_file</span>
          <p style="margin:4px 0 0;font-size:13px;"><b>ลากไฟล์มาวาง</b> หรือคลิกเลือก</p>
          <div class="dz-filename"></div>
        </div>
      </div>
    </div>
    <button class="btn primary mt-2" type="submit"><span class="material-symbols-rounded icon-sm">save</span><?= $edit ? 'บันทึกการแก้ไข' : 'เพิ่มรายการ' ?></button>
  </form>
</div>

<div class="card">
  <div class="section-head" style="margin-bottom:8px;">
    <div><span class="tag">ITEMS</span><h3>รายการปีงบประมาณ <?= (int)$cur_fy ?></h3>
    <p>ข้อมูลแยกตามปีงบประมาณ — ขึ้นปีใหม่ให้เปลี่ยนปีในฟอร์มด้านบน ข้อมูลปีเก่ายังอยู่ครบ</p></div>
    <form method="get" action="" style="margin:0;display:flex;gap:6px;align-items:center;">
      <input type="hidden" name="grp" value="<?= (int)$cur_grp ?>">
      <label style="margin:0;font-size:13px;">ปีงบประมาณ</label>
      <select name="fy" onchange="this.form.submit()" style="width:auto;padding:7px 12px;">
        <?php foreach ($years as $y): ?>
        <option value="<?= (int)$y ?>" <?= (int)$y === (int)$cur_fy ? 'selected' : '' ?>>
          <?= (int)$y ?><?= isset($year_counts[$y]) ? ' (' . $year_counts[$y] . ')' : '' ?>
        </option>
        <?php endforeach; ?>
        <?php $next = fiscal_year_now() + 1; if (!in_array($next, $years, true)): ?>
        <option value="<?= $next ?>"><?= $next ?> (เริ่มปีใหม่)</option>
        <?php endif; ?>
      </select>
    </form>
  </div>
  <div class="cats mb-2">
    <?php foreach ($groups as $gid => $g): ?>
    <a class="cat<?= $cur_grp === $gid ? ' active' : '' ?>" href="<?= e(url('admin/ita.php?grp=' . $gid . '&fy=' . $cur_fy)) ?>"><?= e($g['title']) ?></a>
    <?php endforeach; ?>
  </div>
  <?php if ($items): ?>
  <table class="admin-table">
    <tr><th>ลำดับ</th><th>รหัส</th><th>ชื่อรายการ</th><th>ประเภท</th><th></th></tr>
    <?php foreach ($items as $it): ?>
    <tr>
      <td><?= (int)$it['sort_order'] ?></td>
      <td><b style="color:var(--blue);"><?= e($it['code']) ?></b></td>
      <td><?= e(mb_strimwidth($it['title'], 0, 70, '…')) ?></td>
      <td><?= $it['url'] ? '<span class="badge" style="font-size:11px;">ลิงก์</span>' : ($it['file'] ? '<span class="badge success" style="font-size:11px;">ไฟล์</span>' : '<span class="text-muted" style="font-size:12px;">—</span>') ?></td>
      <td style="white-space:nowrap;">
        <a class="btn small" href="<?= e(url('admin/ita.php?edit=' . $it['id'])) ?>">แก้ไข</a>
        <form method="post" action="" style="display:inline;">
          <?= csrf_field() ?>
          <input type="hidden" name="delete_id" value="<?= (int)$it['id'] ?>">
          <button class="btn small danger" type="submit" data-confirm="ยืนยันลบรายการนี้?">ลบ</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php else: ?>
  <p class="text-muted text-center" style="padding:24px;">ยังไม่มีรายการในหมวดนี้</p>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/_bottom.php'; ?>
