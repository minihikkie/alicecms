<?php
/** section: ติดต่อหน่วยงาน (การ์ดข้อมูลติดต่อ) */
if (!defined('APP_ROOT')) exit('Forbidden');
?>
<div class="card reveal" style="padding:18px;">
  <div class="flex items-center gap-1 mb-1"><span class="material-symbols-rounded" style="color:var(--blue)">location_on</span><b style="color:var(--ink)"><?= e(section_title('contact')) ?></b></div>
  <?php if (setting('site_address')): ?>
  <p class="text-muted" style="font-size:13px;margin:0 0 4px;"><?= e(setting('site_address')) ?></p>
  <?php endif; ?>
  <p class="text-muted" style="font-size:13px;margin:0;">
    <?php
    $parts = array_filter([
        setting('site_phone') ? 'โทร ' . setting('site_phone') : '',
        setting('site_email'),
        setting('site_hours'),
    ]);
    echo e(implode(' · ', $parts));
    ?>
  </p>
  <a class="btn small mt-1" href="<?= e(url('contact.php')) ?>">ดูรายละเอียด <span class="arrow-slide"><span class="material-symbols-rounded icon-sm">arrow_forward</span></span></a>
</div>
