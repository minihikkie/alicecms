<?php
/**
 * sitemap-page.php — แผนผังเว็บไซต์สำหรับผู้ใช้ (คนอ่าน)
 * ต่างจาก sitemap.php ที่เป็น XML สำหรับ Search Engine
 * เกณฑ์เว็บไซต์หน่วยงานภาครัฐกำหนดให้มีหน้านี้
 */
define('PUBLIC_PAGE', 'sitemap');
require __DIR__ . '/includes/init.php';
require __DIR__ . '/includes/content.php';   /* ใช้ content_types_active() */

/* รวบรวมลิงก์ทั้งเว็บตามที่เปิดใช้งานจริง */
$groups = [];

$main = [['index.php', 'หน้าแรก', 'home']];
if (section_live('activity') || section_live('pr') || section_live('announce')) $main[] = ['news.php', 'ข่าวสารทั้งหมด', 'newspaper'];
if (section_live('documents'))   $main[] = ['documents.php', 'เอกสารเผยแพร่', 'folder_open'];
if (section_live('procurement')) $main[] = ['procurement.php', 'จัดซื้อจัดจ้าง', 'shopping_cart'];
if (section_live('ita'))         $main[] = ['ita.php', 'การเปิดเผยข้อมูลสาธารณะ (ITA/OIT)', 'verified'];
if (section_live('personnel'))   $main[] = ['personnel.php', 'โครงสร้างผู้บริหาร', 'groups'];
$groups[] = ['ข้อมูลหน่วยงาน', $main];

$svc = [['services.php', 'บริการออนไลน์ (e-Service)', 'assignment']];
if (section_live('complaint')) {
    $svc[] = ['complaint.php', 'ร้องเรียน–ร้องทุกข์', 'support_agent'];
    $svc[] = ['complaint-track.php', 'ติดตามสถานะเรื่องร้องเรียน', 'travel_explore'];
}
if (section_live('faq')) $svc[] = ['faq.php', 'คำถามที่พบบ่อย', 'quiz'];
$svc[] = ['search.php', 'ค้นหาข้อมูลในเว็บไซต์', 'search'];
$groups[] = ['บริการประชาชน', $svc];

/* ประเภทข่าวที่เปิดใช้ */
$newscat = [];
foreach (post_types() as $tk => $tv) {
    if (section_live($tk)) $newscat[] = ['news.php?type=' . $tk, $tv['label'], $tv['icon'] ?? 'article'];
}
if ($newscat) $groups[] = ['หมวดข่าวสาร', $newscat];

/* ประเภทเนื้อหาที่สร้างเอง */
$ct = [];
foreach (content_types_active() as $t) {
    $ct[] = ['content.php?type=' . urlencode($t['slug']), $t['name_plural'] ?: $t['name'], $t['icon'] ?: 'category'];
}
if ($ct) $groups[] = ['เนื้อหาอื่นๆ', $ct];

/* หน้าเพจที่ผู้ดูแลสร้าง */
$pg = [];
foreach (db()->query("SELECT slug, title FROM pages WHERE status = 'published' ORDER BY sort_order ASC, id ASC") as $p) {
    $pg[] = ['page.php?slug=' . urlencode($p['slug']), $p['title'], 'description'];
}
if ($pg) $groups[] = ['หน้าข้อมูลเพิ่มเติม', $pg];

$groups[] = ['เกี่ยวกับเว็บไซต์', [
    ['about.php', 'เกี่ยวกับหน่วยงาน', 'info'],
    ['contact.php', 'ติดต่อหน่วยงาน', 'call'],
    ['sitemap-page.php', 'แผนผังเว็บไซต์', 'account_tree'],
]];

$page_title = 'แผนผังเว็บไซต์';
require __DIR__ . '/includes/header.php';
?>
<div class="container">
  <div class="page-head reveal">
    <div class="crumb"><a href="<?= e(url('index.php')) ?>">หน้าแรก</a>
      <span class="material-symbols-rounded icon-sm">chevron_right</span> แผนผังเว็บไซต์</div>
    <h1><?= e($page_title) ?></h1>
    <p class="text-muted">รวมทุกหน้าในเว็บไซต์นี้ไว้ที่เดียว เพื่อให้ค้นหาข้อมูลที่ต้องการได้สะดวก</p>
  </div>

  <div class="grid grid-2 mb-4" data-reveal-group>
    <?php foreach ($groups as [$gname, $links]): if (!$links) continue; ?>
    <div class="card reveal">
      <div class="section-head" style="margin-bottom:6px;"><div><h3 style="font-size:17px;"><?= e($gname) ?></h3></div></div>
      <?php foreach ($links as [$href, $label, $icon]): ?>
      <a class="list-row" href="<?= e(url($href)) ?>">
        <div class="lr-icon"><span class="material-symbols-rounded"><?= e($icon) ?></span></div>
        <div style="flex:1;"><div class="lr-title"><?= e($label) ?></div></div>
        <span class="material-symbols-rounded icon-sm" style="color:var(--muted);">chevron_right</span>
      </a>
      <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
