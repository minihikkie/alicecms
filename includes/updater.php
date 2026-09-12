<?php
/**
 * updater.php — ระบบอัปเดตฟีเจอร์ใหม่ผ่านหน้า admin
 *
 * ขั้นตอนตอนอัปเดต:
 *   preflight → ดาวน์โหลดแพ็กเกจ → ตรวจ SHA-256 → สำรอง (ไฟล์ .zip + ฐานข้อมูล .sql)
 *   → แตกไฟล์ → คัดลอกทับ (กัน config.php / uploads / storage / install.lock)
 *   → รัน migrate อัตโนมัติ → บันทึกเวอร์ชัน → ถ้าพังคืนค่าไฟล์เดิม (rollback)
 *
 * ต้องเรียกภายใต้ init.php (มี db(), setting(), log_action())
 */
if (!defined('APP_ROOT')) exit('Forbidden');

/* ───────── กุญแจสาธารณะสำหรับตรวจลายเซ็นแพ็กเกจ ─────────
   ทุกแพ็กเกจต้องถูกเซ็นด้วยกุญแจลับของผู้พัฒนา (tools/release-key/private.pem)
   ระบบจะติดตั้งเฉพาะแพ็กเกจที่ลายเซ็นผ่านเท่านั้น — ป้องกันการถูกสวมรอยระหว่างทาง
   แม้ดาวน์โหลดผ่าน http หรือแม้เซิร์ฟเวอร์อัปเดตถูกยึด */
const UPDATE_PUBLIC_KEY = <<<'PEM'
-----BEGIN PUBLIC KEY-----
MIIBojANBgkqhkiG9w0BAQEFAAOCAY8AMIIBigKCAYEA1ZJm6e4EkuD2IFMb0XrQ
74HEMNQ1+XxH5XdKEqBhFr6vRQ3RHHKWZD7x5LFoOLD0Brhan92fUf0PBkb0vuMv
TQWk8LS98Dbu0X8dWqwmOr4rOtxL/vtVCaZENl3LZSap+8qkDrHQDKlkpQ9sDbzG
d8KNbf991tKKZioU1soM3LUSo6v/rimtyDpxQVad8AztciR0n3NYaSCJIR5PsmQs
IFbPOUXPCWOAkUm1S6kacZXFE3QnsjSuasIpr0y1LcEpkrweTbVOH8hQh0fZLHNb
iY7ezSl0ezE4TNDD4QbTikcO8VRJVL4vKlNuCRdYGCBoYyztg6Ul51CSsdzkrlsH
yJ6p6maaxjV0p/uMwpRPTXB6OvX35Vk4IqyBkeMgSjYrnHLvCwGVgqYFdCLqZVSf
hDzZWVowL3HKPSmnMaF6e1pJxQ16U1rVu7dUHOrMf7cE6UDu68a9ydt0SGwEQpkh
RI7erYVLZ0nyb1pqY+E76hqw50pPpNjG0oWHLr3FOw+nAgMBAAE=
-----END PUBLIC KEY-----
PEM;

/** ตรวจลายเซ็นของแพ็กเกจ — คืน true เมื่อลายเซ็นถูกต้องเท่านั้น */
function up_verify_signature(array $m, ?string &$err = null): bool {
    if (!function_exists('openssl_verify')) {
        $err = 'เซิร์ฟเวอร์ไม่มีส่วนขยาย OpenSSL จึงตรวจลายเซ็นแพ็กเกจไม่ได้';
        return false;
    }
    $sig = base64_decode((string)($m['signature'] ?? ''), true);
    if ($sig === false || $sig === '') {
        $err = 'แพ็กเกจนี้ไม่มีลายเซ็นดิจิทัล — ปฏิเสธการติดตั้งเพื่อความปลอดภัย';
        return false;
    }
    $payload = $m['version'] . "\n" . $m['sha256'] . "\n" . $m['size'];
    $ok = openssl_verify($payload, $sig, UPDATE_PUBLIC_KEY, OPENSSL_ALGO_SHA256);
    if ($ok !== 1) {
        $err = 'ลายเซ็นดิจิทัลไม่ถูกต้อง — แพ็กเกจอาจถูกแก้ไขระหว่างทาง ปฏิเสธการติดตั้ง';
        return false;
    }
    return true;
}

