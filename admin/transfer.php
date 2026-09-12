<?php
/** admin/transfer.php — โอนย้ายเว็บทั้งหมดไปอีกเครื่อง (export/import) เฉพาะ admin */
require dirname(__DIR__) . '/includes/init.php';
require __DIR__ . '/_auth.php';
require_admin();
require dirname(__DIR__) . '/includes/transfer.php';

$cur_version = defined('APP_VERSION') ? APP_VERSION : '0.0.0';
$inspect = null;   /* manifest ที่ตรวจแล้ว (ขั้นยืนยัน) */
$inbox   = '';     /* ชื่อไฟล์ที่เก็บไว้รอนำเข้า */
$result  = null;   /* ผลการนำเข้า */
$errors  = [];

/* ───────── EXPORT: สร้างแล้วส่งให้ดาวน์โหลด ───────── */
if (($_POST['action'] ?? '') === 'export') {
    $err = null;
    $path = transfer_export($err);
    if (!$path) {
        flash_set('danger', 'สร้างไฟล์โอนย้ายไม่สำเร็จ: ' . $err);
        redirect('admin/transfer.php');
    }
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . basename($path) . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    @unlink($path);   /* ส่งแล้วลบ ไม่ทิ้งไฟล์ใหญ่ค้างบนเครื่องต้นทาง */
    exit;
}

