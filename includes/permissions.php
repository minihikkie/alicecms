<?php
/**
 * permissions.php — สิทธิ์การใช้งานหลังบ้านแบบกำหนดเองรายเมนู (v1.66.0)
 *
 * แนวคิด
 *   - ผู้ใช้ role = 'admin'  → ผู้ดูแลระบบสูงสุด ทำได้ทุกอย่าง (ไม่ผ่านตารางสิทธิ์)
 *   - ผู้ใช้ role = 'editor' → ได้สิทธิ์ตาม "กลุ่มสิทธิ์" (ตาราง roles) ที่ผูกไว้ด้วย users.role_id
 *   - แต่ละเมนู (module) มี 4 ระดับ: 0 ไม่เห็น · 1 ดูอย่างเดียว · 2 เพิ่ม/แก้ไข · 3 เพิ่ม/แก้ไข/ลบ
 *
 * การบังคับใช้อยู่ที่ด่านเดียว (admin/_auth.php → perm_enforce()) ทุกหน้าหลังบ้านต้องผ่าน:
 *   GET  ต้องมีระดับ ≥ 1 · POST ต้อง ≥ 2 · POST ที่เป็นการลบต้อง ≥ 3
 *   หน้าที่ไม่อยู่ในรายการ (perm_modules) = เฉพาะผู้ดูแลระบบสูงสุด — หน้าใหม่ที่เพิ่มในอนาคต
 *   จึงปลอดภัยไว้ก่อนจนกว่าจะมาเพิ่มในรายการนี้
 */
if (!defined('APP_ROOT')) exit('Forbidden');

const PERM_NONE = 0, PERM_VIEW = 1, PERM_EDIT = 2, PERM_FULL = 3;

/** ชื่อระดับสิทธิ์ (ใช้ในหน้าจัดการ) */
function perm_level_names(): array {
    return [PERM_NONE => 'ไม่เห็นเมนู', PERM_VIEW => 'ดูอย่างเดียว', PERM_EDIT => 'เพิ่ม · แก้ไข', PERM_FULL => 'เพิ่ม · แก้ไข · ลบ'];
}

/**
 * เมนูที่มอบสิทธิ์ได้: key => [ชื่อ, กลุ่ม, ระดับสูงสุดที่มอบได้, คำอธิบาย]
 * key = ชื่อไฟล์หน้าใน admin/ (ไม่มี .php) — หน้าย่อยผูกกับเมนูแม่ด้วย perm_page_module()
 */
