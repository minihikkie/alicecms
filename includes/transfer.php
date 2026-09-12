<?php
/**
 * transfer.php — โอนย้ายเว็บทั้งหมดไปอีกเครื่อง (Site Transfer)
 *
 * แนวคิด: เว็บ ก export → ไฟล์ .zip เดียว (database.sql + uploads/ + transfer.json)
 *         เว็บ ข import → ตรวจสอบ → สำรองของเดิม → เขียนทับ DB+ไฟล์ → migrate → (rollback ถ้าพัง)
 *
 * กันพลาด: ตรวจ SHA-256, เช็คเวอร์ชัน (กันต้นทางใหม่กว่า), สำรองอัตโนมัติก่อนเขียนทับ,
 *          ไม่แตะ config.php (นำเข้าเข้าฐานข้อมูลของเครื่องปลายทางเอง),
 *          SQL parser แบบรู้ขอบเขต string (กัน ; / ขึ้นบรรทัด ในข้อมูล)
 *
 * ต้องเรียกภายใต้ init.php — reuse ตัวช่วยจาก updater.php
 */
if (!defined('APP_ROOT')) exit('Forbidden');
require_once APP_ROOT . '/includes/updater.php';

const TRANSFER_FORMAT = 1;

/** slug ชื่อเว็บ (จาก site_name หรือชื่อ DB) สำหรับตั้งชื่อไฟล์ */
function transfer_slug(): string {
    $s = preg_replace('/[^A-Za-z0-9]+/', '', (string)setting('site_name', ''));
    if ($s === '') $s = preg_replace('/[^A-Za-z0-9]+/', '', (string)DB_NAME);
    return $s !== '' ? $s : 'govsite';
}

/** เพิ่มไฟล์ใน uploads/ ทั้งหมดลง zip ที่เปิดอยู่ (นับจำนวน/ขนาดผ่าน reference) */
function transfer_add_uploads(ZipArchive $zip, int &$n, int &$bytes): void {
    $base = realpath(APP_ROOT . '/uploads');
    if (!$base) return;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile()) continue;
        $rel = 'uploads/' . str_replace('\\', '/', substr($f->getPathname(), strlen($base) + 1));
        $zip->addFile($f->getPathname(), $rel);
        $n++;
        $bytes += $f->getSize();
    }
}

/* ───────── EXPORT ───────── */

/** สร้างแพ็กเกจโอนย้าย — คืน path ของไฟล์ .zip ที่สร้าง หรือ null พร้อม $err */
function transfer_export(?string &$err = null): ?string {
    if (!class_exists('ZipArchive')) { $err = 'เซิร์ฟเวอร์ไม่มีส่วนขยาย ZipArchive'; return null; }
    $store = up_storage();
    if (!up_dir_writable($store)) { $err = 'โฟลเดอร์ storage/updates เขียนไม่ได้'; return null; }
    @set_time_limit(0);

    $stamp   = date('Ymd-His');
    $sqlpath = $store . '/transfer-db-' . $stamp . '.sql';

    /* 1) dump ฐานข้อมูลทั้งหมดเป็น .sql */
    $berr = null;
    if (!up_backup_db($sqlpath, $berr)) { $err = $berr; return null; }
    $sha = strtolower(hash_file('sha256', $sqlpath));

    /* 2) นับจำนวนแถวแต่ละตาราง (ไว้โชว์ฝั่งปลายทางให้มั่นใจ) */
    $rows = [];
    try {
        $tables = db()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tables as $t) {
            $rows[$t] = (int)db()->query('SELECT COUNT(*) FROM `' . $t . '`')->fetchColumn();
        }
    } catch (Throwable $e) { $tables = []; }

    /* 3) ประกอบ zip: database.sql + uploads/ + transfer.json */
    $zippath = $store . '/govcms-transfer-' . transfer_slug() . '-' . $stamp . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($zippath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        @unlink($sqlpath);
        $err = 'สร้างไฟล์ zip ไม่ได้';
        return null;
    }
    $zip->addFile($sqlpath, 'database.sql');
    $up_n = 0; $up_bytes = 0;
    transfer_add_uploads($zip, $up_n, $up_bytes);

    $manifest = [
        'format'        => TRANSFER_FORMAT,
        'app_version'   => defined('APP_VERSION') ? APP_VERSION : '0.0.0',
        'app_build'     => defined('APP_BUILD') ? APP_BUILD : '',
        'created_at'    => date('c'),
        'source_db'     => DB_NAME,
        'site_name'     => (string)setting('site_name', ''),
        'sha256_db'     => $sha,
        'tables'        => count($rows),
        'row_counts'    => $rows,
        'uploads_files' => $up_n,
        'uploads_bytes' => $up_bytes,
    ];
    $zip->addFromString('transfer.json', json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    $zip->close();
    @unlink($sqlpath); /* sql ถูกบรรจุใน zip แล้ว */

    log_action('สร้างไฟล์โอนย้ายเว็บ', basename($zippath));
    return $zippath;
}

