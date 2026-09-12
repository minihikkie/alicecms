<?php
/**
 * _auth.php — ด่านตรวจสิทธิ์ของทุกหน้า admin
 * ต้อง require หลัง init.php เสมอ — ถ้าไม่ได้ login จะถูกส่งไปหน้า login ทันที
 */
if (!defined('APP_ROOT')) exit('Forbidden');

const SESSION_TIMEOUT = 1800; // หมดอายุอัตโนมัติ 30 นาที

if (empty($_SESSION['admin_id'])) {
    redirect('admin/login.php');
}

/* ตรวจ session หมดอายุ (ไม่มีการใช้งานเกิน 30 นาที) */
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > SESSION_TIMEOUT) {
    session_unset();
    session_destroy();
    redirect('admin/login.php?timeout=1');
}
$_SESSION['last_activity'] = time();

/* ข้อมูลผู้ใช้ปัจจุบัน */
$st = db()->prepare('SELECT id, username, display_name, role FROM users WHERE id = ?');
$st->execute([$_SESSION['admin_id']]);
$ADMIN = $st->fetch();
if (!$ADMIN) {
    session_unset();
    session_destroy();
    redirect('admin/login.php');
}

/** ผู้ใช้ปัจจุบันเป็นผู้ดูแลระบบสูงสุด (admin) หรือไม่ — editor จะ false */
function is_admin_role(): bool {
    return ($GLOBALS['ADMIN']['role'] ?? 'admin') === 'admin';
}

/** กั้นหน้าเฉพาะ admin เท่านั้น (เรียกบนสุดของหน้า settings/theme/users/backup/notify/activity) */
function require_admin(): void {
    if (!is_admin_role()) {
        http_response_code(403);
        exit('เฉพาะผู้ดูแลระบบสูงสุดเท่านั้นที่เข้าถึงหน้านี้ได้');
    }
}