function perm_modules(): array {
    return [
        'highlights'      => ['ข่าวด่วน · ประชาสัมพันธ์', 'เนื้อหาเว็บไซต์', 3, ''],
        'posts'           => ['ข่าวสาร', 'เนื้อหาเว็บไซต์', 3, 'รวมหน้าเขียน/แก้ข่าว'],
        'videos'          => ['วิดีโอความรู้', 'เนื้อหาเว็บไซต์', 3, ''],
        'posters'         => ['นิทรรศการโปสเตอร์', 'เนื้อหาเว็บไซต์', 3, ''],
        'slides'          => ['แบนเนอร์สไลด์', 'เนื้อหาเว็บไซต์', 3, ''],
        'exec-message'    => ['สารหัวหน้าหน่วยงาน', 'เนื้อหาเว็บไซต์', 2, ''],
        'documents'       => ['เอกสารเผยแพร่', 'เนื้อหาเว็บไซต์', 3, 'รวมหมวดเอกสารและสารบัญแบบหนังสือ'],
        'procurement'     => ['จัดซื้อจัดจ้าง', 'เนื้อหาเว็บไซต์', 3, ''],
        'ita'             => ['ITA / OIT', 'เนื้อหาเว็บไซต์', 3, ''],
        'faq'             => ['คำถามที่พบบ่อย', 'เนื้อหาเว็บไซต์', 3, ''],
        'personnel'       => ['ผู้บริหาร', 'เนื้อหาเว็บไซต์', 3, ''],
        'org-chart'       => ['ผังโครงสร้างหน่วยงาน', 'เนื้อหาเว็บไซต์', 3, ''],
        'links'           => ['ลิงก์ที่เกี่ยวข้อง', 'เนื้อหาเว็บไซต์', 3, ''],
        'pages'           => ['หน้าเพจ', 'เครื่องมือสร้างเอง', 3, ''],
        'content'         => ['รายการในประเภทเนื้อหา', 'เครื่องมือสร้างเอง', 3, 'เพิ่ม/แก้รายการของประเภทเนื้อหาที่สร้างไว้'],
        'content-types'   => ['ประเภทเนื้อหา (โครงสร้าง)', 'เครื่องมือสร้างเอง', 3, 'สร้าง/แก้ช่องข้อมูลของประเภทเนื้อหา'],
        'custom-sections' => ['กล่องอิสระหน้าแรก', 'เครื่องมือสร้างเอง', 3, ''],
        'forms'           => ['แบบฟอร์ม/บริการ', 'เครื่องมือสร้างเอง', 3, 'รวมคำตอบที่ประชาชนส่งมา'],
        'media'           => ['คลังสื่อ', 'เครื่องมือสร้างเอง', 3, ''],
        'graphic'         => ['ออกแบบภาพประกาศ', 'เครื่องมือสร้างเอง', 2, ''],
        'complaints'      => ['เรื่องร้องเรียน', 'รับเรื่องจากประชาชน', 3, 'มีข้อมูลส่วนบุคคลของผู้ร้องเรียน (PDPA)'],
        'homepage'        => ['การแสดงผลหน้าแรก', 'รูปลักษณ์ & เมนู', 2, ''],
        'theme'           => ['ธีม & หน้าตา', 'รูปลักษณ์ & เมนู', 2, ''],
        'menu'            => ['เมนูนำทาง', 'รูปลักษณ์ & เมนู', 3, ''],
        'footer-menu'     => ['เมนูท้ายเว็บ', 'รูปลักษณ์ & เมนู', 3, ''],
        'alert'           => ['แถบประกาศด่วน', 'รูปลักษณ์ & เมนู', 2, ''],
        'site-info'       => ['ตั้งค่าเว็บไซต์', 'ระบบ', 2, 'ชื่อหน่วยงาน โลโก้ ข้อมูลติดต่อ'],
        'stats'           => ['สถิติผู้เข้าชม', 'ระบบ', 1, ''],
        'users'           => ['ผู้ใช้งานระบบ', 'ระบบ', 3, 'มอบให้ผู้อื่นได้ไม่เกินสิทธิ์ของตัวเอง · แตะบัญชีผู้ดูแลระบบสูงสุดไม่ได้'],
        'activity'        => ['บันทึกการใช้งาน', 'ระบบ', 1, ''],
        'trash'           => ['ถังขยะ', 'ระบบ', 3, 'แก้ไข = กู้คืน · ลบ = ลบถาวรทีละรายการ (เฉพาะเมนูที่มีสิทธิ์)'],
    ];
}

/** หน้าที่ทุกคนที่ล็อกอินแล้วเข้าได้ */
function perm_open_pages(): array {
    return ['index', 'password', 'twofa'];
}

/** หน้า → เมนูที่ใช้ตรวจสิทธิ์ (หน้าย่อยใช้สิทธิ์ของเมนูแม่) */
function perm_page_module(string $script): string {
    $p = preg_replace('/\.php$/', '', basename($script));
    return ['doc-book' => 'documents', 'post-edit' => 'posts', 'complaint-file' => 'complaints'][$p] ?? $p;
}

/** ตารางในถังขยะ → เมนู (ใช้กรองถังขยะและตรวจสิทธิ์กู้คืน/ลบถาวร) */
function perm_table_module(string $table): string {
    return [
        'posts' => 'posts', 'documents' => 'documents', 'doc_categories' => 'documents', 'doc_toc' => 'documents',
        'procurements' => 'procurement', 'ita_items' => 'ita', 'personnel' => 'personnel', 'links' => 'links',
        'videos' => 'videos', 'highlights' => 'highlights', 'posters' => 'posters', 'custom_sections' => 'custom-sections',
        'footer_links' => 'footer-menu', 'slides' => 'slides', 'faqs' => 'faq', 'pages' => 'pages',
        'content_items' => 'content', 'content_types' => 'content-types', 'forms' => 'forms', 'complaints' => 'complaints',
        'menu_items' => 'menu', 'org_chart_nodes' => 'org-chart', 'media' => 'media',
    ][$table] ?? '';
}

/** ทำความสะอาดชุดสิทธิ์: เฉพาะเมนูที่รู้จัก · ระดับ 0–สูงสุดของเมนูนั้น · ตัด 0 ทิ้ง */
function perm_clean($raw): array {
    if (is_string($raw)) $raw = json_decode($raw, true);
    if (!is_array($raw)) return [];
    $out = [];
    foreach (perm_modules() as $k => $m) {
        $lv = max(0, min($m[2], (int)($raw[$k] ?? 0)));
        if ($lv > 0) $out[$k] = $lv;
    }
    return $out;
}

