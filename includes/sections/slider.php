<?php
/** section: แบนเนอร์สไลด์ + ticker ประกาศล่าสุด */
if (!defined('APP_ROOT')) exit('Forbidden');

$slides = db()->query('SELECT * FROM slides WHERE enabled = 1 ORDER BY sort_order ASC, id ASC')->fetchAll();
if (!$slides) return;

/* ticker: หัวข้อประกาศ/ข่าว 6 รายการล่าสุด */
$ticker_posts = db()->query("SELECT id, title FROM posts WHERE status = 'published'
                             ORDER BY published_at DESC LIMIT 6")->fetchAll();

/* ค่ารวมของสไลเดอร์ */
$g_interval   = max(2000, (int)setting('slider_interval', '6000'));
$g_transition = setting('slider_transition', 'fade') === 'slide' ? 'slide' : 'fade';

/* ตัวช่วยเรนเดอร์ปุ่ม (รองรับ solid/outline + สีกำหนดเอง) */
$render_btn = function (string $text, string $url, array $s, bool $secondary = false): string {
    if (trim($text) === '') return '';
    $style = ($s['btn_style'] ?? 'solid') === 'outline' ? 'outline' : 'solid';
    $col   = (string)($s['btn_color'] ?? '');
    if ($style === 'outline') {
        $c = $col !== '' ? $col : '#ffffff';
        $css = 'background:transparent;border:2px solid ' . $c . ';color:' . $c . ';';
    } elseif ($col !== '') {
        $css = 'background:' . $col . ';border:2px solid ' . $col . ';color:#fff;';
    } else {
        $css = 'background:#fff;border:2px solid #fff;color:var(--blue-700);';
    }
    return '<a class="btn slide-cta' . ($secondary ? ' cta2' : '') . '" style="' . e($css) . '" href="' . e($url !== '' ? $url : '#') . '">' . e($text) . '</a>';
};
?>
<div class="slider <?= $g_transition === 'slide' ? 'tr-slide' : '' ?>" id="hslider" data-interval="<?= $g_interval ?>" aria-label="<?= e(section_title('slider')) ?>">
  <?php foreach ($slides as $i => $s):
    $sa = in_array($s['text_align'] ?? 'center', ['left','center','right'], true) ? $s['text_align'] : 'center';
    $sv = in_array($s['text_valign'] ?? 'middle', ['top','middle','bottom'], true) ? $s['text_valign'] : 'middle';
    $tsz = in_array($s['title_size'] ?? 'md', ['sm','md','lg','xl'], true) ? $s['title_size'] : 'md';
    $hasImg = !empty($s['image']);
    $hasGrad = !$hasImg && !empty($s['grad_from']) && !empty($s['grad_to']);
    $cls = 'sa-' . $sa . ' sv-' . $sv . ' ts-' . $tsz;
    if (!$hasImg && !$hasGrad) $cls .= ' s' . (($i % 3) + 1);   /* ไล่สีตามธีมเริ่มต้น */
    if ($hasGrad) $cls .= ' grad';
    if (($s['text_theme'] ?? '') === 'dark') $cls .= ' txt-dark';
    if (empty($s['text_shadow'])) $cls .= ' no-shadow';
    if (!empty($s['kenburns'])) $cls .= ' kb';
    if ($hasImg) $cls .= ' has-img';
    if ($i === 0) $cls .= ' show';
    $ov = max(0, min(80, (int)($s['overlay'] ?? 35)));
    $fx = max(0, min(100, (int)($s['focus_x'] ?? 50)));
    $fy = max(0, min(100, (int)($s['focus_y'] ?? 50)));
    $dur = max(0, min(30, (int)($s['duration'] ?? 0)));
    $styleVars = '--ov:' . ($ov / 100) . ';';
    if ($hasGrad) $styleVars .= '--gf:' . e($s['grad_from']) . ';--gt:' . e($s['grad_to']) . ';';
    $tcolStyle = !empty($s['text_color']) ? 'color:' . e($s['text_color']) . ';' : '';
  ?>
  <div class="slide <?= $cls ?>" style="<?= $styleVars ?>"<?= $dur ? ' data-dur="' . $dur . '"' : '' ?>>
    <?php if ($hasImg): ?><img class="slide-img" src="<?= e(url($s['image'])) ?>" alt=""
         width="1600" height="640"
         <?php /* สไลด์แรกคือภาพใหญ่สุดที่ผู้ใช้เห็นก่อน (LCP) — โหลดทันทีและจัดลำดับความสำคัญสูง
                  สไลด์ที่เหลือค่อยโหลดทีหลัง ไม่แย่งแบนด์วิดท์ */ ?>
         <?= $i === 0 ? 'loading="eager" fetchpriority="high" decoding="async"' : 'loading="lazy" decoding="async"' ?>
         style="object-position:<?= $fx ?>% <?= $fy ?>%;"><?php endif; ?>
    <?php if (trim((string)$s['title']) !== ''): ?><h2<?= $tcolStyle ? ' style="' . $tcolStyle . '"' : '' ?>><?= e($s['title']) ?></h2><?php endif; ?>
    <?php if (trim((string)$s['subtitle']) !== ''): ?><p<?= $tcolStyle ? ' style="' . $tcolStyle . '"' : '' ?>><?= e($s['subtitle']) ?></p><?php endif; ?>
    <?php $b1 = $render_btn((string)$s['link_text'], (string)$s['link_url'], $s);
          $b2 = $render_btn((string)($s['link_text2'] ?? ''), (string)($s['link_url2'] ?? ''), $s, true);
          if ($b1 || $b2): ?>
    <div class="slide-cta-row"><?= $b1 . $b2 ?></div>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
  <?php if (count($slides) > 1): ?>
  <button class="hs-arrow prev" aria-label="สไลด์ก่อนหน้า"><span class="material-symbols-rounded">chevron_left</span></button>
  <button class="hs-arrow next" aria-label="สไลด์ถัดไป"><span class="material-symbols-rounded">chevron_right</span></button>
  <?php /* ใช้ <button> จริงเพื่อให้กดด้วยคีย์บอร์ดได้ และมีพื้นที่กดกว้างพอตามเกณฑ์การเข้าถึง */ ?>
  <div class="dots" role="tablist" aria-label="เลือกสไลด์"><?php foreach ($slides as $i => $s): ?><button type="button" class="<?= $i === 0 ? 'on' : '' ?>" role="tab" aria-label="สไลด์ที่ <?= $i + 1 ?>" aria-selected="<?= $i === 0 ? 'true' : 'false' ?>"><i></i></button><?php endforeach; ?></div>
  <div class="hs-prog"><i></i></div>
  <?php endif; ?>
</div>

<?php if ($ticker_posts): ?>
<div class="ticker">
  <div class="ticker-label"><span class="material-symbols-rounded icon-sm filled">campaign</span>ข่าวล่าสุด</div>
  <div class="ticker-view">
    <div class="ticker-track">
      <?php foreach ($ticker_posts as $tp): ?>
      <a href="<?= e(url('post.php?id=' . $tp['id'])) ?>"><span class="material-symbols-rounded">fiber_manual_record</span><?= e($tp['title']) ?></a>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php endif; ?>
