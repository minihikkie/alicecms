<?php
/**
 * section: ข่าวด่วน · ประชาสัมพันธ์จากโซเชียล
 * ใบใหญ่ 1 ใบ (เรื่องด่วนที่สุด/ปักหมุด) + ใบเล็กไม่เกิน 3 ใบ
 * แสดงเฉพาะเรื่องที่อยู่ในช่วงเวลา — ไม่มีเรื่องเลยกล่องนี้ซ่อนตัวเอง เรื่องเก่าดูได้ที่หน้าคลัง
 */
if (!defined('APP_ROOT')) exit('Forbidden');
require_once APP_ROOT . '/includes/highlights.php';

$items = hl_active(4);
if (!$items) return;
$lead = array_shift($items);
$hasCritical = $lead['urgency'] === 'critical';
?>
<section class="block mb-4" id="highlight">
  <div class="card card-spacious hl-section<?= $hasCritical ? ' has-critical' : '' ?> reveal">
    <div class="section-head">
      <div>
        <span class="tag"><span class="material-symbols-rounded icon-sm">bolt</span> URGENT</span>
        <h2><?= e(section_title('highlight')) ?></h2>
        <p>เรื่องด่วนและสื่อประชาสัมพันธ์ล่าสุดจากหน่วยงาน — กดที่ภาพเพื่อดูวิดีโอหรือเปิดโพสต์ต้นฉบับ</p>
      </div>
      <a class="btn" href="<?= e(url('highlights.php')) ?>"><span class="material-symbols-rounded icon-sm">history</span>ดูย้อนหลังทั้งหมด</a>
    </div>

    <div class="hl-layout<?= $items ? '' : ' is-single' ?>">
      <?= hl_card($lead, 'feat') ?>
      <?php if ($items): ?>
      <div class="hl-side">
        <?php foreach ($items as $h): ?><?= hl_card($h, 'side') ?><?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
</section>