/** สิทธิ์ของ "เจ้าหน้าที่" แบบเดิม (ก่อน v1.66) — ใช้ตั้งกลุ่มสิทธิ์เริ่มต้นตอนอัปเดต ให้ทุกคนได้สิทธิ์เท่าเดิม */
function perm_legacy_editor(): array {
    $all = ['highlights', 'posts', 'videos', 'posters', 'slides', 'exec-message', 'documents', 'procurement', 'ita',
            'faq', 'personnel', 'org-chart', 'links', 'pages', 'content', 'forms', 'media', 'graphic', 'complaints',
            'stats', 'trash'];
    return perm_clean(array_fill_keys($all, PERM_FULL));
}

/** แม่แบบกลุ่มสิทธิ์สำเร็จรูป (ปุ่มเติมค่าในหน้ากลุ่มสิทธิ์) */
function perm_templates(): array {
    $content = ['highlights', 'posts', 'videos', 'posters', 'slides', 'exec-message', 'documents', 'procurement',
                'ita', 'faq', 'personnel', 'org-chart', 'links', 'pages', 'content', 'media', 'graphic'];
    return [
        'content'  => ['ผู้ดูแลเนื้อหา', 'แก้เนื้อหาทุกเมนู ไม่แตะการตั้งค่าและผู้ใช้',
                       array_fill_keys($content, PERM_FULL) + ['forms' => PERM_FULL, 'stats' => PERM_VIEW, 'trash' => PERM_EDIT]],
        'news'     => ['ฝ่ายข่าวประชาสัมพันธ์', 'ข่าว ข่าวด่วน วิดีโอ โปสเตอร์ สไลด์ (ลบไม่ได้)',
                       ['highlights' => PERM_EDIT, 'posts' => PERM_EDIT, 'videos' => PERM_EDIT, 'posters' => PERM_EDIT,
                        'slides' => PERM_EDIT, 'media' => PERM_EDIT, 'graphic' => PERM_EDIT]],
        'docs'     => ['ฝ่ายเอกสาร / กฎหมาย', 'เอกสารเผยแพร่ จัดซื้อจัดจ้าง ITA',
                       ['documents' => PERM_FULL, 'procurement' => PERM_FULL, 'ita' => PERM_FULL, 'media' => PERM_EDIT, 'trash' => PERM_EDIT]],
        'service'  => ['เจ้าหน้าที่รับเรื่อง', 'เรื่องร้องเรียน แบบฟอร์ม คำถามที่พบบ่อย',
                       ['complaints' => PERM_EDIT, 'forms' => PERM_EDIT, 'faq' => PERM_EDIT]],
        'viewer'   => ['ผู้ตรวจ / ดูอย่างเดียว', 'ดูได้ทุกเมนูเนื้อหา แก้ไม่ได้',
                       array_fill_keys(array_merge($content, ['forms', 'complaints', 'stats', 'activity']), PERM_VIEW)],
        'manager'  => ['หัวหน้างาน (ดูแลผู้ใช้ได้)', 'แก้เนื้อหาทุกเมนู + จัดการบัญชีเจ้าหน้าที่ในขอบเขตของตัวเอง',
                       array_fill_keys($content, PERM_FULL) + ['forms' => PERM_FULL, 'complaints' => PERM_FULL,
                        'stats' => PERM_VIEW, 'activity' => PERM_VIEW, 'trash' => PERM_FULL, 'users' => PERM_FULL]],
    ];
}

/* ─────────────── สิทธิ์ของผู้ใช้ปัจจุบัน ─────────────── */

/** ชุดสิทธิ์ของผู้ใช้ปัจจุบัน — null = ผู้ดูแลระบบสูงสุด (ทุกอย่าง) */
function perm_current(): ?array {
    return $GLOBALS['ADMIN_PERMS'] ?? [];
}

/** ระดับสิทธิ์ของผู้ใช้ปัจจุบันในเมนูหนึ่ง */
function perm_level(string $module): int {
    if (($GLOBALS['ADMIN']['role'] ?? '') === 'admin') return PERM_FULL;
    if (in_array($module, perm_open_pages(), true)) return PERM_FULL;
    return (int)(perm_current()[$module] ?? 0);
}

function can(string $module, int $level = PERM_VIEW): bool {
    return perm_level($module) >= $level;
}

/** ชุดสิทธิ์ A อยู่ในขอบเขตของ B หรือไม่ (ทุกเมนูของ A ≤ B) — ใช้กันการมอบสิทธิ์เกินตัว */
function perm_subset(array $a, ?array $b): bool {
    if ($b === null) return true;
    foreach ($a as $k => $lv) if ($lv > (int)($b[$k] ?? 0)) return false;
    return true;
}

/** โหลดสิทธิ์ของผู้ใช้ (เรียกใน _auth.php) */
function perm_load(array $user): ?array {
    if (($user['role'] ?? '') === 'admin') return null;
    if (empty($user['role_id'])) return [];
    try {
        $st = db()->prepare('SELECT perms FROM roles WHERE id = ?');
        $st->execute([(int)$user['role_id']]);
        return perm_clean((string)$st->fetchColumn());
    } catch (Throwable $e) { return []; }
}

