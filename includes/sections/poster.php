<?php
/**
 * section: นิทรรศการโปสเตอร์ — จัดแสดงภาพประชาสัมพันธ์ทุกสัดส่วนในกล่องเดียว
 *
 * โจทย์ที่ยากของ section นี้คือโปสเตอร์แนวตั้ง (เช่น 9:16) กับแนวนอน (16:9)
 * ต้องอยู่ด้วยกันได้โดย "ห้ามครอปภาพ" เพราะโปสเตอร์ราชการมีข้อความอยู่ทุกมุม
 * ครอปเมื่อไหร่คือตัดเนื้อหาทิ้ง
 *
 * มีสองโครงหน้าตา:
 *  - โรงฉาย (cinema) — จอใหญ่ทีละใบ + แฟ้มภาพเล็กด้านล่างให้เห็นว่ามีอะไรอีกบ้าง
 *    คำบรรยายอยู่ "ใต้จอ" ไม่ทับตัวโปสเตอร์ เพราะโปสเตอร์ราชการมักมีแถบติดต่อของตัวเอง
 *    อยู่ขอบล่าง ถ้าเอาคำบรรยายไปทับก็บังข้อมูลที่หน่วยงานตั้งใจใส่มา
 *  - แถวเลื่อน (stage / strip / fade) — ตรึงความสูงเท่ากันทุกใบ ปล่อยความกว้าง
 *    ผันตามสัดส่วนภาพ ทุกใบจึงวางบนเส้นฐานเดียวกัน
 *
 * ความกว้างมาจาก img_w/img_h ที่บันทึกไว้ตอนอัปโหลด ไม่ได้รอให้ภาพโหลดเสร็จก่อน
 * ถ้ารอ แถวจะกระโดดทีละใบตอนภาพทยอยมา (layout shift)
 */
if (!defined('APP_ROOT')) exit('Forbidden');

$posters = db()->query('SELECT * FROM posters WHERE enabled = 1 ORDER BY sort_order ASC, id ASC')->fetchAll();
if (!$posters) return;            /* ยังไม่มีโปสเตอร์ = ไม่แสดงกล่องเปล่า */

$style   = in_array(setting('poster_style', 'cinema'), ['cinema', 'stage', 'strip', 'fade'], true) ? setting('poster_style', 'cinema') : 'cinema';
$height  = in_array(setting('poster_height', 'md'), ['sm', 'md', 'lg'], true) ? setting('poster_height', 'md') : 'md';
$auto    = setting('poster_auto', '1') === '1';
$ivl     = max(2000, min(30000, (int)setting('poster_interval', '5000')));
$showCap = setting('poster_caption', '1') === '1';
$frame   = setting('poster_frame', '1') === '1';
$zoom    = setting('poster_zoom', '1') === '1';

$cinema = $style === 'cinema';
$cls = 'pstage st-' . $style . ' h-' . $height;
if ($frame) $cls .= ' framed';
if ($zoom)  $cls .= ' zoomable';
$multi = count($posters) > 1;