/* ───────── ค่าพื้นฐาน ───────── */

function up_manifest_url(): string {
    $u = setting('update_url', '');
    return $u !== '' ? $u : (defined('UPDATE_MANIFEST_URL') ? UPDATE_MANIFEST_URL : '');
}

function up_current_version(): string {
    return defined('APP_VERSION') ? APP_VERSION : '0.0.0';
}

/** โฟลเดอร์เก็บไฟล์ชั่วคราว/สำรองของระบบอัปเดต */
function up_storage(): string {
    $d = APP_ROOT . '/storage/updates';
    if (!is_dir($d)) @mkdir($d, 0775, true);
    return $d;
}

/* ───────── HTTP ───────── */

/** ดึงข้อมูลจาก URL (คืน body หรือ false พร้อม $err) */
function up_http_get(string $url, ?string $save_to = null, ?string &$err = null) {
    if (!preg_match('#^https?://#i', $url)) { $err = 'URL ไม่ถูกต้อง'; return false; }
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $fh = null;
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_USERAGENT      => 'GovCMS-Updater/' . up_current_version(),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        if ($save_to) {
            $fh = fopen($save_to, 'wb');
            if (!$fh) { $err = 'เขียนไฟล์ชั่วคราวไม่ได้'; curl_close($ch); return false; }
            curl_setopt($ch, CURLOPT_FILE, $fh);
        } else {
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        }
        $res  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);
        if ($fh) fclose($fh);
        if ($res === false) { $err = 'เชื่อมต่อไม่ได้: ' . $cerr; return false; }
        if ($code < 200 || $code >= 300) { $err = 'เซิร์ฟเวอร์ตอบกลับ HTTP ' . $code; return false; }
        return $save_to ? true : $res;
    }
    /* fallback */
    $ctx = stream_context_create(['http' => ['timeout' => 120, 'user_agent' => 'GovCMS-Updater']]);
    $data = @file_get_contents($url, false, $ctx);
    if ($data === false) { $err = 'ดาวน์โหลดไม่ได้ (file_get_contents)'; return false; }
    if ($save_to) return file_put_contents($save_to, $data) !== false;
    return $data;
}

/* ───────── Manifest ───────── */

/** แปลง JSON manifest ดิบ → array มาตรฐาน — ใช้ร่วมกันทั้งดึงจาก URL (up_fetch_manifest)
 *  และไฟล์ update.json ที่ผู้ดูแลอัปโหลดเอง (กรณีเซิร์ฟเวอร์ต่อออกอินเทอร์เน็ตไม่ได้) */
function up_parse_manifest(string $raw, ?string &$err = null): ?array {
    $m = json_decode($raw, true);
    if (!is_array($m) || empty($m['version']) || empty($m['package_url'])) {
        $err = 'ข้อมูลอัปเดตไม่ถูกต้อง (manifest)';
        return null;
    }
    return [
        'version'     => (string)$m['version'],
        'released'    => (string)($m['released'] ?? ''),
        'min_php'     => (string)($m['min_php'] ?? '8.0.0'),
        'package_url' => (string)$m['package_url'],
        'sha256'      => strtolower((string)($m['sha256'] ?? '')),
        'size'        => (int)($m['size'] ?? 0),
        'signature'   => (string)($m['signature'] ?? ''),
        'notes'       => array_values(array_filter(array_map('strval', (array)($m['notes'] ?? [])))),
    ];
}

/** ดึง + ตรวจ manifest จากเซิร์ฟเวอร์อัปเดต — คืน array หรือ null */
function up_fetch_manifest(?string &$err = null): ?array {
    $url = up_manifest_url();
    if ($url === '') { $err = 'ยังไม่ได้ตั้งค่า URL เซิร์ฟเวอร์อัปเดต'; return null; }
    $raw = up_http_get($url, null, $err);
    if ($raw === false) return null;
    return up_parse_manifest((string)$raw, $err);
}