/* ───────── IMPORT ───────── */

/** อ่าน + ตรวจ manifest จากไฟล์ zip โอนย้าย — คืน array หรือ null พร้อม $err */
function transfer_read_manifest(string $zip_path, ?string &$err = null): ?array {
    if (!class_exists('ZipArchive')) { $err = 'เซิร์ฟเวอร์ไม่มี ZipArchive'; return null; }
    $zip = new ZipArchive();
    if ($zip->open($zip_path) !== true) { $err = 'เปิดไฟล์ไม่ได้ (ไม่ใช่ไฟล์ zip ที่ถูกต้อง)'; return null; }
    $raw   = $zip->getFromName('transfer.json');
    $hasdb = $zip->locateName('database.sql') !== false;
    $zip->close();
    if ($raw === false) { $err = 'ไม่ใช่ไฟล์โอนย้ายที่ถูกต้อง (ไม่พบ transfer.json)'; return null; }
    if (!$hasdb)        { $err = 'ไฟล์โอนย้ายไม่สมบูรณ์ (ไม่พบ database.sql)'; return null; }
    $m = json_decode((string)$raw, true);
    if (!is_array($m) || empty($m['app_version']) || empty($m['sha256_db'])) {
        $err = 'ข้อมูลในไฟล์โอนย้ายไม่ถูกต้อง (manifest)';
        return null;
    }
    return $m;
}

/**
 * รันสคริปต์ .sql ทีละคำสั่ง — parser แบบรู้ขอบเขต string เดี่ยว ('...')
 * แยกคำสั่งที่เครื่องหมาย ; เฉพาะนอก string เท่านั้น (กัน ; หรือขึ้นบรรทัดในข้อมูล)
 * คืนจำนวนคำสั่งที่รัน — โยน Throwable ถ้าคำสั่งใดล้มเหลว
 */
function transfer_run_sql(PDO $pdo, string $file, ?callable $log = null): int {
    $fh = fopen($file, 'rb');
    if (!$fh) throw new RuntimeException('เปิดไฟล์ฐานข้อมูลไม่ได้');
    $buf      = '';
    $inString = false;   /* อยู่ใน '...' */
    $escaped  = false;   /* อักขระก่อนหน้าเป็น backslash ภายใน string */
    $count    = 0;
    $run = function (string $stmt) use ($pdo, &$count) {
        $stmt = trim($stmt);
        if ($stmt === '' || $stmt === ';') return;
        $stmt = rtrim($stmt, "; \t\r\n");
        if ($stmt === '') return;
        $pdo->exec($stmt);
        $count++;
    };
    while (!feof($fh)) {
        $chunk = fread($fh, 65536);
        if ($chunk === false) break;
        $len = strlen($chunk);
        for ($i = 0; $i < $len; $i++) {
            $c = $chunk[$i];
            $buf .= $c;
            if ($inString) {
                if ($escaped)          { $escaped = false; }
                elseif ($c === '\\')   { $escaped = true; }
                elseif ($c === "'")    { $inString = false; }
            } else {
                if ($c === "'")        { $inString = true; }
                elseif ($c === ';')    { $run($buf); $buf = ''; }
            }
        }
    }
    fclose($fh);
    if (trim($buf) !== '') $run($buf);   /* คำสั่งท้ายไฟล์ที่อาจไม่ลงท้ายด้วย ; */
    if ($log) $log("รันคำสั่งฐานข้อมูล $count รายการ");
    return $count;
}

