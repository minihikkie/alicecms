<?php
/** admin/backup.php — สำรองข้อมูล: ฐานข้อมูล (.sql) + ไฟล์อัปโหลด (.zip) + กู้คืนจากชุดสำรอง (เฉพาะ admin) */
require dirname(__DIR__) . '/includes/init.php';
require __DIR__ . '/_auth.php';
require_admin();
require dirname(__DIR__) . '/includes/updater.php';
require dirname(__DIR__) . '/includes/autobackup.php';

/* ── บันทึกการตั้งค่าสำรองอัตโนมัติ ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_autobackup'])) {
    $mode = in_array($_POST['backup_auto'] ?? '', ['off', 'daily', 'weekly'], true) ? $_POST['backup_auto'] : 'weekly';
    setting_set('backup_auto', $mode);
    setting_set('backup_keep', (string)max(1, min(30, (int)($_POST['backup_keep'] ?? 5))));
    ab_rotate();
    log_action('ตั้งค่าสำรองอัตโนมัติ', $mode);
    flash_set('success', 'บันทึกการตั้งค่าสำรองอัตโนมัติแล้ว');
    redirect('admin/backup.php');
}

/* ── สั่งสำรองเดี๋ยวนี้ ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_autobackup'])) {
    [$ok, $msg] = ab_run();
    log_action($ok ? 'สำรองข้อมูลทันที' : 'สำรองข้อมูลล้มเหลว', mb_substr($msg, 0, 200));
    flash_set($ok ? 'success' : 'danger', $msg);
    redirect('admin/backup.php');
}

/* ── ลบชุดสำรองอัตโนมัติทีละชุด ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['del_autobackup'])) {
    $name = basename((string)$_POST['del_autobackup']);
    $path = ab_dir() . '/' . $name;
    if (preg_match('/^auto-[0-9\-]+\.sql$/', $name) && is_file($path) && @unlink($path)) {
        flash_set('success', 'ลบชุดสำรอง ' . $name . ' แล้ว');
    } else {
        flash_set('danger', 'ลบไม่สำเร็จ');
    }
    redirect('admin/backup.php');
}

/* ── ดาวน์โหลดชุดสำรองอัตโนมัติ ── */
if (($_GET['action'] ?? '') === 'get-auto') {
    $name = basename((string)($_GET['name'] ?? ''));
    $path = ab_dir() . '/' . $name;
    if (preg_match('/^auto-[0-9\-]+\.sql$/', $name) && is_file($path)) {
        log_action('ดาวน์โหลดชุดสำรองอัตโนมัติ', $name);
        header('Content-Type: application/sql; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }
    http_response_code(404);
    exit('ไม่พบไฟล์');
}

/* ── กู้คืนจากชุดสำรองที่ระบบสร้างไว้ก่อนอัปเดต/โอนย้าย ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['restore_backup'])) {
    $name  = (string)$_POST['restore_backup'];
    $what  = ($_POST['restore_what'] ?? '') === 'files' ? 'files' : 'db';
    $err   = null;
    if (empty($_POST['confirm_restore'])) {
        flash_set('danger', 'กรุณาติ๊กยืนยันก่อนกู้คืน');
    } elseif ($what === 'db') {
        if (up_restore_db_from($name, $err)) {
            log_action('กู้คืนฐานข้อมูลจากสำรอง', $name);
            flash_set('success', 'กู้คืนฐานข้อมูลจากชุด ' . $name . ' เรียบร้อยแล้ว — โปรดเข้าสู่ระบบใหม่');
        } else {
            flash_set('danger', (string)$err);
        }
    } else {
        if (up_restore_files_from($name, $err)) {
            log_action('กู้คืนไฟล์ระบบจากสำรอง', $name);
            flash_set('success', 'กู้คืนไฟล์ระบบจากชุด ' . $name . ' เรียบร้อยแล้ว');
        } else {
            flash_set('danger', (string)$err);
        }
    }
    redirect('admin/backup.php');
}

$action = $_GET['action'] ?? '';
$stamp  = date('Ymd-His');
$slug   = preg_replace('/[^A-Za-z0-9]+/', '', (string)DB_NAME) ?: 'govsite';

/* ── สำรองฐานข้อมูลเป็นไฟล์ .sql (PHP ล้วน ไม่พึ่ง mysqldump) ── */
if ($action === 'db') {
    log_action('สำรองฐานข้อมูล', '');
    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $slug . '-db-' . $stamp . '.sql"');
    $pdo = db();
    echo "-- สำรองฐานข้อมูล " . DB_NAME . " เมื่อ " . date('c') . "\n";
    echo "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n";
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $t) {
        $create = $pdo->query('SHOW CREATE TABLE `' . $t . '`')->fetch(PDO::FETCH_ASSOC);
        echo "DROP TABLE IF EXISTS `$t`;\n";
        echo ($create['Create Table'] ?? $create['Create View'] ?? '') . ";\n\n";
        $rows = $pdo->query('SELECT * FROM `' . $t . '`');
        foreach ($rows as $row) {
            $cols = '`' . implode('`,`', array_keys($row)) . '`';
            $vals = array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v), array_values($row));
            echo "INSERT INTO `$t` ($cols) VALUES (" . implode(',', $vals) . ");\n";
        }
        echo "\n";
    }
    echo "SET FOREIGN_KEY_CHECKS=1;\n";
    exit;
}

