<?php
/**
 * complaint-file.php — เสิร์ฟไฟล์แนบของเรื่องร้องเรียน เฉพาะเจ้าหน้าที่ที่ล็อกอินแล้ว
 *
 * เหตุผล: ไฟล์แนบเรื่องร้องเรียนเป็นข้อมูลส่วนบุคคล (PDPA) จึงถูกปิดไม่ให้เปิดจาก URL ตรง
 * (uploads/complaints/.htaccess) และต้องผ่านหน้านี้ซึ่งตรวจสิทธิ์ก่อนเสมอ
 */
require dirname(__DIR__) . '/includes/init.php';
require __DIR__ . '/_auth.php';

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(400); exit('คำขอไม่ถูกต้อง'); }

$st = db()->prepare('SELECT file FROM complaints WHERE id = ?');
$st->execute([$id]);
$rel = (string)($st->fetchColumn() ?: '');
if ($rel === '') { http_response_code(404); exit('ไม่พบไฟล์แนบ'); }

/* กัน path traversal — ไฟล์ต้องอยู่ใต้ uploads/complaints เท่านั้น */
$real = realpath(APP_ROOT . '/' . $rel);
$base = realpath(APP_ROOT . '/uploads/complaints');
if (!$real || !$base || strpos($real, $base) !== 0 || !is_file($real)) {
    http_response_code(404);
    exit('ไม่พบไฟล์แนบ');
}

log_action('เปิดไฟล์แนบเรื่องร้องเรียน', '#' . $id);

$ext  = strtolower(pathinfo($real, PATHINFO_EXTENSION));
$mime = [
    'pdf'  => 'application/pdf',
    'jpg'  => 'image/jpeg', 'jpeg' => 'image/jpeg',
    'png'  => 'image/png',  'webp' => 'image/webp',
    'doc'  => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'xls'  => 'application/vnd.ms-excel',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
][$ext] ?? 'application/octet-stream';

/* แสดงในเบราว์เซอร์ได้เฉพาะชนิดที่ปลอดภัย ที่เหลือบังคับดาวน์โหลด */
$inline = in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'webp'], true);

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($real));
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment')
     . '; filename="' . basename($real) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');      /* ห้ามแคชข้อมูลส่วนบุคคล */
readfile($real);
