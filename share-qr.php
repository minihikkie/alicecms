<?php
/**
 * share-qr.php — QR Code ของลิงก์เอกสาร (ไว้แปะหนังสือเวียน / บอร์ดประกาศ)
 *   ?d=<id เอกสาร>   → ลิงก์เปิดเอกสาร (download.php?id=)
 *   ?t=<id หัวข้อสารบัญ> → ลิงก์ไปหัวข้อนั้นในหน้าสารบัญ (documents.php?cat=…#t…)
 *   &dl=1             → ดาวน์โหลดเป็นไฟล์ .svg
 *
 * รับแค่เลข id แล้วประกอบลิงก์ของเว็บเราเอง — ไม่รับข้อความ/URL จากผู้ขอ
 * (กันคนใช้เว็บเราเป็นเครื่องสร้าง QR พาไปเว็บอื่น)
 * สร้าง QR ในเครื่องด้วย includes/qrcode.php ไม่ส่งลิงก์ไปบริการภายนอก
 */
define('PUBLIC_PAGE', 'share-qr');
require __DIR__ . '/includes/init.php';
require __DIR__ . '/includes/qrcode.php';
require_section_live('documents');

$path = '';
$name = 'qr';
if (($d = (int)($_GET['d'] ?? 0)) > 0) {
    $st = db()->prepare('SELECT id FROM documents WHERE id = ?');
    $st->execute([$d]);
    if ($st->fetchColumn()) { $path = url('download.php?id=' . $d); $name = 'qr-doc-' . $d; }
} elseif (($t = (int)($_GET['t'] ?? 0)) > 0) {
    try {
        $st = db()->prepare('SELECT category_id FROM doc_toc WHERE id = ?');
        $st->execute([$t]);
        if ($cid = (int)$st->fetchColumn()) { $path = url('documents.php?cat=' . $cid) . '#t' . $t; $name = 'qr-toc-' . $t; }
    } catch (Throwable $e) {}
}
if ($path === '') { http_response_code(404); exit; }

/* ลิงก์เต็ม: ใช้ is_https() ที่รู้จัก proxy/CDN (abs_url() ดูแค่ $_SERVER['HTTPS'] อาจได้ http ทั้งที่ผู้ใช้เปิด https) */
$host = preg_replace('/[^a-z0-9.\-:\[\]]/i', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
$link = (is_https() ? 'https' : 'http') . '://' . $host . $path;

$svg = qr_svg($link, 512, 'QR Code ลิงก์เอกสาร');
if ($svg === '') { http_response_code(404); exit; }

header('Content-Type: image/svg+xml; charset=utf-8');
/* private: QR ขึ้นกับ Host ของคำขอ ไม่ให้ CDN เก็บไว้แจกคนอื่น */
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');
if (!empty($_GET['dl'])) header('Content-Disposition: attachment; filename="' . $name . '.svg"');
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . $svg;