/* ── สำรองไฟล์อัปโหลดเป็น .zip ── */
if ($action === 'uploads') {
    if (!class_exists('ZipArchive')) {
        flash_set('danger', 'เซิร์ฟเวอร์ไม่รองรับ ZipArchive — สำรองโฟลเดอร์ uploads ด้วยตนเอง');
        redirect('admin/backup.php');
    }
    $tmp = tempnam(sys_get_temp_dir(), 'bak');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::OVERWRITE);
    $base = realpath(APP_ROOT . '/uploads');
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->isFile()) {
            $local = 'uploads/' . substr($file->getPathname(), strlen($base) + 1);
            $zip->addFile($file->getPathname(), str_replace('\\', '/', $local));
        }
    }
    $zip->close();
    log_action('สำรองไฟล์อัปโหลด', '');
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $slug . '-uploads-' . $stamp . '.zip"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    @unlink($tmp);
    exit;
}

/* ── ขนาดข้อมูลโดยประมาณ ── */
$db_size = 0;
try {
    $db_size = (int)db()->query("SELECT COALESCE(SUM(data_length+index_length),0) FROM information_schema.tables WHERE table_schema = DATABASE()")->fetchColumn();
} catch (Throwable $e) {}
$up_size = 0; $up_count = 0;
$base = realpath(APP_ROOT . '/uploads');
if ($base) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)) as $f) {
        if ($f->isFile() && $f->getFilename() !== '.htaccess') { $up_size += $f->getSize(); $up_count++; }
    }
}