/** ตรวจว่ามีอัปเดตใหม่ไหม */
function up_check(?string &$err = null): array {
    $cur = up_current_version();
    $m = up_fetch_manifest($err);
    if (!$m) return ['ok' => false, 'current' => $cur, 'manifest' => null, 'available' => false];
    return [
        'ok'        => true,
        'current'   => $cur,
        'latest'    => $m['version'],
        'available' => version_compare($m['version'], $cur, '>'),
        'manifest'  => $m,
    ];
}

/* ───────── เครื่องมือไฟล์ ───────── */

function up_rrmdir(string $dir): void {
    if (!is_dir($dir)) return;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
    @rmdir($dir);
}

/** path (เทียบจาก APP_ROOT) ที่ต้อง "กันไว้" ไม่ให้อัปเดตทับ/ไม่เอาเข้าสำรอง */
function up_is_protected(string $rel): bool {
    $rel = ltrim(str_replace('\\', '/', $rel), '/');
    if ($rel === 'config.php' || $rel === 'install.lock') return true;
    foreach (['uploads/', 'storage/', 'backups/', '.git/', 'tools/'] as $p) {
        if (strpos($rel, $p) === 0) return true;
    }
    return false;
}

/** ทำสำเนาโค้ดปัจจุบันเป็น .zip (ไม่รวม uploads/storage/.git เพื่อให้เล็ก) */
function up_backup_files(string $zip_path, ?string &$err = null): bool {
    if (!class_exists('ZipArchive')) { $err = 'เซิร์ฟเวอร์ไม่มี ZipArchive'; return false; }
    $zip = new ZipArchive();
    if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        $err = 'สร้างไฟล์สำรองไม่ได้'; return false;
    }
    $base = realpath(APP_ROOT);
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $f) {
        if (!$f->isFile()) continue;
        $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($base) + 1));
        if (up_is_protected($rel)) continue;   /* เก็บ config.php ไว้ในสำรองด้วย? */
        $zip->addFile($f->getPathname(), $rel);
    }
    /* เก็บ config.php ไว้ในสำรองด้วย เผื่อกู้ทั้งชุด */
    if (is_file(APP_ROOT . '/config.php')) $zip->addFile(APP_ROOT . '/config.php', 'config.php');
    $zip->close();
    return true;
}

/** สำรองฐานข้อมูลเป็น .sql (PHP ล้วน — เหมือน backup.php) */
function up_backup_db(string $sql_path, ?string &$err = null): bool {
    try {
        $pdo = db();
        $fh = fopen($sql_path, 'wb');
        if (!$fh) { $err = 'เขียนไฟล์ .sql ไม่ได้'; return false; }
        fwrite($fh, "-- สำรองก่อนอัปเดต " . DB_NAME . " เมื่อ " . date('c') . "\n");
        fwrite($fh, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");
        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tables as $t) {
            $create = $pdo->query('SHOW CREATE TABLE `' . $t . '`')->fetch(PDO::FETCH_ASSOC);
            fwrite($fh, "DROP TABLE IF EXISTS `$t`;\n");
            fwrite($fh, ($create['Create Table'] ?? $create['Create View'] ?? '') . ";\n\n");
            $rows = $pdo->query('SELECT * FROM `' . $t . '`');
            foreach ($rows as $row) {
                $cols = '`' . implode('`,`', array_keys($row)) . '`';
                $vals = array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v), array_values($row));
                fwrite($fh, "INSERT INTO `$t` ($cols) VALUES (" . implode(',', $vals) . ");\n");
            }
            fwrite($fh, "\n");
        }
        fwrite($fh, "SET FOREIGN_KEY_CHECKS=1;\n");
        fclose($fh);
        return true;
    } catch (Throwable $e) {
        $err = 'สำรองฐานข้อมูลล้มเหลว: ' . $e->getMessage();
        return false;
    }
}

