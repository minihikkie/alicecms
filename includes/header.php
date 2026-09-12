<?php
/**
 * header.php — ส่วนหัวของทุกหน้าฝั่งประชาชน
 * ตัวแปรที่รับ: $page_title (ชื่อหน้า, ไม่บังคับ)
 */
if (!defined('APP_ROOT')) exit('Forbidden');

$site_name = setting('site_name', 'หน่วยงานราชการ');
$site_dept = setting('site_dept', '');
$theme     = valid_hex(setting('theme_color', '')) ? setting('theme_color') : '#1A73E8';
$theme700  = valid_hex(setting('theme_color_dark', '')) ? setting('theme_color_dark') : '#1557B0';
$anim      = setting('anim_level', 'full');          // full | min | off
$logo      = setting('logo');
$favicon   = setting('favicon');
$a11y_on   = setting('a11y_bar', '1') === '1';       // แถบช่วยการเข้าถึง

/* ─── SEO: meta description + Open Graph (หน้าตั้งค่าเองได้ผ่าน $meta_description / $og_image) ─── */
$meta_desc = isset($meta_description) && $meta_description !== ''
    ? meta_excerpt($meta_description)
    : meta_excerpt(setting('site_description', $site_name . ($site_dept ? ' ' . $site_dept : '') . ' — ศูนย์ข้อมูลข่าวสารและบริการประชาชนออนไลน์'));
$og_img = isset($og_image) && $og_image
    ? abs_url($og_image)
    : ($logo ? abs_url($logo) : abs_url('assets/img/favicon.svg'));
/* REQUEST_URI มี path เต็มจาก host อยู่แล้ว — ต่อ scheme+host ตรงๆ ไม่ผ่าน url() */
$_scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$canonical = $_scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . strtok($_SERVER['REQUEST_URI'] ?? '/', '#');

