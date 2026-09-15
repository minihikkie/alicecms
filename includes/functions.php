<?php
/**
 * functions.php — ฟังก์ชันกลางของระบบ
 * ห้ามเรียกไฟล์นี้ตรงๆ ต้องผ่าน init.php เท่านั้น
 */
if (!defined('APP_ROOT')) exit('Forbidden');

/* ─────────────────────────────────────────────
   พื้นฐาน
   ───────────────────────────────────────────── */

/** ป้องกัน XSS — ทุก output ที่มาจากผู้ใช้/ฐานข้อมูลต้องผ่านฟังก์ชันนี้ */
function e(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/** คืนค่า PDO connection กลาง */
function db(): PDO {
    return $GLOBALS['pdo'];
}

/** สร้าง URL จาก path สัมพัทธ์กับรากเว็บ */
/** เปิดใช้ URL สวย (ซ่อน .php) อยู่หรือไม่
 *  ตั้งเป็น '1' อัตโนมัติหลังติดตั้ง/อัปเดต เมื่อทดสอบแล้วว่าเซิร์ฟเวอร์รองรับจริง (ดู hardening.php)
 *  ถ้าเซิร์ฟเวอร์ไม่รองรับจะเป็น '0' และลิงก์ทั้งเว็บกลับไปใช้ .php ตามเดิม — ใช้งานได้ปกติทุกกรณี */
function pretty_urls_on(): bool {
    return setting('pretty_urls', '0') === '1';
}

/**
 * ถ้าคำขอเข้ามาด้วย .php และเปิด URL สวยอยู่ → คืน URL ปลายทางที่ควร redirect ไป (ไม่มี .php)
 * คืน null ถ้าไม่ต้อง redirect
 *
 * แยกเป็นฟังก์ชันเพื่อให้ทดสอบได้ (init.php แค่เรียกใช้)
 * รองรับติดตั้งในโฟลเดอร์ย่อย โดยตัดส่วน BASE_URL ออกจาก REQUEST_URI ก่อนตรวจ
 */
function canonical_php_redirect(string $requestUri, string $queryString = ''): ?string {
    if (!pretty_urls_on()) return null;
    $reqPath  = strtok($requestUri, '?');
    $basePath = rtrim((string)(parse_url(url(''), PHP_URL_PATH) ?? '/'), '/');
    $rel = ($basePath !== '' && strncmp($reqPath, $basePath, strlen($basePath)) === 0)
         ? substr($reqPath, strlen($basePath))
         : $reqPath;
    $rel = ltrim($rel, '/');
    /* หน้าสาธารณะระดับราก และหน้าหลังบ้านใน admin/ เท่านั้น — โฟลเดอร์ย่อยอื่นไม่แตะ
       ชื่อที่ขึ้นต้นด้วย _ คือพาร์เชียล (_top.php _auth.php ฯลฯ) ไม่ใช่หน้าที่เปิดตรงได้ จึงไม่ยุ่ง */
    if ($rel === '') return null;
    if (!preg_match('~^(admin/)?[A-Za-z0-9-][A-Za-z0-9_-]*\.php$~', $rel)) return null;
    return url($rel) . ($queryString !== '' ? '?' . $queryString : '');
}

function url(string $path = ''): string {
    $p = ltrim($path, '/');
    /* ตัด .php ออกเฉพาะหน้าสาธารณะระดับราก และหน้าหลังบ้านใน admin/
       ไม่แตะโฟลเดอร์ย่อยอื่น เช่น assets/, uploads/ (ไม่ใช่ .php อยู่แล้ว) */
    if ($p !== '' && pretty_urls_on()) {
        /* แยกคำนำหน้า admin/ ออกก่อน แล้วใช้กติกาเดียวกับหน้าสาธารณะกับส่วนที่เหลือ
           จะได้ไม่ต้องเขียนตรรกะซ้ำสองชุดให้หลุดกันทีหลัง */
        $pre = '';
        if (strncmp($p, 'admin/', 6) === 0) { $pre = 'admin/'; $p = substr($p, 6); }
        if ($p === 'index.php' || strncmp($p, 'index.php?', 10) === 0 || strncmp($p, 'index.php#', 10) === 0) {
            $p = substr($p, 9);            /* index.php[?q] → [?q] (ชี้รากเว็บ หรือราก /admin/) */
        } else {
            /* ตัวคั่น ~ ไม่ใช่ # เพราะแพทเทิร์นมี # อยู่ข้างใน ([?#]) จะไปปิดตัวคั่นก่อนเวลา
               ตัวแรกห้ามเป็น _ เพราะไฟล์ขึ้นต้นด้วย _ คือพาร์เชียล ไม่ใช่หน้าที่มี URL ของตัวเอง */
            $p = preg_replace('~^([A-Za-z0-9-][A-Za-z0-9_-]*)\.php(?=$|[?#])~', '$1', $p);
        }
        $p = $pre . $p;
    }
    return rtrim(BASE_URL, '/') . '/' . $p;
}

/** URL แบบเต็ม (มี scheme + host) — ใช้กับ canonical, Open Graph, sitemap, RSS */
function abs_url(string $path = ''): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host . url($path);
}

/** ตัดข้อความยาวให้สั้นลงสำหรับ meta description (ลบ tag/ขึ้นบรรทัด, จำกัดความยาว) */
function meta_excerpt(?string $text, int $len = 155): string {
    $t = trim(preg_replace('/\s+/u', ' ', strip_tags((string)$text)));
    if (mb_strlen($t) <= $len) return $t;
    return mb_substr($t, 0, $len - 1) . '…';
}

/** redirect แล้วจบการทำงานทันที */
function redirect(string $path): void {
    header('Location: ' . (preg_match('#^https?://#', $path) ? $path : url($path)));
    exit;
}

/* ─────────────────────────────────────────────
   Settings (key-value) — ตั้งค่าหน่วยงาน/ธีม
   ───────────────────────────────────────────── */

/** โหลด settings ทั้งหมดครั้งเดียว เก็บ cache ไว้ใน $GLOBALS */
function settings_all(): array {
    if (!isset($GLOBALS['_settings_cache'])) {
        $GLOBALS['_settings_cache'] = [];
        try {
            foreach (db()->query('SELECT skey, sval FROM settings') as $r) {
                $GLOBALS['_settings_cache'][$r['skey']] = $r['sval'];
            }
        } catch (Throwable $e) { /* ตารางยังไม่ถูกสร้าง */ }
    }
    return $GLOBALS['_settings_cache'];
}

function setting(string $key, string $default = ''): string {
    $all = settings_all();
    return ($all[$key] ?? '') !== '' ? $all[$key] : $default;
}

