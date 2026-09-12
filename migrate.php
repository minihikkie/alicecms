<?php
/**
 * migrate.php — อัปเดตโครงสร้างฐานข้อมูลสำหรับเว็บที่ติดตั้งไปแล้ว (idempotent รันซ้ำได้)
 * รันครั้งเดียวหลังอัปโหลดโค้ดเวอร์ชันใหม่ จาก CLI:  php migrate.php
 * (ไม่เปิดให้เรียกผ่านเว็บ เพื่อความปลอดภัย — ผ่านหน้า admin → อัปเดตระบบ จะรันให้อัตโนมัติ)
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

define('APP_ROOT', __DIR__);
require __DIR__ . '/config.php';
require __DIR__ . '/includes/migrations.php';

$pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

echo "เริ่ม migration...\n";
run_migrations($pdo, function ($m) { echo "  $m\n"; });
echo "เสร็จสิ้น migration\n";
