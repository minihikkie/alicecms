<?php
/**
 * init.php — จุดเริ่มต้นของทุกหน้า
 * โหลด config, ตั้งค่า session/ความปลอดภัย, เชื่อมต่อฐานข้อมูล
 */
define('APP_ROOT', dirname(__DIR__));

/* ยังไม่ติดตั้ง → ส่งไปหน้า install */
if (!file_exists(APP_ROOT . '/config.php')) {
    $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    $root = preg_replace('#/(admin)(/.*)?$#', '', rtrim($dir, '/'));
    header('Location: ' . ($root === '' ? '' : $root) . '/install.php');
    exit;
}

require APP_ROOT . '/config.php';
require APP_ROOT . '/version.php';
require APP_ROOT . '/includes/functions.php';
require APP_ROOT . '/includes/trash.php';      /* ถังขยะ — ลบแล้วกู้คืนได้ */
require APP_ROOT . '/includes/theme-presets.php'; /* ธีมสำเร็จรูป + ภาพฉากหลัง */

/* เวลาประเทศไทย — ให้วันที่ พ.ศ. และระบบล็อก login ตรงกับเวลาจริง */
date_default_timezone_set('Asia/Bangkok');

/**
 * เว็บถูกเปิดผ่าน HTTPS อยู่หรือไม่
 * รองรับกรณีอยู่หลัง reverse proxy / load balancer ที่ปิด TLS ไว้ด้านหน้า (X-Forwarded-Proto)
 * — ถ้าไม่ตรวจ ค่านี้จะเป็น false ทำให้คุกกี้ไม่ถูกตั้งเป็น secure ทั้งที่ผู้ใช้ใช้ https อยู่
 */
function is_https(): bool {
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') return true;
    if ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443) return true;
    $xf = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    if ($xf !== '') return strpos($xf, 'https') !== false;   /* อาจเป็น "https, http" */
    if (strtolower((string)($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '')) === 'on') return true;
    return false;
}

/* ─── ที่เก็บไฟล์เซสชัน ───
 * เก็บไว้ในโฟลเดอร์ของเว็บเอง แทนที่จะพึ่ง session.save_path ของเซิร์ฟเวอร์
 *
 * ทำไม: เจอจริงกับโฮสต์ราชการ — save_path ที่เซิร์ฟเวอร์ตั้งไว้เขียนไม่ได้ (ย้ายเครื่อง/สิทธิ์เปลี่ยน/
 * โควตาเต็ม) ผลคือ PHP ส่งคุกกี้เซสชันให้เบราว์เซอร์ตามปกติ แต่ "ข้อมูล" ในเซสชันหายทุกคำขอ
 * → csrf_token ที่ฝังในฟอร์มไม่มีวันตรงกับของเซิร์ฟเวอร์ → ล็อกอิน admin ไม่ได้เลย
 *   และฟอร์มสาธารณะ (ร้องเรียน/แบบฟอร์ม) ขึ้น "การตรวจสอบความปลอดภัยล้มเหลว (CSRF)" ทั้งหมด
 * อาการนี้วินิจฉัยยากมากเพราะดูเหมือนปัญหา CSRF แต่ต้นเหตุคือที่เก็บเซสชัน
 *
 * โฟลเดอร์ storage/ มี .htaccess ปฏิเสธการเข้าถึงจากเว็บอยู่แล้ว (ดู hardening.php)
 * ถ้าสร้าง/เขียนไม่ได้ ก็ปล่อยให้ใช้ค่าเดิมของเซิร์ฟเวอร์ไป ไม่ทำให้พังกว่าเดิม */
$sess_dir = APP_ROOT . '/storage/sessions';
if (!is_dir($sess_dir)) @mkdir($sess_dir, 0700, true);
if (is_dir($sess_dir) && is_writable($sess_dir)) {
    session_save_path($sess_dir);
    /* บางระบบ (เช่น Debian/Ubuntu) ปิด GC ของ PHP ไว้แล้วใช้ cron เก็บกวาด /var/lib/php แทน
       พอย้าย save_path มาที่ของเรา cron นั้นจะไม่มาเก็บให้ ต้องเปิด GC ของ PHP เอง ไม่งั้นไฟล์ค้างสะสม */
    ini_set('session.gc_probability', '1');
    ini_set('session.gc_divisor', '100');
}