/** บันทึกค่า setting ลงฐานข้อมูล (insert หรือ update อัตโนมัติ) */
function setting_set(string $key, string $val): void {
    $st = db()->prepare('INSERT INTO settings (skey, sval) VALUES (?, ?)
                         ON DUPLICATE KEY UPDATE sval = VALUES(sval)');
    $st->execute([$key, $val]);
    settings_all();
    $GLOBALS['_settings_cache'][$key] = $val;
}

/* ─────────────────────────────────────────────
   Sections — เปิด/ปิด, ลำดับ, ชื่อหัวข้อของหน้าแรก
   ───────────────────────────────────────────── */

/** รายชื่อ section ทั้งหมดของระบบ + ชื่อดีฟอลต์ */
function section_defaults(): array {
    return [
        'slider'      => 'แบนเนอร์ประชาสัมพันธ์',
        'hero'        => 'ค้นหาและสถิติ',
        'chief'       => 'สารจากหัวหน้าหน่วยงาน',
        'personnel'   => 'โครงสร้างผู้บริหาร',
        'activity'    => 'กิจกรรม',
        'pr'          => 'ประชาสัมพันธ์',
        'announce'    => 'ประกาศ',
        'documents'   => 'เอกสารเผยแพร่',
        'ita'         => 'การเปิดเผยข้อมูลสาธารณะ (OIT)',
        'procurement' => 'จัดซื้อจัดจ้าง',
        'faq'         => 'คำถามที่พบบ่อย',
        'complaint'   => 'ร้องเรียน-ร้องทุกข์',
        'contact'     => 'ติดต่อหน่วยงาน',
        'links'       => 'ลิงก์หน่วยงานที่เกี่ยวข้อง',
        'video'       => 'วิดีโอความรู้',
    ];
}

/** โหลด sections ทั้งหมด (cache) เรียงตาม sort_order */
function sections_all(): array {
    if (!isset($GLOBALS['_sections_cache'])) {
        $GLOBALS['_sections_cache'] = [];
        try {
            foreach (db()->query('SELECT * FROM sections ORDER BY sort_order ASC') as $r) {
                $GLOBALS['_sections_cache'][$r['skey']] = $r;
            }
        } catch (Throwable $e) {}
    }
    return $GLOBALS['_sections_cache'];
}

/** section นี้แสดงบนหน้าแรกหรือไม่ (homepage block) */
function section_on(string $key): bool {
    $all = sections_all();
    return !empty($all[$key]) && (int)$all[$key]['enabled'] === 1;
}

/** section นี้แสดงลิงก์ในเมนูด้านบนหรือไม่ (top navigation) */
function section_in_menu(string $key): bool {
    $all = sections_all();
    return !empty($all[$key]) && (int)($all[$key]['in_menu'] ?? 1) === 1;
}

/** ฟีเจอร์นี้เปิดให้ประชาชนเข้าถึงได้หรือไม่ — อยู่บนหน้าแรก "หรือ" ในเมนู อย่างใดอย่างหนึ่ง
 *  ใช้กับ footer / sitemap / แท็บในหน้า / ลิงก์ข้ามหน้า ที่ความหมายคือ "ฟีเจอร์เปิดอยู่" ไม่ใช่ "อยู่บนหน้าแรก" */
function section_live(string $key): bool {
    return section_on($key) || section_in_menu($key);
}

/**
 * กันไม่ให้เข้าหน้าของ section ที่ถูกปิด — เรียกทันทีหลัง init.php ในหน้าที่ผูกกับ section
 *
 * ทำไมต้องมี: สวิตช์ปิด section เดิมแค่ซ่อนออกจากหน้าแรก เมนู และแผนผังเว็บ
 * แต่ตัวไฟล์หน้ายังเปิดได้ตามปกติ ใครมีลิงก์ตรงก็ยังเข้าได้ และที่หนักกว่านั้นคือ
 * หน้าร้องเรียนยังรับเรื่องจากประชาชนเข้าฐานข้อมูลต่อไปทั้งที่ผู้ดูแลปิดไปแล้ว
 * (เจอจริง: ปิดแล้วแต่ยังมีเรื่องส่งเข้ามา เพราะ Google เก็บหน้าไว้ตอนที่ยังเปิดอยู่)
 *
 * ตอบ 404 ไม่ใช่ข้อความ "ปิดปรับปรุง" ที่สถานะ 200 เพราะถ้าตอบ 200
 * Google จะเก็บหน้าไว้ในดัชนีต่อ แล้วคนก็จะคลิกเข้ามาเรื่อยๆ
 */
function require_section_live(string $key): void {
    if (section_live($key)) return;
    http_response_code(404);
    require APP_ROOT . '/404.php';
    exit;
}

/** นิยามลิงก์เมนูอัตโนมัติ ↔ section ที่คุมมัน (เรียงตามลำดับที่จะแสดงในเมนู)
 *  - keys = section ที่เปิด "แสดงในเมนู" แล้วทำให้ลิงก์นี้โผล่ (news รวม 3 ประเภทเป็นลิงก์เดียว)
 *  ใช้ร่วมกัน: header (เรนเดอร์เมนู), admin/menu.php (seed), admin/homepage.php (ตารางตั้งค่า) */
function nav_section_defs(): array {
    return [
        ['file' => 'news.php',        'label' => 'ข่าวสาร',        'keys' => ['activity', 'pr', 'announce']],
        ['file' => 'documents.php',   'label' => 'เอกสาร',         'keys' => ['documents']],
        ['file' => 'procurement.php', 'label' => 'จัดซื้อจัดจ้าง', 'keys' => ['procurement']],
        ['file' => 'ita.php',         'label' => 'ITA',            'keys' => ['ita']],
        ['file' => 'personnel.php',   'label' => 'ผู้บริหาร',      'keys' => ['personnel']],
    ];
}

/** section นี้มีลิงก์ในเมนูอัตโนมัติให้ตั้งค่าได้หรือไม่ (เปิดช่อง "แสดงในเมนู" ในหน้า admin) + คืน label ลิงก์ */
function section_nav_target(string $key): ?array {
    foreach (nav_section_defs() as $nd) {
        if (in_array($key, $nd['keys'], true)) return ['file' => $nd['file'], 'label' => $nd['label']];
    }
    return null;
}

/** ชื่อหัวข้อ section (ใช้ชื่อที่ admin ตั้งเอง ถ้าไม่มีใช้ดีฟอลต์) */
function section_title(string $key): string {
    $all = sections_all();
    $custom = trim($all[$key]['custom_title'] ?? '');
    return $custom !== '' ? $custom : (section_defaults()[$key] ?? $key);
}

/* ─────────────────────────────────────────────
   วันที่แบบไทย (พ.ศ.)
   ───────────────────────────────────────────── */

/** แปลงวันที่เป็นรูปแบบไทย เช่น "10 มิ.ย. 2569" หรือแบบเต็ม "10 มิถุนายน 2569" */
function thai_date(?string $dt, bool $short = true): string {
    if (!$dt) return '';
    $t = strtotime($dt);
    if ($t === false) return '';
    $ms = ['ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
    $mf = ['มกราคม','กุมภาพันธ์','มีนาคม','เมษายน','พฤษภาคม','มิถุนายน',
           'กรกฎาคม','สิงหาคม','กันยายน','ตุลาคม','พฤศจิกายน','ธันวาคม'];
    $d = (int)date('j', $t);
    $m = (int)date('n', $t) - 1;
    $y = (int)date('Y', $t) + 543;
    return $short ? "$d {$ms[$m]} $y" : "$d {$mf[$m]} $y";
}

/** โพสต์ภายใน 7 วันถือว่า "ใหม่" */
function is_new(?string $dt): bool {
    if (!$dt) return false;
    $t = strtotime($dt);
    return $t !== false && (time() - $t) < 7 * 86400;
}

/** แปลงขนาดไฟล์เป็นข้อความอ่านง่าย เช่น 4.2 MB */
function format_bytes(int $b): string {
    if ($b >= 1048576) return number_format($b / 1048576, 1) . ' MB';
    if ($b >= 1024)    return number_format($b / 1024, 0) . ' KB';
    return $b . ' B';
}

/* ─────────────────────────────────────────────
   ประเภทข่าว
   ───────────────────────────────────────────── */

function post_types(): array {
    return [
        'activity' => ['label' => 'กิจกรรม',       'badge' => '',        'icon' => 'photo_camera'],
        'pr'       => ['label' => 'ประชาสัมพันธ์', 'badge' => 'success', 'icon' => 'campaign'],
        'announce' => ['label' => 'ประกาศ',         'badge' => 'warning', 'icon' => 'campaign'],
    ];
}

function post_type_label(string $t): string {
    return post_types()[$t]['label'] ?? $t;
}

/** URL รูปปกข่าว — ถ้าไม่ได้อัปโหลดใช้รูป default */
function post_image_url(?string $image): string {
    return $image ? url($image) : url('assets/img/default-news.svg');
}

/** ประเภทประกาศจัดซื้อจัดจ้าง */
function proc_types(): array {
    return [
        'ebidding' => ['label' => 'e-bidding',      'badge' => ''],
        'price'    => ['label' => 'ราคากลาง',       'badge' => 'warning'],
        'result'   => ['label' => 'ผลการพิจารณา',   'badge' => 'success'],
        'other'    => ['label' => 'อื่นๆ',           'badge' => 'special'],
    ];
}

/** บุคลากร/ผู้บริหารที่เผยแพร่ จัดกลุ่มตามระดับชั้น (1 = สูงสุด) เรียงลำดับในระดับ */
function personnel_by_level(): array {
    $groups = [];
    try {
        $rows = db()->query("SELECT * FROM personnel WHERE status='published'
                             ORDER BY level ASC, sort_order ASC, id ASC");
        foreach ($rows as $p) $groups[(int)$p['level']][] = $p;
    } catch (Throwable $e) {}
    return $groups;
}

/** หมวด ITA/OIT ทั้ง 4 หมวด */
/* ─────────────────────────────────────────────
   PDPA — การขอความยินยอมก่อนเก็บข้อมูลส่วนบุคคล
   ───────────────────────────────────────────── */

/** ข้อความขอความยินยอม (แก้ได้ในหน้าตั้งค่า) */
function pdpa_consent_text(): string {
    $t = trim((string)setting('pdpa_consent_text', ''));
    if ($t !== '') return $t;
    return 'ข้าพเจ้ายินยอมให้ ' . setting('site_name', 'หน่วยงาน')
         . ' เก็บรวบรวม ใช้ และเปิดเผยข้อมูลส่วนบุคคลที่กรอกในแบบฟอร์มนี้ '
         . 'เพื่อวัตถุประสงค์ในการติดต่อกลับและดำเนินการตามที่ร้องขอเท่านั้น '
         . 'ตามพระราชบัญญัติคุ้มครองข้อมูลส่วนบุคคล พ.ศ. 2562';
}

/** ต้องขอความยินยอมหรือไม่ (เปิด/ปิดได้ในหน้าตั้งค่า — ค่าเริ่มต้นคือเปิด) */
function pdpa_required(): bool {
    return setting('pdpa_consent', '1') === '1';
}

/** แสดงกล่องติ๊กยินยอม PDPA ในฟอร์มสาธารณะ */
function pdpa_consent_box(): string {
    if (!pdpa_required()) return '';
    $policy = page_by_slug('privacy-policy') ? url('page.php?slug=privacy-policy') : '';
    $link = $policy ? ' <a href="' . e($policy) . '" target="_blank" rel="noopener">อ่านนโยบายคุ้มครองข้อมูลส่วนบุคคล</a>' : '';
    return '<label class="pdpa-consent">'
         . '<input type="checkbox" name="pdpa_consent" value="1" required>'
         . '<span>' . e(pdpa_consent_text()) . $link . '</span>'
         . '</label>';
}

/** ตรวจว่าติ๊กยินยอมแล้วหรือยัง — คืนข้อความ error หรือ '' ถ้าผ่าน */
function pdpa_consent_error(): string {
    if (!pdpa_required()) return '';
    return empty($_POST['pdpa_consent'])
        ? 'กรุณาติ๊กยินยอมให้เก็บข้อมูลส่วนบุคคลก่อนส่งแบบฟอร์ม'
        : '';
}

/** ปีงบประมาณไทยปัจจุบัน (พ.ศ.) — ปีงบประมาณเริ่ม 1 ตุลาคม */
function fiscal_year_now(): int {
    return (int)date('Y') + 543 + ((int)date('n') >= 10 ? 1 : 0);
}

/** ปีงบประมาณที่มีข้อมูล ITA อยู่ (ใหม่สุดก่อน) — ถ้ายังไม่มีคืนปีปัจจุบัน */
function ita_years(): array {
    try {
        $ys = db()->query('SELECT DISTINCT fiscal_year FROM ita_items WHERE fiscal_year > 0 ORDER BY fiscal_year DESC')
                  ->fetchAll(PDO::FETCH_COLUMN);
        $ys = array_map('intval', $ys);
    } catch (Throwable $e) { $ys = []; }
    if (!$ys) $ys = [fiscal_year_now()];
    return $ys;
}

function ita_groups(): array {
    return [
        1 => ['title' => 'ข้อมูลพื้นฐาน',            'sub' => 'โครงสร้าง · ผู้บริหาร O1–O6',  'icon' => 'apartment',       'style' => ''],
        2 => ['title' => 'การบริหารงาน-งบประมาณ',   'sub' => 'O7–O12',                       'icon' => 'payments',        'style' => ''],
        3 => ['title' => 'การบริหารบุคคล',           'sub' => 'O13–O18',                      'icon' => 'manage_accounts', 'style' => ''],
        4 => ['title' => 'การป้องกันการทุจริต',      'sub' => 'O19–O43',                      'icon' => 'shield',          'style' => 'special'],
    ];
}

/* ─────────────────────────────────────────────
   CSRF protection — ทุกฟอร์ม POST ต้องมี token
   ───────────────────────────────────────────── */

function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** ใส่ hidden input ลงในฟอร์ม */
function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

/**
 * ตรวจ token ฝั่ง server — เรียกก่อนประมวลผล POST ทุกครั้ง
 *
 * แยก 2 กรณีที่อาการเหมือนกันแต่สาเหตุคนละเรื่อง (เดิมขึ้นข้อความเดียวกันหมด หาสาเหตุไม่เจอ):
 *   1. ฟอร์มส่ง token มาด้วย แต่ฝั่งเซิร์ฟเวอร์ไม่มี token ในเซสชันเลย
 *      = เซิร์ฟเวอร์เก็บเซสชันไม่ได้ (หรือเซสชันหมดอายุ/ปิดเบราว์เซอร์) ไม่ใช่การโจมตี
 *   2. token มีทั้งสองฝั่งแต่ไม่ตรงกัน = CSRF จริง หรือฟอร์มค้างจากหน้าเก่า
 * ทั้งสองกรณียังบล็อกคำขอเหมือนเดิม ต่างกันแค่ข้อความที่บอกผู้ใช้ให้ตรงเรื่อง
 */
function csrf_verify(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return;

    $sessionToken = (string)($_SESSION['csrf_token'] ?? '');
    $sent = $_POST['csrf_token'] ?? '';
    if (is_string($sent) && $sessionToken !== '' && hash_equals($sessionToken, $sent)) return;

    $lostSession = ($sessionToken === '' && is_string($sent) && $sent !== '');
    http_response_code(403);
    header('Content-Type: text/html; charset=UTF-8');
    $title = $lostSession ? 'เซสชันหมดอายุหรือไม่ถูกบันทึก' : 'การตรวจสอบความปลอดภัยล้มเหลว (CSRF)';
    $msg = $lostSession
        ? 'ระบบไม่พบข้อมูลเซสชันของคุณบนเซิร์ฟเวอร์ สาเหตุที่พบบ่อยคือเปิดหน้านี้ค้างไว้นานเกินไป '
        . 'หรือปิดเบราว์เซอร์ไปแล้วกลับมากดส่ง <br>กรุณากลับไปโหลดหน้าใหม่แล้วทำรายการอีกครั้ง'
        : 'คำขอนี้ไม่ผ่านการตรวจสอบความปลอดภัย อาจเกิดจากฟอร์มค้างจากหน้าเดิม '
        . 'กรุณากลับไปโหลดหน้าใหม่แล้วทำรายการอีกครั้ง';
    exit('<!DOCTYPE html><html lang="th"><head><meta charset="UTF-8">'
       . '<meta name="viewport" content="width=device-width,initial-scale=1"><title>' . e($title) . '</title>'
       . '<style>body{font-family:system-ui,"Segoe UI",sans-serif;background:#f6f8fc;margin:0;'
       . 'min-height:100vh;display:grid;place-items:center;padding:24px;color:#1f2937}'
       . '.b{background:#fff;border:1px solid #e5e7eb;border-radius:16px;padding:28px 30px;max-width:520px;'
       . 'box-shadow:0 6px 24px rgba(15,23,42,.06)}h1{font-size:19px;margin:0 0 10px}'
       . 'p{font-size:14.5px;line-height:1.75;color:#4b5563;margin:0 0 18px}'
       . 'a{display:inline-block;background:#1a73e8;color:#fff;text-decoration:none;font-size:14px;'
       . 'font-weight:600;padding:10px 20px;border-radius:999px}</style></head><body><div class="b">'
       . '<h1>' . e($title) . '</h1><p>' . $msg . '</p>'
       . '<a href="javascript:history.back()">ย้อนกลับไปหน้าเดิม</a></div></body></html>');
}

/* ─────────────────────────────────────────────
   Flash message (แสดงครั้งเดียวหลัง redirect)
   ───────────────────────────────────────────── */

function flash_set(string $type, string $msg): void {
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
}

function flash_get(): ?array {
    if (!empty($_SESSION['flash'])) {
        $f = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $f;
    }
    return null;
}

/* ─────────────────────────────────────────────
   อัปโหลดไฟล์อย่างปลอดภัย
   - whitelist นามสกุล + ตรวจ MIME จริงด้วย finfo
   - ตั้งชื่อไฟล์ใหม่แบบสุ่ม กันการเดาชื่อ/ทับไฟล์
   - รูปภาพถูก resize + re-encode ใหม่ด้วย GD (ล้าง payload แฝง)
   ───────────────────────────────────────────── */

function upload_image_exts(): array { return ['jpg', 'jpeg', 'png', 'webp']; }

function upload_all_exts(): array {
    return ['jpg', 'jpeg', 'png', 'webp', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'zip'];
}

/** MIME ที่ยอมรับของแต่ละนามสกุล (ตรวจจากเนื้อไฟล์จริง ไม่เชื่อชื่อไฟล์) */
function upload_mime_map(): array {
    return [
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png'  => ['image/png'],
        'webp' => ['image/webp'],
        'pdf'  => ['application/pdf'],
        'doc'  => ['application/msword', 'application/vnd.ms-office', 'application/x-ole-storage', 'application/CDFV2'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        'xls'  => ['application/vnd.ms-excel', 'application/vnd.ms-office', 'application/x-ole-storage', 'application/CDFV2'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
        'zip'  => ['application/zip', 'application/x-zip-compressed'],
    ];
}

/**
 * แกนกลางการบันทึกไฟล์อัปโหลด 1 ไฟล์ (ตรวจ + ย่อ + เปลี่ยนชื่อ) ใช้ทั้งแบบไฟล์เดียวและหลายไฟล์
 * @throws RuntimeException
 */
function store_upload(string $origName, string $tmp, int $error, int $size, string $subdir, array $allowExt, int $maxMB): array {
    if ($error !== UPLOAD_ERR_OK) {
        throw new RuntimeException('อัปโหลดไฟล์ไม่สำเร็จ (รหัส ' . $error . ') — ไฟล์อาจใหญ่เกินค่าที่ server กำหนด');
    }
    if ($size > $maxMB * 1048576) {
        throw new RuntimeException("ไฟล์ \"" . $origName . "\" ใหญ่เกิน {$maxMB} MB");
    }
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowExt, true)) {
        throw new RuntimeException('ไม่อนุญาตไฟล์นามสกุล .' . $ext . ' (รองรับ: ' . implode(', ', $allowExt) . ')');
    }
    // ตรวจ MIME จากเนื้อไฟล์จริง
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = (string)$finfo->file($tmp);
    $okMimes = upload_mime_map()[$ext] ?? [];
    if (!in_array($mime, $okMimes, true)) {
        throw new RuntimeException('ชนิดไฟล์จริงไม่ตรงกับนามสกุล (ตรวจพบ ' . $mime . ') — ไฟล์ถูกปฏิเสธเพื่อความปลอดภัย');
    }
    // ตั้งชื่อใหม่แบบสุ่ม
    $dir = APP_ROOT . '/uploads/' . trim($subdir, '/');
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $name = date('Ymd') . '-' . bin2hex(random_bytes(8)) . '.' . $ext;
    $dest = $dir . '/' . $name;
    if (!move_uploaded_file($tmp, $dest)) {
        throw new RuntimeException('บันทึกไฟล์ลงเครื่อง server ไม่สำเร็จ');
    }
    if (in_array($ext, upload_image_exts(), true)) {
        resize_image($dest, 1600);   // รูปภาพ: ย่อ + เข้ารหัสใหม่
    }
    return [
        'path' => 'uploads/' . trim($subdir, '/') . '/' . $name,
        'size' => (int)filesize($dest),
        'ext'  => $ext,
        'orig' => mb_substr(basename($origName), 0, 200),  // ชื่อไฟล์เดิมไว้แสดงผล
    ];
}

/**
 * รับไฟล์อัปโหลดจากฟอร์ม (ไฟล์เดียว)
 * @return array{path:string,size:int,ext:string}|null  null = ไม่ได้แนบไฟล์
 * @throws RuntimeException
 */
function handle_upload(string $field, string $subdir, ?array $allowExt = null, int $maxMB = 20): ?array {
    if (empty($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) return null;
    $f = $_FILES[$field];
    return store_upload($f['name'], $f['tmp_name'], (int)$f['error'], (int)$f['size'], $subdir, $allowExt ?? upload_all_exts(), $maxMB);
}

/**
 * รับไฟล์อัปโหลดหลายไฟล์จาก input ชื่อ name="field[]" multiple
 * @return array รายการ {path,size,ext} ของไฟล์ที่อัปสำเร็จ (ข้ามช่องที่ว่าง)
 * @throws RuntimeException ถ้าไฟล์ใดไม่ผ่านการตรวจ
 */
function handle_uploads_multi(string $field, string $subdir, ?array $allowExt = null, int $maxMB = 25): array {
    if (empty($_FILES[$field]) || !is_array($_FILES[$field]['name'])) return [];
    $out = [];
    $n = count($_FILES[$field]['name']);
    for ($i = 0; $i < $n; $i++) {
        if ((int)$_FILES[$field]['error'][$i] === UPLOAD_ERR_NO_FILE) continue;
        $out[] = store_upload(
            (string)$_FILES[$field]['name'][$i],
            (string)$_FILES[$field]['tmp_name'][$i],
            (int)$_FILES[$field]['error'][$i],
            (int)$_FILES[$field]['size'][$i],
            $subdir, $allowExt ?? upload_all_exts(), $maxMB
        );
    }
    return $out;
}

/** ฟอนต์ไทยที่เลือกได้ (ชื่อ → พารามิเตอร์ Google Fonts) */
function site_fonts(): array {
    return [
        'Prompt'              => 'Prompt:wght@300;400;500;600;700',
        'Sarabun'             => 'Sarabun:wght@300;400;500;600;700',
        'Kanit'               => 'Kanit:wght@300;400;500;600;700',
        'Mitr'                => 'Mitr:wght@300;400;500;600;700',
        'Noto Sans Thai'      => 'Noto+Sans+Thai:wght@300;400;500;600;700',
        'IBM Plex Sans Thai'  => 'IBM+Plex+Sans+Thai:wght@300;400;500;600;700',
        'Bai Jamjuree'        => 'Bai+Jamjuree:wght@300;400;500;600;700',
    ];
}

/** ความกว้างเนื้อหาตามที่ตั้งค่า (ใช้กับตัวแปร --max) */
function layout_max_width(): string {
    return ['normal' => '1180px', 'wide' => '1360px', 'full' => '1640px'][setting('layout_width', 'normal')] ?? '1180px';
}

/** จัดรูปข้อความอย่างปลอดภัย: **หนา** *เอียง* ลิงก์อัตโนมัติ ขึ้นบรรทัดใหม่ (e() ก่อนเสมอ) */
function format_rich(string $t): string {
    $t = e($t);
    $t = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $t);
    $t = preg_replace('/(?<!\*)\*(?!\*)([^*]+?)\*(?!\*)/s', '<em>$1</em>', $t);
    $t = preg_replace('#(?<!["\'>=])(https?://[^\s<]+)#', '<a href="$1" target="_blank" rel="noopener">$1</a>', $t);
    return nl2br($t);
}

/** URL ของไฟล์ asset พร้อมเลขเวอร์ชัน — กันเบราว์เซอร์ใช้ CSS/JS เก่าที่แคชไว้หลังอัปเดต */
function asset_url(string $path): string {
    $v = defined('APP_VERSION') ? APP_VERSION : '1';
    return url($path) . '?v=' . rawurlencode($v);
}

/** ไอคอน Social media แบบ inline SVG (ไม่พึ่งไฟล์/ไอคอนฟอนต์ภายนอก) — ใช้ที่ contact.php และ footer.php
 *  คืน path data เปล่าๆ (ไม่มี wrapper <svg>) ให้ผู้เรียกห่อ <svg> เองตามบริบท (สี/ขนาดต่างกันไป) */
function social_icon_path(string $platform): string {
    return [
        'facebook' => 'M22 12.06C22 6.51 17.52 2 12 2S2 6.51 2 12.06c0 5.02 3.66 9.18 8.44 9.94v-7.03H7.9v-2.91h2.54V9.85c0-2.51 1.49-3.9 3.77-3.9 1.09 0 2.24.2 2.24.2v2.46h-1.26c-1.24 0-1.63.77-1.63 1.56v1.88h2.78l-.44 2.91h-2.34V22c4.78-.76 8.44-4.92 8.44-9.94Z',
        'youtube'  => 'M23.5 6.2a3 3 0 0 0-2.1-2.1C19.5 3.5 12 3.5 12 3.5s-7.5 0-9.4.6A3 3 0 0 0 .5 6.2 31 31 0 0 0 0 12a31 31 0 0 0 .5 5.8 3 3 0 0 0 2.1 2.1c1.9.6 9.4.6 9.4.6s7.5 0 9.4-.6a3 3 0 0 0 2.1-2.1A31 31 0 0 0 24 12a31 31 0 0 0-.5-5.8ZM9.6 15.6V8.4l6.3 3.6-6.3 3.6Z',
        'tiktok'   => 'M16.6 2c.3 2.1 1.6 3.9 3.4 4.9v3.3a8.2 8.2 0 0 1-4.9-1.6v7.2a6.7 6.7 0 1 1-6.7-6.7c.3 0 .7 0 1 .1v3.4a3.4 3.4 0 1 0 2.4 3.2V2h4.8Z',
        'line'     => 'M21 10.8c0-4.6-4.6-8.3-10.2-8.3S.6 6.2.6 10.8c0 4.1 3.6 7.6 8.6 8.2.3 0 .8.2.9.6.1.3.1.8 0 1.1l-.1.8c0 .2-.2.9.7.5.9-.4 5-2.9 6.9-5 1.3-1.4 1.9-2.7 1.9-4.2Z',
    ][$platform] ?? '';
}

/** ปุ่มไอคอนวงกลม + สีแบรนด์ สำหรับลิงก์ social media ที่ตั้งค่าไว้ — คืน '' ถ้าไม่มี URL */
function social_icon_button(string $platform, string $label): string {
    $url = setting('social_' . $platform);
    if ($url === '') return '';
    $path = social_icon_path($platform);
    if ($path === '') return '';
    return '<a class="social-icon-btn ' . e($platform) . '" href="' . e($url) . '" target="_blank" rel="noopener" title="' . e($label) . '" aria-label="' . e($label) . '">'
         . '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="' . $path . '"/></svg></a>';
}

/** แถวปุ่มไอคอน social media ทั้งหมดที่ตั้งค่าไว้ — คืน '' ถ้าไม่มีสักอัน */
function social_icons_row(): string {
    $items = [
        'facebook' => 'Facebook', 'youtube' => 'YouTube', 'tiktok' => 'TikTok', 'line' => 'LINE',
    ];
    $html = '';
    foreach ($items as $k => $label) $html .= social_icon_button($k, $label);
    return $html === '' ? '' : '<div class="social-icons">' . $html . '</div>';
}

/**
 * URL สำหรับฝังแผนที่ในหน้าติดต่อ — คืน '' ถ้าฝังไม่ได้
 * ลำดับการเลือก:
 *   1. ค่า map_embed ที่ผู้ดูแลวางเอง (ต้องเป็น Google Maps embed จริงเท่านั้น กัน iframe ปลายทางอื่น)
 *   2. สร้างอัตโนมัติจากที่อยู่หน่วยงาน — ผู้ดูแลไม่ต้องไปหา embed code เอง
 *      (รูปแบบ ?q=...&output=embed ใช้ได้โดยไม่ต้องมี API key และทำงานเฉพาะใน iframe)
 */
function map_embed_url(): string {
    $embed = trim((string)setting('map_embed'));
    if ($embed !== '' && preg_match('~^https://www\.google\.com/maps/embed~', $embed)) return $embed;

    $addr = trim((string)setting('site_address'));
    if ($addr === '') return '';
    // ที่อยู่เป็นหลายบรรทัด — ยุบเป็นบรรทัดเดียวก่อนส่งเป็น query
    $addr = trim(preg_replace('/\s+/u', ' ', str_replace(["\r", "\n"], ' ', $addr)));
    return 'https://www.google.com/maps?q=' . rawurlencode($addr) . '&output=embed&hl=th&z=16';
}

/** หน้าเพจกำหนดเอง: ดึงตาม slug (เฉพาะที่เผยแพร่) */
function page_by_slug(string $slug): ?array {
    $st = db()->prepare("SELECT * FROM pages WHERE slug = ? AND status = 'published'");
    $st->execute([$slug]);
    return $st->fetch() ?: null;
}

/** เมนูนำทางกำหนดเอง (รายการที่เปิดใช้ เรียงลำดับ) — กันพังถ้ายังไม่ได้ migrate */
function custom_menu(): array {
    static $c = null;
    if ($c === null) {
        try {
            $c = db()->query("SELECT label, url, new_tab FROM menu_items WHERE enabled = 1 ORDER BY sort_order ASC, id ASC")->fetchAll();
        } catch (Throwable $e) { $c = []; }
    }
    return $c;
}

/** เมนูนำทางแบบโครงสร้าง (เมนูหลัก → เมนูย่อย) สำหรับโหมดคุมเมนูเอง */
function custom_menu_tree(): array {
    static $tree = null;
    if ($tree !== null) return $tree;
    try {
        $rows = db()->query("SELECT id, parent_id, label, url, new_tab FROM menu_items
                             WHERE enabled = 1 ORDER BY sort_order ASC, id ASC")->fetchAll();
    } catch (Throwable $e) { return $tree = []; }
    $top = [];
    foreach ($rows as $r) if (empty($r['parent_id'])) { $r['children'] = []; $top[(int)$r['id']] = $r; }
    foreach ($rows as $r) if (!empty($r['parent_id']) && isset($top[(int)$r['parent_id']])) $top[(int)$r['parent_id']]['children'][] = $r;
    return $tree = array_values($top);
}

/** แปลง url ของเมนู (ภายใน/ภายนอก) เป็นลิงก์จริง + บอกว่าเป็นลิงก์นอกไหม */
function menu_href(string $u): array {
    $ext = (bool)preg_match('#^(https?:)?//#', $u);
    return ['href' => $ext ? $u : url($u), 'external' => $ext];
}

/**
 * ช่องเลือกปลายทางของลิงก์ — ใช้ร่วมกันระหว่างเมนูนำทางกับเมนูท้ายเว็บ
 *
 * เดิมวาง dropdown กับช่องพิมพ์ URL ไว้คู่กัน ทำให้เข้าใจผิดว่าเลือกจาก dropdown แล้วยังต้องพิมพ์ URL เองอีก
 * ตอนนี้ให้เลือกโหมดก่อน แล้วโชว์ช่องเดียวตามโหมด — เลือกหน้าในระบบ = จบที่ dropdown ไม่ต้องพิมพ์อะไรเลย
 *
 * ทั้งสองช่องใช้ name="url" เหมือนกัน แต่ช่องที่ไม่ได้ใช้จะถูก disabled
 * (เบราว์เซอร์ไม่ส่งค่า และไม่ตรวจ required ของ control ที่ disabled จึงไม่ตีกัน)
 * ถ้า JS ไม่ทำงาน จะเหลือ dropdown ใช้ได้ตามปกติ (ช่อง URL ภายนอกถูก disabled ไว้ตั้งแต่ต้น)
 */
function dest_picker_field(string $current, array $sysTargets, array $pages): void {
    $isExternal = $current !== '' && (bool)preg_match('#^https?://#', $current);

    /* ปลายทางภายในที่ไม่มีในรายการ (เช่นของเก่าที่พิมพ์เอง) — เติมเป็นตัวเลือกไว้ ไม่งั้นแก้ไขแล้วค่าหาย */
    $known = array_keys($sysTargets);
    foreach ($pages as $pg) $known[] = 'page.php?slug=' . $pg['slug'];
    $orphan = (!$isExternal && $current !== '' && !in_array($current, $known, true)) ? $current : '';
    ?>
    <label>ปลายทาง <span style="color:var(--danger);">*</span></label>
    <div data-dest class="mb-2">
      <div class="dest-modes">
        <label class="inline-check" style="margin:0;">
          <input type="radio" name="dest_mode" value="system" data-dest-mode <?= $isExternal ? '' : 'checked' ?>>หน้าในระบบ
        </label>
        <label class="inline-check" style="margin:0;">
          <input type="radio" name="dest_mode" value="external" data-dest-mode <?= $isExternal ? 'checked' : '' ?>>ลิงก์ภายนอก
        </label>
      </div>
      <select name="url" data-dest-system required <?= $isExternal ? 'disabled hidden' : '' ?>>
        <option value="">— เลือกหน้าที่ต้องการ —</option>
        <optgroup label="หน้าระบบ">
          <?php foreach ($sysTargets as $tu => $tl): ?>
          <option value="<?= e($tu) ?>" <?= $current === $tu ? 'selected' : '' ?>><?= e($tl) ?></option>
          <?php endforeach; ?>
        </optgroup>
        <?php if ($pages): ?>
        <optgroup label="หน้าเพจที่สร้างเอง">
          <?php foreach ($pages as $pg): $pv = 'page.php?slug=' . $pg['slug']; ?>
          <option value="<?= e($pv) ?>" <?= $current === $pv ? 'selected' : '' ?>><?= e($pg['title']) ?></option>
          <?php endforeach; ?>
        </optgroup>
        <?php endif; ?>
        <?php if ($orphan !== ''): ?>
        <optgroup label="ปลายทางเดิม">
          <option value="<?= e($orphan) ?>" selected><?= e($orphan) ?></option>
        </optgroup>
        <?php endif; ?>
      </select>
      <input type="text" name="url" data-dest-external required placeholder="https://..."
             value="<?= $isExternal ? e($current) : '' ?>" <?= $isExternal ? '' : 'disabled hidden' ?>>
    </div>
    <?php
}

/** เปิดโหมด "คุมเมนูท้ายเว็บเอง" อยู่หรือไม่ — ปิดอยู่ (ค่าเริ่มต้น) = footer.php ใช้รายการอัตโนมัติเดิม ไม่เปลี่ยนพฤติกรรม */
function footer_custom_on(): bool {
    return setting('footer_custom', '0') === '1';
}

/** ชื่อคอลัมน์ footer (1-3) — ถ้ายังไม่ได้ตั้งเอง ใช้ชื่อเดิมที่เคยตายตัวในโค้ด (ข่าวสาร/หน่วยงาน/นโยบาย) */
function footer_col_title(int $col): string {
    $defaults = [1 => 'ข่าวสาร', 2 => 'หน่วยงาน', 3 => 'นโยบาย'];
    return setting('footer_col' . $col . '_title', $defaults[$col] ?? '');
}

/** ลิงก์ footer แบบกำหนดเอง จัดกลุ่มตามคอลัมน์ (เฉพาะที่เปิดแสดง เรียงตามลำดับที่จัดไว้) */
function footer_links_grouped(): array {
    $out = [1 => [], 2 => [], 3 => []];
    try {
        $rows = db()->query("SELECT * FROM footer_links WHERE enabled = 1 ORDER BY col ASC, sort_order ASC, id ASC")->fetchAll();
    } catch (Throwable $e) { return $out; }
    foreach ($rows as $r) {
        $col = (int)$r['col'];
        if (isset($out[$col])) $out[$col][] = $r;
    }
    return $out;
}

/**
 * ค่าที่ควรแสดงในช่องกรอก — ใช้ค่าที่ผู้ใช้เพิ่งพิมพ์ก่อน (กรณีบันทึกไม่ผ่านแล้วหน้าถูกเรนเดอร์ใหม่)
 * ถ้าไม่มีค่อยใช้ค่าจากฐานข้อมูล/ค่าเริ่มต้น — กันเจ้าหน้าที่พิมพ์ยาวแล้วต้องพิมพ์ใหม่ทั้งหมด
 *
 * ใช้:  <input value="<?= e(old('title', $edit['title'] ?? '')) ?>">
 * (ตอนบันทึกสำเร็จหน้าจะ redirect ทำให้ $_POST ว่าง จึงไม่กระทบการแก้ไขปกติ)
 */
function old(string $key, $fallback = '') {
    if (!isset($_POST[$key])) return $fallback;
    $v = $_POST[$key];
    return is_scalar($v) ? (string)$v : $fallback;
}

/** เหมือน old() แต่สำหรับ checkbox/radio — คืน true ถ้าค่าที่พิมพ์ไว้ตรงกับที่ระบุ */
function old_checked(string $key, $value, bool $fallback = false): bool {
    if (!isset($_POST[$key])) return $fallback;
    $v = $_POST[$key];
    if (is_array($v)) return in_array((string)$value, array_map('strval', $v), true);
    return (string)$v === (string)$value;
}

/** ตรวจลิงก์ภายนอก (Google Drive ฯลฯ) — คืน URL ถ้าเป็น http/https เท่านั้น ไม่งั้น '' */
function ext_link_clean(string $u): string {
    $u = trim($u);
    return ($u !== '' && preg_match('#^https?://#i', $u) && mb_strlen($u) <= 500) ? $u : '';
}

/**
 * กรองลิงก์ที่ผู้ดูแลกรอกเอง (สไลด์/เมนู/ITA/ลิงก์หน่วยงาน)
 * อนุญาต: ลิงก์ภายในเว็บ (news.php, /page/xxx) และ scheme ปลอดภัย http, https, mailto, tel
 * บล็อก: javascript:, data:, vbscript:, file: ฯลฯ ที่ใช้ยิงสคริปต์ใส่ผู้ใช้คนอื่นได้
 * คืน '' ถ้าไม่ปลอดภัย (ผู้เรียกจะได้เก็บค่าว่างแทนลิงก์อันตราย)
 */
function safe_link_url(string $u): string {
    /* ตัดอักขระควบคุมออกก่อน — เบราว์เซอร์ข้าม \t \n \r ตอนตีความ scheme (เช่น "java\tscript:") */
    $u = preg_replace('/[\x00-\x1F\x7F]+/', '', trim($u)) ?? '';
    if ($u === '' || mb_strlen($u) > 500) return '';
    if (preg_match('#^([a-z][a-z0-9+.\-]*):#i', $u, $m)) {
        $scheme = strtolower($m[1]);
        if (!in_array($scheme, ['http', 'https', 'mailto', 'tel'], true)) return '';
    }
    return $u;
}

/** เดานามสกุลไฟล์จาก URL (เช่น .pdf) — คืน 'link' ถ้าเดาไม่ได้ */
function ext_from_url(string $u): string {
    $path = (string)parse_url($u, PHP_URL_PATH);
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    return preg_match('/^[a-z0-9]{1,5}$/', $ext) ? $ext : 'link';
}

/** สร้าง slug จากข้อความ (รองรับไทย) */
function slugify(string $s): string {
    $s = preg_replace('/\s+/u', '-', trim($s));
    $s = preg_replace('#[^\p{L}\p{N}\-_]+#u', '', (string)$s);
    $s = trim((string)$s, '-');
    return mb_strtolower(mb_substr($s !== '' ? $s : 'page', 0, 120));
}

/** ดึง YouTube video id (11 ตัว) จาก URL หลายรูปแบบ — คืน '' ถ้าไม่ใช่ YouTube */
function youtube_id(string $url): string {
    if (preg_match('~(?:youtube\.com/(?:watch\?v=|embed/|shorts/|live/)|youtu\.be/)([A-Za-z0-9_-]{11})~', $url, $m)) {
        return $m[1];
    }
    return '';
}

/**
 * ภาพปกวิดีโอ — ใช้ภาพที่อัปเองก่อน ถ้าไม่มีค่อยดึงจาก YouTube
 * ภาพจาก YouTube โหลดโดยเบราว์เซอร์ผู้ชม ไม่ผ่านเซิร์ฟเวอร์ จึงใช้ได้แม้เครือข่ายหน่วยงานบล็อกเน็ตขาออก
 */
function video_thumb_url(array $v): string {
    if (!empty($v['cover'])) return url((string)$v['cover']);
    $vid = (string)($v['video_id'] ?? '');
    return $vid !== '' ? 'https://i.ytimg.com/vi/' . rawurlencode($vid) . '/hqdefault.jpg' : '';
}

/**
 * แท็ก <img> ภาพปกวิดีโอ พร้อมตัวดักภาพเสีย — ใช้ทุกที่ที่แสดงภาพปก
 * มี 2 กรณีที่ภาพใช้ไม่ได้ และต้องดักคนละทาง:
 *   1. โหลดไม่ได้เลย (เครือข่ายบล็อก YouTube) → onerror
 *   2. รหัสวิดีโอไม่มีอยู่จริง (พิมพ์ผิด) → YouTube ไม่ส่ง error แต่ส่งภาพเทาเปล่า 120px มาแทน
 *      จึงต้องเช็คความกว้างตอน onload ด้วย (ภาพจริง = 480px)
 * ทั้งสองกรณีจะติดคลาส is-missing → ซ่อนภาพ เหลือพื้นเข้ม+ไอคอนวิดีโอ ซึ่งยังกดเล่นได้ปกติ
 */
function video_thumb_img(array $v, string $class = '', string $alt = ''): string {
    $src = video_thumb_url($v);
    /* เช็ค 120px เฉพาะภาพที่ดึงจาก YouTube — ภาพปกที่ผู้ดูแลอัปเองจะเล็กแค่ไหนก็ถือว่าตั้งใจ ไม่ต้องซ่อน */
    $sizeGuard = empty($v['cover'])
        ? ' onload="if(this.naturalWidth&lt;=120)this.classList.add(\'is-missing\')"' : '';
    return '<img src="' . e($src) . '" alt="' . e($alt) . '" loading="lazy"'
         . ($class !== '' ? ' class="' . e($class) . '"' : '')
         . $sizeGuard
         . ' onerror="this.classList.add(\'is-missing\')">';
}

/** URL ตัวเล่น YouTube แบบไม่เก็บคุกกี้ติดตาม (โหลดเมื่อผู้ใช้กดเล่นเท่านั้น) */
function video_embed_src(string $videoId, bool $autoplay = true): string {
    return 'https://www.youtube-nocookie.com/embed/' . rawurlencode($videoId)
         . '?rel=0&modestbranding=1&playsinline=1' . ($autoplay ? '&autoplay=1' : '');
}

/** วิดีโอที่เผยแพร่แล้ว เรียงตามลำดับที่จัดเอง */
function videos_published(int $limit = 0): array {
    $sql = 'SELECT * FROM videos WHERE status = \'published\' ORDER BY sort_order ASC, id DESC';
    if ($limit > 0) $sql .= ' LIMIT ' . $limit;
    try { return db()->query($sql)->fetchAll(); } catch (Throwable $e) { return []; }
}

/** ย่อรูปไม่เกิน $max px (ด้านยาวสุด) + บีบอัด + เข้ารหัสใหม่ด้วย GD */
function resize_image(string $path, int $max = 1600): void {
    $info = @getimagesize($path);
    if (!$info) return;
    [$w, $h] = $info;
    $src = @imagecreatefromstring((string)file_get_contents($path));
    if (!$src) return;

    if ($w > $max || $h > $max) {
        $ratio = min($max / $w, $max / $h);
        $nw = (int)round($w * $ratio);
        $nh = (int)round($h * $ratio);
    } else {
        $nw = $w; $nh = $h;
    }

    $dst = imagecreatetruecolor($nw, $nh);
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if (in_array($ext, ['png', 'webp'], true)) {
        // รักษาความโปร่งใส
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
        imagefilledrectangle($dst, 0, 0, $nw, $nh, $transparent);
    }
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

    switch ($ext) {
        case 'png':  imagepng($dst, $path, 8); break;
        case 'webp': imagewebp($dst, $path, 82); break;
        default:     imagejpeg($dst, $path, 82); break;
    }
    imagedestroy($src);
    imagedestroy($dst);
}

/**
 * ครอบตัดรูปด้วย GD ตามสัดส่วน (ratio 0..1 ของภาพต้นฉบับ) แล้วย่อ/ขยายเป็น $outW×$outH
 * ใช้ ratio เพื่อให้แม่นยำไม่ว่าภาพจะถูกย่อขนาดมาก่อนหรือไม่ — เขียนทับไฟล์เดิม
 */
function crop_image(string $path, float $rx, float $ry, float $rw, float $rh, int $outW = 1600, int $outH = 640): bool {
    if (!is_file($path)) return false;
    $info = @getimagesize($path);
    if (!$info) return false;
    [$w, $h] = $info;
    $src = @imagecreatefromstring((string)file_get_contents($path));
    if (!$src) return false;

    $rx = max(0, min(1, $rx)); $ry = max(0, min(1, $ry));
    $rw = max(0.02, min(1, $rw)); $rh = max(0.02, min(1, $rh));
    $sx = (int)round($rx * $w); $sy = (int)round($ry * $h);
    $sw = (int)round($rw * $w); $sh = (int)round($rh * $h);
    if ($sx + $sw > $w) $sw = $w - $sx;
    if ($sy + $sh > $h) $sh = $h - $sy;
    if ($sw <= 0 || $sh <= 0) { imagedestroy($src); return false; }

    $dst = imagecreatetruecolor($outW, $outH);
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if (in_array($ext, ['png', 'webp'], true)) {
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $t = imagecolorallocatealpha($dst, 0, 0, 0, 127);
        imagefilledrectangle($dst, 0, 0, $outW, $outH, $t);
    }
    imagecopyresampled($dst, $src, 0, 0, $sx, $sy, $outW, $outH, $sw, $sh);
    switch ($ext) {
        case 'png':  imagepng($dst, $path, 8); break;
        case 'webp': imagewebp($dst, $path, 85); break;
        default:     imagejpeg($dst, $path, 86); break;
    }
    imagedestroy($src);
    imagedestroy($dst);
    return true;
}

/** ลบไฟล์อัปโหลดอย่างปลอดภัย (เฉพาะไฟล์ใต้โฟลเดอร์ uploads เท่านั้น) */
function delete_upload(?string $relPath): void {
    if (!$relPath) return;
    $real = realpath(APP_ROOT . '/' . $relPath);
    $base = realpath(APP_ROOT . '/uploads');
    if ($real && $base && str_starts_with($real, $base) && is_file($real)) {
        @unlink($real);
    }
}

/* ─────────────────────────────────────────────
   สถิติผู้เข้าชม (ไม่เก็บข้อมูลส่วนบุคคล)
   ───────────────────────────────────────────── */

/** นับ page view รายวันต่อหน้า — เก็บเฉพาะชื่อหน้า + วันที่ + จำนวน */
function track_page_view(string $page): void {
    /* PDPA — คุกกี้วิเคราะห์การใช้งานต้องได้รับความยินยอมก่อน
       ผู้ที่เลือก "ใช้เฉพาะที่จำเป็น" จะไม่ถูกเก็บสถิติ (การกดปฏิเสธต้องมีผลจริง) */
    if (($_COOKIE['cookie_consent'] ?? '') === 'essential') return;

    /* กรองบอท/โปรแกรมอัตโนมัติ — ไม่นับเข้าสถิติ เพื่อให้ตัวเลขสะท้อนคนจริง */
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    if ($ua === '' || preg_match('/bot|crawl|spider|slurp|bingpreview|facebookexternalhit|whatsapp|telegrambot|headless|phantom|monitor|uptime|pingdom|curl|wget|python-requests|axios|go-http|libwww|java\/|okhttp/i', $ua)) {
        return;
    }
    $page = mb_substr($page, 0, 100);
    /* นับครั้งเดียวต่อ session ต่อหน้า ต่อวัน — กด F5 ซ้ำในเบราว์เซอร์เดิมไม่เพิ่มยอด (แม่นยำขึ้น) */
    $key = $page . '|' . date('Y-m-d');
    if (!empty($_SESSION['pv'][$key])) return;
    $_SESSION['pv'][$key] = true;
    try {
        $st = db()->prepare('INSERT INTO page_views (vdate, page, views) VALUES (CURDATE(), ?, 1)
                             ON DUPLICATE KEY UPDATE views = views + 1');
        $st->execute([$page]);
    } catch (Throwable $e) { /* ไม่ให้สถิติพังหน้าเว็บ */ }
}

/** ยอดผู้เข้าชมรวมเดือนปัจจุบัน */
function views_this_month(): int {
    try {
        return (int)db()->query("SELECT COALESCE(SUM(views),0) FROM page_views
                                 WHERE vdate >= DATE_FORMAT(CURDATE(), '%Y-%m-01')")->fetchColumn();
    } catch (Throwable $e) { return 0; }
}

/** ยอดผู้เข้าชมสะสมทั้งหมด */
function views_total(): int {
    try {
        return (int)db()->query("SELECT COALESCE(SUM(views),0) FROM page_views")->fetchColumn();
    } catch (Throwable $e) { return 0; }
}

/* ─────────────────────────────────────────────
   แบ่งหน้า (pagination)
   ───────────────────────────────────────────── */

/** สร้าง HTML ปุ่มแบ่งหน้า — $baseUrl ต้องมี query string พร้อมต่อ &page= */
function pagination_html(int $total, int $perPage, int $page, string $baseUrl): string {
    $pages = max(1, (int)ceil($total / $perPage));
    if ($pages <= 1) return '';
    $sep = str_contains($baseUrl, '?') ? '&' : '?';
    $html = '<nav class="pagination" aria-label="แบ่งหน้า">';
    if ($page > 1) {
        $html .= '<a class="btn small" href="' . e($baseUrl . $sep . 'page=' . ($page - 1)) . '"><span class="material-symbols-rounded icon-sm">chevron_left</span></a>';
    }
    $start = max(1, $page - 2);
    $end   = min($pages, $page + 2);
    if ($start > 1) $html .= '<a class="btn small" href="' . e($baseUrl . $sep . 'page=1') . '">1</a>' . ($start > 2 ? '<span class="pg-dots">…</span>' : '');
    for ($i = $start; $i <= $end; $i++) {
        $cls = $i === $page ? 'btn small primary' : 'btn small';
        $html .= '<a class="' . $cls . '" href="' . e($baseUrl . $sep . 'page=' . $i) . '">' . $i . '</a>';
    }
    if ($end < $pages) $html .= ($end < $pages - 1 ? '<span class="pg-dots">…</span>' : '') . '<a class="btn small" href="' . e($baseUrl . $sep . 'page=' . $pages) . '">' . $pages . '</a>';
    if ($page < $pages) {
        $html .= '<a class="btn small" href="' . e($baseUrl . $sep . 'page=' . ($page + 1)) . '"><span class="material-symbols-rounded icon-sm">chevron_right</span></a>';
    }
    return $html . '</nav>';
}

/* ─────────────────────────────────────────────
   ระบบ login + ป้องกัน brute force
   ───────────────────────────────────────────── */

/** ดึง IP ผู้ใช้ */
function client_ip(): string {
    return mb_substr($_SERVER['REMOTE_ADDR'] ?? 'unknown', 0, 45);
}

/** บันทึกการกระทำของผู้ดูแล (เพิ่ม/แก้/ลบ) ลง activity_logs — เพื่อความโปร่งใส/ตรวจสอบย้อนหลัง */
function log_action(string $action, string $detail = ''): void {
    try {
        $st = db()->prepare('INSERT INTO activity_logs (user_id, username, action, detail, ip) VALUES (?,?,?,?,?)');
        $st->execute([
            $_SESSION['admin_id'] ?? null,
            mb_substr((string)($GLOBALS['ADMIN']['username'] ?? 'system'), 0, 100),
            mb_substr($action, 0, 100),
            mb_substr($detail, 0, 255),
            client_ip(),
        ]);
    } catch (Throwable $e) { /* ไม่ให้ log พังการทำงานหลัก */ }
}

/** ตรวจว่า user/IP นี้ถูกล็อกจากการ login ผิดเกิน 5 ครั้งใน 15 นาทีหรือไม่
 *  @return int วินาทีที่เหลือก่อนปลดล็อก (0 = ไม่ถูกล็อก) */
function login_lock_remaining(string $username): int {
    /* คำนวณเวลาที่เหลือในฝั่ง MySQL ทั้งหมด — กันปัญหา timezone PHP/MySQL ไม่ตรงกัน */
    $st = db()->prepare("SELECT COUNT(*) AS n,
                         TIMESTAMPDIFF(SECOND, NOW(), MAX(created_at) + INTERVAL 15 MINUTE) AS remain
                         FROM login_logs
                         WHERE success = 0 AND (username = ? OR ip = ?)
                         AND created_at > (NOW() - INTERVAL 15 MINUTE)");
    $st->execute([$username, client_ip()]);
    $r = $st->fetch();
    if ((int)$r['n'] >= 5) {
        return max(0, (int)$r['remain']);
    }
    return 0;
}

/** บันทึก log การ login ทุกครั้ง (เวลา, IP, สำเร็จ/ล้มเหลว) */
function login_log(string $username, bool $success): void {
    $st = db()->prepare('INSERT INTO login_logs (username, ip, success) VALUES (?, ?, ?)');
    $st->execute([mb_substr($username, 0, 100), client_ip(), $success ? 1 : 0]);
}

/** ตรวจสอบรูปแบบสี hex เช่น #1A73E8 */
function valid_hex(string $c): bool {
    return (bool)preg_match('/^#[0-9A-Fa-f]{6}$/', $c);
}

/** คำนวณสีเข้มขึ้นสำหรับ hover (--blue-700) จากสีหลัก */
function shade_hex(string $hex, float $factor = 0.68): string {
    $n = hexdec(substr($hex, 1));
    $f = fn($x) => max(0, (int)round($x * $factor));
    return sprintf('#%02x%02x%02x', $f(($n >> 16) & 255), $f(($n >> 8) & 255), $f($n & 255));
}
