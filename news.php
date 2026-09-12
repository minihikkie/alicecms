<?php
/** news.php — รายการข่าวตามประเภท (ค้นหา + แบ่งหน้า) */
define('PUBLIC_PAGE', 'news');
require __DIR__ . '/includes/init.php';

$types = post_types();
$type  = isset($_GET['type']) && isset($types[$_GET['type']]) ? $_GET['type'] : '';
$q     = trim((string)($_GET['q'] ?? ''));
$page  = max(1, (int)($_GET['page'] ?? 1));
$per   = 12;

/* สร้างเงื่อนไขค้นหาแบบ prepared statement */
$where  = ["status = 'published'"];
$params = [];
if ($type !== '') { $where[] = 'type = ?';  $params[] = $type; }
if ($q !== '')    { $where[] = '(title LIKE ? OR body LIKE ?)'; $params[] = "%$q%"; $params[] = "%$q%"; }
$wsql = implode(' AND ', $where);

$st = db()->prepare("SELECT COUNT(*) FROM posts WHERE $wsql");
$st->execute($params);
$total = (int)$st->fetchColumn();

$st = db()->prepare("SELECT id, type, title, image, attachment, published_at, views FROM posts
                     WHERE $wsql ORDER BY published_at DESC LIMIT $per OFFSET " . (($page - 1) * $per));
$st->execute($params);
$posts = $st->fetchAll();

$page_title = $type !== '' ? $types[$type]['label'] : 'ข่าวสารทั้งหมด';
require __DIR__ . '/includes/header.php';

$base_qs = 'news.php?' . http_build_query(array_filter(['type' => $type, 'q' => $q]));
?>
<div class="container">
  <div class="page-head reveal">
    <div class="crumb"><a href="<?= e(url('index.php')) ?>">หน้าแรก</a> <span class="material-symbols-rounded icon-sm">chevron_right</span> ข่าวสาร</div>
    <h1><?= e($page_title) ?></h1>
  </div>

  <div class="flex items-center gap-2 mb-3 reveal" style="flex-wrap:wrap;">
    <div class="cats">
      <a class="cat<?= $type === '' ? ' active' : '' ?>" href="<?= e(url('news.php')) ?>">ทั้งหมด</a>
      <?php foreach ($types as $tk => $tv): if (!section_live($tk)) continue; ?>
      <a class="cat<?= $type === $tk ? ' active' : '' ?>" href="<?= e(url('news.php?type=' . $tk)) ?>"><span class="material-symbols-rounded icon-sm"><?= e($tv['icon']) ?></span><?= e($tv['label']) ?></a>
      <?php endforeach; ?>
    </div>
    <form class="searchbar" style="max-width:320px;margin:0;margin-left:auto;box-shadow:var(--shadow-soft);" action="<?= e(url('news.php')) ?>" method="get" role="search">
      <?php if ($type !== ''): ?><input type="hidden" name="type" value="<?= e($type) ?>"><?php endif; ?>
      <span class="material-symbols-rounded" style="color:var(--muted)">search</span>
      <input type="search" name="q" value="<?= e($q) ?>" placeholder="ค้นหาข่าว..." aria-label="ค้นหาข่าว">
    </form>
  </div>

  <?php if ($q !== ''): ?>
  <p class="text-muted mb-2">ผลการค้นหา "<?= e($q) ?>" — พบ <?= number_format($total) ?> รายการ</p>
  <?php endif; ?>

  <?php if ($posts): ?>
  <div class="grid grid-3" data-reveal-group>
    <?php foreach ($posts as $p): ?>
    <a class="card news-card reveal" href="<?= e(url('post.php?id=' . $p['id'])) ?>">
      <div class="news-thumb">
        <img class="lazyimg" loading="lazy" src="<?= e(post_image_url($p['image'])) ?>" alt="<?= e($p['title']) ?>">
      </div>
      <div class="nc-body">
        <div class="flex items-center gap-1" style="flex-wrap:wrap;">
          <span class="badge <?= e($types[$p['type']]['badge'] ?? '') ?>" style="font-size:11px;padding:2px 9px;"><?= e(post_type_label($p['type'])) ?></span>
          <?php if (is_new($p['published_at'])): ?><span class="badge gold" style="font-size:11px;padding:2px 8px;">ใหม่</span><?php endif; ?>
        </div>
        <div class="nc-title"><?= e($p['title']) ?></div>
        <span class="lr-date"><?= e(thai_date($p['published_at'])) ?> · อ่าน <?= number_format((int)$p['views']) ?> ครั้ง</span>
      </div>
    </a>
    <?php endforeach; ?>
  </div>
  <?= pagination_html($total, $per, $page, url($base_qs)) ?>
  <?php else: ?>
  <div class="card text-center text-muted reveal" style="padding:48px;">
    <span class="material-symbols-rounded icon-xl" style="color:var(--border);">newspaper</span>
    <p>ไม่พบข่าวที่ค้นหา</p>
  </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
