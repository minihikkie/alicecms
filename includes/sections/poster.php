<?php
/**
 * section: นิทรรศการโปสเตอร์ — จัดแสดงภาพประชาสัมพันธ์ทุกสัดส่วนในแถวเดียวกัน
 *
 * โจทย์ที่ยากของ section นี้คือโปสเตอร์แนวตั้ง (เช่น 9:16) กับแนวนอน (16:9)
 * ต้องอยู่ด้วยกันได้โดย "ห้ามครอปภาพ" เพราะโปสเตอร์ราชการมีข้อความอยู่ทุกมุม
 * ครอปเมื่อไหร่คือตัดเนื้อหาทิ้ง
 *
 * วิธีแก้: ตรึง "ความสูงเวที" ไว้เท่ากันทุกใบ แล้วปล่อยให้ความกว้างผันไปตามสัดส่วนของภาพ
 * ทุกใบจึงวางอยู่บนเส้นฐานเดียวกัน ดูตั้งใจ ไม่ใช่ดูรก — เป็นวิธีเดียวกับที่หน้าสินค้า
 * ของ Apple ใช้จัดภาพหลายสัดส่วนในแถวเดียว
 *
 * ความกว้างมาจาก img_w/img_h ที่บันทึกไว้ตอนอัปโหลด ไม่ได้รอให้ภาพโหลดเสร็จก่อน
 * ถ้ารอ แถวจะกระโดดทีละใบตอนภาพทยอยมา (layout shift) ซึ่งเป็นสิ่งที่ทำให้เว็บดูไม่มืออาชีพที่สุด
 */
if (!defined('APP_ROOT')) exit('Forbidden');

$posters = db()->query('SELECT * FROM posters WHERE enabled = 1 ORDER BY sort_order ASC, id ASC')->fetchAll();
if (!$posters) return;            /* ยังไม่มีโปสเตอร์ = ไม่แสดงกล่องเปล่า */

$style   = in_array(setting('poster_style', 'stage'), ['stage', 'strip', 'fade'], true) ? setting('poster_style', 'stage') : 'stage';
$height  = in_array(setting('poster_height', 'md'), ['sm', 'md', 'lg'], true) ? setting('poster_height', 'md') : 'md';
$auto    = setting('poster_auto', '1') === '1';
$ivl     = max(2000, min(30000, (int)setting('poster_interval', '5000')));
$showCap = setting('poster_caption', '1') === '1';
$frame   = setting('poster_frame', '1') === '1';
$zoom    = setting('poster_zoom', '1') === '1';

$cls = 'pstage st-' . $style . ' h-' . $height;
if ($frame) $cls .= ' framed';
if ($zoom)  $cls .= ' zoomable';
$multi = count($posters) > 1;
?>
<section class="block mb-4" id="poster">
  <div class="card card-spacious reveal">
    <div class="section-head">
      <div>
        <span class="tag"><span class="material-symbols-rounded icon-sm">gallery_thumbnail</span> POSTER</span>
        <h2><?= e(section_title('poster')) ?></h2>
        <p>ภาพประชาสัมพันธ์และอินโฟกราฟิกของหน่วยงาน<?= $zoom ? ' — คลิกที่ภาพเพื่อดูขนาดเต็ม' : '' ?></p>
      </div>
      <?php if ($multi && $style !== 'fade'): ?>
      <div class="ps-nav">
        <button type="button" class="ps-arrow" data-ps-prev aria-label="โปสเตอร์ก่อนหน้า"><span class="material-symbols-rounded">chevron_left</span></button>
        <button type="button" class="ps-arrow" data-ps-next aria-label="โปสเตอร์ถัดไป"><span class="material-symbols-rounded">chevron_right</span></button>
      </div>
      <?php endif; ?>
    </div>

    <div class="<?= $cls ?>" data-poster
         <?= $auto && $multi ? ' data-auto="1" data-interval="' . $ivl . '"' : '' ?>>
      <div class="pstage-track" role="list">
        <?php foreach ($posters as $i => $p):
          /* สัดส่วนสำรอง 3:4 เมื่อยังไม่รู้ขนาดจริง (แถวเก่าที่อัปโหลดก่อนมีฟีเจอร์นี้) */
          $w = (int)$p['img_w']; $h = (int)$p['img_h'];
          $ar = ($w > 0 && $h > 0) ? round($w / $h, 4) : 0.75;
          $ar = max(0.25, min(4.0, $ar));                  /* กันภาพยาวผิดปกติดันแถวพัง */
          $cap = trim((string)$p['caption']);
          $ttl = trim((string)$p['title']);
          $href = safe_link_url((string)$p['link_url']);
        ?>
        <figure class="pcard<?= $i === 0 ? ' on' : '' ?>" style="--ar:<?= $ar ?>" role="listitem">
          <?php if ($href !== ''): ?><a class="pcard-img" href="<?= e($href) ?>"><?php else: ?><div class="pcard-img"><?php endif; ?>
            <img src="<?= e(url($p['image'])) ?>" alt="<?= e($ttl !== '' ? $ttl : 'โปสเตอร์ประชาสัมพันธ์') ?>"
                 <?= $w > 0 && $h > 0 ? 'width="' . $w . '" height="' . $h . '"' : '' ?>
                 <?= $i === 0 ? 'loading="eager" fetchpriority="high"' : 'loading="lazy"' ?> decoding="async"
                 <?= $zoom ? 'data-ps-zoom' : '' ?>>
          <?php if ($href !== ''): ?></a><?php else: ?></div><?php endif; ?>
          <?php if ($showCap && ($ttl !== '' || $cap !== '')): ?>
          <figcaption>
            <?php if ($ttl !== ''): ?><b><?= e($ttl) ?></b><?php endif; ?>
            <?php if ($cap !== ''): ?><span><?= e($cap) ?></span><?php endif; ?>
          </figcaption>
          <?php endif; ?>
        </figure>
        <?php endforeach; ?>
      </div>

      <?php if ($multi): ?>
      <div class="ps-dots" role="tablist" aria-label="เลือกโปสเตอร์">
        <?php foreach ($posters as $i => $p): ?>
        <button type="button" class="<?= $i === 0 ? 'on' : '' ?>" role="tab"
                aria-selected="<?= $i === 0 ? 'true' : 'false' ?>" aria-label="โปสเตอร์ที่ <?= $i + 1 ?>"><i></i></button>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
</section>
