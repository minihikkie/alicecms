<?php
/**
 * feed.php — RSS 2.0 ข่าวล่าสุด (สำหรับติดตามข่าวสารผ่านโปรแกรมอ่าน RSS / รวมข่าวภาครัฐ)
 */
require __DIR__ . '/includes/init.php';
header('Content-Type: application/rss+xml; charset=utf-8');

$site_name = setting('site_name', 'หน่วยงานราชการ');
$desc = setting('site_description', $site_name . ' — ข่าวสารและประกาศ');

$posts = [];
try {
    $posts = db()->query("SELECT id, title, body, published_at FROM posts
                          WHERE status='published' ORDER BY published_at DESC LIMIT 30")->fetchAll();
} catch (Throwable $e) {}

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<rss version="2.0"><channel>' . "\n";
echo '  <title>' . htmlspecialchars($site_name, ENT_XML1) . '</title>' . "\n";
echo '  <link>' . htmlspecialchars(abs_url('index.php'), ENT_XML1) . '</link>' . "\n";
echo '  <description>' . htmlspecialchars($desc, ENT_XML1) . '</description>' . "\n";
echo '  <language>th</language>' . "\n";
foreach ($posts as $p) {
    echo '  <item>' . "\n";
    echo '    <title>' . htmlspecialchars($p['title'], ENT_XML1) . '</title>' . "\n";
    echo '    <link>' . htmlspecialchars(abs_url('post.php?id=' . $p['id']), ENT_XML1) . '</link>' . "\n";
    echo '    <guid>' . htmlspecialchars(abs_url('post.php?id=' . $p['id']), ENT_XML1) . '</guid>' . "\n";
    echo '    <description>' . htmlspecialchars(meta_excerpt($p['body'], 300), ENT_XML1) . '</description>' . "\n";
    if ($p['published_at']) echo '    <pubDate>' . date(DATE_RSS, strtotime($p['published_at'])) . '</pubDate>' . "\n";
    echo '  </item>' . "\n";
}
echo '</channel></rss>';