/* ───────── INSPECT: รับไฟล์ (อัปโหลด/เลือกจากเครื่อง) → ตรวจ → แสดงรายละเอียด+ยืนยัน ───────── */
if (($_POST['action'] ?? '') === 'inspect') {
    $store = up_storage();
    $path  = '';

    if (($_POST['source'] ?? '') === 'server') {
        $name = basename((string)($_POST['server_file'] ?? ''));
        $cand = $store . '/' . $name;
        if ($name !== '' && is_file($cand)) $path = $cand;
        else $errors[] = 'ไม่พบไฟล์ที่เลือกบนเครื่อง';
    } else {
        $up = $_FILES['package'] ?? null;
        if (!$up || ($up['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $code = $up['error'] ?? UPLOAD_ERR_NO_FILE;
            $errors[] = $code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE
                ? 'ไฟล์ใหญ่เกินกว่าที่เซิร์ฟเวอร์รับได้ — ใช้วิธี "วางไฟล์บนเครื่อง" แทน'
                : 'อัปโหลดไฟล์ไม่สำเร็จ (รหัส ' . (int)$code . ')';
        } elseif (strtolower(pathinfo($up['name'], PATHINFO_EXTENSION)) !== 'zip') {
            $errors[] = 'ต้องเป็นไฟล์ .zip ที่ได้จากปุ่มดาวน์โหลดของเว็บต้นทาง';
        } else {
            $dest = $store . '/inbox-' . date('Ymd-His') . '.zip';
            if (!@move_uploaded_file($up['tmp_name'], $dest)) $errors[] = 'บันทึกไฟล์ที่อัปโหลดไม่ได้ (storage/updates เขียนไม่ได้?)';
            else $path = $dest;
        }
    }

    if (!$errors && $path) {
        $merr = null;
        $m = transfer_read_manifest($path, $merr);
        if (!$m) { $errors[] = $merr; @unlink($path); }
        else { $inspect = $m; $inbox = basename($path); }
    }
}

/* ───────── APPLY: ยืนยันแล้ว → ดำเนินการเขียนทับจริง ───────── */
if (($_POST['action'] ?? '') === 'apply') {
    $name = basename((string)($_POST['inbox'] ?? ''));
    $path = up_storage() . '/' . $name;

    if (empty($_POST['confirm_overwrite'])) {
        $errors[] = 'กรุณาติ๊กยืนยันว่าเข้าใจว่าข้อมูลปัจจุบันจะถูกแทนที่';
    } elseif ($name === '' || !is_file($path)) {
        $errors[] = 'ไม่พบไฟล์ที่จะนำเข้า — กรุณาเลือกไฟล์ใหม่';
    } else {
        $merr = null;
        $m = transfer_read_manifest($path, $merr);
        if (!$m) { $errors[] = $merr; }
        elseif (version_compare((string)$m['app_version'], $cur_version, '>')) {
            $errors[] = 'เว็บต้นทางเวอร์ชัน v' . e($m['app_version']) . ' ใหม่กว่าเว็บนี้ (v' . e($cur_version) . ') — กรุณาอัปเดตเว็บนี้ก่อนที่เมนู "อัปเดตระบบ"';
        } else {
            $result = transfer_import($path, $m);
            @unlink($path);   /* ลบไฟล์นำเข้าหลังเสร็จ */
            if ($result['ok']) log_action('โอนย้ายเว็บสำเร็จ', 'สำรองเดิม: ' . $result['backup']);
        }
    }
    /* ถ้า error ก่อนเริ่ม ให้กลับไปแสดงรายละเอียดเดิม */
    if ($errors && $name !== '' && is_file($path)) {
        $merr = null;
        if ($mm = transfer_read_manifest($path, $merr)) { $inspect = $mm; $inbox = $name; }
    }
}

/* ข้อมูลฝั่ง UI */
$srv_files  = transfer_server_files();
$up_max     = ini_get('upload_max_filesize');
$post_max   = ini_get('post_max_size');
$admin_title = 'โอนย้ายเว็บ';
require __DIR__ . '/_top.php';

/** ตัดสินความเข้ากันได้ของเวอร์ชัน */
function transfer_version_verdict(string $src, string $cur): array {
    $cmp = version_compare($src, $cur);
    if ($cmp > 0)  return ['danger',  'ต้นทางใหม่กว่า — ต้องอัปเดตเว็บนี้ก่อน', false];
    if ($cmp < 0)  return ['warning', 'ต้นทางเก่ากว่า — ระบบจะปรับโครงสร้างให้อัตโนมัติหลังนำเข้า', true];
    return ['success', 'เวอร์ชันตรงกัน — พร้อมโอนย้าย', true];
}
?>

<?php if ($errors): ?>
<div class="alert danger"><span class="material-symbols-rounded">error</span>
  <div><?php foreach ($errors as $er): ?><div><?= e($er) ?></div><?php endforeach; ?></div>
</div>
<?php endif; ?>

<?php if ($result): /* ───── ผลการนำเข้า ───── */ ?>
<div class="card mb-2">
  <div class="section-head" style="margin-bottom:8px;">
    <div><span class="tag"><span class="material-symbols-rounded icon-sm">swap_horiz</span> TRANSFER</span>
      <h3><?= $result['ok'] ? 'โอนย้ายข้อมูลสำเร็จ 🎉' : 'โอนย้ายไม่สำเร็จ' ?></h3></div>
  </div>
  <div class="alert <?= $result['ok'] ? 'success' : 'danger' ?>" style="font-size:13px;">
    <span class="material-symbols-rounded"><?= $result['ok'] ? 'check_circle' : 'error' ?></span>
    <div><?= $result['ok']
      ? 'ข้อมูล ผู้ใช้ และไฟล์ทั้งหมดถูกแทนที่ด้วยของเว็บต้นทางแล้ว — โปรด<b>เข้าสู่ระบบใหม่ด้วยบัญชีของเว็บต้นทาง</b>'
      : e($result['error']) ?></div>
  </div>
  <pre style="background:#0f172a;color:#e2e8f0;border-radius:10px;padding:14px 16px;font-size:12.5px;line-height:1.7;overflow:auto;max-height:360px;white-space:pre-wrap;"><?php foreach ($result['log'] as $l) echo e($l) . "\n"; ?></pre>
  <?php if ($result['ok']): ?>
  <a class="btn primary mt-1" href="<?= e(url('admin/login.php')) ?>"><span class="material-symbols-rounded icon-sm">login</span>ไปหน้าเข้าสู่ระบบ</a>
  <?php else: ?>
  <a class="btn mt-1" href="<?= e(url('admin/transfer.php')) ?>">กลับ</a>
  <?php endif; ?>
</div>

<?php elseif ($inspect): /* ───── ขั้นยืนยันก่อนเขียนทับ ───── */
  $tot_rows = array_sum(array_map('intval', (array)($inspect['row_counts'] ?? [])));
  [$vc, $vmsg, $vok] = transfer_version_verdict((string)$inspect['app_version'], $cur_version);
?>
<div class="card mb-2">
  <div class="section-head" style="margin-bottom:8px;">
    <div><span class="tag"><span class="material-symbols-rounded icon-sm">fact_check</span> ตรวจสอบ</span>
      <h3>ยืนยันการโอนย้าย (เขียนทับเว็บนี้)</h3>
      <p>ตรวจรายละเอียดของไฟล์ก่อน แล้วยืนยันเพื่อเขียนทับข้อมูลทั้งหมดของเว็บนี้</p></div>
  </div>

  <table class="admin-table mb-2">
    <tr><th style="width:200px;">เว็บต้นทาง</th><td><b style="color:var(--ink);"><?= e($inspect['site_name'] ?: $inspect['source_db']) ?></b></td></tr>
    <tr><th>เวอร์ชันต้นทาง</th><td>v<?= e($inspect['app_version']) ?> <span class="text-muted">(<?= e($inspect['app_build'] ?? '') ?>)</span> &nbsp;·&nbsp; เว็บนี้ v<?= e($cur_version) ?>
      <br><span class="badge <?= e($vc) ?>" style="margin-top:4px;"><span class="material-symbols-rounded icon-sm">info</span><?= e($vmsg) ?></span></td></tr>
    <tr><th>สร้างเมื่อ</th><td><?= e(str_replace('T', ' ', substr((string)($inspect['created_at'] ?? ''), 0, 19))) ?></td></tr>
    <tr><th>ฐานข้อมูล</th><td><?= number_format((int)($inspect['tables'] ?? 0)) ?> ตาราง · <?= number_format($tot_rows) ?> แถว</td></tr>
    <tr><th>ไฟล์อัปโหลด</th><td><?= number_format((int)($inspect['uploads_files'] ?? 0)) ?> ไฟล์ · <?= e(format_bytes((int)($inspect['uploads_bytes'] ?? 0))) ?></td></tr>
  </table>

  <div class="alert danger" style="font-size:13px;">
    <span class="material-symbols-rounded">warning</span>
    <div><b>ข้อมูลทั้งหมดของเว็บนี้จะถูกลบและแทนที่</b> ด้วยข้อมูลจากเว็บต้นทาง — รวมข่าว เอกสาร ไฟล์ ผู้ใช้/รหัสผ่าน และการตั้งค่า<br>
      ระบบจะสำรองของเดิมไว้ที่ <code>storage/updates/</code> โดยอัตโนมัติ (กู้คืนได้หากผิดพลาด)</div>
  </div>

  <?php if ($vok): ?>
  <form method="post" action="">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="apply">
    <input type="hidden" name="inbox" value="<?= e($inbox) ?>">
    <label class="flex items-center gap-1 mb-2" style="cursor:pointer;font-size:13.5px;">
      <input type="checkbox" name="confirm_overwrite" value="1" required>
      ฉันเข้าใจว่าข้อมูลทั้งหมดของเว็บนี้จะถูกแทนที่ และจะเข้าสู่ระบบใหม่ด้วยบัญชีของเว็บต้นทาง
    </label>
    <button class="btn danger" type="submit" data-confirm="ยืนยันเขียนทับข้อมูลเว็บนี้ทั้งหมดด้วยข้อมูลจากเว็บต้นทาง?">
      <span class="material-symbols-rounded icon-sm">swap_horiz</span>เริ่มโอนย้าย (เขียนทับ)</button>
    <a class="btn" href="<?= e(url('admin/transfer.php')) ?>">ยกเลิก</a>
  </form>
  <?php else: ?>
  <a class="btn" href="<?= e(url('admin/transfer.php')) ?>">กลับ</a>
  <?php endif; ?>
</div>

<?php else: /* ───── หน้าหลัก: export + import ───── */ ?>
<div class="card mb-2">
  <div class="section-head" style="margin-bottom:8px;">
    <div><span class="tag"><span class="material-symbols-rounded icon-sm">swap_horiz</span> TRANSFER</span><h3>โอนย้ายเว็บไปอีกเครื่อง</h3>
    <p>ย้ายทั้งเว็บ (ฐานข้อมูล + ไฟล์อัปโหลดทั้งหมด) ไปลงเครื่องใหม่ในไฟล์เดียว เหมือนยกเว็บไปทั้งชุด</p></div>
  </div>

  <div class="grid grid-2">
    <!-- EXPORT -->
    <div class="card" style="background:rgba(255,255,255,.7);">
      <div class="flex items-center gap-1 mb-1"><span class="material-symbols-rounded" style="color:var(--blue);">download</span><b style="color:var(--ink);">1) ดาวน์โหลดจากเว็บนี้ (ต้นทาง)</b></div>
      <p class="text-muted" style="font-size:13px;">สร้างไฟล์ <code>.zip</code> รวมฐานข้อมูล + ไฟล์อัปโหลดทั้งหมด + ลายเซ็นตรวจสอบ (SHA-256)</p>
      <form method="post" action="">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="export">
        <button class="btn primary" type="submit"><span class="material-symbols-rounded icon-sm">archive</span>สร้าง & ดาวน์โหลดไฟล์โอนย้าย</button>
      </form>
    </div>

    <!-- IMPORT -->
    <div class="card" style="background:rgba(255,255,255,.7);">
      <div class="flex items-center gap-1 mb-1"><span class="material-symbols-rounded" style="color:var(--danger);">upload</span><b style="color:var(--ink);">2) นำเข้าที่เครื่องใหม่ (ปลายทาง)</b></div>
      <p class="text-muted" style="font-size:13px;">อัปโหลดไฟล์ที่ได้จากข้อ 1 — ระบบจะ<b>ตรวจสอบก่อน</b> แล้วให้ยืนยันอีกครั้งก่อนเขียนทับ</p>
      <form method="post" action="" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="inspect">
        <input type="hidden" name="source" value="upload">
        <input type="file" name="package" accept=".zip" required
               style="display:block;width:100%;padding:9px;border:1px dashed var(--border);border-radius:10px;margin-bottom:8px;">
        <button class="btn" type="submit"><span class="material-symbols-rounded icon-sm">fact_check</span>ตรวจสอบไฟล์</button>
      </form>
      <p class="text-muted" style="font-size:11.5px;margin-top:6px;">ขนาดอัปโหลดสูงสุดของเซิร์ฟเวอร์: <?= e($up_max) ?> (POST <?= e($post_max) ?>)</p>
    </div>
  </div>
