<?php
/** section: ITA/OIT — 4 หมวด (เรนเดอร์เฉพาะการ์ด ให้ index.php ครอบ section/grid) */
if (!defined('APP_ROOT')) exit('Forbidden');
?>
<div class="card reveal" id="ita">
  <div class="section-head" style="margin-bottom:10px;">
    <div><span class="tag"><span class="material-symbols-rounded icon-sm">verified</span> ITA</span><h3><?= e(section_title('ita')) ?></h3></div>
    <a class="btn small" href="<?= e(url('ita.php')) ?>">ทั้งหมด</a>
  </div>
  <div class="grid grid-2" style="gap:10px;">
    <?php foreach (ita_groups() as $gid => $g): ?>
    <a class="tool <?= e($g['style']) ?>" href="<?= e(url('ita.php?grp=' . $gid)) ?>">
      <div class="ti"><span class="material-symbols-rounded"><?= e($g['icon']) ?></span></div>
      <div><div class="tt"><?= e($g['title']) ?></div><div class="ts"><?= e($g['sub']) ?></div></div>
    </a>
    <?php endforeach; ?>
  </div>
</div>
