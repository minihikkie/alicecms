<?php
/**
 * autobackup.php — สำรองข้อมูลอัตโนมัติตามรอบเวลา + ลบชุดเก่าหมุนเวียน
 *
 * ทำงานได้โดยไม่ต้องตั้ง cron: ตรวจทุกครั้งที่ผู้ดูแลเปิดหน้าแอดมิน ถ้าถึงรอบแล้วจะสำรองให้เอง
 * (ถ้ามี cron ก็เรียก tools/cron-backup.php ได้ ซึ่งแม่นยำกว่าเพราะไม่ต้องรอคนเข้าเว็บ)
 *
 * ตั้งค่าที่ใช้ (ตาราง settings):
 *   backup_auto  = off | daily | weekly   (ค่าเริ่มต้น weekly)
 *   backup_keep  = จำนวนชุดที่เก็บไว้      (ค่าเริ่มต้น 5)
 *   backup_last  = เวลาสำรองล่าสุด (ISO)
 */
if (!defined('APP_ROOT')) exit('Forbidden');
require_once APP_ROOT . '/includes/updater.php';   /* ใช้ up_backup_db() / up_storage() */

/** โฟลเดอร์เก็บชุดสำรองอัตโนมัติ */
function ab_dir(): string {
    $d = APP_ROOT . '/storage/backups';
    if (!is_dir($d)) @mkdir($d, 0775, true);
    return $d;
}

/** รอบการสำรองเป็นวินาที (0 = ปิด) */
function ab_interval(): int {
    return ['off' => 0, 'daily' => 86400, 'weekly' => 604800][setting('backup_auto', 'weekly')] ?? 604800;
}

/** ถึงเวลาสำรองรอบใหม่หรือยัง */
function ab_due(): bool {
    $iv = ab_interval();
    if ($iv <= 0) return false;
    $last = strtotime((string)setting('backup_last', '')) ?: 0;
    return (time() - $last) >= $iv;
}

/** รายการชุดสำรองอัตโนมัติ (ใหม่สุดก่อน) */
function ab_list(): array {
    $out = [];
    foreach (glob(ab_dir() . '/auto-*.sql') ?: [] as $f) {
        $out[] = ['name' => basename($f), 'path' => $f, 'size' => (int)@filesize($f), 'time' => (int)@filemtime($f)];
    }
    usort($out, fn($a, $b) => $b['time'] <=> $a['time']);
    return $out;
}

/** ลบชุดเก่าให้เหลือตามจำนวนที่ตั้งไว้ — คืนจำนวนที่ลบ */
function ab_rotate(?int $keep = null): int {
    $keep = $keep ?? max(1, (int)setting('backup_keep', '5'));
    $all  = ab_list();
    $n = 0;
    foreach (array_slice($all, $keep) as $old) { if (@unlink($old['path'])) $n++; }
    return $n;
}

/**
 * สำรองฐานข้อมูลหนึ่งชุด (ไฟล์ .sql ในโฟลเดอร์ปิด) แล้วหมุนเวียนลบของเก่า
 * คืน [ok, ข้อความ]
 */
function ab_run(): array {
    $dir = ab_dir();
    if (!up_dir_writable($dir)) return [false, 'โฟลเดอร์ storage/backups เขียนไม่ได้'];
    $file = $dir . '/auto-' . date('Ymd-His') . '.sql';
    $err = null;
    if (!up_backup_db($file, $err)) {
        @unlink($file);
        return [false, (string)$err];
    }
    setting_set('backup_last', date('c'));
    $removed = ab_rotate();
    return [true, 'สำรองข้อมูลอัตโนมัติแล้ว (' . format_bytes((int)@filesize($file)) . ')'
                . ($removed ? " · ลบชุดเก่า $removed ชุด" : '')];
}

/** เรียกจากหน้าแอดมิน: ถ้าถึงรอบให้สำรองเงียบๆ — คืนข้อความสรุป หรือ '' ถ้าไม่ได้ทำอะไร */
function ab_maybe_run(): string {
    if (!ab_due()) return '';
    try {
        [$ok, $msg] = ab_run();
        log_action($ok ? 'สำรองข้อมูลอัตโนมัติ' : 'สำรองข้อมูลอัตโนมัติล้มเหลว', mb_substr($msg, 0, 200));
        return $ok ? $msg : '';
    } catch (Throwable $e) {
        return '';
    }
}

/** ลบชุดสำรองของระบบอัปเดตที่เก่าเกินกำหนด (storage/updates/backup-*) — กันพื้นที่เต็ม */
function ab_rotate_update_backups(int $keep = 5): int {
    $dirs = up_backups();          /* เรียงใหม่สุดก่อนอยู่แล้ว */
    $n = 0;
    foreach (array_slice($dirs, $keep) as $old) {
        up_rrmdir(up_storage() . '/' . $old['name']);
        $n++;
    }
    return $n;
}
