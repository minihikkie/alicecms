<?php
/**
 * install.php — Setup Wizard ติดตั้งระบบเว็บไซต์หน่วยงานราชการ
 * กรอกข้อมูล → สร้างฐานข้อมูล → สร้างบัญชี admin → พร้อมใช้ทันที
 * หลังติดตั้งสำเร็จจะสร้างไฟล์ install.lock ป้องกันการรันซ้ำ
 */
define('APP_ROOT', __DIR__);

/* ── ติดตั้งแล้ว → ล็อกตัวเอง ── */
if (file_exists(__DIR__ . '/install.lock')) {
    http_response_code(403);
    exit('ระบบติดตั้งเรียบร้อยแล้ว — หากต้องการติดตั้งใหม่ ให้ลบไฟล์ install.lock และ config.php ก่อน (ข้อมูลเดิมในฐานข้อมูลจะไม่ถูกลบอัตโนมัติ)');
}

$errors = [];
$warnings = [];   /* ปัญหาที่ไม่ร้ายแรงพอจะหยุดการติดตั้ง แต่ต้องแจ้งให้รู้ (เช่น อัปโหลดโลโก้ไม่สำเร็จ) */
$done = false;

/* สีสำเร็จรูป */
$presets = [
    ['น้ำเงิน', '#1A73E8', '#1557B0'], ['กรมท่า', '#0F4C9C', '#093567'],
    ['เขียว', '#16A34A', '#0F7A36'], ['ม่วง', '#7C3AED', '#5B21B6'],
    ['ส้ม', '#EA580C', '#B23F06'], ['เลือดหมู', '#B91C1C', '#7F1212'],
];