$admin_title = 'สำรองข้อมูล';
require __DIR__ . '/_top.php';
?>
<div class="card mb-2">
  <div class="section-head" style="margin-bottom:8px;">
    <div><span class="tag"><span class="material-symbols-rounded icon-sm">cloud_download</span> BACKUP</span><h3>สำรองข้อมูลเว็บไซต์</h3>
    <p>ดาวน์โหลดเก็บไว้สม่ำเสมอ — ข้อมูลทั้งหมดอยู่ 2 ส่วน ควรสำรองทั้งคู่</p></div>
  </div>

  <div class="grid grid-2">
    <div class="card" style="background:rgba(255,255,255,.7);">
      <div class="flex items-center gap-1 mb-1"><span class="material-symbols-rounded" style="color:var(--blue);">database</span><b style="color:var(--ink);">ฐานข้อมูล (.sql)</b></div>
      <p class="text-muted" style="font-size:13px;">ข่าว เอกสาร ตั้งค่า ผู้ใช้ เรื่องร้องเรียน สถิติ ทั้งหมด — ขนาดประมาณ <?= e(format_bytes($db_size)) ?></p>
      <a class="btn primary" href="<?= e(url('admin/backup.php?action=db')) ?>"><span class="material-symbols-rounded icon-sm">download</span>ดาวน์โหลดฐานข้อมูล</a>
    </div>
    <div class="card" style="background:rgba(255,255,255,.7);">
      <div class="flex items-center gap-1 mb-1"><span class="material-symbols-rounded" style="color:var(--success);">folder_zip</span><b style="color:var(--ink);">ไฟล์อัปโหลด (.zip)</b></div>
      <p class="text-muted" style="font-size:13px;">รูปข่าว เอกสาร โลโก้ ไฟล์แนบ — <?= number_format($up_count) ?> ไฟล์ ประมาณ <?= e(format_bytes($up_size)) ?></p>
      <a class="btn success" href="<?= e(url('admin/backup.php?action=uploads')) ?>"><span class="material-symbols-rounded icon-sm">download</span>ดาวน์โหลดไฟล์อัปโหลด</a>
    </div>
  </div>

  <div class="alert info mt-2" style="font-size:13px;">
    <span class="material-symbols-rounded">info</span>
    <div><b>วิธีกู้คืนจากไฟล์ที่ดาวน์โหลด:</b> นำไฟล์ .sql ไป import ใน phpMyAdmin และแตกไฟล์ .zip ทับโฟลเดอร์ uploads — แนะนำสำรองอย่างน้อยสัปดาห์ละครั้ง</div>
  </div>
</div>

<!-- สำรองอัตโนมัติตามรอบเวลา -->
<div class="card mb-2">
  <div class="section-head" style="margin-bottom:8px;">
    <div><span class="tag"><span class="material-symbols-rounded icon-sm">schedule</span> AUTO</span><h3>สำรองอัตโนมัติ</h3>
    <p>ระบบสำรองฐานข้อมูลให้เองตามรอบที่ตั้งไว้ และลบชุดเก่าอัตโนมัติเพื่อไม่ให้พื้นที่เต็ม</p></div>
    <form method="post" action="" style="margin:0;">
      <?= csrf_field() ?>
      <input type="hidden" name="run_autobackup" value="1">
      <button class="btn small primary" type="submit"><span class="material-symbols-rounded icon-sm">play_arrow</span>สำรองเดี๋ยวนี้</button>
    </form>
  </div>

  <form method="post" action="" class="mb-2">
    <?= csrf_field() ?>
    <input type="hidden" name="save_autobackup" value="1">
    <div class="form-row">
      <div>
        <label>ความถี่</label>
        <?php $mode = setting('backup_auto', 'weekly'); ?>
        <select name="backup_auto" class="mb-2">
          <option value="weekly" <?= $mode === 'weekly' ? 'selected' : '' ?>>ทุกสัปดาห์ (แนะนำ)</option>
          <option value="daily"  <?= $mode === 'daily'  ? 'selected' : '' ?>>ทุกวัน</option>
          <option value="off"    <?= $mode === 'off'    ? 'selected' : '' ?>>ปิดการสำรองอัตโนมัติ</option>
        </select>
      </div>
      <div>
        <label>เก็บย้อนหลังกี่ชุด</label>
        <input type="number" name="backup_keep" value="<?= (int)setting('backup_keep', '5') ?>" min="1" max="30" class="mb-2">
      </div>
    </div>
    <button class="btn primary" type="submit"><span class="material-symbols-rounded icon-sm">save</span>บันทึกการตั้งค่า</button>
    <span class="text-muted" style="font-size:12.5px;margin-left:10px;">
      สำรองล่าสุด: <?= setting('backup_last') ? e(thai_date(date('Y-m-d H:i:s', strtotime(setting('backup_last'))))) : 'ยังไม่เคย' ?>
    </span>
  </form>

  <?php $autos = ab_list(); if ($autos): ?>
  <table class="admin-table">
    <tr><th>ชุดสำรอง</th><th style="width:160px;">เมื่อ</th><th style="width:110px;">ขนาด</th><th style="width:170px;"></th></tr>
    <?php foreach ($autos as $a): ?>
    <tr>
      <td><?= e($a['name']) ?></td>
      <td class="lr-date" style="white-space:nowrap;"><?= e(thai_date(date('Y-m-d H:i:s', $a['time']))) ?></td>
      <td class="lr-date"><?= e(format_bytes($a['size'])) ?></td>
      <td style="white-space:nowrap;">
        <a class="btn small" href="<?= e(url('admin/backup.php?action=get-auto&name=' . urlencode($a['name']))) ?>">ดาวน์โหลด</a>
        <form method="post" action="" style="display:inline;">
          <?= csrf_field() ?>
          <input type="hidden" name="del_autobackup" value="<?= e($a['name']) ?>">
          <button class="btn small danger" type="submit" data-confirm="ลบชุดสำรอง <?= e($a['name']) ?>?">ลบ</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php else: ?>
  <p class="text-muted text-center" style="padding:20px;">ยังไม่มีชุดสำรองอัตโนมัติ — จะถูกสร้างเมื่อถึงรอบ หรือกด "สำรองเดี๋ยวนี้"</p>
  <?php endif; ?>
