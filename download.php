<?php
/**
 * download.php — ส่งไฟล์เอกสารพร้อมนับจำนวนดาวน์โหลด
 * เข้าผ่าน download.php?id=<id> → เพิ่มตัวนับ → stream ไฟล์จากโฟลเดอร์ uploads เท่านั้น
 */
require __DIR__ . '/includes/init.php';

$id = (int)($_GET['id'] ?? 0);
$st = db()->prepare('SELECT title, file, ext_url, ext FROM documents WHERE id = ?');
$st->execute([$id]);
$doc = $st->fetch();

if (!$doc) {
    http_response_code(404);
    require __DIR__ . '/404.php';
    exit;
}

/* เอกสารแบบลิงก์ภายนอก (Google Drive ฯลฯ): นับแล้วส่งต่อไปลิงก์นั้น */
if (!empty($doc['ext_url']) && preg_match('#^https?://#i', $doc['ext_url'])) {
    if (empty($_SESSION['dl'][$id])) {
        db()->prepare('UPDATE documents SET downloads = downloads + 1 WHERE id = ?')->execute([$id]);
        $_SESSION['dl'][$id] = true;
    }
    header('Location: ' . $doc['ext_url'], true, 302);
    exit;
}

if (!$doc['file']) {
    http_response_code(404);
    require __DIR__ . '/404.php';
    exit;
}

/* ตรวจ path ให้อยู่ใต้ uploads เท่านั้น (กัน path traversal) */
$real = realpath(APP_ROOT . '/' . $doc['file']);
$base = realpath(APP_ROOT . '/uploads');
if (!$real || !$base || !str_starts_with($real, $base) || !is_file($real)) {
    http_response_code(404);
    require __DIR__ . '/404.php';
    exit;
}

/* นับดาวน์โหลด — ครั้งเดียวต่อ session ต่อเอกสาร กันรีเฟรชปั่นยอด */
if (empty($_SESSION['dl'][$id])) {
    db()->prepare('UPDATE documents SET downloads = downloads + 1 WHERE id = ?')->execute([$id]);
    $_SESSION['dl'][$id] = true;
}

/* ตั้งชื่อไฟล์ที่ผู้ใช้เห็น = ชื่อเอกสาร + นามสกุลจริง */
$ext = $doc['ext'] ?: pathinfo($real, PATHINFO_EXTENSION);
$safeName = preg_replace('/[\/\\\\:*?"<>|]+/u', '_', $doc['title']);
$download = $safeName . '.' . $ext;

$mime = [
    'pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
    'png' => 'image/png', 'webp' => 'image/webp', 'zip' => 'application/zip',
    'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'xls' => 'application/vnd.ms-excel', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
][strtolower($ext)] ?? 'application/octet-stream';

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($real));
header('Content-Disposition: attachment; filename="' . rawurlencode($download) . '"; filename*=UTF-8\'\'' . rawurlencode($download));
header('X-Content-Type-Options: nosniff');
readfile($real);
exit;
