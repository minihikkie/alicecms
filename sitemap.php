<?php
/**
 * sitemap.php — แผนผังเว็บไซต์ (XML) สำหรับ Search Engine
 * รวมหน้าหลัก + ข่าวที่เผยแพร่ + หน้าเอกสาร/จัดซื้อ/ITA ตาม section ที่เปิด
 */
require __DIR__ . '/includes/init.php';
header('Content-Type: application/xml; charset=utf-8');

$urls = [];
$add = function (string $path, string $lastmod = '', string $freq = 'weekly', string $prio = '0.6') use (&$urls) {
    $urls[] = ['loc' => abs_url($path), 'lastmod' => $lastmod, 'freq' => $freq, 'prio' => $prio];
};

/* หน้าหลัก/หน้าคงที่ — เพิ่มเฉพาะ section ที่เปิดใช้ */
$add('index.php', date('Y-m-d'), 'daily', '1.0');
$add('about.php', '', 'monthly', '0.5');
$add('contact.php', '', 'monthly', '0.5');
if (section_live('personnel')) $add('personnel.php', '', 'monthly', '0.5');
if (section_live('activity') || section_live('pr') || section_live('announce')) $add('news.php', '', 'daily', '0.8');
if (section_live('documents'))   $add('documents.php', '', 'weekly', '0.7');
if (section_live('ita'))         $add('ita.php', '', 'monthly', '0.6');
if (section_live('procurement')) $add('procurement.php', '', 'weekly', '0.6');
if (section_live('complaint'))   $add('complaint.php', '', 'monthly', '0.4');
if (section_live('faq'))         $add('faq.php', '', 'monthly', '0.5');
if (section_live('video'))       $add('videos.php', '', 'weekly', '0.7');
$add('services.php', '', 'monthly', '0.7');
$add('sitemap-page.php', '', 'monthly', '0.3');

/* วิดีโอที่เผยแพร่ — แต่ละคลิปมีหน้าของตัวเอง (video.php?id=) */
try {
    foreach (db()->query("SELECT id FROM videos WHERE status='published'") as $v) {
        $add('video.php?id=' . $v['id'], '', 'monthly', '0.6');
    }
} catch (Throwable $e) {}

/* เนื้อหาแบบกำหนดเอง — หน้ารวมของแต่ละประเภท + รายการย่อย */
try {
    foreach (db()->query("SELECT id, slug FROM content_types WHERE status='on'") as $ct) {
        $add('content.php?type=' . $ct['slug'], '', 'weekly', '0.6');
        $st = db()->prepare("SELECT id FROM content_items WHERE type_id = ? AND status='published'");
        $st->execute([$ct['id']]);
        foreach ($st as $it) $add('content.php?type=' . $ct['slug'] . '&id=' . $it['id'], '', 'monthly', '0.5');
    }
} catch (Throwable $e) {}

/* แบบฟอร์ม/บริการออนไลน์ที่ยังเปิดรับ */
try {
    foreach (db()->query("SELECT slug FROM forms WHERE status='open'") as $f) {
        $add('form.php?slug=' . $f['slug'], '', 'monthly', '0.5');
    }
} catch (Throwable $e) {}

/* หน้าเพจกำหนดเอง */
try {
    foreach (db()->query("SELECT slug, updated_at FROM pages WHERE status='published'") as $pg) {
        $add('page.php?slug=' . $pg['slug'], date('Y-m-d', strtotime($pg['updated_at'] ?: 'now')), 'monthly', '0.5');
    }
} catch (Throwable $e) {}

/* ข่าวที่เผยแพร่ทั้งหมด */
try {
    $rows = db()->query("SELECT id, updated_at, published_at FROM posts WHERE status='published' ORDER BY published_at DESC LIMIT 2000");
    foreach ($rows as $p) {
        $lm = date('Y-m-d', strtotime($p['updated_at'] ?: $p['published_at'] ?: 'now'));
        $add('post.php?id=' . $p['id'], $lm, 'monthly', '0.7');
    }
} catch (Throwable $e) {}

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($urls as $u) {
    echo '  <url><loc>' . htmlspecialchars($u['loc'], ENT_XML1) . '</loc>';
    if ($u['lastmod']) echo '<lastmod>' . $u['lastmod'] . '</lastmod>';
    echo '<changefreq>' . $u['freq'] . '</changefreq><priority>' . $u['prio'] . '</priority></url>' . "\n";
}
echo '</urlset>';
