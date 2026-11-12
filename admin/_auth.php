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

/* ข้อมูลผู้ใช้ปัจจุบัน (+ กลุ่มสิทธิ์ · สถานะบัญชี — v1.66.0)
   ถ้าฐานข้อมูลยังไม่ได้ migrate (ช่วงสั้นๆ ระหว่างอัปเดต) ใช้คำสั่งแบบเดิมไปก่อน */
require_once APP_ROOT . '/includes/permissions.php';
try {
    $st = db()->prepare('SELECT u.id, u.username, u.display_name, u.role, u.role_id, u.is_active, r.name AS role_name
                         FROM users u LEFT JOIN roles r ON r.id = u.role_id WHERE u.id = ?');
    $st->execute([$_SESSION['admin_id']]);
    $ADMIN = $st->fetch();
} catch (Throwable $e) {
    $st = db()->prepare('SELECT id, username, display_name, role FROM users WHERE id = ?');
    $st->execute([$_SESSION['admin_id']]);
    $ADMIN = $st->fetch();
    if ($ADMIN) $ADMIN += ['role_id' => null, 'is_active' => 1, 'role_name' => '', 'legacy' => true];
}
if (!$ADMIN || (int)($ADMIN['is_active'] ?? 1) !== 1) {
    /* ถูกลบ/ถูกระงับบัญชีระหว่างใช้งาน → ออกจากระบบทันที */
    session_unset();
    session_destroy();
    redirect('admin/login.php' . ($ADMIN ? '?disabled=1' : ''));
}
/* ก่อน migrate: เจ้าหน้าที่ใช้สิทธิ์แบบเดิมไปก่อน */
$ADMIN_PERMS = !empty($ADMIN['legacy']) && $ADMIN['role'] !== 'admin' ? perm_legacy_editor() : perm_load($ADMIN);
perm_enforce();

/** ผู้ใช้ปัจจุบันเป็นผู้ดูแลระบบสูงสุด (admin) หรือไม่ — editor จะ false */
function is_admin_role(): bool {
    return ($GLOBALS['ADMIN']['role'] ?? 'admin') === 'admin';
}

/** กั้นการกระทำเฉพาะผู้ดูแลระบบสูงสุด (สำรองข้อมูล โอนย้าย อัปเดต กลุ่มสิทธิ์ ล้างถังขยะ)
 *  หน้าที่มอบสิทธิ์ได้ตรวจด้วย perm_enforce() แล้ว ไม่ต้องเรียกฟังก์ชันนี้ */
function require_admin(): void {
    if (!is_admin_role()) {
        log_action('ถูกปฏิเสธสิทธิ์', mb_substr('สงวนผู้ดูแลระบบสูงสุด · ' . basename($_SERVER['SCRIPT_NAME'] ?? ''), 0, 200));
        flash_set('danger', 'การกระทำนี้สงวนไว้สำหรับผู้ดูแลระบบสูงสุด');
        redirect('admin/index.php');
    }
}