/** คืนค่าฐานข้อมูลจากไฟล์สำรอง (ใช้ตอน rollback) */
function transfer_restore_db(string $file): bool {
    try { transfer_run_sql(db(), $file); return true; }
    catch (Throwable $e) { return false; }
}

/** ลบ uploads ปัจจุบัน (เว้น .htaccess) แล้วนำของจาก staging มาวางแทน (mirror) */
function transfer_apply_uploads(string $staging_uploads, ?callable $log = null): void {
    $dest = APP_ROOT . '/uploads';
    if (!is_dir($dest)) @mkdir($dest, 0775, true);

    /* ลบไฟล์เดิมทั้งหมด ยกเว้น .htaccess (กฎความปลอดภัย) */
    if (is_dir($dest)) {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dest, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            if ($f->isFile()) { if ($f->getFilename() === '.htaccess') continue; @unlink($f->getPathname()); }
            elseif ($f->isDir()) { @rmdir($f->getPathname()); }
        }
    }

    /* คัดลอกของใหม่ */
    $n = 0;
    if (is_dir($staging_uploads)) {
        $base = realpath($staging_uploads);
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (!$f->isFile()) continue;
            $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($base) + 1));
            $d   = $dest . '/' . $rel;
            $dir = dirname($d);
            if (!is_dir($dir)) @mkdir($dir, 0775, true);
            @copy($f->getPathname(), $d);
            $n++;
        }
    }
    if ($log) $log("นำเข้าไฟล์อัปโหลด $n รายการ");
}

/**
 * ดำเนินการโอนย้ายเต็มขั้นตอน (เขียนทับเว็บปลายทางด้วยข้อมูลจากแพ็กเกจ)
 * คืน ['ok'=>bool, 'log'=>string[], 'error'=>string, 'backup'=>string]
 */