/* มีโปสเตอร์ใบไหนมีข้อความให้แสดงบ้างไหม — ถ้าไม่มีเลยก็ไม่ต้องกันที่ว่างไว้ใต้จอ */
$anyCap = false;
if ($showCap) {
    foreach ($posters as $p) {
        if (trim((string)$p['title']) !== '' || trim((string)$p['caption']) !== '') { $anyCap = true; break; }
    }
}
?>
<section class="block mb-4" id="poster">
  <div class="card card-spacious reveal">
    <div class="section-head">
      <div>
        <span class="tag"><span class="material-symbols-rounded icon-sm">gallery_thumbnail</span> POSTER</span>
        <h2><?= e(section_title('poster')) ?></h2>
        <p>ภาพประชาสัมพันธ์และอินโฟกราฟิกของหน่วยงาน<?= $zoom ? ' — คลิกที่ภาพเพื่อดูขนาดเต็ม' : '' ?></p>
      </div>
      <?php /* โหมดโรงฉายวางปุ่มไว้บนจอเอง หัวข้อจึงไม่ต้องมีปุ่มซ้ำ */ ?>
      <?php if ($multi && !in_array($style, ['fade', 'cinema'], true)): ?>
      <div class="ps-nav">
        <button type="button" class="ps-arrow" data-ps-prev aria-label="โปสเตอร์ก่อนหน้า"><span class="material-symbols-rounded">chevron_left</span></button>
        <button type="button" class="ps-arrow" data-ps-next aria-label="โปสเตอร์ถัดไป"><span class="material-symbols-rounded">chevron_right</span></button>
      </div>
      <?php endif; ?>
    </div>

    <?php /* --dur ส่งจังหวะเปลี่ยนภาพให้ CSS ใช้เป็นระยะเวลาซูมและแถบเวลา จะได้ตรงกันเป๊ะ */ ?>
    <div class="<?= $cls ?>" data-poster style="--dur:<?= $ivl ?>ms"
         <?= $auto && $multi ? ' data-auto="1" data-interval="' . $ivl . '"' : '' ?>>

      <?php if ($cinema): ?><div class="ps-screen"><?php endif; ?>
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
          <?php if ($cinema): ?>
          <?php /* ภาพเดิมซ้ำอีกใบเป็นฉากหลังเบลอ — เบราว์เซอร์ใช้ไฟล์ที่โหลดแล้วซ้ำ ไม่ได้ยิงขอเพิ่ม */ ?>
          <img class="pcard-bg" src="<?= e(url($p['image'])) ?>" alt="" aria-hidden="true"
               <?= $i === 0 ? 'loading="eager"' : 'loading="lazy"' ?> decoding="async">
          <?php endif; ?>
          <?php if ($href !== ''): ?><a class="pcard-img" href="<?= e($href) ?>"><?php else: ?><div class="pcard-img"><?php endif; ?>
            <img src="<?= e(url($p['image'])) ?>" alt="<?= e($ttl !== '' ? $ttl : 'โปสเตอร์ประชาสัมพันธ์') ?>"
                 <?= $w > 0 && $h > 0 ? 'width="' . $w . '" height="' . $h . '"' : '' ?>
                 <?= $i === 0 ? 'loading="eager" fetchpriority="high"' : 'loading="lazy"' ?> decoding="async"
                 <?= $zoom ? 'data-ps-zoom' : '' ?>>
          <?php if ($href !== ''): ?></a><?php else: ?></div><?php endif; ?>
          <?php /* โหมดโรงฉายย้ายคำบรรยายไปไว้ใต้จอ ไม่ใส่ตรงนี้ เพื่อไม่ให้ทับตัวโปสเตอร์ */ ?>
          <?php if (!$cinema && $showCap && ($ttl !== '' || $cap !== '')): ?>
          <figcaption>
            <?php if ($ttl !== ''): ?><b><?= e($ttl) ?></b><?php endif; ?>
            <?php if ($cap !== ''): ?><span><?= e($cap) ?></span><?php endif; ?>
          </figcaption>
          <?php endif; ?>
        </figure>
        <?php endforeach; ?>
      </div>

      <?php if ($cinema): ?>
        <?php if ($multi): ?>
        <button type="button" class="ps-side prev" data-ps-prev aria-label="โปสเตอร์ก่อนหน้า"><span class="material-symbols-rounded">chevron_left</span></button>
        <button type="button" class="ps-side next" data-ps-next aria-label="โปสเตอร์ถัดไป"><span class="material-symbols-rounded">chevron_right</span></button>
        <?php if ($auto): ?><div class="ps-prog" aria-hidden="true"><i></i></div><?php endif; ?>
        <?php endif; ?>
      </div><?php /* /ps-screen */ ?>

        <?php if ($anyCap): ?>
        <?php /* คำบรรยายทุกใบซ้อนอยู่ที่เดียวกัน โชว์เฉพาะใบที่กำลังฉาย — ความสูงจึงคงที่ ไม่กระตุกตอนเปลี่ยนภาพ */ ?>
        <div class="ps-caps">
          <?php foreach ($posters as $i => $p):
            $cap = trim((string)$p['caption']); $ttl = trim((string)$p['title']); ?>
          <div class="ps-cap<?= $i === 0 ? ' on' : '' ?>">
            <?php if ($ttl !== ''): ?><b><?= e($ttl) ?></b><?php endif; ?>
            <?php if ($cap !== ''): ?><span><?= e($cap) ?></span><?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if ($multi): ?>
        <?php /* แฟ้มภาพ — เห็นทั้งหมดว่ามีอะไรบ้าง และกดข้ามไปใบที่สนใจได้เลย ไม่ต้องรอวน */ ?>
        <div class="ps-thumbs" role="tablist" aria-label="เลือกโปสเตอร์">
          <?php foreach ($posters as $i => $p):
            $ttl = trim((string)$p['title']); ?>
          <button type="button" class="ps-thumb<?= $i === 0 ? ' on' : '' ?>" role="tab"
                  aria-selected="<?= $i === 0 ? 'true' : 'false' ?>"
                  aria-label="<?= e($ttl !== '' ? $ttl : 'โปสเตอร์ที่ ' . ($i + 1)) ?>">
            <img src="<?= e(url($p['image'])) ?>" alt="" loading="lazy" decoding="async">
          </button>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

      <?php elseif ($multi): ?>
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
