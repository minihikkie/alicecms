<?php
/** section: ลิงก์หน่วยงานที่เกี่ยวข้อง — กริดการ์ด (admin เพิ่ม/ลบ/เรียง) */
if (!defined('APP_ROOT')) exit('Forbidden');

$links = db()->query('SELECT * FROM links ORDER BY sort_order ASC, id ASC')->fetchAll();
if (!$links) return;
?>
<section class="block mb-4">
  <div class="section-head reveal">
    <div><span class="tag"><span class="material-symbols-rounded icon-sm">link</span> LINKS</span><h2><?= e(section_title('links')) ?></h2><p>หน่วยงานและบริการสำคัญที่ประชาชนใช้บ่อย</p></div>
  </div>
  <div class="tool-grid" data-reveal-group>
    <?php foreach ($links as $l): ?>
    <a class="tool <?= e($l['style']) ?> reveal" href="<?= e($l['url']) ?>" target="_blank" rel="noopener">
      <div class="ti<?= !empty($l['image']) ? ' has-logo' : '' ?>">
        <?php if (!empty($l['image'])): ?><img src="<?= e(url($l['image'])) ?>" alt="โลโก้<?= e($l['title']) ?>" loading="lazy"><?php else: ?><span class="material-symbols-rounded"><?= e($l['icon'] ?: 'link') ?></span><?php endif; ?>
      </div>
      <div><div class="tt"><?= e($l['title']) ?></div><div class="ts"><?= e($l['subtitle']) ?></div></div>
    </a>
    <?php endforeach; ?>
  </div>
</section>
