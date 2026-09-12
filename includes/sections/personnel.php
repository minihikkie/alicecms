<?php
/** section: โครงสร้างผู้บริหาร — แสดงผู้บริหารระดับบน + ลิงก์ดูทั้งหมด */
if (!defined('APP_ROOT')) exit('Forbidden');

$levels = personnel_by_level();
if (!$levels) return;
/* แสดงเฉพาะ 2 ระดับบนสุดบนหน้าแรก (ที่เหลือดูในหน้าเต็ม) */
$top = array_slice($levels, 0, 2, true);
$total = 0; foreach ($levels as $g) $total += count($g);
?>
<section class="block mb-4">
  <div class="section-head reveal">
    <div><span class="tag"><span class="material-symbols-rounded icon-sm">groups</span> EXECUTIVES</span><h2><?= e(section_title('personnel')) ?></h2><p>คณะผู้บริหารของหน่วยงาน</p></div>
    <a class="btn" href="<?= e(url('personnel.php')) ?>">ดูโครงสร้างทั้งหมด <span class="arrow-slide"><span class="material-symbols-rounded icon-sm">arrow_forward</span></span></a>
  </div>
  <div class="card card-spacious reveal">
    <div class="org">
      <?php foreach ($top as $lv => $people): ?>
      <div class="org-row" data-reveal-group>
        <?php foreach ($people as $p): ?>
        <div class="person reveal<?= $lv == 1 ? ' person-lead' : '' ?>">
          <div class="avatar">
            <?php if ($p['photo']): ?><img class="lazyimg" loading="lazy" src="<?= e(url($p['photo'])) ?>" alt="<?= e($p['name']) ?>"><?php else: ?><span class="material-symbols-rounded">person</span><?php endif; ?>
          </div>
          <div class="pn"><?= e($p['name']) ?></div>
          <?php if ($p['position']): ?><div class="pp"><?= e($p['position']) ?></div><?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
