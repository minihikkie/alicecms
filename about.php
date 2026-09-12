<?php
/** about.php — เกี่ยวกับหน่วยงาน (วิสัยทัศน์ / ข้อมูล / โครงสร้าง) */
define('PUBLIC_PAGE', 'about');
require __DIR__ . '/includes/init.php';
require __DIR__ . '/includes/orgchart.php';
require __DIR__ . '/includes/blocks.php';
$org_tree = org_chart_tree();
$about_blocks_html = render_blocks(setting('about_blocks'));
$page_title = 'เกี่ยวกับหน่วยงาน';
require __DIR__ . '/includes/header.php';

/** เรนเดอร์ node แบบ recursive เป็นผัง HTML (โครงเดียวกับ admin/org-chart.php)
 *  $isRoot = true เฉพาะชั้นบนสุด (จัดแถวกลาง+กล่องไล่สีเด่น) ชั้นลูกลงไปเป็น grid ที่ห่อบรรทัดเอง */
function org_chart_render(array $nodes, bool $isRoot = true): void {
    if (!$nodes) return;
    echo '<div class="' . ($isRoot ? 'org-root-row' : 'org-children') . '">';
    foreach ($nodes as $n) {
        echo '<div class="org-node">';
        echo '<div class="org-box' . ($isRoot ? ' org-box-root' : '') . '">' . e($n['label']) . '</div>';
        if ($n['children']) org_chart_render($n['children'], false);
        echo '</div>';
    }
    echo '</div>';
}
?>
<div class="container">
  <div class="page-head reveal">
    <div class="crumb"><a href="<?= e(url('index.php')) ?>">หน้าแรก</a> <span class="material-symbols-rounded icon-sm">chevron_right</span> เกี่ยวกับ</div>
    <h1>เกี่ยวกับหน่วยงาน</h1>
  </div>

  <?php if (setting('site_vision')): ?>
  <div class="card card-spacious mb-3 reveal" style="background:linear-gradient(135deg, color-mix(in srgb, var(--blue) 8%, transparent), rgba(255,255,255,.92) 55%); border:2px solid color-mix(in srgb, var(--blue) 28%, transparent); text-align:center;">
    <h2 class="grad" style="margin:8px 0;">"<?= e(setting('site_vision')) ?>"</h2>
    <p class="text-muted" style="margin:0;">วิสัยทัศน์<?= e(setting('site_name', '')) ?></p>
  </div>
  <?php endif; ?>

  <?php if ($about_blocks_html !== ''): ?>
  <div class="card card-spacious mb-3 reveal">
    <div class="page-content"><?= $about_blocks_html ?></div>
  </div>
  <?php elseif (setting('about_text')): ?>
  <div class="card card-spacious mb-3 reveal">
    <div class="post-body"><?= nl2br(e(setting('about_text'))) ?></div>
  </div>
  <?php endif; ?>

  <?php if ($org_tree): ?>
  <div class="card card-spacious mb-3 reveal">
    <h3>โครงสร้างหน่วยงาน</h3>
    <div style="overflow-x:auto;padding:16px 0 4px;">
      <div class="org-chart">
        <?php org_chart_render($org_tree); ?>
      </div>
    </div>
  </div>
  <?php elseif (setting('structure_image')): ?>
  <div class="card card-spacious mb-3 reveal">
    <h3>โครงสร้างหน่วยงาน</h3>
    <img src="<?= e(url(setting('structure_image'))) ?>" alt="โครงสร้างหน่วยงาน" style="width:100%;border-radius:var(--r16);cursor:zoom-in;" data-lightbox="<?= e(url(setting('structure_image'))) ?>" class="lazyimg" loading="lazy">
  </div>
  <?php endif; ?>

  <?php if (!setting('site_vision') && $about_blocks_html === '' && !setting('about_text') && !$org_tree && !setting('structure_image')): ?>
  <div class="card text-center text-muted reveal" style="padding:48px;">ยังไม่มีข้อมูล — ตั้งค่าได้ที่ระบบจัดการเว็บไซต์</div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
