<?php
/**
 * section: กล่องอิสระบนหน้าแรก — เนื้อหาสร้างเองจาก Block Builder ชุดเดียวกับหน้าเพจ
 *
 * ต่างจาก section อื่นตรงที่ไฟล์นี้ใช้ซ้ำได้หลายกล่อง โดยรับคีย์มาทาง $CUSTOM_KEY
 * (index.php ตั้งค่าให้ก่อน include) คีย์รูปแบบ custom-<id>
 *
 * ทำไมไม่ใช้ "หน้าเพจ" แทน: หน้าเพจเป็นหน้าแยกที่ต้องกดจากเมนู
 * ส่วนกล่องนี้อยู่บนหน้าแรกเลย และเรียงสลับกับ section มาตรฐานได้
 */
if (!defined('APP_ROOT')) exit('Forbidden');

$key = $CUSTOM_KEY ?? '';
$cs  = $key !== '' ? custom_section_get($key) : null;
if (!$cs) return;

require_once APP_ROOT . '/includes/blocks.php';
$html = render_blocks($cs['blocks'] ?? null);
$lead = trim((string)$cs['lead']);
$showHead = (int)$cs['show_head'] === 1;
$heading  = section_title($key);

/* ไม่มีทั้งเนื้อหาและหัวข้อ = ไม่ต้องแสดงกล่องเปล่า */
if ($html === '' && !($showHead && ($heading !== '' || $lead !== ''))) return;

$bg    = in_array($cs['bg'], ['card', 'plain', 'tint', 'dark', 'grad'], true) ? $cs['bg'] : 'card';
/* ต้องตรงกับค่าที่หน้า admin บันทึก (box | full) — เคยเขียนเป็น wide แล้วไม่ตรงกัน */
$width = $cs['width'] === 'full' ? 'full' : 'box';
$align = $cs['align'] === 'center' ? 'center' : 'left';
?>
<section class="block mb-4 cs-sec cs-w-<?= e($width) ?>" id="<?= e($key) ?>">
  <div class="cs-box cs-bg-<?= e($bg) ?> cs-al-<?= e($align) ?> reveal">
    <?php if ($showHead && ($heading !== '' || $lead !== '')): ?>
    <div class="section-head cs-head">
      <div>
        <?php if ($heading !== ''): ?><h2><?= e($heading) ?></h2><?php endif; ?>
        <?php if ($lead !== ''): ?><p><?= e($lead) ?></p><?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
    <?php if ($html !== ''): ?><div class="cs-body"><?= $html ?></div><?php endif; ?>
  </div>
</section>
