<?php
/** ita.php — การเปิดเผยข้อมูลสาธารณะ (OIT) 4 หมวด O1–O43 */
define('PUBLIC_PAGE', 'ita');
require __DIR__ . '/includes/init.php';

$groups = ita_groups();
$grp = (int)($_GET['grp'] ?? 0);
if ($grp !== 0 && !isset($groups[$grp])) $grp = 0;

/* ปีงบประมาณที่เลือกดู (ค่าเริ่มต้น = ปีล่าสุดที่มีข้อมูล) */
$years = ita_years();
$fy    = (int)($_GET['fy'] ?? 0);
if (!in_array($fy, $years, true)) $fy = (int)$years[0];

$items = [];
if ($grp > 0) {
    $st = db()->prepare('SELECT * FROM ita_items WHERE grp = ? AND fiscal_year = ? ORDER BY sort_order ASC, id ASC');
    $st->execute([$grp, $fy]);
    $items = $st->fetchAll();
}

$page_title = $grp > 0 ? $groups[$grp]['title'] : section_title('ita');
require __DIR__ . '/includes/header.php';
?>
<div class="container">
  <div class="page-head reveal">
    <div class="crumb">
      <a href="<?= e(url('index.php')) ?>">หน้าแรก</a> <span class="material-symbols-rounded icon-sm">chevron_right</span>
      <?php if ($grp > 0): ?><a href="<?= e(url('ita.php')) ?>">ITA</a> <span class="material-symbols-rounded icon-sm">chevron_right</span> <?= e($groups[$grp]['title']) ?>
      <?php else: ?>ITA<?php endif; ?>
    </div>
    <h1><?= e($page_title) ?></h1>
    <p class="text-muted">การประเมินคุณธรรมและความโปร่งใสในการดำเนินงานของหน่วยงานภาครัฐ (ITA) — การเปิดเผยข้อมูลสาธารณะ (OIT)</p>
  </div>

  <?php if (count($years) > 1): ?>
  <div class="flex items-center gap-2 mb-3 reveal" style="flex-wrap:wrap;">
    <span class="text-muted" style="font-size:13.5px;">ปีงบประมาณ</span>
    <div class="cats">
      <?php foreach ($years as $y): ?>
      <a class="cat<?= (int)$y === $fy ? ' active' : '' ?>"
         href="<?= e(url('ita.php?' . http_build_query(array_filter(['grp' => $grp ?: null, 'fy' => $y])))) ?>"><?= (int)$y ?></a>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($grp === 0): ?>
  <div class="grid grid-2 mb-4" data-reveal-group>
    <?php foreach ($groups as $gid => $g):
        $st = db()->prepare('SELECT COUNT(*) FROM ita_items WHERE grp = ? AND fiscal_year = ?');
        $st->execute([$gid, $fy]);
        $cnt = (int)$st->fetchColumn();
    ?>
    <a class="tool <?= e($g['style']) ?> reveal" href="<?= e(url('ita.php?grp=' . $gid . '&fy=' . $fy)) ?>" style="padding:24px 20px;">
      <div class="ti" style="width:54px;height:54px;"><span class="material-symbols-rounded icon-lg"><?= e($g['icon']) ?></span></div>
      <div><div class="tt"><?= e($g['title']) ?></div><div class="ts"><?= e($g['sub']) ?> · <?= $cnt ?> รายการ</div></div>
    </a>
    <?php endforeach; ?>
  </div>
  <?php else: ?>
  <div class="card reveal mb-4">
    <?php if ($items): ?>
    <?php foreach ($items as $it):
        $href = $it['url'] ?: ($it['file'] ? url($it['file']) : '');
    ?>
    <a class="list-row" <?= $href ? 'href="' . e($href) . '" target="_blank" rel="noopener"' : '' ?>>
      <div class="lr-icon"><span class="material-symbols-rounded"><?= $it['url'] ? 'link' : 'picture_as_pdf' ?></span></div>
      <div style="flex:1;">
        <div class="lr-title"><?php if ($it['code']): ?><b style="color:var(--blue);"><?= e($it['code']) ?></b> · <?php endif; ?><?= e($it['title']) ?></div>
        <?php if (trim((string)($it['note'] ?? '')) !== ''): ?><div class="lr-date" style="color:var(--text);"><?= e($it['note']) ?></div><?php endif; ?>
        <div class="lr-date">
          <?php if ($it['file']): ?>ไฟล์แนบ · <?= e(strtoupper(pathinfo($it['file'], PATHINFO_EXTENSION))) ?><?php endif; ?>
          <?php if (!empty($it['published_at'])): ?><?= $it['file'] ? ' · ' : '' ?>เผยแพร่ <?= e(thai_date($it['published_at'])) ?><?php endif; ?>
        </div>
      </div>
      <?php if ($href): ?><span class="btn small" style="flex-shrink:0;"><span class="material-symbols-rounded icon-sm"><?= $it['url'] ? 'open_in_new' : 'download' ?></span></span><?php endif; ?>
    </a>
    <?php endforeach; ?>
    <?php else: ?>
    <div class="text-center text-muted" style="padding:32px;">ยังไม่มีข้อมูลในหมวดนี้</div>
    <?php endif; ?>
  </div>
  <a class="btn mb-4" href="<?= e(url('ita.php')) ?>"><span class="material-symbols-rounded icon-sm">arrow_back</span>กลับหน้ารวม ITA</a>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
