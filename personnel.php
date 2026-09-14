<?php
/** personnel.php — โครงสร้างผู้บริหาร/ทำเนียบบุคลากร (จัดผังตามระดับชั้น) */
define('PUBLIC_PAGE', 'personnel');
require __DIR__ . '/includes/init.php';

/* section นี้ถูกปิดอยู่ → ตอบ 404 ไม่ให้เข้าถึงหน้าโดยตรง */
require_section_live("personnel");

$levels = personnel_by_level();
$page_title = section_title('personnel');
require __DIR__ . '/includes/header.php';
?>
<div class="container">
  <div class="page-head reveal">
    <div class="crumb"><a href="<?= e(url('index.php')) ?>">หน้าแรก</a> <span class="material-symbols-rounded icon-sm">chevron_right</span> โครงสร้างผู้บริหาร</div>
    <h1><?= e($page_title) ?></h1>
    <p class="text-muted"><?= e(setting('site_name', 'หน่วยงาน')) ?><?= setting('site_dept') ? ' ' . e(setting('site_dept')) : '' ?></p>
  </div>

  <?php if ($levels): ?>
  <div class="org mb-4">
    <?php foreach ($levels as $lv => $people): ?>
    <div class="org-row reveal" data-reveal-group>
      <?php foreach ($people as $p): ?>
      <div class="person reveal<?= $lv == 1 ? ' person-lead' : '' ?>">
        <div class="avatar">
          <?php if ($p['photo']): ?>
            <img class="lazyimg" loading="lazy" src="<?= e(url($p['photo'])) ?>" alt="<?= e($p['name']) ?>">
          <?php else: ?>
            <span class="material-symbols-rounded">person</span>
          <?php endif; ?>
        </div>
        <div class="pn"><?= e($p['name']) ?></div>
        <?php if ($p['position']): ?><div class="pp"><?= e($p['position']) ?></div><?php endif; ?>
        <?php if ($p['phone'] || $p['email']): ?>
        <div class="pc">
          <?php if ($p['phone']): ?><span><span class="material-symbols-rounded icon-sm">call</span> <?= e($p['phone']) ?></span><?php endif; ?>
          <?php if ($p['email']): ?><span><span class="material-symbols-rounded icon-sm">mail</span> <?= e($p['email']) ?></span><?php endif; ?>
        </div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
  </div>
  <?php else: ?>
  <div class="card text-center text-muted reveal mb-4" style="padding:48px;">
    <span class="material-symbols-rounded icon-xl" style="color:var(--border);">groups</span>
    <p>ยังไม่มีข้อมูลโครงสร้างผู้บริหาร</p>
  </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