/* เมนูบน — โผล่/หายตามสวิตช์ "แสดงในเมนู" (in_menu) แยกจากการแสดงบนหน้าแรก */
$nav_items = [['index.php', 'หน้าแรก']];
foreach (nav_section_defs() as $nd) {
    foreach ($nd['keys'] as $k) {
        if (section_in_menu($k)) { $nav_items[] = [$nd['file'], $nd['label']]; break; }
    }
}
/* เมนูกำหนดเอง (หน้าเพจ/ลิงก์ที่ผู้ดูแลเพิ่ม) */
foreach (custom_menu() as $mi) $nav_items[] = [$mi['url'], $mi['label'], (int)$mi['new_tab']];
$nav_items[] = ['about.php', 'เกี่ยวกับ'];
$nav_items[] = ['contact.php', 'ติดต่อ'];
$current_script = basename($_SERVER['SCRIPT_NAME'] ?? '');
/* โหมดคุมเมนูเอง: ถ้าเปิด nav_custom และมีเมนูที่ตั้งไว้ จะใช้เมนูนั้นแทนเมนูอัตโนมัติ */
$nav_tree = (setting('nav_custom', '0') === '1') ? custom_menu_tree() : [];
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= isset($page_title) ? e($page_title) . ' — ' : '' ?><?= e($site_name) ?></title>
<meta name="description" content="<?= e($meta_desc) ?>">
<link rel="canonical" href="<?= e($canonical) ?>">
<link rel="alternate" type="application/rss+xml" title="<?= e($site_name) ?> — ข่าวสาร" href="<?= e(url('feed.php')) ?>">
<meta name="theme-color" content="<?= e($theme) ?>">
<!-- PWA (ติดตั้งเป็นแอปบนมือถือ/เดสก์ท็อป) -->
<link rel="manifest" href="<?= e(url('manifest.php')) ?>">
<link rel="apple-touch-icon" href="<?= e(url('assets/img/pwa/icon-192.png')) ?>">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="<?= e(mb_substr($site_name, 0, 30)) ?>">
<!-- Open Graph (แชร์ Facebook/LINE) -->
<meta property="og:type" content="<?= e($og_type ?? 'website') ?>"><?php
/* ข้อมูลเพิ่มเติมสำหรับหน้าประเภทบทความ/ข่าว (แชร์แล้วแสดงวันที่และหมวดได้) */
if (!empty($og_article['published_time'])) echo "\n" . '<meta property="article:published_time" content="' . e($og_article['published_time']) . '">';
if (!empty($og_article['section']))        echo "\n" . '<meta property="article:section" content="' . e($og_article['section']) . '">';
?>
<meta property="og:site_name" content="<?= e($site_name) ?>">
<meta property="og:title" content="<?= e(isset($page_title) ? $page_title : $site_name) ?>">
<meta property="og:description" content="<?= e($meta_desc) ?>">
<meta property="og:url" content="<?= e($canonical) ?>">
<meta property="og:image" content="<?= e($og_img) ?>">
<meta property="og:locale" content="th_TH">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?= e(isset($page_title) ? $page_title : $site_name) ?>">
<meta name="twitter:description" content="<?= e($meta_desc) ?>">
<meta name="twitter:image" content="<?= e($og_img) ?>">
<!-- กัน FOUC ของแถบช่วยการเข้าถึง: คืนค่าขนาดอักษร/โหมดสีก่อนเรนเดอร์ -->
<script nonce="<?= e(CSP_NONCE) ?>">
window.APP_BASE=<?= json_encode(url(''), JSON_UNESCAPED_SLASHES) ?>;
(function(){try{var d=document.documentElement;var f=localStorage.getItem('a11yFont');if(f)d.setAttribute('data-fontscale',f);if(localStorage.getItem('a11yContrast')==='1')d.classList.add('contrast');}catch(e){}})();
</script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<?php $fonts = site_fonts(); $cur_font = isset($fonts[setting('font_family', 'Prompt')]) ? setting('font_family', 'Prompt') : 'Prompt'; ?>
<link href="https://fonts.googleapis.com/css2?family=<?= e($fonts[$cur_font]) ?>&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200&display=block" rel="stylesheet">
<link rel="stylesheet" href="<?= e(asset_url('theme-kit.css')) ?>">
<link rel="stylesheet" href="<?= e(asset_url('assets/css/site.css')) ?>">
<link rel="stylesheet" href="<?= e(asset_url('assets/css/animations.css')) ?>">
<?php if ($favicon): ?>
<link rel="icon" href="<?= e(url($favicon)) ?>">
<?php else: ?>
<link rel="icon" type="image/svg+xml" href="<?= e(url('assets/img/favicon.svg')) ?>">
<?php endif; ?>
<style>:root { --blue: <?= e($theme) ?>; --blue-700: <?= e($theme700) ?>; --max: <?= e(layout_max_width()) ?>; }
body { font-family: '<?= e($cur_font) ?>', 'Prompt', system-ui, sans-serif; }</style>
<noscript><style>.reveal,.reveal-left,.reveal-right{opacity:1 !important;transform:none !important;}
.cookiebar{display:flex !important;}</style></noscript>
<?php
/* JSON-LD — ประกาศข้อมูลหน่วยงานให้ Search Engine (เฉพาะหน้าแรก) */
if (($current_script ?: 'index.php') === 'index.php'):
  $ld = [
    '@context' => 'https://schema.org',
    '@type'    => 'GovernmentOrganization',
    'name'     => $site_name,
    'url'      => abs_url('index.php'),
    'logo'     => $og_img,
  ];
  if ($site_dept)              $ld['parentOrganization'] = ['@type' => 'GovernmentOrganization', 'name' => $site_dept];
  if (setting('site_phone'))   $ld['telephone'] = setting('site_phone');
  if (setting('site_email'))   $ld['email'] = setting('site_email');
  if (setting('site_address')) {
      /* ที่อยู่เก็บเป็นข้อความหลายบรรทัด แยกเป็นฟิลด์ย่อยอัตโนมัติไม่ได้อย่างแม่นยำ
         จึงใส่เป็น streetAddress ทั้งก้อน แต่ระบุประเทศไว้ด้วย ช่วยให้ Google จับคู่หน่วยงานกับสถานที่ได้ง่ายขึ้น */
      $ld['address'] = [
          '@type'          => 'PostalAddress',
          'streetAddress'  => trim(preg_replace('/\s+/u', ' ', (string)setting('site_address'))),
          'addressCountry' => 'TH',
      ];
  }
  /* ช่องทางติดต่อ — ประกาศแยกจาก telephone เพื่อบอกว่าเป็นสายบริการประชาชนภาษาไทย */
  if (setting('site_phone')) {
      $ld['contactPoint'] = [[
          '@type'             => 'ContactPoint',
          'contactType'       => 'customer service',
          'telephone'         => setting('site_phone'),
          'availableLanguage' => ['th'],
      ]];
  }
  $social = array_values(array_filter([setting('social_facebook'), setting('social_youtube'), setting('social_tiktok'), setting('social_line')]));
  if ($social) $ld['sameAs'] = $social;

  /* WebSite + SearchAction — ให้ Google รู้ว่าเว็บมีระบบค้นหาในตัว
     (เป็นเงื่อนไขของ "ช่องค้นหา" ที่บางครั้งขึ้นใต้ผลการค้นหาชื่อหน่วยงาน) */
  $ldSite = [
      '@context'        => 'https://schema.org',
      '@type'           => 'WebSite',
      'name'            => $site_name,
      'url'             => rtrim(abs_url(''), '/') . '/',
      'inLanguage'      => 'th-TH',
      'potentialAction' => [
          '@type'       => 'SearchAction',
          'target'      => ['@type' => 'EntryPoint', 'urlTemplate' => abs_url('search.php') . '?q={search_term_string}'],
          'query-input' => 'required name=search_term_string',
      ],
  ];
