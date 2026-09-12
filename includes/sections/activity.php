<?php
/** section: ข่าวกิจกรรม — การ์ด 6 ใบล่าสุด (กริด 3 คอลัมน์ = 2 แถวพอดีบนจอคอม) */
if (!defined('APP_ROOT')) exit('Forbidden');

$acts = db()->query("SELECT id, title, image, published_at FROM posts
                     WHERE type = 'activity' AND status = 'published'
                     ORDER BY published_at DESC LIMIT 6")->fetchAll();
?>
<section class="block mb-4" id="news">
  <div class="card card-spacious reveal act-feature">
    <div class="section-head">
      <div><span class="tag"><span class="material-symbols-rounded icon-sm">photo_camera</span> ACTIVITY</span><h2><?= e(section_title('activity')) ?></h2></div>
      <a class="btn" href="<?= e(url('news.php?type=activity')) ?>">ดูทั้งหมด <span class="arrow-slide"><span class="material-symbols-rounded icon-sm">arrow_forward</span></span></a>
    </div>
    <?php if ($acts): ?>
    <div class="act-grid" data-reveal-group>
      <?php foreach ($acts as $p): ?>
      <a class="act-card reveal" href="<?= e(url('post.php?id=' . $p['id'])) ?>">
        <img class="act-card-img lazyimg" loading="lazy" src="<?= e($p['image'] ? url($p['image']) : url('assets/img/default-news.svg')) ?>" alt="<?= e($p['title']) ?>">
        <div class="act-card-info">
          <span class="act-card-date"><span class="material-symbols-rounded icon-sm">calendar_today</span><?= e(thai_date($p['published_at'])) ?></span>
          <h3 class="act-card-title"><?= e($p['title']) ?></h3>
        </div>
      </a>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="text-center text-muted" style="padding:32px;">ยังไม่มีกิจกรรม</div>
    <?php endif; ?>
  </div>
</section>
