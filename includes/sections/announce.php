<?php
/** section: ประกาศ (section เด่น) — การ์ดมีภาพปก + ป้าย "ใหม่" pulse บนรูป */
if (!defined('APP_ROOT')) exit('Forbidden');

$anns = db()->query("SELECT id, title, image, published_at FROM posts
                     WHERE type = 'announce' AND status = 'published'
                     ORDER BY published_at DESC LIMIT 6")->fetchAll();
?>
<section class="block mb-4">
  <div class="card card-spacious reveal">
    <div class="section-head">
      <div>
        <span class="tag"><span class="material-symbols-rounded icon-sm">campaign</span> ANNOUNCEMENT</span>
        <h2><?= e(section_title('announce')) ?></h2>
        <p>ประกาศและเรื่องแจ้งสำคัญถึงประชาชนและเจ้าหน้าที่ในสังกัด</p>
      </div>
      <a class="btn primary" href="<?= e(url('news.php?type=announce')) ?>">ดูทั้งหมด <span class="arrow-slide"><span class="material-symbols-rounded icon-sm">arrow_forward</span></span></a>
    </div>
    <?php if ($anns): ?>
    <div class="grid grid-3" data-reveal-group>
      <?php foreach ($anns as $a): $new = is_new($a['published_at']); ?>
      <a class="card news-card reveal" href="<?= e(url('post.php?id=' . $a['id'])) ?>">
        <div class="news-thumb">
          <img class="lazyimg" loading="lazy" src="<?= e(post_image_url($a['image'])) ?>" alt="<?= e($a['title']) ?>">
          <?php if ($new): ?><span class="announce-new-tag pulse-new">ใหม่</span><?php endif; ?>
        </div>
        <div class="nc-body">
          <span class="badge warning" style="font-size:11px;padding:2px 9px;width:max-content;"><span class="material-symbols-rounded icon-sm">campaign</span>ประกาศ</span>
          <div class="nc-title"><?= e($a['title']) ?></div>
          <span class="lr-date"><span class="material-symbols-rounded icon-sm" style="vertical-align:-3px;">calendar_today</span> <?= e(thai_date($a['published_at'])) ?></span>
        </div>
      </a>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="text-center text-muted" style="padding:24px;">ยังไม่มีประกาศ</div>
    <?php endif; ?>
  </div>
</section>
