<?php
/** section: ประชาสัมพันธ์ — ซ้าย: ข่าวล่าสุดเด่นเต็มที่ | ขวา: รายการที่เหลือเรียงลง */
if (!defined('APP_ROOT')) exit('Forbidden');

$prs = db()->query("SELECT id, title, body, image, views, published_at FROM posts
                    WHERE type = 'pr' AND status = 'published'
                    ORDER BY published_at DESC LIMIT 5")->fetchAll();
$lead = array_shift($prs);
?>
<section class="block mb-4">
  <div class="card card-spacious reveal pr-feature">
    <div class="section-head">
      <div>
        <span class="tag"><span class="material-symbols-rounded icon-sm">campaign</span> PR</span>
        <h2><?= e(section_title('pr')) ?></h2>
        <p>ประกาศและข่าวสารสำคัญถึงประชาชนและเจ้าหน้าที่ในสังกัด</p>
      </div>
      <a class="btn primary" href="<?= e(url('news.php?type=pr')) ?>">ดูทั้งหมด <span class="arrow-slide"><span class="material-symbols-rounded icon-sm">arrow_forward</span></span></a>
    </div>

    <?php if ($lead): ?>
    <div class="pr-grid">
      <!-- ซ้าย: ข่าวล่าสุดเด่นมาก -->
      <a class="pr-hero" href="<?= e(url('post.php?id=' . $lead['id'])) ?>">
        <div class="pr-hero-img">
          <img class="lazyimg" loading="lazy" src="<?= e(post_image_url($lead['image'])) ?>" alt="<?= e($lead['title']) ?>">
          <span class="pr-hero-tag">ล่าสุด</span>
        </div>
        <div class="pr-hero-body">
          <h3 class="pr-hero-title"><?= e($lead['title']) ?></h3>
          <?php $ex = meta_excerpt($lead['body'], 150); if ($ex !== ''): ?>
          <p class="pr-hero-excerpt"><?= e($ex) ?></p>
          <?php endif; ?>
          <div class="pr-hero-meta">
            <span class="lr-date"><span class="material-symbols-rounded icon-sm">calendar_today</span><?= e(thai_date($lead['published_at'])) ?></span>
            <span class="lr-date"><span class="material-symbols-rounded icon-sm">visibility</span>อ่าน <?= number_format((int)$lead['views']) ?> ครั้ง</span>
            <span class="pr-hero-readmore">อ่านต่อ <span class="material-symbols-rounded icon-sm">arrow_forward</span></span>
          </div>
        </div>
      </a>

      <!-- ขวา: รายการที่เหลือ -->
      <?php if ($prs): ?>
      <div class="pr-side">
        <?php foreach ($prs as $p): ?>
        <a class="pr-side-item" href="<?= e(url('post.php?id=' . $p['id'])) ?>">
          <div class="pr-side-thumb"><img class="lazyimg" loading="lazy" src="<?= e(post_image_url($p['image'])) ?>" alt=""></div>
          <div class="pr-side-text">
            <div class="pr-side-title"><?= e($p['title']) ?></div>
            <div class="lr-date"><span class="material-symbols-rounded icon-sm">schedule</span><?= e(thai_date($p['published_at'])) ?></div>
          </div>
          <span class="material-symbols-rounded pr-side-arrow">chevron_right</span>
        </a>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
    <?php else: ?>
    <div class="text-center text-muted" style="padding:24px;">ยังไม่มีประชาสัมพันธ์</div>
    <?php endif; ?>
  </div>
</section>