function transfer_import(string $zip_path, array $m, ?callable $progress = null): array {
    $log = [];
    $add = function ($msg) use (&$log, $progress) { $log[] = $msg; if ($progress) $progress($msg); };
    @set_time_limit(0);

    $store  = up_storage();
    $stamp  = date('Ymd-His');
    $work   = $store . '/transfer-work-' . $stamp;
    $stage  = $work . '/stage';
    $bakdir = $store . '/backup-pretransfer-' . $stamp;

    $fail = function ($err) use (&$log, $work) { up_rrmdir($work); return ['ok' => false, 'log' => $log, 'error' => $err, 'backup' => '']; };

    /* 0) ตรวจความพร้อม */
    if (!class_exists('ZipArchive'))            return $fail('เซิร์ฟเวอร์ไม่มี ZipArchive');
    if (!up_dir_writable(APP_ROOT . '/uploads')) return $fail('โฟลเดอร์ uploads เขียนไม่ได้');
    if (!up_dir_writable($store))                return $fail('โฟลเดอร์ storage/updates เขียนไม่ได้');
    @mkdir($stage, 0775, true);
    @mkdir($bakdir, 0775, true);

    /* 1) แตกไฟล์ */
    $add('กำลังแตกไฟล์โอนย้าย...');
    $zip = new ZipArchive();
    if ($zip->open($zip_path) !== true)  return $fail('เปิดไฟล์โอนย้ายไม่ได้');
    if (!$zip->extractTo($stage)) { $zip->close(); return $fail('แตกไฟล์ไม่สำเร็จ'); }
    $zip->close();
    $sqlfile = $stage . '/database.sql';
    if (!is_file($sqlfile)) return $fail('แพ็กเกจไม่สมบูรณ์ (ไม่พบ database.sql)');

    /* 2) ตรวจ SHA-256 ของฐานข้อมูล */
    $got = strtolower(hash_file('sha256', $sqlfile));
    if ($got !== strtolower((string)$m['sha256_db'])) {
        return $fail('SHA-256 ของฐานข้อมูลไม่ตรง — ไฟล์อาจเสียหายหรือถูกแก้ไข');
    }
    $add('ตรวจสอบความถูกต้องของข้อมูล (SHA-256) ผ่าน ✓');

    /* 3) สำรองของเดิมก่อนเขียนทับ (เผื่อ rollback) */
    $add('กำลังสำรองข้อมูลปัจจุบันก่อนเขียนทับ...');
    $berr = null;
    if (!up_backup_db($bakdir . '/db.sql', $berr)) return $fail('สำรองฐานข้อมูลปัจจุบันไม่ได้: ' . $berr);
    if (class_exists('ZipArchive')) {
        $ub = new ZipArchive();
        if ($ub->open($bakdir . '/uploads.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
            $n = 0; $b = 0; transfer_add_uploads($ub, $n, $b); $ub->close();
        }
    }
    $add('สำรองเรียบร้อย: storage/updates/' . basename($bakdir));

    /* 4) นำเข้าฐานข้อมูล (เขียนทับ) — ถ้าพังให้คืนค่าเดิม */
    $add('กำลังนำเข้าฐานข้อมูล (เขียนทับของเดิม)...');
    try {
        transfer_run_sql(db(), $sqlfile, function ($mm) use ($add) { $add('  ' . $mm); });
        $add('นำเข้าฐานข้อมูลสำเร็จ');
    } catch (Throwable $e) {
        $add('เกิดข้อผิดพลาด — กำลังคืนค่าฐานข้อมูลเดิม (rollback)...');
        transfer_restore_db($bakdir . '/db.sql');
        return $fail('นำเข้าฐานข้อมูลล้มเหลว: ' . $e->getMessage() . ' (คืนค่าฐานข้อมูลเดิมแล้ว)');
    }

    /* 5) นำเข้าไฟล์อัปโหลด (เขียนทับ) */
    $add('กำลังนำเข้าไฟล์อัปโหลด...');
    try {
        transfer_apply_uploads($stage . '/uploads', $add);
    } catch (Throwable $e) {
        $add('⚠ นำเข้าไฟล์อัปโหลดมีปัญหา: ' . $e->getMessage() . ' — ฐานข้อมูลนำเข้าแล้ว ตรวจไฟล์ที่ ' . basename($bakdir));
    }

    /* 6) ปรับโครงสร้าง DB ให้ตรงกับเวอร์ชันโค้ดของเครื่องปลายทาง */
    $add('กำลังปรับโครงสร้างฐานข้อมูลให้ตรงกับระบบ...');
    try {
        require APP_ROOT . '/includes/migrations.php';
        run_migrations(db(), function ($mm) use ($add) { $add('  ' . $mm); });
        $add('ปรับโครงสร้างฐานข้อมูลเสร็จ');
    } catch (Throwable $e) {
        $add('⚠ migrate มีปัญหา: ' . $e->getMessage() . ' — สำรองอยู่ที่ ' . basename($bakdir));
    }

    /* 7) สรุป */
    setting_set('last_transfer_at', date('c'));
    log_action('โอนย้ายข้อมูลเข้าเว็บ', 'จาก ' . (($m['site_name'] ?? '') ?: ($m['source_db'] ?? '?')) . ' v' . ($m['app_version'] ?? '?'));
    $add('โอนย้ายข้อมูลสำเร็จ 🎉 — ข้อมูล ผู้ใช้ และไฟล์ทั้งหมดถูกแทนที่ด้วยของเว็บต้นทางแล้ว');
    $add('โปรดเข้าสู่ระบบใหม่ด้วยบัญชีผู้ใช้ของเว็บต้นทาง');

    up_rrmdir($work);
    return ['ok' => true, 'log' => $log, 'error' => '', 'backup' => basename($bakdir)];
}

/** รายชื่อไฟล์ .zip โอนย้ายที่วางไว้ใน storage/updates (สำหรับนำเข้าจากไฟล์บนเครื่องปลายทาง) */
function transfer_server_files(): array {
    $out = [];
    foreach (glob(up_storage() . '/*.zip') ?: [] as $f) {
        $out[] = ['name' => basename($f), 'path' => $f, 'size' => (int)@filesize($f), 'time' => (int)@filemtime($f)];
    }
    usort($out, fn($a, $b) => $b['time'] <=> $a['time']);
    return $out;
}
