<?php
/**
 * _top.php — โครงหน้า admin ส่วนบน (topbar + เมนูซ้าย)
 * ตัวแปรที่รับ: $admin_title (ชื่อหน้า)
 */
if (!defined('APP_ROOT')) exit('Forbidden');

$theme    = valid_hex(setting('theme_color', '')) ? setting('theme_color') : '#1A73E8';
$theme700 = valid_hex(setting('theme_color_dark', '')) ? setting('theme_color_dark') : '#1557B0';
$anim     = setting('anim_level', 'full');

/* นับเรื่องร้องเรียนใหม่สำหรับ badge เมนู */
$n_new_complaints = (int)db()->query("SELECT COUNT(*) FROM complaints WHERE status = 'new'")->fetchColumn();
/* จำนวนของในถังขยะ (โชว์เป็นป้ายข้างเมนู) */
$n_trash = trash_count();

/* สำรองข้อมูลอัตโนมัติ — ตรวจว่าถึงรอบหรือยัง (ทำเงียบๆ ไม่รบกวนการใช้งาน) */
require_once APP_ROOT . '/includes/autobackup.php';
$auto_backup_msg = ab_maybe_run();

/* เมนูซ้ายแบบจัดกลุ่ม: [หัวข้อกลุ่ม, [[ไฟล์, ไอคอน, ชื่อ, เฉพาะ admin?], ...]] */
$is_admin = is_admin_role();
$menu_groups = [
    ['', [
        ['index.php',        'dashboard',        'แดชบอร์ด',           false],
    ]],
    ['เนื้อหาเว็บไซต์', [
        ['posts.php',        'newspaper',        'ข่าวสาร',            false],
        ['videos.php',       'smart_display',    'วิดีโอความรู้',      false],
        ['slides.php',       'view_carousel',    'แบนเนอร์สไลด์',      false],
        ['exec-message.php', 'person',           'สารหัวหน้าหน่วยงาน', false],
        ['documents.php',    'folder_open',      'เอกสารเผยแพร่',      false],
        ['procurement.php',  'shopping_cart',    'จัดซื้อจัดจ้าง',     false],
        ['ita.php',          'verified',         'ITA / OIT',          false],
        ['faq.php',          'quiz',             'คำถามที่พบบ่อย',     false],
        ['personnel.php',    'groups',           'ผู้บริหาร',          false],
        ['org-chart.php',    'account_tree',     'ผังโครงสร้างหน่วยงาน', false],
        ['links.php',        'link',             'ลิงก์ที่เกี่ยวข้อง', false],
    ]],
    ['เครื่องมือสร้างเอง', [
        ['pages.php',        'description',      'หน้าเพจ',            false],
        ['content-types.php','category',         'ประเภทเนื้อหา',      true],
        ['forms.php',        'dynamic_form',     'แบบฟอร์ม/บริการ',    false],
        ['media.php',        'perm_media',       'คลังสื่อ',           false],
        ['graphic.php',      'design_services',  'ออกแบบภาพประกาศ',    false],
    ]],
    ['รับเรื่องจากประชาชน', [
        ['complaints.php',   'support_agent',    'เรื่องร้องเรียน',    false],
    ]],
    ['รูปลักษณ์ & เมนู', [
        ['homepage.php',     'web',              'การแสดงผลหน้าแรก',   true],
        ['theme.php',        'palette',          'ธีม & หน้าตา',       true],
        ['menu.php',         'menu',             'เมนูนำทาง',          true],
        ['footer-menu.php',  'footer',           'เมนูท้ายเว็บ',       true],
        ['alert.php',        'campaign',         'แถบประกาศด่วน',      true],
    ]],
    ['ระบบ', [
        ['site-info.php',    'settings',         'ตั้งค่าเว็บไซต์',    true],
        ['stats.php',        'monitoring',       'สถิติผู้เข้าชม',     false],
        ['users.php',        'group',            'ผู้ใช้งานระบบ',      true],
        ['activity.php',     'history',          'บันทึกการใช้งาน',    true],
        ['trash.php',        'delete',           'ถังขยะ',             false],
        ['backup.php',       'cloud_download',   'สำรองข้อมูล',        true],
        ['transfer.php',     'swap_horiz',       'โอนย้ายเว็บ',        true],
        ['notify.php',       'mark_email_unread','แจ้งเตือนอีเมล',     true],
        ['update.php',       'system_update',    'อัปเดตระบบ',         true],
    ]],
    ['บัญชีของฉัน', [
        ['password.php',     'key',              'เปลี่ยนรหัสผ่าน',    false],
        ['twofa.php',        'phonelink_lock',   'ยืนยันตัวตน 2 ชั้น', false],
    ]],
];
$current = basename($_SERVER['SCRIPT_NAME'] ?? '');
$flash = flash_get();
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= isset($admin_title) ? e($admin_title) . ' — ' : '' ?>ระบบจัดการเว็บไซต์</title>
<meta name="robots" content="noindex, nofollow">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200&display=block" rel="stylesheet">
<link rel="stylesheet" href="<?= e(asset_url('theme-kit.css')) ?>">
<link rel="stylesheet" href="<?= e(asset_url('assets/css/site.css')) ?>">
<link rel="stylesheet" href="<?= e(asset_url('assets/css/animations.css')) ?>">
<link rel="stylesheet" href="<?= e(asset_url('assets/css/admin.css')) ?>">
<link rel="icon" type="image/svg+xml" href="<?= e(setting('favicon') ? url(setting('favicon')) : url('assets/img/favicon.svg')) ?>">
<style>:root { --blue: <?= e($theme) ?>; --blue-700: <?= e($theme700) ?>; }</style>
</head>
<body class="anim-<?= e(in_array($anim, ['full','min','off'], true) ? $anim : 'full') ?>">

