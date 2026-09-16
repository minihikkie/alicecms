<?php
/**
 * migrations.php — โครงสร้างฐานข้อมูลทั้งหมด (idempotent รันซ้ำได้ปลอดภัย)
 * เรียกได้ทั้งจาก migrate.php (CLI) และจากระบบอัปเดตอัตโนมัติในหน้า admin
 *
 * run_migrations(PDO $pdo, ?callable $log): รัน migration ทั้งหมด
 *   $log(string $msg) — ฟังก์ชันรับข้อความ progress (ถ้าไม่ส่งจะไม่พิมพ์อะไร)
 */
if (!function_exists('run_migrations')) {

/** เพิ่มคอลัมน์ถ้ายังไม่มี */
function migr_add_col(PDO $pdo, string $table, string $col, string $ddl, callable $log): void {
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns
                         WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
    $st->execute([$table, $col]);
    if ((int)$st->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE `$table` ADD COLUMN $ddl");
        $log("+ $table.$col");
    } else {
        $log("= $table.$col (มีอยู่แล้ว)");
    }
}

function run_migrations(PDO $pdo, ?callable $log = null): void {
    $log = $log ?: function ($m) {};

    /* เฟส 1 */
    migr_add_col($pdo, 'complaints', 'ref_code',     "ref_code VARCHAR(20) NULL UNIQUE AFTER id", $log);
    migr_add_col($pdo, 'complaints', 'response',     "response TEXT NULL", $log);
    migr_add_col($pdo, 'complaints', 'responded_at', "responded_at DATETIME NULL", $log);
    migr_add_col($pdo, 'documents',  'downloads',    "downloads INT NOT NULL DEFAULT 0", $log);

    /* เฟส 2 */
    migr_add_col($pdo, 'users', 'role', "role ENUM('admin','editor') NOT NULL DEFAULT 'admin'", $log);
    $pdo->exec("UPDATE users SET role='admin' WHERE role IS NULL OR role=''");
    $pdo->exec("CREATE TABLE IF NOT EXISTS activity_logs (
      id INT AUTO_INCREMENT PRIMARY KEY,
      user_id INT NULL,
      username VARCHAR(100) NOT NULL DEFAULT '',
      action VARCHAR(100) NOT NULL,
      detail VARCHAR(255) NOT NULL DEFAULT '',
      ip VARCHAR(45) NOT NULL DEFAULT '',
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $log("= activity_logs (ตาราง)");

    /* เฟส 3 */
    migr_add_col($pdo, 'posts', 'video_url', "video_url VARCHAR(300) NULL AFTER attachment_size", $log);

    /* โลโก้ลิงก์หน่วยงาน */
    migr_add_col($pdo, 'links', 'image', "image VARCHAR(300) NULL AFTER icon", $log);

    /* ตัวจัดสไลด์มืออาชีพ */
    migr_add_col($pdo, 'slides', 'focus_x',     "focus_x TINYINT NOT NULL DEFAULT 50", $log);
    migr_add_col($pdo, 'slides', 'focus_y',     "focus_y TINYINT NOT NULL DEFAULT 50", $log);
    migr_add_col($pdo, 'slides', 'text_align',  "text_align VARCHAR(10) NOT NULL DEFAULT 'center'", $log);
    migr_add_col($pdo, 'slides', 'text_valign', "text_valign VARCHAR(10) NOT NULL DEFAULT 'middle'", $log);
    migr_add_col($pdo, 'slides', 'text_theme',  "text_theme VARCHAR(10) NOT NULL DEFAULT 'light'", $log);
    migr_add_col($pdo, 'slides', 'overlay',     "overlay TINYINT NOT NULL DEFAULT 35", $log);

    /* v1.1.0 — ตัวจัดสไลด์จัดเต็ม: ลูกเล่นภาพ + แต่งข้อความ + ปุ่ม/ไล่สี */
    migr_add_col($pdo, 'slides', 'kenburns',    "kenburns TINYINT(1) NOT NULL DEFAULT 0", $log);
    migr_add_col($pdo, 'slides', 'duration',    "duration SMALLINT NOT NULL DEFAULT 0", $log);
    migr_add_col($pdo, 'slides', 'title_size',  "title_size VARCHAR(8) NOT NULL DEFAULT 'md'", $log);
    migr_add_col($pdo, 'slides', 'text_color',  "text_color VARCHAR(7) NOT NULL DEFAULT ''", $log);
    migr_add_col($pdo, 'slides', 'text_shadow', "text_shadow TINYINT(1) NOT NULL DEFAULT 1", $log);
    migr_add_col($pdo, 'slides', 'btn_style',   "btn_style VARCHAR(8) NOT NULL DEFAULT 'solid'", $log);
    migr_add_col($pdo, 'slides', 'btn_color',   "btn_color VARCHAR(7) NOT NULL DEFAULT ''", $log);
    migr_add_col($pdo, 'slides', 'link_text2',  "link_text2 VARCHAR(100) NOT NULL DEFAULT ''", $log);
    migr_add_col($pdo, 'slides', 'link_url2',   "link_url2 VARCHAR(500) NOT NULL DEFAULT ''", $log);
    migr_add_col($pdo, 'slides', 'grad_from',   "grad_from VARCHAR(7) NOT NULL DEFAULT ''", $log);
    migr_add_col($pdo, 'slides', 'grad_to',     "grad_to VARCHAR(7) NOT NULL DEFAULT ''", $log);
    $sset = $pdo->prepare('INSERT IGNORE INTO settings (skey, sval) VALUES (?, ?)');
    $sset->execute(['slider_interval', '6000']);
    $sset->execute(['slider_transition', 'fade']);
    $log("= settings (slider_interval, slider_transition)");

    /* v1.3.0 — หน้าเพจกำหนดเอง + เมนูนำทาง + แถบประกาศด่วน */
    $pdo->exec("CREATE TABLE IF NOT EXISTS pages (
      id INT AUTO_INCREMENT PRIMARY KEY,
      slug VARCHAR(120) NOT NULL UNIQUE,
      title VARCHAR(250) NOT NULL,
      body MEDIUMTEXT,
      status ENUM('draft','published') NOT NULL DEFAULT 'published',
      sort_order INT NOT NULL DEFAULT 0,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $log("= pages (ตาราง)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS menu_items (
      id INT AUTO_INCREMENT PRIMARY KEY,
      label VARCHAR(120) NOT NULL,
      url VARCHAR(500) NOT NULL,
      sort_order INT NOT NULL DEFAULT 0,
      new_tab TINYINT(1) NOT NULL DEFAULT 0,
      enabled TINYINT(1) NOT NULL DEFAULT 1
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $log("= menu_items (ตาราง)");
    /* แถบประกาศด่วน (settings) */
    $sset->execute(['alert_enabled', '0']);
    $sset->execute(['alert_text', '']);
    $sset->execute(['alert_link', '']);
    $sset->execute(['alert_style', 'urgent']);
    $log("= settings (แถบประกาศด่วน)");

    /* หน้านโยบายความเป็นส่วนตัว (PDPA) เริ่มต้น — แก้ไขได้ในเมนูหน้าเพจ */
    $privacy = "หน่วยงานให้ความสำคัญกับการคุ้มครองข้อมูลส่วนบุคคลของท่าน ตามพระราชบัญญัติคุ้มครองข้อมูลส่วนบุคคล พ.ศ. 2562 (PDPA)\n\n"
        . "1. ข้อมูลที่เก็บรวบรวม\n"
        . "เว็บไซต์อาจเก็บข้อมูลที่ท่านกรอกผ่านแบบฟอร์ม (เช่น การร้องเรียน) และข้อมูลการเข้าใช้งานโดยใช้คุกกี้เพื่อพัฒนาบริการ\n\n"
        . "2. วัตถุประสงค์\n"
        . "เพื่อให้บริการข้อมูลข่าวสาร ตอบข้อร้องเรียน และปรับปรุงคุณภาพเว็บไซต์ โดยไม่เปิดเผยต่อบุคคลภายนอกเว้นแต่ที่กฎหมายกำหนด\n\n"
        . "3. สิทธิของเจ้าของข้อมูล\n"
        . "ท่านมีสิทธิขอเข้าถึง แก้ไข ลบ หรือคัดค้านการประมวลผลข้อมูลส่วนบุคคลของท่านได้ โดยติดต่อหน่วยงานผ่านช่องทางที่ประกาศไว้\n\n"
        . "4. คุกกี้ (Cookies)\n"
        . "เว็บไซต์ใช้คุกกี้ที่จำเป็นต่อการทำงานและคุกกี้เพื่อวิเคราะห์การใช้งาน ท่านสามารถปฏิเสธได้ผ่านแถบความยินยอมด้านล่างของเว็บ\n\n"
        . "หากมีข้อสงสัยเกี่ยวกับนโยบายนี้ กรุณาติดต่อหน่วยงานผ่านหน้า \"ติดต่อเรา\"";
    $pdo->prepare("INSERT IGNORE INTO pages (slug, title, body, status, sort_order) VALUES ('privacy', ?, ?, 'published', 99)")
        ->execute(['นโยบายความเป็นส่วนตัว (PDPA)', $privacy]);
    $log("= pages.privacy (PDPA เริ่มต้น)");

    /* v1.11.0 — Block Builder (สร้างหน้าด้วยบล็อก) */
    migr_add_col($pdo, 'pages', 'blocks', "blocks MEDIUMTEXT NULL AFTER body", $log);

    /* v1.13.0 — Custom Content Types (สร้างประเภทเนื้อหาเอง) */
    $pdo->exec("CREATE TABLE IF NOT EXISTS content_types (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $log("= content_types (ตาราง)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS content_items (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $log("= content_items (ตาราง)");

    /* v1.12.0 — Form Builder (e-Service) */
    $pdo->exec("CREATE TABLE IF NOT EXISTS forms (
      id INT AUTO_INCREMENT PRIMARY KEY,
      slug VARCHAR(120) NOT NULL UNIQUE,
      title VARCHAR(200) NOT NULL,
      description VARCHAR(500) NOT NULL DEFAULT '',
      fields MEDIUMTEXT NULL,
      notify_email VARCHAR(150) NOT NULL DEFAULT '',
      success_msg VARCHAR(500) NOT NULL DEFAULT '',
      status ENUM('open','closed') NOT NULL DEFAULT 'open',
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $log("= forms (ตาราง)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS form_responses (
      id INT AUTO_INCREMENT PRIMARY KEY,
      form_id INT NOT NULL,
      data MEDIUMTEXT NOT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_form (form_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $log("= form_responses (ตาราง)");

    /* v1.10.0 — คลังสื่อกลาง (Media Library) */
    $pdo->exec("CREATE TABLE IF NOT EXISTS media (
      id INT AUTO_INCREMENT PRIMARY KEY,
      path VARCHAR(300) NOT NULL,
      orig VARCHAR(200) NOT NULL DEFAULT '',
      mime VARCHAR(100) NOT NULL DEFAULT '',
      size INT NOT NULL DEFAULT 0,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $log("= media (ตาราง)");

    /* v1.8.0 — เมนูนำทางขั้นสูง: เมนูย่อย + โหมดคุมเมนูเอง */
    migr_add_col($pdo, 'menu_items', 'parent_id', "parent_id INT NULL AFTER id", $log);
    $pdo->prepare('INSERT IGNORE INTO settings (skey, sval) VALUES (?, ?)')->execute(['nav_custom', '0']);
    $log("= settings (nav_custom)");

    /* v1.4.0 — ใช้ลิงก์ภายนอก (Google Drive ฯลฯ) แทนการอัปโหลดไฟล์จริง */
    migr_add_col($pdo, 'documents',    'ext_url', "ext_url VARCHAR(500) NOT NULL DEFAULT '' AFTER file", $log);
    migr_add_col($pdo, 'procurements', 'ext_url', "ext_url VARCHAR(500) NOT NULL DEFAULT '' AFTER file", $log);
    /* documents.file เดิม NOT NULL ไม่มี default — ผ่อนให้ว่างได้เมื่อใช้ลิงก์ภายนอก */
    try { $pdo->exec("ALTER TABLE documents MODIFY file VARCHAR(300) NOT NULL DEFAULT ''"); $log("~ documents.file (อนุญาตค่าว่าง)"); } catch (Throwable $e) {}

    /* โครงสร้างผู้บริหาร/บุคลากร */
    $pdo->exec("CREATE TABLE IF NOT EXISTS personnel (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $log("= personnel (ตาราง)");
    $mx = (int)$pdo->query('SELECT COALESCE(MAX(sort_order),0) FROM sections')->fetchColumn();
    $pdo->prepare("INSERT IGNORE INTO sections (skey, enabled, sort_order, custom_title) VALUES ('personnel', 0, ?, '')")->execute([$mx + 1]);
    $log("= sections.personnel");

    $pdo->exec("CREATE TABLE IF NOT EXISTS post_attachments (
      id INT AUTO_INCREMENT PRIMARY KEY,
      post_id INT NOT NULL,
      name VARCHAR(200) NOT NULL DEFAULT '',
      file VARCHAR(300) NOT NULL,
      file_size INT NOT NULL DEFAULT 0,
      ext VARCHAR(10) NOT NULL DEFAULT '',
      INDEX idx_post (post_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $log("= post_attachments (ตาราง)");

    /* แยกสวิตช์ "หน้าแรก" กับ "เมนู" ออกจากกัน — เพิ่ม sections.in_menu
       backfill = enabled ครั้งเดียว เพื่อคงพฤติกรรมเดิม (เมนูโผล่ตาม section ที่เปิด)
       จากนั้น personnel ปรับให้หน้าแรก=ปิดเสมอ (เข้าผ่านเมนูเท่านั้น) ส่วนการแสดงเมนูคุมด้วย in_menu */
    $sec_has_inmenu = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'sections' AND column_name = 'in_menu'")->fetchColumn();
    if (!$sec_has_inmenu) {
        $pdo->exec("ALTER TABLE sections ADD COLUMN in_menu TINYINT(1) NOT NULL DEFAULT 1 AFTER enabled");
        $pdo->exec("UPDATE sections SET in_menu = enabled");
        $pdo->exec("UPDATE sections SET enabled = 0 WHERE skey = 'personnel'");
        $log("+ sections.in_menu (backfill = enabled, personnel→เมนูอย่างเดียว)");
    } else {
        $log("= sections.in_menu (มีอยู่แล้ว)");
    }

    /* ถังขยะ — เก็บสำเนาข้อมูลที่ถูกลบไว้กู้คืนได้ (ดู includes/trash.php) */
    $pdo->exec("CREATE TABLE IF NOT EXISTS trash (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $log("= trash (ตาราง)");

    /* ── ลบไฟล์ที่เลิกใช้แล้ว ──
       ตัวอัปเดตคัดลอกทับอย่างเดียว ไม่ลบไฟล์ที่ถูกถอดออกจากระบบ
       ถ้าไม่ลบ ไฟล์เก่าจะค้างและยังเรียกใช้ได้ กลายเป็นทางเข้าซ้ำซ้อน
       รายการนี้รันซ้ำได้ปลอดภัย (ลบเฉพาะที่ยังมีอยู่) */
    $retired = [
        /* v1.21.1: เปลี่ยนชื่อเป็น admin/site-info.php
           เพราะไฟร์วอลล์ (ModSecurity/Imunify360) ของโฮสต์บางเจ้าบล็อกไฟล์ชื่อ settings.php อัตโนมัติ */
        'admin/settings.php',
        /* v1.23.4: เปลี่ยนชื่อเป็น admin/exec-message.php — เจอ WAF บล็อกไฟล์ chief.php แบบเดียวกัน
           (Error ID/หน้าบล็อกฟอร์แมตเดียวกับที่เคยเจอกับ settings.php บนเว็บ tsd.police.go.th) */
        'admin/chief.php',
    ];
    foreach ($retired as $rel) {
        $p = APP_ROOT . '/' . $rel;
        if (is_file($p) && @unlink($p)) $log("- ลบไฟล์ที่เลิกใช้: $rel");
    }

    /* ── ซ่อม uploads/.htaccess รุ่นเก่าที่เป็นบั๊ก (v1.22.1 และก่อนหน้า) ──
       เดิมใช้ "Require all denied" คู่กับ "<FilesMatch> Require all granted </FilesMatch>"
       เพื่อปิดทุกไฟล์แล้วเปิดเฉพาะนามสกุลรูป/เอกสาร — แต่บนเซิร์ฟเวอร์บางแบบ (AllowOverride ไม่ครอบคลุม
       AuthConfig) ชุดคำสั่งนี้ทำให้ไฟล์ทุกไฟล์ในโฟลเดอร์ uploads/ เปิดไม่ได้เลย ขึ้น 500 Internal Server Error
       (พบจริงกับกรณีอัปโหลดโลโก้ไม่ขึ้น — ไฟล์ถูกบันทึกสำเร็จแต่เปิดจาก URL ไม่ได้)
       ensure_hardening_files() ปกติจะไม่ทับไฟล์ที่มีอยู่แล้ว (กันไปทับที่แอดมินแก้เอง) จึงต้องซ่อมเฉพาะจุดนี้ตรงๆ
       ตรวจก่อนว่าเป็นไฟล์รุ่นเก่าจริง (มีลายเซ็นคำสั่งที่เป็นบั๊ก) ถึงจะทับ — ไม่แตะไฟล์ที่แอดมินแก้เอง */
    $upHt = APP_ROOT . '/uploads/.htaccess';
    if (is_file($upHt)) {
        $cur = (string)@file_get_contents($upHt);
        $isBuggyOld = str_contains($cur, 'Require all denied') && str_contains($cur, 'FilesMatch');
        if ($isBuggyOld) {
            require_once APP_ROOT . '/includes/hardening.php';
            $fresh = hardening_files()['uploads/.htaccess'] ?? '';
            if ($fresh !== '' && @file_put_contents($upHt, $fresh) !== false) {
                $log('~ ซ่อม uploads/.htaccess (รุ่นเก่าทำให้ไฟล์อัปโหลดเปิดไม่ได้บนบางเซิร์ฟเวอร์)');
            } else {
                $log('⚠ พบ uploads/.htaccess รุ่นเก่าที่เป็นบั๊ก แต่ซ่อมอัตโนมัติไม่ได้ (เขียนไฟล์ไม่ได้) — ตรวจสอบสิทธิ์เขียนของไฟล์นี้');
            }
        }
    }

    /* ดัชนีเพิ่มประสิทธิภาพ — query หน้าแรก/หน้ารวมข่าวที่ไม่ระบุประเภท ใช้ idx_type_status ไม่ได้
       (คอลัมน์นำคือ type) จึงต้องมีดัชนีที่ขึ้นต้นด้วย status */
    $add_index = function (string $table, string $name, string $cols) use ($pdo, $log) {
        try {
            $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.statistics
                                 WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?");
            $st->execute([$table, $name]);
            if ((int)$st->fetchColumn() === 0) {
                $pdo->exec("ALTER TABLE `$table` ADD INDEX `$name` ($cols)");
                $log("+ index $table.$name");
            } else {
                $log("= index $table.$name (มีอยู่แล้ว)");
            }
        } catch (Throwable $e) { $log("⚠ สร้าง index $table.$name ไม่ได้: " . $e->getMessage()); }
    };
    $add_index('posts',         'idx_status_pub',  'status, published_at');
    $add_index('procurements',  'idx_ptype_date',  'ptype, pdate');
    $add_index('documents',     'idx_cat_created', 'category_id, created_at');
    $add_index('complaints',    'idx_status_date', 'status, created_at');
    $add_index('ita_items',     'idx_fy_grp',      'fiscal_year, grp, sort_order');

    /* robots.txt — ประกาศตำแหน่ง sitemap ให้ Search Engine (ใช้ URL จริงของเว็บ)
       เขียนเฉพาะเมื่อยังไม่มีบรรทัดนี้ และรู้ที่อยู่เว็บจริงแล้วเท่านั้น */
    $robots = APP_ROOT . '/robots.txt';
    if (is_file($robots) && is_writable($robots)) {
        $rc = (string)file_get_contents($robots);
        if (stripos($rc, 'Sitemap:') === false) {
            $base = defined('BASE_URL') ? rtrim((string)BASE_URL, '/') : '';
            if ($base === '' && !empty($_SERVER['HTTP_HOST'])) {
                $scheme = (function_exists('is_https') && is_https()) ? 'https' : 'http';
                $base = $scheme . '://' . $_SERVER['HTTP_HOST'];
            }
            if ($base !== '' && preg_match('#^https?://#i', $base)) {
                file_put_contents($robots, rtrim($rc) . "\n\nSitemap: $base/sitemap.php\n");
                $log('+ robots.txt (ประกาศ Sitemap)');
            }
        }
    }

    /* หน้านโยบายที่เว็บไซต์หน่วยงานภาครัฐต้องมี — สร้างเป็นหน้าเพจที่แก้ไขเนื้อหาได้เองในแอดมิน
       สร้างครั้งเดียว ถ้าผู้ดูแลลบหรือแก้ไปแล้วจะไม่สร้างซ้ำ */
    if ((int)($pdo->query("SELECT COUNT(*) FROM settings WHERE skey = 'policy_pages_seeded'")->fetchColumn()) === 0) {
        $site = (string)($pdo->query("SELECT sval FROM settings WHERE skey='site_name'")->fetchColumn() ?: 'หน่วยงาน');
        $policies = [
            ['privacy-policy', 'นโยบายคุ้มครองข้อมูลส่วนบุคคล',
             "$site ให้ความสำคัญกับการคุ้มครองข้อมูลส่วนบุคคลของท่าน ตามพระราชบัญญัติคุ้มครองข้อมูลส่วนบุคคล พ.ศ. 2562\n\n"
             . "1) ข้อมูลที่เก็บรวบรวม\nเราเก็บข้อมูลเท่าที่จำเป็น เช่น ชื่อ-นามสกุล ที่อยู่ อีเมล หมายเลขโทรศัพท์ และรายละเอียดเรื่องที่ท่านแจ้ง เมื่อท่านกรอกแบบฟอร์มบนเว็บไซต์\n\n"
             . "2) วัตถุประสงค์\nใช้เพื่อติดต่อกลับ ดำเนินการตามคำขอ และปรับปรุงการให้บริการเท่านั้น ไม่นำไปใช้เพื่อการค้าหรือเปิดเผยแก่บุคคลภายนอกโดยไม่ได้รับความยินยอม เว้นแต่กฎหมายกำหนด\n\n"
             . "3) ระยะเวลาเก็บรักษา\nเก็บไว้เท่าที่จำเป็นตามวัตถุประสงค์ หรือไม่เกินระยะเวลาที่กฎหมายกำหนด เมื่อพ้นกำหนดจะลบหรือทำให้ไม่สามารถระบุตัวบุคคลได้\n\n"
             . "4) สิทธิของเจ้าของข้อมูล\nท่านมีสิทธิขอเข้าถึง ขอสำเนา ขอแก้ไขให้ถูกต้อง ขอลบ ขอระงับการใช้ คัดค้านการประมวลผล และถอนความยินยอมได้ โดยติดต่อผ่านหน้า \"ติดต่อหน่วยงาน\"\n\n"
             . "5) ความปลอดภัย\nเว็บไซต์มีมาตรการทางเทคนิคและการบริหารจัดการที่เหมาะสมเพื่อป้องกันการเข้าถึง เปลี่ยนแปลง หรือเปิดเผยข้อมูลโดยมิชอบ\n\n"
             . "หมายเหตุ: กรุณาปรับข้อความให้ตรงกับการดำเนินงานจริงของหน่วยงาน และระบุผู้ควบคุมข้อมูลส่วนบุคคล (DPO) พร้อมช่องทางติดต่อ"],
            ['website-policy', 'นโยบายเว็บไซต์',
             "นโยบายการใช้งานเว็บไซต์ $site\n\n"
             . "1) วัตถุประสงค์\nเว็บไซต์นี้จัดทำขึ้นเพื่อเผยแพร่ข้อมูลข่าวสาร ให้บริการประชาชน และเปิดเผยข้อมูลสาธารณะของหน่วยงาน\n\n"
             . "2) ลิขสิทธิ์\nเนื้อหาบนเว็บไซต์เป็นลิขสิทธิ์ของหน่วยงาน สามารถนำไปใช้เพื่อประโยชน์สาธารณะได้โดยอ้างอิงแหล่งที่มา ห้ามนำไปใช้เพื่อการค้าโดยไม่ได้รับอนุญาต\n\n"
             . "3) ความรับผิดชอบ\nหน่วยงานพยายามปรับปรุงข้อมูลให้ถูกต้องและเป็นปัจจุบัน แต่ไม่รับผิดชอบต่อความเสียหายที่เกิดจากการนำข้อมูลไปใช้ กรณีข้อมูลคลาดเคลื่อนโปรดแจ้งเจ้าหน้าที่\n\n"
             . "4) การเชื่อมโยงเว็บไซต์ภายนอก\nลิงก์ไปยังเว็บไซต์อื่นมีไว้เพื่ออำนวยความสะดวก หน่วยงานไม่มีส่วนรับผิดชอบต่อเนื้อหาของเว็บไซต์เหล่านั้น\n\n"
             . "5) การปรับปรุงนโยบาย\nหน่วยงานอาจปรับปรุงนโยบายนี้ตามความเหมาะสม โดยจะประกาศบนหน้านี้"],
            ['security-policy', 'นโยบายการรักษาความมั่นคงปลอดภัยเว็บไซต์',
             "$site ให้ความสำคัญกับความมั่นคงปลอดภัยของเว็บไซต์และข้อมูลของผู้ใช้บริการ\n\n"
             . "1) การควบคุมการเข้าถึง\nระบบจัดการเว็บไซต์กำหนดสิทธิ์ตามบทบาทหน้าที่ ใช้รหัสผ่านที่เข้ารหัส และรองรับการยืนยันตัวตนสองชั้น (2FA)\n\n"
             . "2) การบันทึกการใช้งาน\nระบบบันทึกการเข้าใช้งานและการเปลี่ยนแปลงข้อมูลสำคัญ เพื่อการตรวจสอบย้อนหลัง\n\n"
             . "3) การสำรองข้อมูล\nมีการสำรองข้อมูลอย่างสม่ำเสมอ และเก็บไว้ในที่ที่เข้าถึงจากภายนอกไม่ได้\n\n"
             . "4) การป้องกันภัยคุกคาม\nมีมาตรการป้องกันการโจมตีที่พบบ่อย เช่น การจำกัดจำนวนครั้งการเข้าสู่ระบบ การตรวจสอบไฟล์ที่อัปโหลด และการป้องกันการปลอมแปลงคำขอ\n\n"
             . "5) การแจ้งเหตุ\nหากพบช่องโหว่หรือเหตุผิดปกติ โปรดแจ้งผู้ดูแลระบบผ่านหน้า \"ติดต่อหน่วยงาน\" โดยเร็ว"],
        ];
        $ins = $pdo->prepare("INSERT IGNORE INTO pages (title, slug, body, blocks, status, sort_order) VALUES (?,?,?,?,'published',?)");
        $order = 90;
        foreach ($policies as [$slug, $title, $body]) {
            $blocks = json_encode([['type' => 'text', 'text' => $body]], JSON_UNESCAPED_UNICODE);
            $ins->execute([$title, $slug, '', $blocks, $order++]);
        }
        $pdo->prepare("INSERT IGNORE INTO settings (skey, sval) VALUES ('policy_pages_seeded', '1')")->execute();
        $log('+ หน้านโยบาย 3 ฉบับ (นโยบายคุ้มครองข้อมูล/เว็บไซต์/ความมั่นคงปลอดภัย)');
    }

    /* ITA/OIT — รองรับหลายปีงบประมาณ + วันที่เผยแพร่ + คำอธิบายประกอบ (เกณฑ์ประเมินต้องใช้) */
    migr_add_col($pdo, 'ita_items', 'fiscal_year', "fiscal_year SMALLINT NOT NULL DEFAULT 0 AFTER grp", $log);
    migr_add_col($pdo, 'ita_items', 'note',        "note VARCHAR(500) NOT NULL DEFAULT '' AFTER title", $log);
    migr_add_col($pdo, 'ita_items', 'published_at', "published_at DATE NULL AFTER note", $log);
    /* รายการเดิมที่ยังไม่ระบุปี ให้ถือเป็นปีงบประมาณปัจจุบัน (พ.ศ.) */
    $cur_fy = (int)date('Y') + 543 + ((int)date('n') >= 10 ? 1 : 0);
    $pdo->prepare('UPDATE ita_items SET fiscal_year = ? WHERE fiscal_year = 0')->execute([$cur_fy]);

    /* อีเมลผู้ใช้ (ใช้กับระบบลืมรหัสผ่าน) + ตารางโทเคนรีเซ็ตรหัสผ่าน */
    migr_add_col($pdo, 'users', 'email', "email VARCHAR(150) NOT NULL DEFAULT '' AFTER display_name", $log);
    $pdo->exec("CREATE TABLE IF NOT EXISTS password_resets (
      id INT AUTO_INCREMENT PRIMARY KEY,
      user_id INT NOT NULL,
      token_hash CHAR(64) NOT NULL,
      expires_at DATETIME NOT NULL,
      used_at DATETIME NULL,
      ip VARCHAR(45) NOT NULL DEFAULT '',
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_token (token_hash),
      INDEX idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $log("= password_resets (ตาราง)");

    /* ยืนยันตัวตนสองชั้น (2FA / TOTP) — ต่อผู้ใช้ */
    migr_add_col($pdo, 'users', 'totp_secret',  "totp_secret VARCHAR(64) NOT NULL DEFAULT '' AFTER role", $log);
    migr_add_col($pdo, 'users', 'totp_enabled', "totp_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER totp_secret", $log);
    migr_add_col($pdo, 'users', 'backup_codes', "backup_codes TEXT NULL AFTER totp_enabled", $log);

    /* ผังโครงสร้างหน่วยงาน — สร้าง/แก้ไขเองในแอดมิน แทนการอัปโหลดรูปจากข้างนอก
       parent_id ผูกตัวเอง (เหมือน menu_items) รองรับความลึกได้ไม่จำกัดชั้น (หน่วยงาน→กอง→ฝ่าย→กลุ่มงาน ฯลฯ) */
    $pdo->exec("CREATE TABLE IF NOT EXISTS org_chart_nodes (
      id INT AUTO_INCREMENT PRIMARY KEY,
      parent_id INT NULL,
      label VARCHAR(200) NOT NULL,
      sort_order INT NOT NULL DEFAULT 0,
      INDEX idx_parent (parent_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $log("= org_chart_nodes (ตาราง)");

    /* จัดลำดับการแสดงเอกสารเผยแพร่เอง (ลากเรียงในหน้า admin ต่อหมวด — ไม่งั้นเรียงตามวันที่สร้างอย่างเดียว
       ผู้ดูแลจัดลำดับเองไม่ได้ เช่น อยากปักรายงานปีล่าสุดไว้บนสุดแม้จะไม่ใช่ไฟล์ที่เพิ่งอัปโหลดล่าสุด) */
    migr_add_col($pdo, 'documents', 'sort_order', "sort_order INT NOT NULL DEFAULT 0 AFTER downloads", $log);
    /* backfill ครั้งเดียวตอนเพิ่งมีคอลัมน์ (ทุกแถวยังเป็น 0 เท่ากันหมด) ให้เลขลำดับตรงกับที่เคยแสดงอยู่เดิม
       (ใหม่สุด=เลขน้อยสุด=แสดงก่อน คงพฤติกรรมเดิมไว้เป็นจุดเริ่มต้น) — รันซ้ำได้ปลอดภัย เพราะถ้าแอดมินเคย
       จัดลำดับเองแล้ว (มีค่าที่ไม่ใช่ 0 อยู่) จะข้ามไปเลย ไม่ทับของที่จัดไว้แล้ว */
    $docCount = (int)$pdo->query('SELECT COUNT(*) FROM documents')->fetchColumn();
    $unsorted = (int)$pdo->query('SELECT COUNT(*) FROM documents WHERE sort_order <> 0')->fetchColumn();
    if ($docCount > 0 && $unsorted === 0) {
        $ids = $pdo->query('SELECT id FROM documents ORDER BY created_at DESC, id DESC')->fetchAll(PDO::FETCH_COLUMN);
        $updSort = $pdo->prepare('UPDATE documents SET sort_order = ? WHERE id = ?');
        $rank = 1;
        foreach ($ids as $did) { $updSort->execute([$rank++, $did]); }
        $log("~ documents.sort_order (backfill $docCount รายการ ตามลำดับเดิม)");
    }
    $add_index('documents', 'idx_cat_sort', 'category_id, sort_order');

    /* เมนูท้ายเว็บ (footer) แบบกำหนดเอง — ค่าเริ่มต้น footer_custom=0 ใช้ footer เดิม (โค้ดตายตัว) เหมือนก่อนหน้านี้เป๊ะ
       col = คอลัมน์ที่ 1/2/3 ใน footer (เทียบเท่า "หมวด" ของเอกสาร — ลากเรียงแยกอิสระต่อคอลัมน์) */
    $pdo->exec("CREATE TABLE IF NOT EXISTS footer_links (
      id INT AUTO_INCREMENT PRIMARY KEY,
      col TINYINT NOT NULL DEFAULT 1,
      label VARCHAR(120) NOT NULL,
      url VARCHAR(500) NOT NULL,
      new_tab TINYINT(1) NOT NULL DEFAULT 0,
      enabled TINYINT(1) NOT NULL DEFAULT 1,
      sort_order INT NOT NULL DEFAULT 0,
      INDEX idx_col_sort (col, sort_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $log("= footer_links (ตาราง)");
    $pdo->prepare('INSERT IGNORE INTO settings (skey, sval) VALUES (?, ?)')->execute(['footer_custom', '0']);
    $log("= settings (footer_custom)");

    /* วิดีโอความรู้ (YouTube) — เก็บแค่ video_id 11 ตัว ไม่เก็บ URL เต็ม
       (แกะด้วย youtube_id() ตอนบันทึก แล้วประกอบ URL ใหม่ตอนแสดง — กัน URL แปลกปลอมหลุดเข้า iframe)
       cover = ภาพปกที่อัปเองทับภาพจาก YouTube (ไม่บังคับ — เผื่อเครื่องที่เข้า YouTube ไม่ได้) */
    $pdo->exec("CREATE TABLE IF NOT EXISTS videos (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $log("= videos (ตาราง)");
    $mxv = (int)$pdo->query('SELECT COALESCE(MAX(sort_order),0) FROM sections')->fetchColumn();
    $pdo->prepare("INSERT IGNORE INTO sections (skey, enabled, in_menu, sort_order, custom_title) VALUES ('video', 1, 1, ?, '')")->execute([$mxv + 1]);
    $log("= sections.video");

    /* ── นิทรรศการโปสเตอร์ (section: poster) ──
       เก็บ width/height ของภาพจริงไว้ด้วย เพราะเวทีใช้ความสูงคงที่แล้วให้ความกว้าง
       ผันตามสัดส่วนภาพ ถ้าไม่รู้สัดส่วนล่วงหน้าแถวจะกระโดดตอนรูปทยอยโหลดเสร็จ */
    $pdo->exec("CREATE TABLE IF NOT EXISTS posters (
      id INT AUTO_INCREMENT PRIMARY KEY,
      title VARCHAR(200) NOT NULL DEFAULT '',
      caption VARCHAR(400) NOT NULL DEFAULT '',
      image VARCHAR(300) NOT NULL,
      img_w INT NOT NULL DEFAULT 0,
      img_h INT NOT NULL DEFAULT 0,
      link_url VARCHAR(300) NOT NULL DEFAULT '',
      enabled TINYINT(1) NOT NULL DEFAULT 1,
      sort_order INT NOT NULL DEFAULT 0,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_enabled_sort (enabled, sort_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $log("= posters (ตาราง)");
    /* เปิดไว้ได้เลย — ตัว section คืนค่าว่างเมื่อยังไม่มีโปสเตอร์ หน้าแรกของเว็บที่มีอยู่จึงไม่เปลี่ยน
       จนกว่าผู้ดูแลจะเพิ่มรูปเอง (ไม่แสดงกล่องเปล่า) */
    $mxp = (int)$pdo->query('SELECT COALESCE(MAX(sort_order),0) FROM sections')->fetchColumn();
    $pdo->prepare("INSERT IGNORE INTO sections (skey, enabled, in_menu, sort_order, custom_title) VALUES ('poster', 1, 0, ?, '')")->execute([$mxp + 1]);
    $log("= sections.poster");

    /* settings เริ่มต้นใหม่ (เพิ่มเฉพาะที่ยังไม่มี) */
    $ins = $pdo->prepare('INSERT IGNORE INTO settings (skey, sval) VALUES (?, ?)');
    $ins->execute(['a11y_bar', '1']);
    /* ลูกเล่นของนิทรรศการโปสเตอร์ — ตั้งได้ที่หน้า admin/posters.php */
    $ins->execute(['poster_style', 'stage']);      /* stage | strip | fade */
    $ins->execute(['poster_height', 'md']);        /* sm | md | lg */
    $ins->execute(['poster_auto', '1']);
    $ins->execute(['poster_interval', '5000']);
    $ins->execute(['poster_caption', '1']);
    $ins->execute(['poster_frame', '1']);
    $ins->execute(['poster_zoom', '1']);
    $name = (string)($pdo->query("SELECT sval FROM settings WHERE skey='site_name'")->fetchColumn() ?: 'หน่วยงานราชการ');
    $ins->execute(['site_description', $name . ' — ศูนย์ข้อมูลข่าวสารและบริการประชาชนออนไลน์']);
    $log("= settings (a11y_bar, site_description)");
}

}
