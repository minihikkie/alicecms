<?php
/** section: วิดีโอความรู้ — ซ้าย: จอใหญ่เล่นในหน้าเลย | ขวา: รายการวิดีโออื่น คลิกแล้วสลับมาเล่นในจอใหญ่
 *  ตัวเล่น YouTube จะยังไม่ถูกโหลดจนกว่าผู้ใช้จะกดเล่น (แสดงแค่ภาพปกก่อน)
 *  — หน้าแรกจึงเบา และไม่มีคุกกี้ YouTube ติดผู้เข้าชมที่ไม่ได้กดดู */
if (!defined('APP_ROOT')) exit('Forbidden');

$videos = videos_published(5);
$lead   = array_shift($videos);
?>
<section class="block mb-4" id="video">
  <div class="card card-spacious reveal">
    <div class="section-head">
      <div>
        <span class="tag"><span class="material-symbols-rounded icon-sm">smart_display</span> VIDEO</span>
        <h2><?= e(section_title('video')) ?></h2>
        <p>วิดีโอให้ความรู้และสื่อประชาสัมพันธ์ที่หน่วยงานจัดทำ</p>
      </div>
      <a class="btn primary" href="<?= e(url('videos.php')) ?>">ดูทั้งหมด <span class="arrow-slide"><span class="material-symbols-rounded icon-sm">arrow_forward</span></span></a>
    </div>

    <?php if ($lead): ?>
    <div class="vid-grid" data-video-section>
      <!-- ซ้าย: จอใหญ่ -->
      <div class="vid-main">
        <div class="vid-stage" data-vid-stage data-vid="<?= e($lead['video_id']) ?>">
          <?= video_thumb_img($lead, 'vid-stage-img', $lead['title']) ?>
          <button type="button" class="vid-play" data-vid-play aria-label="เล่นวิดีโอ <?= e($lead['title']) ?>">
            <span class="material-symbols-rounded">play_arrow</span>
          </button>
          <?php if ($lead['duration']): ?><span class="vid-time" data-vid-time><?= e($lead['duration']) ?></span><?php endif; ?>
        </div>
        <div class="vid-main-body">
          <h3 class="vid-main-title" data-vid-title><?= e($lead['title']) ?></h3>
          <?php if ($lead['description']): ?>
          <p class="vid-main-desc" data-vid-desc><?= e($lead['description']) ?></p>
          <?php else: ?>
          <p class="vid-main-desc" data-vid-desc hidden></p>
          <?php endif; ?>
          <?php if ($lead['published_at']): ?>
          <div class="lr-date" data-vid-date><span class="material-symbols-rounded icon-sm">calendar_today</span><?= e(thai_date($lead['published_at'])) ?></div>
          <?php else: ?>
          <div class="lr-date" data-vid-date hidden><span class="material-symbols-rounded icon-sm">calendar_today</span><span></span></div>
          <?php endif; ?>
        </div>
      </div>

      <!-- ขวา: รายการวิดีโออื่น -->
      <?php if ($videos): ?>
      <div class="vid-side">
        <?php foreach ($videos as $v): ?>
        <button type="button" class="vid-side-item" data-vid-pick
                data-vid="<?= e($v['video_id']) ?>"
                data-title="<?= e($v['title']) ?>"
                data-desc="<?= e($v['description']) ?>"
                data-date="<?= e($v['published_at'] ? thai_date($v['published_at']) : '') ?>"
                data-time="<?= e($v['duration']) ?>"
                data-thumb="<?= e(video_thumb_url($v)) ?>">
          <span class="vid-side-thumb">
            <?= video_thumb_img($v) ?>
            <span class="vid-side-play"><span class="material-symbols-rounded">play_arrow</span></span>
            <?php if ($v['duration']): ?><span class="vid-time small"><?= e($v['duration']) ?></span><?php endif; ?>
          </span>
          <span class="vid-side-text">
            <span class="vid-side-title"><?= e($v['title']) ?></span>
            <?php if ($v['published_at']): ?>
            <span class="lr-date"><span class="material-symbols-rounded icon-sm">schedule</span><?= e(thai_date($v['published_at'])) ?></span>
            <?php endif; ?>
          </span>
        </button>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
    <?php else: ?>
    <div class="text-center text-muted" style="padding:24px;">ยังไม่มีวิดีโอ</div>
    <?php endif; ?>
  </div>
</section>
