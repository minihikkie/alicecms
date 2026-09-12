<?php
/** section: สารจากหัวหน้าหน่วยงาน (ชื่อ section เปลี่ยนได้ใน admin) */
if (!defined('APP_ROOT')) exit('Forbidden');

$chief_name = setting('chief_name');
if ($chief_name === '') return; // ยังไม่ได้ตั้งค่า → ไม่แสดง
?>
<section class="block mt-3 mb-2">
  <div class="card card-spacious chief" style="background:linear-gradient(120deg, rgba(255,255,255,.95), color-mix(in srgb, var(--blue) 6%, transparent));">
    <div class="chief-photo reveal-left">
      <div class="frame">
        <?php if (setting('chief_photo')): ?>
          <img src="<?= e(url(setting('chief_photo'))) ?>" alt="<?= e($chief_name) ?>">
        <?php else: ?>
          <span class="material-symbols-rounded" style="font-size:84px;">person</span>
        <?php endif; ?>
      </div>
      <?php if (setting('chief_badge')): ?>
      <span class="badge"><span class="material-symbols-rounded icon-sm filled">military_tech</span><?= e(setting('chief_badge')) ?></span>
      <?php endif; ?>
      <h3 class="mt-1" style="margin-bottom:2px;"><?= e($chief_name) ?></h3>
      <div class="text-muted" style="font-size:13px;"><?= e(setting('chief_position')) ?></div>
    </div>
    <div class="chief-msg reveal-right">
      <span class="tag"><span class="material-symbols-rounded icon-sm">format_quote</span> MESSAGE</span>
      <h2 style="margin-bottom:0;"><?= e(section_title('chief')) ?></h2>
      <?php if (setting('chief_quote')): ?>
      <div class="quote">"<?= e(setting('chief_quote')) ?>"</div>
      <?php endif; ?>
      <?php if (setting('chief_message')): ?>
      <p class="body"><?= nl2br(e(setting('chief_message'))) ?></p>
      <?php endif; ?>
      <div class="sign">
        <b><?= e($chief_name) ?></b>
        <span class="text-muted"><?= e(setting('chief_position')) ?><?= setting('site_dept') ? ' ' . e(setting('site_dept')) : '' ?></span>
      </div>
    </div>
  </div>
</section>