</div>

<!-- นำเข้าจากไฟล์ที่วางบนเครื่อง (สำหรับเว็บใหญ่ที่อัปโหลดผ่านเบราว์เซอร์ไม่ได้) -->
<div class="card mb-2">
  <div class="section-head" style="margin-bottom:6px;">
    <div><span class="tag"><span class="material-symbols-rounded icon-sm">dns</span> ทางเลือก</span><h3>นำเข้าจากไฟล์บนเครื่อง (เว็บขนาดใหญ่)</h3>
    <p>หากไฟล์ใหญ่เกินกว่าจะอัปโหลดผ่านเบราว์เซอร์ ให้วางไฟล์ <code>.zip</code> ไว้ที่โฟลเดอร์ <code>storage/updates/</code> แล้วเลือกที่นี่</p></div>
  </div>
  <?php if ($srv_files): ?>
  <form method="post" action="">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="inspect">
    <input type="hidden" name="source" value="server">
    <table class="admin-table">
      <tr><th style="width:40px;"></th><th>ไฟล์</th><th style="width:120px;">ขนาด</th></tr>
      <?php foreach ($srv_files as $i => $f): ?>
      <tr>
        <td><input type="radio" name="server_file" value="<?= e($f['name']) ?>" <?= $i === 0 ? 'checked' : '' ?> required></td>
        <td><?= e($f['name']) ?></td>
        <td class="lr-date"><?= e(format_bytes($f['size'])) ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
    <button class="btn mt-1" type="submit"><span class="material-symbols-rounded icon-sm">fact_check</span>ตรวจสอบไฟล์ที่เลือก</button>
  </form>
  <?php else: ?>
  <p class="text-muted text-center" style="padding:18px;">ยังไม่มีไฟล์ <code>.zip</code> ในโฟลเดอร์ <code>storage/updates/</code></p>
  <?php endif; ?>
</div>

<div class="alert info" style="font-size:13px;">
  <span class="material-symbols-rounded">tips_and_updates</span>
  <div><b>ขั้นตอนแนะนำ:</b> 1) ที่เว็บ ก กด "สร้าง & ดาวน์โหลด" &nbsp;→&nbsp; 2) ติดตั้งเว็บ ข ให้เสร็จ (มี config.php/ฐานข้อมูลของตัวเอง) แล้วอัปเดตให้เวอร์ชันเท่ากับ ก &nbsp;→&nbsp; 3) ที่เว็บ ข อัปโหลดไฟล์แล้วยืนยันโอนย้าย &nbsp;·&nbsp; <b>config.php ของเว็บ ข จะไม่ถูกแตะ</b> (เชื่อมฐานข้อมูลของ ข เหมือนเดิม)</div>
</div>
<?php endif; ?>
<?php require __DIR__ . '/_bottom.php'; ?>