/** คัดลอกไฟล์จาก staging ทับ live (ข้ามไฟล์ที่กันไว้) — คืนจำนวนไฟล์ที่เขียน */
function up_apply_files(string $staging, ?string &$err = null): int {
    $base = realpath($staging);
    if (!$base) { $err = 'ไม่พบโฟลเดอร์ staging'; return -1; }
    $count = 0;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $f) {
        if (!$f->isFile()) continue;
        $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($base) + 1));
        if (up_is_protected($rel)) continue;
        $dest = APP_ROOT . '/' . $rel;
        $dir  = dirname($dest);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) { $err = 'สร้างโฟลเดอร์ไม่ได้: ' . $rel; return -1; }
        if (!@copy($f->getPathname(), $dest)) { $err = 'เขียนไฟล์ไม่ได้: ' . $rel; return -1; }
        $count++;
    }
    return $count;
}

/** หา root จริงใน staging (เผื่อ zip มีโฟลเดอร์ครอบชั้นเดียว) */
function up_staging_root(string $dir): string {
    if (is_file($dir . '/version.php') || is_file($dir . '/index.php')) return $dir;
    $items = array_values(array_diff(scandir($dir) ?: [], ['.', '..']));
    if (count($items) === 1 && is_dir($dir . '/' . $items[0])) {
        $sub = $dir . '/' . $items[0];
        if (is_file($sub . '/version.php') || is_file($sub . '/index.php')) return $sub;
    }
    return $dir;
}

/* ───────── ขั้นตอนหลัก ───────── */

/** ทดสอบเขียนจริงในโฟลเดอร์ (is_writable เชื่อถือไม่ได้บน Windows) */
function up_dir_writable(string $dir): bool {
    if (!is_dir($dir)) return false;
    $probe = $dir . '/.wtest-' . substr(md5((string)mt_rand()), 0, 8);
    if (@file_put_contents($probe, 'x') === false) return false;
    @unlink($probe);
    return true;
}

/** ตรวจความพร้อมก่อนอัปเดต — คืน array ของปัญหา (ว่าง = พร้อม) */
function up_preflight(array $m): array {
    $errs = [];
    if (!class_exists('ZipArchive'))                 $errs[] = 'เซิร์ฟเวอร์ไม่มีส่วนขยาย ZipArchive (จำเป็นต่อการแตกไฟล์)';
    if (!up_dir_writable(APP_ROOT))                  $errs[] = 'โฟลเดอร์ระบบเขียนไม่ได้ (ต้องมีสิทธิ์เขียนทับไฟล์)';
    if (!empty($m['min_php']) && version_compare(PHP_VERSION, $m['min_php'], '<'))
        $errs[] = 'ต้องใช้ PHP ' . $m['min_php'] . ' ขึ้นไป (ปัจจุบัน ' . PHP_VERSION . ')';
    if (!up_dir_writable(up_storage()))              $errs[] = 'โฟลเดอร์ storage/updates เขียนไม่ได้';
    return $errs;
}

/**
 * ดำเนินการอัปเดตเต็มขั้นตอน
 * $localPackagePath: ถ้าระบุ = ใช้ไฟล์ .zip นี้แทนการดาวน์โหลด (กรณีผู้ดูแลอัปโหลดไฟล์เอง
 * เพราะเซิร์ฟเวอร์ต่อออกอินเทอร์เน็ตไม่ได้) — ขั้นตอนตรวจ SHA-256/ลายเซ็นหลังจากนี้ยังทำงานเหมือนเดิมทุกประการ
 * คืน ['ok'=>bool, 'log'=>string[], 'error'=>string, 'backup'=>string]
 */
