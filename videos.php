<?php
/** videos.php — รวมวิดีโอความรู้ทั้งหมด (คลิกการ์ด → เล่นในหน้าเดียวกัน) */
define('PUBLIC_PAGE', 'videos');
require __DIR__ . '/includes/init.php';

$videos = videos_published();
$page_title = section_title('video');
require __DIR__ . '/includes/header.php';
?>
<div class="container">
  <div class="page-head reveal">
    <div class="crumb"><a href="<?= e(url('index.php')) ?>">หน้าแรก</a> <span class="material-symbols-rounded icon-sm">chevron_right</span> <?= e($page_title) ?></div>
    <h1><?= e($page_title) ?></h1>
    <p class="text-muted">วิดีโอให้ความรู้และสื่อประชาสัมพันธ์ที่หน่วยงานจัดทำ</p>
  </div>

  <?php if ($videos): ?>
  <div class="vid-library mb-4" data-reveal-group>
    <?php foreach ($videos as $v): ?>
    <a class="card vid-card reveal" href="<?= e(url('video.php?id=' . $v['id'])) ?>">
      <span class="vid-card-thumb">
        <?= video_thumb_img($v, "", $v["title"]) ?>
        <span class="vid-play" aria-hidden="true"><span class="material-symbols-rounded">play_arrow</span></span>
        <?php if ($v['duration']): ?><span class="vid-time"><?= e($v['duration']) ?></span><?php endif; ?>
      </span>
      <span class="vid-card-body">
        <?php if (is_new($v['published_at'])): ?>
        <span class="flex items-center gap-1" style="flex-wrap:wrap;">
          <span class="badge gold" style="font-size:11px;padding:2px 8px;">ใหม่</span>
        </span>
        <?php endif; ?>
        <h3 class="vid-card-title"><?= e($v['title']) ?></h3>
        <?php if ($v['description']): ?><p class="vid-card-desc"><?= e($v['description']) ?></p><?php endif; ?>
        <?php if ($v['published_at']): ?>
        <span class="lr-date"><span class="material-symbols-rounded icon-sm">calendar_today</span><?= e(thai_date($v['published_at'])) ?></span>
        <?php endif; ?>
      </span>
    </a>
    <?php endforeach; ?>
  </div>
  <?php else: ?>
  <div class="card text-center mb-4" style="padding:40px;">
    <span class="material-symbols-rounded icon-lg text-muted">smart_display</span>
    <p class="text-muted" style="margin:8px 0 0;">ยังไม่มีวิดีโอเผยแพร่</p>
  </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