/** POST นี้เป็นการลบหรือไม่ (รูปแบบที่ใช้ในหน้าหลังบ้านทั้งหมด) */
function perm_is_delete_request(array $post): bool {
    foreach (['delete_id', 'del_response', 'del_att', 'purge_id', 'empty_all', 'del_autobackup', 'delete_role'] as $k) {
        if (isset($post[$k])) return true;
    }
    return ($post['action'] ?? '') === 'delete' || ($post['cat_action'] ?? '') === 'delete';
}

/** ปฏิเสธ: บันทึก + แจ้งผู้ใช้อย่างสุภาพ แล้วพากลับ */
function perm_deny(string $module, string $what): void {
    $label = perm_modules()[$module][0] ?? $module;
    if (function_exists('log_action')) log_action('ถูกปฏิเสธสิทธิ์', mb_substr($what . ' · ' . $label, 0, 200));
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        flash_set('danger', 'คุณไม่มีสิทธิ์' . $what . 'ในเมนู "' . $label . '" — ติดต่อผู้ดูแลระบบหากต้องการสิทธิ์เพิ่ม');
        $back = 'admin/' . (isset(perm_modules()[$module]) ? $module : 'index') . '.php';
        if ($module === 'content' && isset($_GET['type'])) $back .= '?type=' . rawurlencode((string)$_GET['type']);
        redirect($back);
    }
    flash_set('danger', 'คุณไม่มีสิทธิ์เข้าเมนู "' . $label . '"');
    redirect('admin/index.php');
}

/** ด่านตรวจสิทธิ์ของหน้าปัจจุบัน — เรียกจาก _auth.php ทุกหน้า */
function perm_enforce(): void {
    if (($GLOBALS['ADMIN']['role'] ?? '') === 'admin') return;
    $module = perm_page_module($_SERVER['SCRIPT_NAME'] ?? '');
    if (in_array($module, perm_open_pages(), true)) return;
    $isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

    if (!isset(perm_modules()[$module])) {
        /* หน้าระบบที่สงวนไว้ให้ผู้ดูแลระบบสูงสุด (สำรองข้อมูล โอนย้าย อัปเดต แจ้งเตือน กลุ่มสิทธิ์ ฯลฯ) */
        if (function_exists('log_action')) log_action('ถูกปฏิเสธสิทธิ์', 'หน้า ' . mb_substr($module, 0, 60));
        flash_set('danger', 'หน้านี้สงวนไว้สำหรับผู้ดูแลระบบสูงสุด');
        redirect('admin/index.php');
    }

    $lv = perm_level($module);
    /* หน้าต่างเลือกภาพจากคลังสื่อ (เปิดจากช่องแก้ข้อความของทุกเมนู) — ผู้ที่แก้เนื้อหาเมนูใดก็ได้ดูคลังสื่อได้ */
    if ($module === 'media' && !$isPost && isset($_GET['pick']) && $lv < PERM_VIEW) {
        foreach (perm_current() ?? [] as $lvx) if ($lvx >= PERM_EDIT) return;
    }
    if ($lv < PERM_VIEW) perm_deny($module, 'เข้าดู');
    if ($isPost) {
        if (perm_is_delete_request($_POST)) { if ($lv < PERM_FULL) perm_deny($module, 'ลบข้อมูล'); }
        elseif ($lv < PERM_EDIT) perm_deny($module, 'เพิ่มหรือแก้ไขข้อมูล');
    }
}

/** ชื่อกลุ่มสิทธิ์ของผู้ใช้ปัจจุบัน (โชว์บนแถบบน) */
function perm_role_label(): string {
    if (($GLOBALS['ADMIN']['role'] ?? '') === 'admin') return 'ผู้ดูแลระบบสูงสุด';
    return (string)($GLOBALS['ADMIN']['role_name'] ?? '') ?: 'เจ้าหน้าที่';
}

/** ฐานข้อมูลมีตารางกลุ่มสิทธิ์แล้วหรือยัง (กันหน้าแตกถ้า migrate ไม่สำเร็จ) — ไม่พร้อม = แจ้งแล้วกลับแดชบอร์ด */
function perm_require_schema(): void {
    try { db()->query('SELECT is_active, role_id FROM users LIMIT 1'); db()->query('SELECT id FROM roles LIMIT 1'); return; }
    catch (Throwable $e) {
        flash_set('danger', 'ฐานข้อมูลยังไม่ได้อัปเดตโครงสร้างสำหรับกลุ่มสิทธิ์ — ไปที่ อัปเดตระบบ หรือรัน migrate.php อีกครั้ง');
        redirect('admin/index.php');
    }
}