$all_sections = [
    'slider' => 'แบนเนอร์สไลด์', 'hero' => 'ค้นหา + สถิติ', 'chief' => 'สารจากหัวหน้าหน่วยงาน',
    'personnel' => 'โครงสร้างผู้บริหาร',
    'activity' => 'กิจกรรม', 'pr' => 'ประชาสัมพันธ์', 'announce' => 'ประกาศ', 'video' => 'วิดีโอความรู้',
    'documents' => 'เอกสารเผยแพร่', 'ita' => 'ITA / OIT', 'procurement' => 'จัดซื้อจัดจ้าง',
    'faq' => 'คำถามที่พบบ่อย', 'complaint' => 'ร้องเรียน-ร้องทุกข์', 'contact' => 'ติดต่อหน่วยงาน',
    'links' => 'ลิงก์ที่เกี่ยวข้อง',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    /* ── 1) อ่านค่า ── */
    $db_host = trim((string)($_POST['db_host'] ?? 'localhost'));
    $db_name = trim((string)($_POST['db_name'] ?? ''));
    $db_user = trim((string)($_POST['db_user'] ?? 'root'));
    $db_pass = (string)($_POST['db_pass'] ?? '');

    $site_name = mb_substr(trim((string)($_POST['site_name'] ?? '')), 0, 200);
    $site_dept = mb_substr(trim((string)($_POST['site_dept'] ?? '')), 0, 200);

    $theme = strtoupper(trim((string)($_POST['theme_color'] ?? '#1A73E8')));
    if (!preg_match('/^#[0-9A-F]{6}$/', $theme)) $theme = '#1A73E8';
    $theme_dark = '';
    foreach ($presets as [$n, $c, $d]) if (strtoupper($c) === $theme) $theme_dark = $d;
    if ($theme_dark === '') {
        $n = hexdec(substr($theme, 1));
        $f = fn($x) => max(0, (int)round($x * 0.68));
        $theme_dark = sprintf('#%02X%02X%02X', $f(($n >> 16) & 255), $f(($n >> 8) & 255), $f($n & 255));
    }

    $checked_sections = (array)($_POST['sections'] ?? []);

    $admin_user = trim((string)($_POST['admin_user'] ?? ''));
    $admin_pass = (string)($_POST['admin_pass'] ?? '');
    $admin_pass2 = (string)($_POST['admin_pass2'] ?? '');

    /* ── 2) ตรวจสอบ ── */
    if ($db_name === '' || !preg_match('/^[A-Za-z0-9_]+$/', $db_name)) $errors[] = 'ชื่อฐานข้อมูลต้องเป็นอักษรอังกฤษ/ตัวเลข/_ เท่านั้น';
    if ($site_name === '') $errors[] = 'กรุณากรอกชื่อหน่วยงาน';
    if (!preg_match('/^[A-Za-z0-9_.@-]{4,50}$/', $admin_user)) $errors[] = 'ชื่อผู้ใช้ admin ต้องยาว 4–50 ตัว (อังกฤษ/ตัวเลข/_.@-)';
    if (mb_strlen($admin_pass) < 8) $errors[] = 'รหัสผ่าน admin ต้องยาวอย่างน้อย 8 ตัวอักษร';
    if ($admin_pass !== $admin_pass2) $errors[] = 'รหัสผ่านทั้งสองช่องไม่ตรงกัน';

    /* ── 3) เชื่อมต่อ + สร้างฐานข้อมูล ── */
    $pdo = null;
    if (!$errors) {
        try {
            $pdo = new PDO("mysql:host=$db_host;charset=utf8mb4", $db_user, $db_pass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `$db_name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("USE `$db_name`");
        } catch (PDOException $ex) {
            $errors[] = 'เชื่อมต่อฐานข้อมูลไม่สำเร็จ: ' . $ex->getMessage();
        }
    }

    /* ── 4) สร้างตาราง ── */
    if (!$errors && $pdo) {
        try {
            $pdo->exec("
CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(100) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  display_name VARCHAR(150) NOT NULL DEFAULT '',
  email VARCHAR(150) NOT NULL DEFAULT '',
  role ENUM('admin','editor') NOT NULL DEFAULT 'admin',
  totp_secret VARCHAR(64) NOT NULL DEFAULT '',
  totp_enabled TINYINT(1) NOT NULL DEFAULT 0,
  backup_codes TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS activity_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL,
  username VARCHAR(100) NOT NULL DEFAULT '',
  action VARCHAR(100) NOT NULL,
  detail VARCHAR(255) NOT NULL DEFAULT '',
  ip VARCHAR(45) NOT NULL DEFAULT '',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
  skey VARCHAR(100) PRIMARY KEY,
  sval TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sections (
  skey VARCHAR(50) PRIMARY KEY,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  in_menu TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  custom_title VARCHAR(150) NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS password_resets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  ip VARCHAR(45) NOT NULL DEFAULT '',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_token (token_hash),
  INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS trash (
  id INT AUTO_INCREMENT PRIMARY KEY,
  uid VARCHAR(40) NOT NULL,
  table_name VARCHAR(64) NOT NULL,
  row_id INT NOT NULL,
  kind VARCHAR(64) NOT NULL DEFAULT '',
  label VARCHAR(250) NOT NULL DEFAULT '',
  payload MEDIUMTEXT NOT NULL,
  children MEDIUMTEXT NULL,
  files TEXT NULL,
  deleted_by VARCHAR(100) NOT NULL DEFAULT '',
  deleted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_deleted (deleted_at),
  INDEX idx_table (table_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS doc_categories (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  icon VARCHAR(50) NOT NULL DEFAULT 'folder',
  style VARCHAR(20) NOT NULL DEFAULT '',
  sort_order INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS posts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  type ENUM('activity','pr','announce') NOT NULL DEFAULT 'activity',
  title VARCHAR(250) NOT NULL,
  body MEDIUMTEXT,
  image VARCHAR(300),
  attachment VARCHAR(300),
  attachment_size INT NOT NULL DEFAULT 0,
  video_url VARCHAR(300),
  views INT NOT NULL DEFAULT 0,
  status ENUM('draft','published') NOT NULL DEFAULT 'published',
  published_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_type_status (type, status, published_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS post_attachments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  post_id INT NOT NULL,
  name VARCHAR(200) NOT NULL DEFAULT '',
  file VARCHAR(300) NOT NULL,
  file_size INT NOT NULL DEFAULT 0,
  ext VARCHAR(10) NOT NULL DEFAULT '',
  INDEX idx_post (post_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS personnel (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(200) NOT NULL,
  position VARCHAR(250),
  level TINYINT NOT NULL DEFAULT 1,
  photo VARCHAR(300),
  phone VARCHAR(50),
  email VARCHAR(150),
  sort_order INT NOT NULL DEFAULT 0,
  status ENUM('draft','published') NOT NULL DEFAULT 'published',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_level (level, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS documents (
  id INT AUTO_INCREMENT PRIMARY KEY,
  category_id INT NULL,
  title VARCHAR(250) NOT NULL,
  file VARCHAR(300) NOT NULL DEFAULT '',
  ext_url VARCHAR(500) NOT NULL DEFAULT '',
  file_size INT NOT NULL DEFAULT 0,
  ext VARCHAR(10) NOT NULL DEFAULT '',
  downloads INT NOT NULL DEFAULT 0,
  sort_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_cat (category_id),
  INDEX idx_cat_sort (category_id, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ita_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  grp TINYINT NOT NULL DEFAULT 1,
  code VARCHAR(10) NOT NULL DEFAULT '',
  title VARCHAR(250) NOT NULL,
  file VARCHAR(300),
  url VARCHAR(500),
  sort_order INT NOT NULL DEFAULT 0,
  INDEX idx_grp (grp)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS procurements (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(250) NOT NULL,
  ptype ENUM('ebidding','price','result','other') NOT NULL DEFAULT 'other',
  pdate DATE NOT NULL,
  file VARCHAR(300),
  ext_url VARCHAR(500) NOT NULL DEFAULT '',
  file_size INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS slides (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(250) NOT NULL,
  subtitle VARCHAR(500),
  image VARCHAR(300),
  link_text VARCHAR(100),
  link_url VARCHAR(500),
  focus_x TINYINT NOT NULL DEFAULT 50,
  focus_y TINYINT NOT NULL DEFAULT 50,
  text_align VARCHAR(10) NOT NULL DEFAULT 'center',
  text_valign VARCHAR(10) NOT NULL DEFAULT 'middle',
  text_theme VARCHAR(10) NOT NULL DEFAULT 'light',
  overlay TINYINT NOT NULL DEFAULT 35,
  kenburns TINYINT(1) NOT NULL DEFAULT 0,
  duration SMALLINT NOT NULL DEFAULT 0,
  title_size VARCHAR(8) NOT NULL DEFAULT 'md',
  text_color VARCHAR(7) NOT NULL DEFAULT '',
  text_shadow TINYINT(1) NOT NULL DEFAULT 1,
  btn_style VARCHAR(8) NOT NULL DEFAULT 'solid',
  btn_color VARCHAR(7) NOT NULL DEFAULT '',
  link_text2 VARCHAR(100) NOT NULL DEFAULT '',
  link_url2 VARCHAR(500) NOT NULL DEFAULT '',
  grad_from VARCHAR(7) NOT NULL DEFAULT '',
  grad_to VARCHAR(7) NOT NULL DEFAULT '',
  sort_order INT NOT NULL DEFAULT 0,
  enabled TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS faqs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  question VARCHAR(500) NOT NULL,
  answer TEXT NOT NULL,
  sort_order INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(120) NOT NULL UNIQUE,
  title VARCHAR(250) NOT NULL,
  body MEDIUMTEXT,
  blocks MEDIUMTEXT NULL,
  status ENUM('draft','published') NOT NULL DEFAULT 'published',
  sort_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS content_types (
  id INT AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(120) NOT NULL UNIQUE,
  name VARCHAR(120) NOT NULL,
  name_plural VARCHAR(120) NOT NULL DEFAULT '',
  icon VARCHAR(50) NOT NULL DEFAULT 'category',
  fields MEDIUMTEXT NULL,
  has_image TINYINT(1) NOT NULL DEFAULT 1,
  status ENUM('on','off') NOT NULL DEFAULT 'on',
  sort_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS content_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  type_id INT NOT NULL,
  title VARCHAR(250) NOT NULL,
  slug VARCHAR(150) NOT NULL DEFAULT '',
  image VARCHAR(300) NOT NULL DEFAULT '',
  data MEDIUMTEXT NULL,
  status ENUM('draft','published') NOT NULL DEFAULT 'published',
  sort_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_type (type_id, status, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS forms (
  id INT AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(120) NOT NULL UNIQUE,
  title VARCHAR(200) NOT NULL,
  description VARCHAR(500) NOT NULL DEFAULT '',
  fields MEDIUMTEXT NULL,
  notify_email VARCHAR(150) NOT NULL DEFAULT '',
  success_msg VARCHAR(500) NOT NULL DEFAULT '',
  status ENUM('open','closed') NOT NULL DEFAULT 'open',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS form_responses (
  id INT AUTO_INCREMENT PRIMARY KEY,
  form_id INT NOT NULL,
  data MEDIUMTEXT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_form (form_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS media (
  id INT AUTO_INCREMENT PRIMARY KEY,
  path VARCHAR(300) NOT NULL,
  orig VARCHAR(200) NOT NULL DEFAULT '',
  mime VARCHAR(100) NOT NULL DEFAULT '',
  size INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS menu_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  parent_id INT NULL,
  label VARCHAR(120) NOT NULL,
  url VARCHAR(500) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  new_tab TINYINT(1) NOT NULL DEFAULT 0,
  enabled TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS org_chart_nodes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  parent_id INT NULL,
  label VARCHAR(200) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  INDEX idx_parent (parent_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS links (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(200) NOT NULL,
  subtitle VARCHAR(200),
  url VARCHAR(500) NOT NULL,
  icon VARCHAR(50) NOT NULL DEFAULT 'link',
  image VARCHAR(300),
  style VARCHAR(20) NOT NULL DEFAULT '',
  sort_order INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS footer_links (
  id INT AUTO_INCREMENT PRIMARY KEY,
  col TINYINT NOT NULL DEFAULT 1,
  label VARCHAR(120) NOT NULL,
  url VARCHAR(500) NOT NULL,
  new_tab TINYINT(1) NOT NULL DEFAULT 0,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  INDEX idx_col_sort (col, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS videos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(200) NOT NULL,
  video_id VARCHAR(20) NOT NULL,
  description VARCHAR(500) NOT NULL DEFAULT '',
  duration VARCHAR(12) NOT NULL DEFAULT '',
  cover VARCHAR(300) NULL,
  published_at DATE NULL,
  status ENUM('draft','published') NOT NULL DEFAULT 'published',
  sort_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_status_sort (status, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS complaints (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ref_code VARCHAR(20) NULL UNIQUE,
  name VARCHAR(150),
  contact VARCHAR(200),
  subject VARCHAR(250) NOT NULL,
  detail TEXT NOT NULL,
  file VARCHAR(300),
  status ENUM('new','progress','done') NOT NULL DEFAULT 'new',
  response TEXT NULL,
  responded_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS page_views (
  vdate DATE NOT NULL,
  page VARCHAR(100) NOT NULL,
  views INT NOT NULL DEFAULT 0,
  PRIMARY KEY (vdate, page)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(100) NOT NULL,
  ip VARCHAR(45) NOT NULL,
  success TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_attempt (username, ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");
        } catch (PDOException $ex) {
            $errors[] = 'สร้างตารางไม่สำเร็จ: ' . $ex->getMessage();
        }
    }

    /* ── 5) seed ข้อมูลเริ่มต้น ── */
    if (!$errors && $pdo) {
        try {
            /* บัญชี admin */
            $st = $pdo->prepare('INSERT INTO users (username, password_hash, display_name) VALUES (?,?,?)
                                 ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash)');
            $st->execute([$admin_user, password_hash($admin_pass, PASSWORD_BCRYPT), 'ผู้ดูแลระบบ']);

            /* settings */
            $settings = [
                'site_name' => $site_name, 'site_dept' => $site_dept,
                'theme_color' => $theme, 'theme_color_dark' => $theme_dark,
                'anim_level' => 'full', 'logo_icon' => 'account_balance',
                'a11y_bar' => '1',
                'site_description' => $site_name . ($site_dept ? ' ' . $site_dept : '') . ' — ศูนย์ข้อมูลข่าวสารและบริการประชาชนออนไลน์',
            ];
            $st = $pdo->prepare('INSERT INTO settings (skey, sval) VALUES (?,?) ON DUPLICATE KEY UPDATE sval = VALUES(sval)');
            foreach ($settings as $k => $v) $st->execute([$k, $v]);

            /* sections — เปิดตามที่เลือกใน wizard (in_menu เริ่มต้นตาม enabled; personnel = เมนูเท่านั้น) */
            $st = $pdo->prepare('INSERT INTO sections (skey, enabled, in_menu, sort_order, custom_title) VALUES (?,?,?,?,?)
                                 ON DUPLICATE KEY UPDATE enabled = VALUES(enabled)');
            $order = 1;
            foreach (array_keys($all_sections) as $key) {
                $on = in_array($key, $checked_sections, true) ? 1 : 0;
                $home = $key === 'personnel' ? 0 : $on;   /* ผู้บริหารไม่อยู่บนหน้าแรก */
                $st->execute([$key, $home, $on, $order++, '']);
            }

            /* หมวดเอกสารเริ่มต้น */
            if ((int)$pdo->query('SELECT COUNT(*) FROM doc_categories')->fetchColumn() === 0) {
                $st = $pdo->prepare('INSERT INTO doc_categories (name, icon, style, sort_order) VALUES (?,?,?,?)');
                $st->execute(['คู่มือประชาชน', 'menu_book', '', 1]);
                $st->execute(['แบบฟอร์ม', 'description', '', 2]);
                $st->execute(['กฎหมาย / ระเบียบ', 'gavel', 'special', 3]);
                $st->execute(['วารสาร', 'auto_stories', 'warning', 4]);
            }

            /* สไลด์ต้อนรับ */
            if ((int)$pdo->query('SELECT COUNT(*) FROM slides')->fetchColumn() === 0) {
                $st = $pdo->prepare('INSERT INTO slides (title, subtitle, sort_order, enabled) VALUES (?,?,1,1)');
                $st->execute(['ยินดีต้อนรับสู่เว็บไซต์' . $site_name, 'ศูนย์ข้อมูลข่าวสารและบริการประชาชนออนไลน์']);
            }

            /* FAQ ตัวอย่าง */
            if ((int)$pdo->query('SELECT COUNT(*) FROM faqs')->fetchColumn() === 0) {
                $st = $pdo->prepare('INSERT INTO faqs (question, answer, sort_order) VALUES (?,?,?)');
                $st->execute(['ติดต่อราชการได้วัน-เวลาไหน?', 'วันจันทร์–ศุกร์ เวลา 08.30–16.30 น. เว้นวันหยุดราชการ', 1]);
                $st->execute(['แจ้งเรื่องร้องเรียนได้ช่องทางใดบ้าง?', 'ผ่านแบบฟอร์มร้องเรียนบนเว็บไซต์ โทรศัพท์ หรือยื่นหนังสือด้วยตนเองที่หน่วยงาน', 2]);
            }

            /* ลิงก์หน่วยงานกลางที่ใช้ได้ทุกหน่วย */
            if ((int)$pdo->query('SELECT COUNT(*) FROM links')->fetchColumn() === 0) {
                $st = $pdo->prepare('INSERT INTO links (title, subtitle, url, icon, style, sort_order) VALUES (?,?,?,?,?,?)');
                $st->execute(['ศูนย์ข้อมูลข่าวสารราชการ', 'oic.go.th', 'https://www.oic.go.th', 'account_balance', 'special', 1]);
                $st->execute(['แอปพลิเคชันทางรัฐ', 'บริการภาครัฐออนไลน์', 'https://www.dga.or.th', 'apps', '', 2]);
                $st->execute(['ป.ป.ช. แจ้งเบาะแสทุจริต', 'nacc.go.th', 'https://www.nacc.go.th', 'report', 'special', 3]);
            }
        } catch (PDOException $ex) {
            $errors[] = 'บันทึกข้อมูลเริ่มต้นไม่สำเร็จ: ' . $ex->getMessage();
        }
    }

    /* ── 6) เขียน config.php + โฟลเดอร์ uploads + อัปโหลดโลโก้ + ล็อก ── */
    if (!$errors) {
        $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
        $config = "<?php\n"
            . "/**\n * config.php — ค่าเชื่อมต่อฐานข้อมูล (สร้างโดย install.php)\n"
            . " * ย้าย server ใหม่: แก้ค่า DB_* และ BASE_URL ให้ตรงกับเครื่องใหม่\n */\n"
            . "define('DB_HOST', " . var_export($db_host, true) . ");\n"
            . "define('DB_NAME', " . var_export($db_name, true) . ");\n"
            . "define('DB_USER', " . var_export($db_user, true) . ");\n"
            . "define('DB_PASS', " . var_export($db_pass, true) . ");\n"
            . "define('BASE_URL', " . var_export($base, true) . ");\n";
        if (file_put_contents(__DIR__ . '/config.php', $config) === false) {
            $errors[] = 'เขียนไฟล์ config.php ไม่สำเร็จ — ตรวจสอบสิทธิ์การเขียนโฟลเดอร์';
        }

        /* สร้างโฟลเดอร์เก็บไฟล์อัปโหลด — ถ้าสร้างไม่สำเร็จต้องแจ้งทันที
           (เดิมใช้ @mkdir เงียบๆ ทำให้ติดตั้งผ่านแต่อัปโหลดอะไรไม่ได้เลย และผู้ใช้ไม่รู้สาเหตุ) */
        $dir_failed = [];
        foreach (['posts', 'docs', 'slides', 'site', 'complaints', 'ita', 'proc', 'personnel', 'media', 'forms'] as $d) {
            $p = __DIR__ . "/uploads/$d";
            if (!is_dir($p) && !@mkdir($p, 0755, true)) { $dir_failed[] = "uploads/$d"; continue; }
            /* ทดสอบเขียนจริง — is_writable เชื่อถือไม่ได้เสมอไปบนโฮสต์บางแบบ */
            $probe = $p . '/.wtest-' . substr(md5((string)mt_rand()), 0, 6);
            if (@file_put_contents($probe, 'x') === false) $dir_failed[] = "uploads/$d (เขียนไม่ได้)";
            else @unlink($probe);
        }
        if ($dir_failed) {
            $errors[] = 'สร้างโฟลเดอร์เก็บไฟล์ไม่สำเร็จ: ' . implode(', ', $dir_failed)
                      . ' — กรุณาตั้งสิทธิ์โฟลเดอร์เว็บเป็น 755 แล้วติดตั้งใหม่ '
                      . '(ถ้าข้ามขั้นนี้ ระบบจะอัปโหลดรูปและไฟล์แนบไม่ได้)';
        }

        /* สร้างไฟล์ป้องกัน .htaccess ของ uploads/storage/backups (ไม่ได้มากับแพ็กเกจ เพราะเป็นโฟลเดอร์ข้อมูล) */
        require_once __DIR__ . '/includes/hardening.php';
        $hard = ensure_hardening_files(__DIR__);
        if (!empty($hard['failed'])) {
            $errors[] = 'สร้างไฟล์ความปลอดภัยไม่สำเร็จ: ' . implode(', ', $hard['failed'])
                      . ' — ตรวจสอบสิทธิ์การเขียน แล้วสร้างเองภายหลัง';
        }

        /* ตรวจทันทีว่า .htaccess ที่เพิ่งสร้างไม่ทำให้ไฟล์ใน uploads/ เปิดไม่ได้
           (พบจริงกับบางโฮสต์ — เซิร์ฟเวอร์ปฏิเสธคำสั่งบางอย่างจน 500 ทั้งโฟลเดอร์)
           ถ้าทดสอบไม่ผ่าน ระบบจะลบไฟล์นั้นออกเองทันที ไม่ต้องรอผู้ใช้มาเจอปัญหาทีหลัง */
        if (!$errors && in_array('uploads/.htaccess', $hard['created'], true)) {
            $scheme  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $selfUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $base;
            $selfOk  = self_test_uploads_access(__DIR__, $selfUrl);
            if ($selfOk === false) {
                $warnings[] = 'เซิร์ฟเวอร์นี้ไม่รองรับไฟล์ความปลอดภัยเสริมในโฟลเดอร์ uploads/ '
                            . '(ทดสอบแล้วทำให้เปิดไฟล์ไม่ได้) ระบบจึงปิดการใช้งานส่วนนี้ให้อัตโนมัติ — '
                            . 'การอัปโหลดไฟล์ยังปลอดภัยตามปกติ (ตรวจสอบไฟล์ตั้งแต่ขั้นอัปโหลดอยู่แล้ว)';
            }
        }

        /* ทดสอบ URL แบบซ่อน .php (/news แทน /news.php) แล้วเปิด/ปิดให้อัตโนมัติ
           ถ้าเซิร์ฟเวอร์ไม่รองรับ ระบบจะถอดกฎออกและใช้ .php ตามเดิม — ไม่ทำให้เว็บพัง */
        if (!$errors) {
            $scheme  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $selfUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $base;
            $pm = apply_pretty_urls(__DIR__, rtrim($selfUrl, '/'));
            if (strncmp($pm, '⚠', 3) === 0) $warnings[] = mb_substr($pm, 2);
        }

        /* โลโก้ (ถ้าอัปโหลด) — ตรวจ MIME ก่อนบันทึก
           ไม่ให้ล้มเหลวแบบเงียบ: ทุกเหตุที่อัปโหลดไม่สำเร็จต้องมีข้อความแจ้งเสมอ
           (เดิมล้มเงียบ ทำให้ติดตั้งเสร็จสวยงามแต่ไม่มีโลโก้ และไม่รู้สาเหตุ) */
        $logo_warn = '';
        if (!$errors && !empty($_FILES['logo']) && $_FILES['logo']['error'] !== UPLOAD_ERR_NO_FILE) {
            if ($_FILES['logo']['error'] !== UPLOAD_ERR_OK) {
                $logo_warn = 'อัปโหลดโลโก้ไม่สำเร็จ (รหัสข้อผิดพลาด ' . (int)$_FILES['logo']['error'] . ')';
            } else {
                $ext = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
                $ok = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
                if (!isset($ok[$ext])) {
                    $logo_warn = 'ไม่รองรับไฟล์โลโก้นามสกุล .' . $ext . ' (ใช้ได้: JPG, PNG, WEBP)';
                } elseif ($_FILES['logo']['size'] > 4 * 1048576) {
                    $logo_warn = 'ไฟล์โลโก้ใหญ่เกิน 4 MB';
                } else {
                    $finfo = new finfo(FILEINFO_MIME_TYPE);
                    $mime  = (string)$finfo->file($_FILES['logo']['tmp_name']);
                    if ($mime !== $ok[$ext]) {
                        $logo_warn = 'ไฟล์โลโก้ไม่ใช่รูปภาพจริงตามนามสกุล (ตรวจพบชนิดไฟล์: ' . $mime . ')';
                    } else {
                        $name = 'uploads/site/logo-' . bin2hex(random_bytes(6)) . '.' . $ext;
                        if (!@move_uploaded_file($_FILES['logo']['tmp_name'], __DIR__ . '/' . $name)) {
                            $logo_warn = 'บันทึกไฟล์โลโก้ลงเซิร์ฟเวอร์ไม่สำเร็จ — ตรวจสอบสิทธิ์เขียนของโฟลเดอร์ uploads/site (ต้องเป็น 755)';
                        } else {
                            $pdo->prepare('INSERT INTO settings (skey, sval) VALUES (?,?) ON DUPLICATE KEY UPDATE sval = VALUES(sval)')
                                ->execute(['logo', $name]);
                        }
                    }
                }
            }
            /* โลโก้อัปโหลดไม่สำเร็จ ไม่ใช่เหตุร้ายแรงพอจะหยุดการติดตั้ง — แจ้งเตือนแต่ให้ติดตั้งต่อได้
               (ตั้งค่าไอคอนแทนไปก่อน แล้วอัปโหลดใหม่ทีหลังในหน้า "ตั้งค่าเว็บไซต์" ได้) */
            if ($logo_warn !== '') $warnings[] = $logo_warn;
        }

        if (!$errors) {
            file_put_contents(__DIR__ . '/install.lock', 'installed: ' . date('c'));
            $done = true;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ติดตั้งระบบเว็บไซต์หน่วยงาน</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200&display=block" rel="stylesheet">
<link rel="stylesheet" href="theme-kit.css">
<style>
.wizard { max-width: 720px; margin: 40px auto; padding: 0 16px; }
.step-dots { display: flex; gap: 8px; justify-content: center; margin-bottom: 24px; }
.step-dots i { width: 36px; height: 6px; border-radius: 99px; background: rgba(15,23,42,.12); transition: background .3s; }
.step-dots i.on { background: var(--blue); }
.wstep { display: none; animation: fadeIn .35s ease-out; }
.wstep.show { display: block; }
.swatch-row { display: flex; gap: 10px; flex-wrap: wrap; }
.swatch-pick { display: flex; flex-direction: column; align-items: center; gap: 6px; cursor: pointer; font-size: 12px; color: var(--muted); }
.swatch-pick i { width: 44px; height: 44px; border-radius: 50%; border: 3px solid transparent; transition: transform .2s, border-color .2s; }
.swatch-pick input { display: none; }
.swatch-pick input:checked + i { border-color: var(--ink); transform: scale(1.12); }
.sec-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 8px; }
.sec-grid label { display: flex; align-items: center; gap: 8px; font-size: 14px; padding: 10px 12px; margin: 0;
  border: 1px solid var(--border); border-radius: 12px; background: rgba(255,255,255,.7); cursor: pointer; }
.sec-grid input { width: 17px; height: 17px; accent-color: var(--blue); }
@media (max-width: 560px) { .sec-grid { grid-template-columns: 1fr; } }
</style>
</head>
<body>
<div class="wizard">
  <div class="text-center mb-3">
    <?php /* ตรา AliceCMS — หน้านี้ยังไม่มีหน่วยงาน จึงเป็นตราของตัวระบบเอง ไม่ใช่ไอคอนอาคารราชการ
             วาดในหน้าเลยเพราะระหว่างติดตั้งยังไม่รู้ว่าเว็บวางอยู่ใต้โฟลเดอร์ไหน ลิงก์ไฟล์ภาพอาจหลุด */ ?>
    <div class="logo" style="margin:0 auto 12px;width:56px;height:56px;border-radius:18px;">
      <?php require __DIR__ . '/includes/mark.php'; ?>
    </div>
    <h1 style="font-size:clamp(24px,3vw,34px);">ติดตั้งระบบเว็บไซต์<span class="grad">หน่วยงานราชการ</span></h1>
    <p class="text-muted">ตั้งค่า 4 ขั้นตอน — พร้อมใช้งานทันที ไม่ต้องแก้โค้ด</p>
  </div>

  <?php if (!$done):
    /* ── ตรวจความพร้อมเซิร์ฟเวอร์ก่อนติดตั้ง ──
       แจ้งตั้งแต่แรกว่าขาดอะไร ดีกว่าติดตั้งผ่านแล้วไปพังตอนใช้งานจริง */
    $req_ext = [
        'pdo_mysql' => 'เชื่อมต่อฐานข้อมูล',
        'mbstring'  => 'ข้อความภาษาไทย',
        'gd'        => 'ย่อ/ครอบตัดรูปภาพ',
        'fileinfo'  => 'ตรวจชนิดไฟล์ที่อัปโหลด',
        'zip'       => 'อัปเดต / สำรอง / โอนย้ายเว็บ',
        'openssl'   => 'ตรวจลายเซ็นแพ็กเกจอัปเดต',
    ];
    $pre = [];
    $pre[] = ['PHP ' . PHP_VERSION, version_compare(PHP_VERSION, '8.0.0', '>='), 'ต้องเป็น PHP 8.0 ขึ้นไป'];
    foreach ($req_ext as $ex => $why) $pre[] = ['ส่วนขยาย ' . $ex, extension_loaded($ex), $why];
    /* ทดสอบเขียนไฟล์จริงในโฟลเดอร์เว็บ (ต้องเขียน config.php และสร้างโฟลเดอร์ได้) */
    $probe = __DIR__ . '/.wtest-' . substr(md5((string)mt_rand()), 0, 6);
    $can_write_root = @file_put_contents($probe, 'x') !== false;
    if ($can_write_root) @unlink($probe);
    $pre[] = ['สิทธิ์เขียนไฟล์ในโฟลเดอร์เว็บ', $can_write_root, 'ต้องตั้งสิทธิ์โฟลเดอร์เป็น 755'];
    $pre[] = ['เปิดให้อัปโหลดไฟล์ (file_uploads)', (bool)ini_get('file_uploads'), 'ต้องเปิดใน php.ini'];
    $pre_fail = array_values(array_filter($pre, fn($r) => !$r[1]));
  ?>
    <?php if ($pre_fail): ?>
    <div class="card mb-3" style="border:2px solid var(--danger);">
      <div class="flex items-center gap-1 mb-1">
        <span class="material-symbols-rounded" style="color:var(--danger);">error</span>
        <b style="color:var(--ink);">เซิร์ฟเวอร์ยังไม่พร้อม — พบ <?= count($pre_fail) ?> รายการที่ต้องแก้ก่อน</b>
      </div>
      <p class="text-muted" style="font-size:13px;margin-top:0;">ติดตั้งต่อได้ แต่ระบบบางส่วนจะใช้งานไม่ได้ แนะนำให้แก้ให้ครบก่อน</p>
      <table style="width:100%;border-collapse:collapse;font-size:13.5px;">
        <?php foreach ($pre as [$label, $ok, $why]): ?>
        <tr style="border-bottom:1px solid rgba(0,0,0,.06);">
          <td style="padding:6px 4px;width:26px;">
            <span class="material-symbols-rounded" style="font-size:19px;color:<?= $ok ? 'var(--success)' : 'var(--danger)' ?>;"><?= $ok ? 'check_circle' : 'cancel' ?></span>
          </td>
          <td style="padding:6px 4px;"><?= htmlspecialchars($label) ?></td>
          <td style="padding:6px 4px;color:var(--muted);"><?= $ok ? '' : htmlspecialchars($why) ?></td>
        </tr>
        <?php endforeach; ?>
      </table>
      <p class="text-muted" style="font-size:12.5px;margin-bottom:0;">
        แก้ได้ที่แผงควบคุมโฮสต์ (เช่น DirectAdmin → Select PHP Version) หรือแจ้งผู้ให้บริการ
      </p>
    </div>
    <?php else: ?>
    <div class="alert success mb-3" style="font-size:13.5px;">
      <span class="material-symbols-rounded">check_circle</span>
      <div>ตรวจความพร้อมเซิร์ฟเวอร์แล้ว — ครบทุกอย่าง พร้อมติดตั้ง</div>
    </div>
    <?php endif; ?>
  <?php endif; ?>

  <?php if ($done): ?>
  <div class="card card-spacious text-center">
    <span class="material-symbols-rounded" style="font-size:72px;color:var(--success);">check_circle</span>
    <h2>ติดตั้งสำเร็จ!</h2>
    <p class="text-muted">ระบบพร้อมใช้งานแล้ว — เข้าสู่ระบบจัดการเพื่อเพิ่มข้อมูล หรือดูหน้าเว็บได้เลย</p>
    <div class="flex gap-1 justify-center mt-2" style="flex-wrap:wrap;">
      <a class="btn primary large" href="admin/login.php"><span class="material-symbols-rounded">admin_panel_settings</span>เข้าสู่ระบบจัดการ</a>
      <a class="btn large" href="index.php"><span class="material-symbols-rounded">home</span>ดูหน้าเว็บ</a>
    </div>
    <?php if ($warnings): ?>
    <div class="alert warning mt-3" style="text-align:left;">
      <span class="material-symbols-rounded">warning</span>
      <div style="font-size:13px;"><b>มีบางรายการที่ต้องแก้ไขภายหลัง:</b>
        <?php foreach ($warnings as $w): ?><div>• <?= htmlspecialchars($w, ENT_QUOTES, 'UTF-8') ?></div><?php endforeach; ?>
        <div class="text-muted" style="margin-top:4px;">แก้ไขได้ที่เมนู "ตั้งค่าเว็บไซต์" หลังเข้าสู่ระบบ</div>
      </div>
    </div>
    <?php endif; ?>
    <div class="alert warning mt-3" style="text-align:left;">
      <span class="material-symbols-rounded">warning</span>
      <div style="font-size:13px;"><b>เพื่อความปลอดภัย:</b> ไฟล์ install.lock ถูกสร้างแล้วเพื่อป้องกันการติดตั้งซ้ำ — อย่าลบไฟล์นี้ และห้ามเผยแพร่ข้อมูลใน config.php</div>
    </div>
  </div>
  <?php else: ?>

  <?php if ($errors): ?>
  <div class="alert danger"><span class="material-symbols-rounded">error</span>
    <div><?php foreach ($errors as $er): ?><div><?= htmlspecialchars($er, ENT_QUOTES, 'UTF-8') ?></div><?php endforeach; ?></div>
  </div>
  <?php endif; ?>

  <div class="step-dots"><i class="on"></i><i></i><i></i><i></i></div>

  <form method="post" action="" enctype="multipart/form-data" id="wizard-form">
    <!-- ขั้น 1: ฐานข้อมูล -->
    <div class="wstep show card card-spacious" data-step="1">
      <span class="tag"><span class="material-symbols-rounded icon-sm">database</span> STEP 1/4</span>
      <h3>เชื่อมต่อฐานข้อมูล MySQL</h3>
      <p class="text-muted" style="font-size:13px;">XAMPP มาตรฐาน: host = localhost, user = root, รหัสผ่านเว้นว่าง — ถ้ายังไม่มีฐานข้อมูล ระบบจะสร้างให้อัตโนมัติ</p>
      <div class="grid grid-2" style="gap:12px;">
        <div><label>Database Host</label><input type="text" name="db_host" value="<?= htmlspecialchars($_POST['db_host'] ?? 'localhost') ?>" required></div>
        <div><label>Database Name</label><input type="text" name="db_name" value="<?= htmlspecialchars($_POST['db_name'] ?? 'gov_website') ?>" required></div>
        <div><label>Username</label><input type="text" name="db_user" value="<?= htmlspecialchars($_POST['db_user'] ?? 'root') ?>" required></div>
        <div><label>Password</label><input type="password" name="db_pass" value="<?= htmlspecialchars($_POST['db_pass'] ?? '') ?>"></div>
      </div>
      <div class="flex justify-between mt-3">
        <span></span>
        <button class="btn primary" type="button" data-next>ถัดไป <span class="material-symbols-rounded icon-sm">arrow_forward</span></button>
      </div>
    </div>

    <!-- ขั้น 2: ข้อมูลหน่วยงาน + โลโก้ -->
    <div class="wstep card card-spacious" data-step="2">
      <span class="tag"><span class="material-symbols-rounded icon-sm">apartment</span> STEP 2/4</span>
      <h3>ข้อมูลหน่วยงาน</h3>
      <label>ชื่อหน่วยงาน <span style="color:var(--danger);">*</span></label>
      <input type="text" name="site_name" value="<?= htmlspecialchars($_POST['site_name'] ?? '') ?>" placeholder="เช่น กองวิชาการ / เทศบาลตำบล... / โรงเรียน..." class="mb-2">
      <label>สังกัด (ไม่บังคับ)</label>
      <input type="text" name="site_dept" value="<?= htmlspecialchars($_POST['site_dept'] ?? '') ?>" placeholder="เช่น สำนักงานตำรวจแห่งชาติ / กรม... / กระทรวง..." class="mb-2">
      <label>โลโก้หน่วยงาน (ไม่บังคับ — อัปโหลดทีหลังได้)</label>
      <input type="file" name="logo" accept=".jpg,.jpeg,.png,.webp" style="padding:10px;">
      <div class="flex justify-between mt-3">
        <button class="btn" type="button" data-prev><span class="material-symbols-rounded icon-sm">arrow_back</span> ย้อนกลับ</button>
        <button class="btn primary" type="button" data-next>ถัดไป <span class="material-symbols-rounded icon-sm">arrow_forward</span></button>
      </div>
    </div>

    <!-- ขั้น 3: ธีมสี + section -->
    <div class="wstep card card-spacious" data-step="3">
      <span class="tag"><span class="material-symbols-rounded icon-sm">palette</span> STEP 3/4</span>
      <h3>ธีมสีและส่วนแสดงผลหน้าแรก</h3>
      <label>เลือกสีธีม (เปลี่ยนทีหลังได้)</label>
      <div class="swatch-row mb-3">
        <?php foreach ($presets as $i => [$name, $c, $d]): ?>
        <label class="swatch-pick">
          <input type="radio" name="theme_color" value="<?= $c ?>" <?= $i === 0 ? 'checked' : '' ?>>
          <i style="background:<?= $c ?>;"></i><?= $name ?>
        </label>
        <?php endforeach; ?>
      </div>
      <label>เปิดใช้ section บนหน้าแรก (ปิด/เปิด/เรียงใหม่ทีหลังได้ใน admin)</label>
      <div class="sec-grid">
        <?php foreach ($all_sections as $key => $label): ?>
        <label><input type="checkbox" name="sections[]" value="<?= $key ?>" checked><?= $label ?></label>
        <?php endforeach; ?>
      </div>
      <div class="flex justify-between mt-3">
        <button class="btn" type="button" data-prev><span class="material-symbols-rounded icon-sm">arrow_back</span> ย้อนกลับ</button>
        <button class="btn primary" type="button" data-next>ถัดไป <span class="material-symbols-rounded icon-sm">arrow_forward</span></button>
      </div>
    </div>

    <!-- ขั้น 4: บัญชี admin -->
    <div class="wstep card card-spacious" data-step="4">
      <span class="tag"><span class="material-symbols-rounded icon-sm">admin_panel_settings</span> STEP 4/4</span>
      <h3>สร้างบัญชีผู้ดูแลระบบ</h3>
      <label>ชื่อผู้ใช้ <span style="color:var(--danger);">*</span></label>
      <input type="text" name="admin_user" value="<?= htmlspecialchars($_POST['admin_user'] ?? '') ?>" placeholder="4–50 ตัวอักษร (อังกฤษ/ตัวเลข)" class="mb-2" autocomplete="username">
      <div class="grid grid-2" style="gap:12px;">
        <div><label>รหัสผ่าน <span style="color:var(--danger);">*</span></label>
          <input type="password" name="admin_pass" placeholder="อย่างน้อย 8 ตัวอักษร" autocomplete="new-password"></div>
        <div><label>ยืนยันรหัสผ่าน <span style="color:var(--danger);">*</span></label>
          <input type="password" name="admin_pass2" autocomplete="new-password"></div>
      </div>
      <div class="alert info mt-2" style="font-size:13px;">
        <span class="material-symbols-rounded">shield</span>
        <div>รหัสผ่านถูกเข้ารหัสด้วย bcrypt — เข้าระบบจัดการได้ที่ <b>/admin</b> เท่านั้น ไม่มีลิงก์บนหน้าเว็บ</div>
      </div>
      <div class="flex justify-between mt-3">
        <button class="btn" type="button" data-prev><span class="material-symbols-rounded icon-sm">arrow_back</span> ย้อนกลับ</button>
        <button class="btn primary large" type="submit"><span class="material-symbols-rounded">rocket_launch</span>ติดตั้งระบบ</button>
      </div>
    </div>
  </form>
  <?php endif; ?>
</div>

<script src="assets/js/install.js"></script>
</body>
</html>
