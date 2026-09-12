<?php
/** page.php — แสดงหน้าเพจกำหนดเอง (วิสัยทัศน์/ประวัติ/อำนาจหน้าที่ ฯลฯ) ตาม slug */
define('PUBLIC_PAGE', 'page');
require __DIR__ . '/includes/init.php';
require __DIR__ . '/includes/blocks.php';

$slug = preg_replace('/[^\p{L}\p{N}\-_]/u', '', (string)($_GET['slug'] ?? ''));
$page = $slug !== '' ? page_by_slug($slug) : null;

if (!$page) {
    http_response_code(404);
    $page_title = 'ไม่พบหน้าที่ต้องการ';
    require __DIR__ . '/includes/header.php';
    echo '<div class="container"><div class="page-head reveal text-center"><h1>ไม่พบหน้าที่ต้องการ</h1>'
       . '<p class="text-muted">หน้านี้อาจถูกย้ายหรือลบไปแล้ว</p>'
       . '<a class="btn primary mt-2" href="' . e(url('index.php')) . '"><span class="material-symbols-rounded icon-sm">home</span>กลับหน้าแรก</a></div></div>';
    require __DIR__ . '/includes/footer.php';
    exit;
}

$page_title = $page['title'];
require __DIR__ . '/includes/header.php';
?>
<div class="container">
  <div class="page-head reveal text-center">
    <h1><?= e($page['title']) ?></h1>
  </div>
  <div class="card card-spacious reveal page-content">
    <?php if (has_blocks($page['blocks'] ?? null)): ?>
    <?= render_blocks($page['blocks']) ?>
    <?php else: ?>
    <?= nl2br(e($page['body'])) ?>
    <?php endif; ?>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