<header class="topbar">
  <div class="container topbar-inner">
    <?php /* ตรา AliceCMS — วาดเป็น SVG ในหน้าเลย ไม่ใช่ไฟล์ภาพ เพราะใช้ currentColor
             จึงเปลี่ยนสีตามธีมที่หน่วยงานตั้งไว้ และไม่เสียรอบโหลดเพิ่มอีกหนึ่งไฟล์ */ ?>
    <div class="logo" title="AliceCMS <?= e(APP_VERSION) ?>">
      <svg viewBox="0 0 64 64" aria-hidden="true" focusable="false">
        <g fill="currentColor" transform="translate(-2.2 5.6) scale(.95)">
          <rect x="13" y="19" width="38" height="7" rx="3.5" opacity=".55"/>
          <rect x="13" y="30" width="38" height="7" rx="3.5" opacity=".8"/>
          <rect x="13" y="41" width="25" height="7" rx="3.5"/>
          <path d="M46.5 6C47.85 12.3 49.2 13.65 55.5 15C49.2 16.35 47.85 17.7 46.5 24C45.15 17.7 43.8 16.35 37.5 15C43.8 13.65 45.15 12.3 46.5 6Z"/>
          <path d="M54 20C54.75 23.5 55.25 24.25 59 25C55.25 25.75 54.75 26.5 54 30C53.25 26.5 52.75 25.75 49 25C52.75 24.25 53.25 23.5 54 20Z"/>
          <path d="M42 22.4C42.54 24.92 42.9 25.46 45.6 26C42.9 26.54 42.54 27.08 42 29.6C41.46 27.08 41.1 26.54 38.4 26C41.1 25.46 41.46 24.92 42 22.4Z"/>
        </g>
      </svg>
    </div>
    <div class="brand">ระบบจัดการเว็บไซต์<small><?= e(setting('site_name', 'หน่วยงาน')) ?> — สำหรับเจ้าหน้าที่</small></div>
    <nav class="nav">
      <a class="badge" href="<?= e(url('index.php')) ?>" target="_blank" rel="noopener"><span class="material-symbols-rounded icon-sm">open_in_new</span>ดูหน้าเว็บ</a>
      <span class="badge success"><span class="material-symbols-rounded icon-sm">verified_user</span><?= e($ADMIN['display_name'] ?: $ADMIN['username']) ?> · <?= $is_admin ? 'ผู้ดูแลระบบ' : 'เจ้าหน้าที่' ?></span>
      <a class="btn danger small" href="<?= e(url('admin/logout.php')) ?>"><span class="material-symbols-rounded icon-sm">logout</span>ออกจากระบบ</a>
    </nav>
  </div>
</header>

<?php if ($flash): ?>
<div class="toast <?= $flash['type'] === 'danger' ? 'danger' : '' ?>">
  <span class="material-symbols-rounded"><?= $flash['type'] === 'danger' ? 'error' : 'check_circle' ?></span>
  <?= e($flash['msg']) ?>
</div>
<?php endif; ?>

<div class="container mt-3 mb-4">
  <div class="admin-grid">

    <!-- เมนูซ้าย (จัดกลุ่ม) -->
    <div class="card card-compact side-menu">
      <?php foreach ($menu_groups as [$gtitle, $gitems]):
        $vis = array_values(array_filter($gitems, fn($m) => !$m[3] || $is_admin));
        if (!$vis) continue; ?>
        <?php if ($gtitle !== ''): ?><div class="menu-group-label"><?= e($gtitle) ?></div><?php endif; ?>
        <?php foreach ($vis as [$file, $icon, $label]): ?>
        <a class="<?= $current === $file ? 'on' : '' ?>" href="<?= e(url('admin/' . $file)) ?>">
          <span class="material-symbols-rounded icon-sm"><?= e($icon) ?></span><?= e($label) ?>
          <?php if ($file === 'complaints.php' && $n_new_complaints > 0): ?>
          <span class="badge danger badge-pulse" style="font-size:10px;padding:1px 7px;margin-left:auto;"><?= $n_new_complaints ?></span>
          <?php endif; ?>
          <?php if ($file === 'trash.php' && $n_trash > 0): ?>
          <span class="badge" style="font-size:10px;padding:1px 7px;margin-left:auto;"><?= $n_trash ?></span>
          <?php endif; ?>
        </a>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </div>

    <!-- เนื้อหาหลัก -->
    <div>