function up_perform(array $m, ?callable $progress = null, ?string $localPackagePath = null): array {
    $log = [];
    $add = function ($msg) use (&$log, $progress) { $log[] = $msg; if ($progress) $progress($msg); };
    $store = up_storage();
    $stamp = date('Ymd-His');
    $from  = up_current_version();
    $to    = $m['version'];
    $work  = $store . '/work-' . $stamp;
    $stage = $work . '/stage';
    $pkg   = $work . '/package.zip';
    $bakdir = $store . '/backup-' . $from . '-' . $stamp;

    /* ล้มก่อนติดตั้งไฟล์จริง = ระบบยังไม่ถูกแตะเลย ชุดสำรองที่เพิ่งสร้างจึงไม่มีประโยชน์
       ต้องลบทิ้งด้วย ไม่งั้น "กดอัปเดตซ้ำ" แต่ละครั้งจะทิ้งสำรองค้างไว้กินพื้นที่เพิ่มเรื่อยๆ
       จนดิสก์เต็ม แล้วลามไปทำให้เขียนไฟล์เซสชันไม่ได้ = ล็อกอินหลังบ้านไม่ได้ (เจอมาแล้วจริง) */
    $fail = function ($err) use (&$log, $work, $bakdir) {
        up_rrmdir($work);
        up_rrmdir($bakdir);
        return ['ok' => false, 'log' => $log, 'error' => $err, 'backup' => ''];
    };
    /* ใช้หลังเริ่มติดตั้งไฟล์แล้ว — ต้องเก็บสำรองไว้ให้กู้คืน */
    $failKeepBackup = function ($err) use (&$log, $work, $bakdir) {
        up_rrmdir($work);
        return ['ok' => false, 'log' => $log, 'error' => $err, 'backup' => basename($bakdir)];
    };

    /* 0) preflight */
    $pf = up_preflight($m);
    if ($pf) return $fail(implode(' • ', $pf));

    /* 0.1) เก็บกวาดสำรองเก่า "ก่อน" สร้างชุดใหม่ — เดิมทำหลังอัปเดตสำเร็จเท่านั้น
       ซึ่งช่วยอะไรไม่ได้เลยเมื่อพื้นที่ใกล้เต็ม เพราะจะล้มระหว่างทางก่อนถึงขั้นเก็บกวาดทุกครั้ง */
    try {
        require_once APP_ROOT . '/includes/autobackup.php';
        $pruned = ab_rotate_update_backups(3);
        if ($pruned > 0) $add("ลบชุดสำรองเก่า $pruned ชุด เพื่อเตรียมพื้นที่");
    } catch (Throwable $e) { /* เก็บกวาดไม่ได้ ไม่ใช่เหตุให้หยุดอัปเดต */ }

    @mkdir($stage, 0775, true);
    @mkdir($bakdir, 0775, true);

    /* 1) ดาวน์โหลดแพ็กเกจ (หรือใช้ไฟล์ที่ผู้ดูแลอัปโหลดเองถ้ามี) */
    if ($localPackagePath !== null) {
        $add('กำลังใช้ไฟล์แพ็กเกจที่อัปโหลดเอง...');
        if (!is_file($localPackagePath) || !@copy($localPackagePath, $pkg)) {
            return $fail('อ่านไฟล์แพ็กเกจที่อัปโหลดไว้ไม่สำเร็จ — กรุณาอัปโหลดใหม่');
        }
        $add('เตรียมไฟล์แพ็กเกจสำเร็จ (' . format_bytes((int)@filesize($pkg)) . ')');
    } else {
        $add("กำลังดาวน์โหลดแพ็กเกจ v$to ...");
        $derr = null;
        if (!up_http_get($m['package_url'], $pkg, $derr)) return $fail('ดาวน์โหลดแพ็กเกจไม่สำเร็จ — ' . $derr);
        $add('ดาวน์โหลดสำเร็จ (' . format_bytes((int)@filesize($pkg)) . ')');
    }

    /* 2) ตรวจ checksum — ต้องมีเสมอ ไม่มีถือว่าไม่ปลอดภัย */
    if (empty($m['sha256'])) return $fail('แพ็กเกจไม่มีค่า SHA-256 — ปฏิเสธการติดตั้งเพื่อความปลอดภัย');
    $got = strtolower(hash_file('sha256', $pkg));
    if ($got !== $m['sha256']) return $fail('SHA-256 ไม่ตรง — แพ็กเกจอาจเสียหายหรือถูกแก้ไข (got ' . substr($got, 0, 12) . '…)');
    if ((int)$m['size'] > 0 && (int)@filesize($pkg) !== (int)$m['size']) {
        return $fail('ขนาดไฟล์ไม่ตรงกับที่ประกาศไว้ — ปฏิเสธการติดตั้ง');
    }
    $add('ตรวจสอบ SHA-256 ผ่าน ✓');

    /* 2.1) ตรวจลายเซ็นดิจิทัล — ด่านสำคัญที่สุด กันการถูกสวมรอยระหว่างทาง (MITM) */
    $sigErr = null;
    if (!up_verify_signature($m, $sigErr)) return $fail($sigErr);
    $add('ตรวจสอบลายเซ็นดิจิทัลผ่าน ✓');

    /* 3) สำรองก่อนอัปเดต */
    $add('กำลังสำรองฐานข้อมูล...');
    $berr = null;
    if (!up_backup_db($bakdir . '/db.sql', $berr)) return $fail($berr);
    $add('กำลังสำรองไฟล์ระบบ...');
    if (!up_backup_files($bakdir . '/files.zip', $berr)) return $fail($berr);
    $add('สำรองเรียบร้อย: storage/updates/' . basename($bakdir));

    /* 4) แตกไฟล์ */
    $zip = new ZipArchive();
    if ($zip->open($pkg) !== true) return $fail('เปิดไฟล์แพ็กเกจไม่ได้');
    if (!$zip->extractTo($stage)) {
        $zip->close();
        /* สาเหตุที่พบบ่อยที่สุดคือพื้นที่ดิสก์หมดพอดีตอนสำรองเสร็จ ไม่ใช่ไฟล์แพ็กเกจเสีย
           (SHA-256 กับลายเซ็นผ่านมาแล้วทั้งคู่ แปลว่าไฟล์สมบูรณ์แน่นอน) — บอกให้ตรงจุดจะได้ไม่ไล่ผิดทาง */
        return $fail('แตกไฟล์ไม่สำเร็จ — ไฟล์แพ็กเกจผ่านการตรวจสอบแล้วจึงไม่ได้เสียหาย '
                   . 'สาเหตุที่พบบ่อยคือพื้นที่ดิสก์เต็ม กรุณาลบชุดสำรองเก่าใน storage/updates/ แล้วลองใหม่');
    }
    $zip->close();
    $root = up_staging_root($stage);
    if (!is_file($root . '/version.php')) return $fail('แพ็กเกจไม่สมบูรณ์ (ไม่พบ version.php)');
    $add('แตกไฟล์สำเร็จ');

    /* 5) คัดลอกทับ */
    $add('กำลังติดตั้งไฟล์ใหม่...');
    $aerr = null;
    $n = up_apply_files($root, $aerr);
    if ($n < 0) {
        /* rollback ไฟล์ */
        $add('เกิดข้อผิดพลาด — กำลังคืนค่าไฟล์เดิม (rollback)...');
        up_restore_files($bakdir . '/files.zip');
        /* ถึงตรงนี้ไฟล์ระบบถูกแตะไปแล้ว ต้องเก็บชุดสำรองไว้ให้กู้คืนด้วยมือได้
           ถ้า rollback อัตโนมัติทำได้ไม่ครบ (เช่น ดิสก์เต็มระหว่างคืนค่า) */
        return $failKeepBackup('ติดตั้งไฟล์ล้มเหลว: ' . $aerr . ' (คืนค่าไฟล์เดิมแล้ว '
                             . 'ชุดสำรองอยู่ที่ storage/updates/' . basename($bakdir) . ')');
    }
    $add("ติดตั้งไฟล์ใหม่ $n รายการ");

    /* 5.1) ซ่อมไฟล์ป้องกัน .htaccess ที่อาจขาด (uploads/storage/backups ไม่ได้มากับแพ็กเกจ) */
    try {
        require_once APP_ROOT . '/includes/hardening.php';
        $hard = ensure_hardening_files(APP_ROOT);
        if (!empty($hard['created'])) $add('สร้างไฟล์ความปลอดภัยที่ขาด: ' . implode(', ', $hard['created']));
        if (!empty($hard['failed']))  $add('⚠ สร้างไฟล์ความปลอดภัยไม่ได้: ' . implode(', ', $hard['failed']));

        /* ทดสอบทันทีว่า uploads/.htaccess ที่เพิ่งสร้าง/มีอยู่ ไม่ทำให้ไฟล์เปิดไม่ได้
           (บางเซิร์ฟเวอร์ปฏิเสธคำสั่งบางอย่างจน 500 ทั้งโฟลเดอร์ — เจอจริงกับโฮสต์บางแห่ง)
           ตรวจทุกครั้งที่อัปเดต ไม่ใช่แค่ตอนสร้างใหม่ เผื่อไฟล์เดิมค้างมาแบบเสีย */
        $selfOk = self_test_uploads_access(APP_ROOT, rtrim(abs_url(''), '/'));
        if ($selfOk === false) {
            $add('⚠ เซิร์ฟเวอร์นี้ไม่รองรับไฟล์ป้องกันในโฟลเดอร์ uploads/ (ทดสอบแล้วเปิดไฟล์ไม่ได้) '
               . '— ปิดการใช้งานไฟล์นี้ให้อัตโนมัติ การอัปโหลดยังปลอดภัยตามปกติ');
        }

        /* ทดสอบ URL แบบซ่อน .php แล้วเปิด/ปิดให้อัตโนมัติ
           ต้องทำ "หลัง" คัดลอกไฟล์ใหม่เสร็จ เพราะ .htaccess ตัวใหม่เพิ่งถูกเขียนลงไป */
        $prettyMsg = apply_pretty_urls(APP_ROOT, rtrim(abs_url(''), '/'));
        if ($prettyMsg !== '') $add($prettyMsg);
    } catch (Throwable $e) {
        $add('⚠ ตรวจไฟล์ความปลอดภัยไม่สำเร็จ: ' . $e->getMessage());
    }

    /* 6) migrate ฐานข้อมูล (ใช้ไฟล์เวอร์ชันใหม่ที่เพิ่งติดตั้ง) */
    $add('กำลังอัปเดตโครงสร้างฐานข้อมูล...');
    $migrate_error = '';
    try {
        require APP_ROOT . '/includes/migrations.php';
        run_migrations(db(), function ($mm) use ($add) { $add('  ' . $mm); });
        $add('อัปเดตฐานข้อมูลเสร็จ');
    } catch (Throwable $e) {
        $migrate_error = $e->getMessage();
        $add('✗ อัปเดตโครงสร้างฐานข้อมูลล้มเหลว: ' . $migrate_error);
    }

    /* 7) บันทึกเวอร์ชัน + log */
    setting_set('installed_version', $to);
    setting_set('last_update_at', date('c'));

    /* ถ้า migrate ล้ม ต้องไม่รายงานว่าสำเร็จ — เว็บอาจใช้งานผิดปกติจนกว่าจะแก้ */
    if ($migrate_error !== '') {
        setting_set('migrate_failed_at', date('c'));
        log_action('อัปเดตระบบ (ฐานข้อมูลล้มเหลว)', "v$from → v$to: $migrate_error");
        $add('ไฟล์ระบบติดตั้งครบแล้ว แต่โครงสร้างฐานข้อมูลยังไม่สมบูรณ์');
        $add('วิธีแก้: กด "อัปเดตอีกครั้ง" หรือกู้คืนจากสำรอง ' . basename($bakdir) . ' ที่หน้าสำรองข้อมูล');
        up_rrmdir($work);
        return [
            'ok'      => false,
            'log'     => $log,
            'error'   => 'ติดตั้งไฟล์สำเร็จ แต่ปรับโครงสร้างฐานข้อมูลไม่สำเร็จ: ' . $migrate_error
                       . ' — ข้อมูลเดิมสำรองไว้ที่ ' . basename($bakdir),
            'backup'  => basename($bakdir),
            'partial' => true,
        ];
    }

    setting_set('migrate_failed_at', '');
    log_action('อัปเดตระบบ', "v$from → v$to");
    $add("อัปเดตเป็นเวอร์ชัน $to สำเร็จ 🎉");

    /* 8) ลบชุดสำรองเก่าที่เกินกำหนด — ทุกครั้งที่อัปเดตจะสร้าง backup ใหม่ (db.sql + files.zip)
       เดิมมีแต่ ab_rotate_update_backups() ใน tools/cron-backup.php ซึ่งต้องตั้ง cron เอง
       ถ้าไม่ได้ตั้ง (ซึ่งเป็นค่าเริ่มต้นของโฮสต์ทั่วไป) สำรองจะสะสมไปเรื่อยๆ จนพื้นที่เต็ม
       — พอดิสก์เต็ม เขียนไฟล์เซสชันไม่ได้ (ล็อกอินไม่ได้) และอัปโหลดไฟล์ได้ 0 ไบต์ (เว็บล่ม)
       จึงต้องเก็บกวาดตรงนี้ด้วย ไม่พึ่ง cron อย่างเดียว */
    try {
        require_once APP_ROOT . '/includes/autobackup.php';
        $pruned = ab_rotate_update_backups(3);
        if ($pruned > 0) $add("ลบชุดสำรองเก่า $pruned ชุด (เก็บ 3 ชุดล่าสุด) เพื่อไม่ให้พื้นที่เต็ม");
    } catch (Throwable $e) { /* เก็บกวาดไม่สำเร็จ ไม่ควรทำให้การอัปเดตที่สำเร็จแล้วกลายเป็นล้มเหลว */ }

    up_rrmdir($work);
    return ['ok' => true, 'log' => $log, 'error' => '', 'backup' => basename($bakdir)];
}

