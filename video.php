<?php
/** video.php — หน้าเล่นวิดีโอเดี่ยว + รายการวิดีโออื่นแนะนำ */
define('PUBLIC_PAGE', 'videos');
require __DIR__ . '/includes/init.php';

/* section นี้ถูกปิดอยู่ → ตอบ 404 ไม่ให้เข้าถึงหน้าโดยตรง */
require_section_live("video");

$id = (int)($_GET['id'] ?? 0);
$st = db()->prepare("SELECT * FROM videos WHERE id = ? AND status = 'published'");
$st->execute([$id]);
$v = $st->fetch();
if (!$v) { http_response_code(404); $page_title = 'ไม่พบวิดีโอ'; }
else     { $page_title = $v['title']; }

$others = [];
if ($v) {
    $st = db()->prepare("SELECT * FROM videos WHERE status = 'published' AND id <> ? ORDER BY sort_order ASC, id DESC LIMIT 6");
    $st->execute([$id]);
    $others = $st->fetchAll();
}
require __DIR__ . '/includes/header.php';
?>
<div class="container">
  <?php if (!$v): ?>
  <div class="card text-center mb-4" style="padding:48px;">
    <span class="material-symbols-rounded icon-lg text-muted">videocam_off</span>
    <h1 style="margin:10px 0 6px;">ไม่พบวิดีโอ</h1>
    <p class="text-muted" style="margin:0 0 16px;">วิดีโอนี้อาจถูกลบหรือยังไม่เผยแพร่</p>
    <a class="btn primary" href="<?= e(url('videos.php')) ?>">ดูวิดีโอทั้งหมด</a>
  </div>
  <?php else: ?>
  <div class="page-head reveal">
    <div class="crumb">
      <a href="<?= e(url('index.php')) ?>">หน้าแรก</a> <span class="material-symbols-rounded icon-sm">chevron_right</span>
      <a href="<?= e(url('videos.php')) ?>"><?= e(section_title('video')) ?></a>
    </div>
  </div>

  <div class="card card-spacious mb-3 reveal">
    <div class="vid-stage is-playing" style="margin-bottom:16px;">
      <iframe class="vid-stage-frame" src="<?= e(video_embed_src($v['video_id'], false)) ?>"
              title="<?= e($v['title']) ?>" loading="lazy" allowfullscreen
              allow="accelerometer; autoplay; encrypted-media; gyroscope; picture-in-picture"></iframe>
    </div>
    <h1 style="margin:0 0 8px;font-size:23px;line-height:1.45;"><?= e($v['title']) ?></h1>
    <div class="flex items-center gap-1 mb-2" style="flex-wrap:wrap;">
      <?php if ($v['published_at']): ?>
      <span class="lr-date"><span class="material-symbols-rounded icon-sm">calendar_today</span><?= e(thai_date($v['published_at'])) ?></span>
      <?php endif; ?>
      <?php if ($v['duration']): ?>
      <span class="lr-date"><span class="material-symbols-rounded icon-sm">schedule</span>ความยาว <?= e($v['duration']) ?></span>
      <?php endif; ?>
    </div>
    <?php if ($v['description']): ?>
    <p class="text-muted" style="font-size:15px;line-height:1.8;margin:0;"><?= nl2br(e($v['description'])) ?></p>
    <?php endif; ?>
  </div>

  <?php if ($others): ?>
  <div class="section-head"><div><h2 style="font-size:20px;">วิดีโออื่นที่น่าสนใจ</h2></div></div>
  <div class="vid-library mb-4" data-reveal-group>
    <?php foreach ($others as $o): ?>
    <a class="card vid-card reveal" href="<?= e(url('video.php?id=' . $o['id'])) ?>">
      <span class="vid-card-thumb">
        <?= video_thumb_img($o, "", $o["title"]) ?>
        <span class="vid-play" aria-hidden="true"><span class="material-symbols-rounded">play_arrow</span></span>
        <?php if ($o['duration']): ?><span class="vid-time"><?= e($o['duration']) ?></span><?php endif; ?>
      </span>
      <span class="vid-card-body">
        <h3 class="vid-card-title"><?= e($o['title']) ?></h3>
      </span>
    </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
