<?php
/**
 * cron-backup.php — สำรองฐานข้อมูลอัตโนมัติ (สำหรับตั้ง cron บนเซิร์ฟเวอร์)
 *
 * ใช้เมื่ออยากให้สำรองตรงเวลาแม้ไม่มีใครเปิดหน้าแอดมิน
 * ตัวอย่างตั้ง cron ให้สำรองทุกวันตี 3:
 *   0 3 * * * /usr/bin/php /var/www/govsite/tools/cron-backup.php >> /var/log/govcms-backup.log 2>&1
 *
 * (ถ้าไม่ตั้ง cron ก็ยังทำงานได้ — ระบบจะสำรองให้เองเมื่อผู้ดูแลเปิดหน้าแอดมินและถึงรอบแล้ว)
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

require dirname(__DIR__) . '/includes/init.php';
require dirname(__DIR__) . '/includes/autobackup.php';

$force = in_array('--force', $argv, true);

if (!$force && !ab_due()) {
    echo '[' . date('c') . "] ยังไม่ถึงรอบสำรอง (ตั้งไว้: " . setting('backup_auto', 'weekly') . ")\n";
    exit(0);
}

[$ok, $msg] = ab_run();
echo '[' . date('c') . '] ' . ($ok ? 'สำเร็จ: ' : 'ล้มเหลว: ') . $msg . "\n";

/* เก็บกวาดชุดสำรองของระบบอัปเดตที่เก่าเกินไปด้วย */
$n = ab_rotate_update_backups(5);
if ($n > 0) echo '[' . date('c') . "] ลบชุดสำรองเก่าของระบบอัปเดต $n ชุด\n";

exit($ok ? 0 : 1);
