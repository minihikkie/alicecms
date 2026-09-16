<?php
/**
 * section: นิทรรศการโปสเตอร์ — จัดแสดงภาพประชาสัมพันธ์ทุกสัดส่วนในกล่องเดียว
 *
 * โจทย์ที่ยากของ section นี้คือโปสเตอร์แนวตั้ง (เช่น 9:16) กับแนวนอน (16:9)
 * ต้องอยู่ด้วยกันได้โดย "ห้ามครอปภาพ" เพราะโปสเตอร์ราชการมีข้อความอยู่ทุกมุม
 * ครอปเมื่อไหร่คือตัดเนื้อหาทิ้ง
 *
 * โหมด "โรงฉาย" คิดเสียว่าทั้งกล่องคือจอหนังหนึ่งจอ ทุกอย่างอยู่ในจอนั้น ไม่มีอะไรหลุดออกมาข้างนอก:
 *   ชั้นหลังสุด  ฉากหลังเบลอจากภาพที่กำลังฉาย
 *   ชั้นกลาง     ภาพเล็กของโปสเตอร์ใบอื่นลอยไปมาช้าๆ เป็นบรรยากาศ ให้รู้ว่ายังมีอีกหลายใบ
 *   ชั้นหน้า     โปสเตอร์ที่กำลังฉาย คมชัดเต็มความสูง
 *   แถบล่างในจอ  ชื่อ/คำบรรยาย + แฟ้มภาพให้กดข้าม (กันพื้นที่ไว้ต่างหาก โปสเตอร์จึงไม่ถูกทับ)
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

/* มีโปสเตอร์ใบไหนมีข้อความให้แสดงบ้างไหม — ถ้าไม่มีเลยก็ไม่ต้องกันที่ว่างไว้ */
$anyCap = false;
if ($showCap) {
    foreach ($posters as $p) {
        if (trim((string)$p['title']) !== '' || trim((string)$p['caption']) !== '') { $anyCap = true; break; }
    }
}
$hasBar = $cinema && ($anyCap || $multi);

/* ตำแหน่งของภาพเล็กที่ลอยอยู่ในฉาก — เขียนไว้ตายตัวเป็นชุด ไม่สุ่ม
   เพราะถ้าสุ่มทุกครั้งที่โหลดหน้า ภาพจะย้ายที่ไปเรื่อยจนดูไม่ตั้งใจ
   และทุกจุดเลี่ยงแนวกลางจอไว้ ตรงนั้นเป็นที่ของโปสเตอร์ที่กำลังฉาย
   [ซ้าย%, บน%, ขนาดpx, ระยะลอยX, ระยะลอยY, องศาเอียง, วินาทีต่อรอบ, หน่วงเริ่ม] */
$FLOAT_SLOTS = [
    [ 4, 10,  96,  26, -20, -5, 27,  0],
    [15, 58,  70, -22,  24,  4, 33, -7],
    [26, 26,  54,  18,  20, -3, 29, -15],
    [78, 14,  88, -24, -18,  5, 31, -4],
    [69, 62,  66,  20,  22, -4, 35, -11],
    [58, 34,  50, -16, -22,  3, 30, -19],
    [ 8, 80,  60,  22, -16, -4, 37, -23],
    [86, 76,  56, -18,  18,  5, 32, -28],
];
?>
<section class="block mb-4" id="poster">
  <div class="card card-spacious reveal">
    <div class="section-head">
      <div>
        <span class="tag"><span class="material-symbols-rounded icon-sm">gallery_thumbnail</span> POSTER</span>
        <h2><?= e(section_title('poster')) ?></h2>
        <p>ภาพประชาสัมพันธ์และอินโฟกราฟิกของหน่วยงาน<?= $zoom ? ' — คลิกที่ภาพเพื่อดูขนาดเต็ม' : '' ?></p>
      </div>
      <?php /* โหมดโรงฉายวางปุ่มไว้ในจอเอง หัวข้อจึงไม่ต้องมีปุ่มซ้ำ */ ?>
      <?php if ($multi && !in_array($style, ['fade', 'cinema'], true)): ?>
      <div class="ps-nav">
        <button type="button" class="ps-arrow" data-ps-prev aria-label="โปสเตอร์ก่อนหน้า"><span class="material-symbols-rounded">chevron_left</span></button>
        <button type="button" class="ps-arrow" data-ps-next aria-label="โปสเตอร์ถัดไป"><span class="material-symbols-rounded">chevron_right</span></button>
      </div>
      <?php endif; ?>
    </div>

    <?php /* --dur ส่งจังหวะเปลี่ยนภาพให้ CSS ใช้เป็นระยะเวลาซูมและแถบเวลา จะได้ตรงกันเป๊ะ */ ?>
    <div class="<?= $cls ?><?= $hasBar ? ' has-bar' : '' ?>" data-poster style="--dur:<?= $ivl ?>ms"
         <?= $auto && $multi ? ' data-auto="1" data-interval="' . $ivl . '"' : '' ?>>

      <?php if ($cinema): ?>
      <div class="ps-screen">

        <?php if ($multi): ?>
        <?php /* ภาพเล็กลอยอยู่หลังโปสเตอร์ — เป็นบรรยากาศของฉาก ไม่ใช่ปุ่มกด จึงไม่รับคลิกและซ่อนจากโปรแกรมอ่านหน้าจอ */ ?>
        <div class="ps-floats" aria-hidden="true">
          <?php foreach (array_slice($posters, 0, count($FLOAT_SLOTS)) as $i => $p):
            [$fx, $fy, $fs, $dx, $dy, $rot, $dur, $delay] = $FLOAT_SLOTS[$i]; ?>
          <img class="ps-float" src="<?= e(url($p['image'])) ?>" alt="" loading="lazy" decoding="async"
               style="left:<?= $fx ?>%;top:<?= $fy ?>%;width:<?= $fs ?>px;--dx:<?= $dx ?>px;--dy:<?= $dy ?>px;--rot:<?= $rot ?>deg;--fdur:<?= $dur ?>s;--fdelay:<?= $delay ?>s">
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      <?php endif; ?>

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
          <?php /* โหมดโรงฉายย้ายคำบรรยายไปไว้ในแถบล่างของจอ ไม่ใส่ตรงนี้ เพื่อไม่ให้ทับตัวโปสเตอร์ */ ?>
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
        <?php endif; ?>

        <?php if ($hasBar): ?>
        <?php /* แถบล่างในจอ — เหมือนแถบควบคุมของเครื่องเล่นวิดีโอ กันพื้นที่ไว้ต่างหาก
                 โปสเตอร์จึงไม่มีทางถูกทับ แม้เป็นโปสเตอร์แนวตั้งที่สูงเต็มจอ */ ?>
        <div class="ps-bar">
          <?php if ($anyCap): ?>
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
        </div>
        <?php endif; ?>

        <?php if ($auto && $multi): ?><div class="ps-prog" aria-hidden="true"><i></i></div><?php endif; ?>
      </div><?php /* /ps-screen */ ?>

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