</div>

<!-- ชุดสำรองอัตโนมัติที่ระบบสร้างไว้ก่อนอัปเดต/โอนย้าย — กู้คืนได้จากหน้านี้เลย -->
<div class="card mb-2">
  <div class="section-head" style="margin-bottom:8px;">
    <div><span class="tag"><span class="material-symbols-rounded icon-sm">history</span> RESTORE</span><h3>กู้คืนจากชุดสำรองอัตโนมัติ</h3>
    <p>ระบบสำรองข้อมูลให้อัตโนมัติทุกครั้ง<b>ก่อนอัปเดตและก่อนโอนย้ายเว็บ</b> — ถ้าอัปเดตแล้วมีปัญหา กู้กลับได้จากที่นี่</p></div>
  </div>

  <?php $bks = up_backups(); if ($bks): ?>
  <div class="alert danger" style="font-size:13px;"><span class="material-symbols-rounded">warning</span>
    <div>การกู้คืนจะ<b>เขียนทับข้อมูลปัจจุบันทั้งหมด</b> — ควรดาวน์โหลดสำรองของตอนนี้เก็บไว้ก่อน</div></div>
  <table class="admin-table">
    <tr><th>ชุดสำรอง</th><th style="width:150px;">เมื่อ</th><th style="width:110px;">ขนาด</th><th style="width:330px;">กู้คืน</th></tr>
    <?php foreach ($bks as $b): ?>
    <tr>
      <td><b style="color:var(--ink);"><?= e($b['name']) ?></b></td>
      <td class="lr-date" style="white-space:nowrap;"><?= e(thai_date(date('Y-m-d H:i:s', $b['time']))) ?></td>
      <td class="lr-date"><?= e(format_bytes($b['size'])) ?></td>
      <td>
        <form method="post" action="" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
          <?= csrf_field() ?>
          <input type="hidden" name="restore_backup" value="<?= e($b['name']) ?>">
          <label style="display:flex;align-items:center;gap:4px;font-size:12px;margin:0;">
            <input type="checkbox" name="confirm_restore" value="1"> ยืนยัน
          </label>
          <button class="btn small danger" type="submit" name="restore_what" value="db"
                  data-confirm="กู้คืนฐานข้อมูลจาก <?= e($b['name']) ?>? ข้อมูลปัจจุบันทั้งหมดจะถูกเขียนทับ">ฐานข้อมูล</button>
          <button class="btn small" type="submit" name="restore_what" value="files"
                  data-confirm="กู้คืนไฟล์ระบบจาก <?= e($b['name']) ?>? ไฟล์โปรแกรมจะถูกเขียนทับกลับเป็นเวอร์ชันนั้น">ไฟล์ระบบ</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php else: ?>
  <p class="text-muted text-center" style="padding:26px;">ยังไม่มีชุดสำรองอัตโนมัติ — จะถูกสร้างขึ้นเองเมื่อคุณอัปเดตระบบหรือโอนย้ายเว็บ</p>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/_bottom.php'; ?>