?>
<script type="application/ld+json" nonce="<?= e(CSP_NONCE) ?>"><?= json_encode($ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
<script type="application/ld+json" nonce="<?= e(CSP_NONCE) ?>"><?= json_encode($ldSite, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
<?php endif; ?>
<?php /* JSON-LD เพิ่มเติมที่หน้าอื่นกำหนดเอง (เช่น NewsArticle + BreadcrumbList ของหน้าอ่านข่าว) */
foreach (($json_ld_extra ?? []) as $_ld): ?>
<script type="application/ld+json" nonce="<?= e(CSP_NONCE) ?>"><?= json_encode($_ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
<?php endforeach; ?>
</head>
<?php
$body_cls = 'anim-' . (in_array($anim, ['full','min','off'], true) ? $anim : 'full');
if (setting('header_layout', 'left') === 'center') $body_cls .= ' hdr-center';
if (setting('header_sticky', '1') !== '1') $body_cls .= ' hdr-static';
?>
<body class="<?= e($body_cls) ?>">

<!-- ลิงก์ข้ามไปยังเนื้อหาหลัก (WCAG 2.4.1) — มองไม่เห็นจนกว่าจะกด Tab -->
<a class="skip-link" href="#main-content">ข้ามไปยังเนื้อหาหลัก</a>

<?php if ($a11y_on): ?>
<!-- ปุ่มลอย + แผงช่วยการเข้าถึง (Web Accessibility) -->
<div class="a11y-fab-wrap">
  <div class="a11y-panel" id="a11yPanel" role="region" aria-label="ตั้งค่าการแสดงผล" hidden>
    <div class="a11y-panel-title"><span class="material-symbols-rounded icon-sm">tune</span>การแสดงผล</div>
    <div class="a11y-row">
      <span class="a11y-row-label">ขนาดตัวอักษร</span>
      <div class="a11y-btns">
        <button type="button" class="a11y-btn" data-a11y="font-dec" aria-label="ลดขนาดตัวอักษร" title="ลดขนาด">ก<span style="font-size:11px;">−</span></button>
        <button type="button" class="a11y-btn" data-a11y="font-reset" aria-label="ขนาดตัวอักษรปกติ" title="ขนาดปกติ">ก</button>
        <button type="button" class="a11y-btn" data-a11y="font-inc" aria-label="เพิ่มขนาดตัวอักษร" title="เพิ่มขนาด">ก<span style="font-size:18px;">+</span></button>
      </div>
    </div>
    <div class="a11y-row">
      <span class="a11y-row-label">โหมดสีตัดกัน</span>
      <button type="button" class="a11y-btn wide" data-a11y="contrast" aria-pressed="false" aria-label="สลับโหมดสีตัดกัน"><span class="material-symbols-rounded icon-sm">contrast</span>เปิด/ปิด</button>
    </div>
  </div>
  <button type="button" class="a11y-fab" id="a11yFab" aria-label="เครื่องมือช่วยการเข้าถึง" aria-expanded="false" aria-controls="a11yPanel" title="ช่วยการเข้าถึง">
    <span class="material-symbols-rounded">accessibility_new</span>
  </button>
</div>
<?php endif; ?>

<?php
/* แถบประกาศด่วน (ปิดได้ต่อเซสชัน — JS) */
$alert_text = setting('alert_enabled', '0') === '1' ? trim(setting('alert_text', '')) : '';
if ($alert_text !== ''):
    $alert_style = in_array(setting('alert_style', 'urgent'), ['urgent','warning','info'], true) ? setting('alert_style', 'urgent') : 'urgent';
    $alert_link  = setting('alert_link', '');
    $alert_key   = substr(md5($alert_text . $alert_style), 0, 8);
?>
<div class="alertbar ab-<?= e($alert_style) ?>" id="alertBar" data-key="<?= e($alert_key) ?>" role="region" aria-label="ประกาศด่วน">
  <span class="material-symbols-rounded">campaign</span>
  <span class="ab-text"><?php if ($alert_link !== ''): ?><a href="<?= e(preg_match('#^https?://#', $alert_link) ? $alert_link : url($alert_link)) ?>"><?= e($alert_text) ?></a><?php else: ?><?= e($alert_text) ?><?php endif; ?></span>
  <button type="button" class="ab-close" id="alertClose" aria-label="ปิดประกาศ"><span class="material-symbols-rounded">close</span></button>
</div>
<?php endif; ?>

<!-- TOPBAR -->
<header class="topbar">
  <div class="container topbar-inner">
    <a class="brand-link" href="<?= e(url('index.php')) ?>">
      <div class="logo<?= $logo ? ' has-img' : '' ?>">
        <?php if ($logo): ?>
          <img src="<?= e(url($logo)) ?>" alt="โลโก้<?= e($site_name) ?>">
        <?php else: ?>
          <span class="material-symbols-rounded filled"><?= e(setting('logo_icon', 'account_balance')) ?></span>
        <?php endif; ?>
      </div>
      <div class="brand"><?= e($site_name) ?><?php if ($site_dept): ?><small><?= e($site_dept) ?></small><?php endif; ?></div>
    </a>
    <button class="menu-btn" aria-label="เมนู" aria-expanded="false">
      <span class="material-symbols-rounded">menu</span>
    </button>
    <nav class="nav">
      <?php if ($nav_tree): /* ── โหมดคุมเมนูเอง: เมนูหลัก + ย่อย ── */ ?>
        <?php foreach ($nav_tree as $mi): $h = menu_href($mi['url']); ?>
        <?php if (!empty($mi['children'])): ?>
        <div class="nav-has-sub">
          <a class="navlink" href="<?= e($h['href']) ?>"<?= $mi['new_tab'] ? ' target="_blank" rel="noopener"' : '' ?>><?= e($mi['label']) ?><span class="material-symbols-rounded icon-sm nav-caret">expand_more</span></a>
          <div class="nav-sub">
            <?php foreach ($mi['children'] as $ch): $ch_h = menu_href($ch['url']); ?>
            <a href="<?= e($ch_h['href']) ?>"<?= $ch['new_tab'] ? ' target="_blank" rel="noopener"' : '' ?>><?= e($ch['label']) ?></a>
            <?php endforeach; ?>
          </div>
        </div>
        <?php else: ?>
        <a class="navlink" href="<?= e($h['href']) ?>"<?= $mi['new_tab'] ? ' target="_blank" rel="noopener"' : '' ?>><?= e($mi['label']) ?></a>
        <?php endif; ?>
        <?php endforeach; ?>
      <?php else: /* ── โหมดอัตโนมัติ (เดิม) ── */ ?>
        <?php foreach ($nav_items as $ni):
          $href = $ni[0]; $label = $ni[1]; $blank = !empty($ni[2]);
          $isExt = (bool)preg_match('#^(https?:)?//#', $href);
          $hrefOut = $isExt ? $href : url($href);
        ?>
        <a class="navlink<?= (!$isExt && $current_script === $href) ? ' active' : '' ?>" href="<?= e($hrefOut) ?>"<?= $blank ? ' target="_blank" rel="noopener"' : '' ?>><?= e($label) ?></a>
        <?php endforeach; ?>
      <?php endif; ?>
    </nav>
  </div>
</header>

<main class="page-main" id="main-content" tabindex="-1">