/** คืนค่าไฟล์จากสำรอง (ใช้ตอน rollback) */
function up_restore_files(string $zip_path): bool {
    if (!is_file($zip_path) || !class_exists('ZipArchive')) return false;
    $tmp = up_storage() . '/restore-' . date('His');
    @mkdir($tmp, 0775, true);
    $zip = new ZipArchive();
    if ($zip->open($zip_path) !== true) return false;
    $zip->extractTo($tmp);
    $zip->close();
    up_apply_files($tmp);
    up_rrmdir($tmp);
    return true;
}

/**
 * กู้คืนฐานข้อมูลจากไฟล์สำรอง .sql ที่ระบบสร้างไว้ก่อนอัปเดต/โอนย้าย
 * คืน [ok, ข้อความ] — เขียนทับฐานข้อมูลปัจจุบันทั้งหมด
 */
function up_restore_db_from(string $backupName, ?string &$err = null): bool {
    $dir = up_storage() . '/' . basename($backupName);
    $sql = $dir . '/db.sql';
    if (!is_file($sql)) { $err = 'ไม่พบไฟล์สำรองฐานข้อมูลในชุดนี้'; return false; }
    require_once APP_ROOT . '/includes/transfer.php';   /* ใช้ตัวรันคำสั่ง SQL ตัวเดียวกัน */
    try {
        transfer_run_sql(db(), $sql);
        return true;
    } catch (Throwable $e) {
        $err = 'กู้คืนฐานข้อมูลไม่สำเร็จ: ' . $e->getMessage();
        return false;
    }
}

/** กู้คืนไฟล์ระบบจากชุดสำรอง (files.zip) */
function up_restore_files_from(string $backupName, ?string &$err = null): bool {
    $zip = up_storage() . '/' . basename($backupName) . '/files.zip';
    if (!is_file($zip)) { $err = 'ไม่พบไฟล์สำรองของระบบในชุดนี้'; return false; }
    if (!up_restore_files($zip)) { $err = 'คืนค่าไฟล์ระบบไม่สำเร็จ'; return false; }
    return true;
}

/** รายการสำรองที่มีอยู่ (ใหม่สุดก่อน) */
function up_backups(): array {
    $store = up_storage();
    $out = [];
    foreach (glob($store . '/backup-*', GLOB_ONLYDIR) ?: [] as $d) {
        $out[] = [
            'name' => basename($d),
            'time' => @filemtime($d) ?: 0,
            'size' => (int)(@filesize($d . '/files.zip') ?: 0) + (int)(@filesize($d . '/db.sql') ?: 0),
        ];
    }
    usort($out, fn($a, $b) => $b['time'] <=> $a['time']);
    return $out;
}