/* ─── Session อย่างปลอดภัย ─── */
session_name('GOVCMS_SESS');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'httponly' => true,                                   // JS อ่าน cookie ไม่ได้
    'samesite' => 'Lax',                                  // กัน CSRF ข้ามเว็บ
    'secure'   => is_https(),                             // ส่งคุกกี้เฉพาะช่องทางเข้ารหัส
]);
session_start();

/* ─── CSP nonce — อนุญาตสคริปต์ inline เฉพาะที่เราใส่เอง (JSON-LD, กัน FOUC) อย่างปลอดภัย ─── */
define('CSP_NONCE', base64_encode(random_bytes(16)));

/* ─── Security headers ─── */
/* HSTS: สั่งเบราว์เซอร์ให้ใช้ https กับเว็บนี้เท่านั้น (ส่งเฉพาะเมื่อใช้ https อยู่จริง
   ถ้าส่งตอนยังเป็น http จะทำให้เข้าเว็บไม่ได้) — เปิดใช้อัตโนมัติทันทีที่ติดตั้งใบรับรอง */
if (is_https()) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
/* frame-src อนุญาตเฉพาะ YouTube และ Google Maps (เนื้อหาฝังที่ระบบรองรับเท่านั้น) */
header("Content-Security-Policy: default-src 'self'; img-src 'self' data: https://*.ggpht.com https://*.ytimg.com; "
     . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
     . "font-src https://fonts.gstatic.com; script-src 'self' 'nonce-" . CSP_NONCE . "'; "
     . "frame-src 'self' https://www.youtube-nocookie.com https://www.youtube.com https://www.google.com https://maps.google.com; "
     . "base-uri 'self'; frame-ancestors 'self'; form-action 'self'; object-src 'none'");

/* ─── เชื่อมต่อฐานข้อมูล (PDO + prepared statements เท่านั้น) ─── */
try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    exit('ไม่สามารถเชื่อมต่อฐานข้อมูลได้ กรุณาตรวจสอบการตั้งค่าใน config.php');
}

/* ─── URL สวย: ถ้าเข้ามาด้วย .php ให้ส่งต่อไปแบบไม่มี .php (301 ถาวร) ───
   ทำฝั่ง PHP ไม่ใช่ .htaccess เพราะ PHP รู้ค่า BASE_URL แน่นอน (รองรับติดตั้งในโฟลเดอร์ย่อย)
   ส่วน RewriteRule R=301 ที่ไม่มี RewriteBase จะสร้าง URL เพี้ยน (พิสูจน์กับ Apache จริงแล้ว)

   ไม่วนลูป: /news.php → 301 → /news → .htaccess rewrite ภายในเป็น news.php
   รอบหลัง REQUEST_URI คือ "/news" ไม่มี .php เงื่อนไขจึงไม่เข้าอีก
   เฉพาะ GET/HEAD — ถ้า 301 ตอน POST ข้อมูลในฟอร์มจะหาย */
if (in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
    $canonical = canonical_php_redirect(
        (string)($_SERVER['REQUEST_URI'] ?? ''),
        (string)($_SERVER['QUERY_STRING'] ?? '')
    );
    if ($canonical !== null) {
        header('Location: ' . $canonical, true, 301);
        exit;
    }
}

/* ─── ตรวจ CSRF token อัตโนมัติสำหรับทุก POST ─── */
csrf_verify();

/* ─── เก็บสถิติผู้เข้าชม (เฉพาะหน้าเว็บฝั่งประชาชน ไม่นับ admin) ─── */
if (defined('PUBLIC_PAGE')) {
    track_page_view(PUBLIC_PAGE);
}
