<?php
/** documents.php — เอกสารเผยแพร่ตามหมวด (ค้นหา + แบ่งหน้า) */
define('PUBLIC_PAGE', 'documents');
require __DIR__ . '/includes/init.php';

/* section นี้ถูกปิดอยู่ → ตอบ 404 ไม่ให้เข้าถึงหน้าโดยตรง */
require_section_live("documents");

$cat  = (int)($_GET['cat'] ?? 0);
$q    = trim((string)($_GET['q'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$per  = 20;

$cats = db()->query('SELECT c.*, (SELECT COUNT(*) FROM documents d WHERE d.category_id = c.id) AS n
                     FROM doc_categories c ORDER BY c.sort_order ASC, c.id ASC')->fetchAll();

$where  = ['1=1'];
$params = [];
if ($cat > 0)   { $where[] = 'd.category_id = ?'; $params[] = $cat; }
if ($q !== '')  { $where[] = 'd.title LIKE ?';    $params[] = "%$q%"; }
$wsql = implode(' AND ', $where);

$st = db()->prepare("SELECT COUNT(*) FROM documents d WHERE $wsql");
$st->execute($params);
$total = (int)$st->fetchColumn();

$st = db()->prepare("SELECT d.*, c.name AS cat_name, c.icon AS cat_icon FROM documents d
                     LEFT JOIN doc_categories c ON c.id = d.category_id
                     WHERE $wsql ORDER BY d.sort_order ASC, d.created_at DESC LIMIT $per OFFSET " . (($page - 1) * $per));
$st->execute($params);
$docs = $st->fetchAll();

$cat_name = '';
$catRow = null;
foreach ($cats as $c) if ((int)$c['id'] === $cat) { $cat_name = $c['name']; $catRow = $c; }

/* หมวดที่ตั้งเป็น "สารบัญแบบหนังสือ" → แสดงเป็นเล่ม 3 ชั้น ชื่อเรียกชั้นตามรูปแบบเล่มของหมวด
   (ประมวล: ลักษณะ → บท → ฉบับแก้ไข · วารสาร: ปี → ฉบับ → บทความ ฯลฯ)
   ?view=list = ดูไฟล์ทั้งหมดของหมวดแบบเดิม · ค้นหาจากช่องค้นหาด้านบน = ผลค้นหาแบบรายการเหมือนเดิม
   ถ้ายังไม่ได้ใส่สารบัญเลย ใช้รายการแบบเดิมไปก่อน หน้าจะได้ไม่ว่าง */
$isBookCat = $catRow && ($catRow['layout'] ?? 'list') === 'book';
$bookTree = [];
if ($isBookCat && ($_GET['view'] ?? '') !== 'list' && $q === '') {
    require_once __DIR__ . '/includes/doc-book.php';
    $bookRows = docbook_rows($cat);
    $bookTree = docbook_tree($bookRows);
    $bookConf = docbook_conf($catRow);
}
$showBook = (bool)$bookTree;

$page_title = $cat_name !== '' ? $cat_name : section_title('documents');
if ($showBook && trim((string)($catRow['intro'] ?? '')) !== '') $meta_description = mb_substr(trim($catRow['intro']), 0, 160);
require __DIR__ . '/includes/header.php';
$base_qs = 'documents.php?' . http_build_query(array_filter(['cat' => $cat ?: null, 'q' => $q, 'view' => $isBookCat && !$showBook && ($_GET['view'] ?? '') === 'list' ? 'list' : null]));
?>
<div class="container">
  <div class="page-head reveal">
    <div class="crumb"><a href="<?= e(url('index.php')) ?>">หน้าแรก</a> <span class="material-symbols-rounded icon-sm">chevron_right</span>
      <?php if ($cat_name !== ''): ?><a href="<?= e(url('documents.php')) ?>">เอกสารเผยแพร่</a> <span class="material-symbols-rounded icon-sm">chevron_right</span> <?= e($cat_name) ?><?php else: ?>เอกสารเผยแพร่<?php endif; ?></div>
    <?php if (!$showBook): ?><h1><?= e($page_title) ?></h1><?php endif; ?>
    <?php if ($isBookCat && !$showBook && ($_GET['view'] ?? '') === 'list'): ?>
    <a class="btn small mt-1" href="<?= e(url('documents.php?cat=' . $cat)) ?>"><span class="material-symbols-rounded icon-sm">menu_book</span>กลับไปดูแบบสารบัญ</a>
    <?php endif; ?>
  </div>

  <div class="flex items-center gap-2 mb-3 reveal" style="flex-wrap:wrap;">
    <div class="cats">
      <a class="cat<?= $cat === 0 ? ' active' : '' ?>" href="<?= e(url('documents.php')) ?>">ทั้งหมด</a>
      <?php foreach ($cats as $c): ?>
      <a class="cat<?= $cat === (int)$c['id'] ? ' active' : '' ?>" href="<?= e(url('documents.php?cat=' . $c['id'])) ?>">
        <span class="material-symbols-rounded icon-sm"><?= e($c['icon'] ?: 'folder') ?></span><?= e($c['name']) ?> (<?= (int)$c['n'] ?>)
      </a>
      <?php endforeach; ?>
    </div>
    <?php if (!$showBook): ?>
    <form class="searchbar" style="max-width:320px;margin:0;margin-left:auto;box-shadow:var(--shadow-soft);" action="<?= e(url('documents.php')) ?>" method="get" role="search">
      <?php if ($cat): ?><input type="hidden" name="cat" value="<?= $cat ?>"><?php endif; ?>
      <span class="material-symbols-rounded" style="color:var(--muted)">search</span>
      <input type="search" name="q" value="<?= e($q) ?>" placeholder="ค้นหาเอกสาร..." aria-label="ค้นหาเอกสาร">
    </form>
    <?php endif; ?>
  </div>

  <?php if ($showBook):
    $bs = docbook_stats($bookTree);
    $bookUpdated = max(array_map(fn($r) => (string)$r['updated_at'], $bookRows));
    $useStatus = !empty($bookConf['status']);
    $hasRep = $useStatus && $bs['rep_any'] > 0;
  ?>
  <section class="bk-cover reveal" aria-labelledby="bk-title">
    <div class="bk-art" aria-hidden="true">
      <span class="bk-art-ic material-symbols-rounded"><?= e($bookConf['cat_icon']) ?></span>
      <span class="bk-art-n"><b><?= $bs['part'] ?></b><?= e($bookConf['l1']) ?></span>
    </div>
    <div class="bk-cover-txt">
      <span class="bk-kicker"><span class="material-symbols-rounded icon-sm" aria-hidden="true">menu_book</span>สารบัญเอกสาร</span>
      <h1 id="bk-title"><?= e($cat_name) ?></h1>
      <?php if (trim((string)($catRow['intro'] ?? '')) !== ''): ?><p class="bk-intro"><?= e($catRow['intro']) ?></p><?php endif; ?>
      <dl class="bk-stats">
        <div><dt><?= e($bookConf['l1']) ?></dt><dd><?= number_format($bs['part']) ?></dd></div>
        <?php if ($bs['chapter']): ?><div><dt><?= e($bookConf['l2']) ?></dt><dd><?= number_format($bs['chapter']) ?></dd></div><?php endif; ?>
        <?php if ($bs['amend']): ?><div><dt><?= e($bookConf['l3']) ?></dt><dd><?= number_format($bs['amend']) ?></dd></div><?php endif; ?>
        <?php if ($useStatus): ?><div><dt><?= e($bookConf['s_on']) ?></dt><dd class="ok"><?= number_format($bs['active']) ?></dd></div><?php endif; ?>
        <?php if ($useStatus && $bs['repealed']): ?><div><dt><?= e($bookConf['s_off']) ?></dt><dd class="rep"><?= number_format($bs['repealed']) ?></dd></div><?php endif; ?>
      </dl>
      <div class="bk-cover-foot">
        <span class="text-muted"><span class="material-symbols-rounded icon-sm" aria-hidden="true">update</span> ปรับปรุงสารบัญ <?= e(thai_date($bookUpdated)) ?></span>
        <?php if ((int)$catRow['n'] > 0): ?><a class="btn small" href="<?= e(url('documents.php?cat=' . $cat . '&view=list')) ?>"><span class="material-symbols-rounded icon-sm">format_list_bulleted</span>ไฟล์ทั้งหมดในหมวด (<?= (int)$catRow['n'] ?>)</a><?php endif; ?>
        <button class="btn small" type="button" data-bk-print hidden><span class="material-symbols-rounded icon-sm">print</span>พิมพ์สารบัญ</button>
      </div>
    </div>
  </section>

  <div class="bk-tools" data-bk-tools hidden>
    <label class="bk-search">
      <span class="material-symbols-rounded" aria-hidden="true">search</span>
      <input type="search" data-bk-q placeholder="ค้นหา<?= e($bookConf['l1'] . ' · ' . $bookConf['l2'] . ' · ' . $bookConf['l3']) ?> หรือคำสำคัญ" aria-label="ค้นหาในสารบัญ" autocomplete="off">
    </label>
    <?php if ($hasRep): ?>
    <div class="bk-seg" role="radiogroup" aria-label="แสดง">
      <button type="button" role="radio" aria-checked="true" data-bk-f="all">ทั้งหมด</button>
      <button type="button" role="radio" aria-checked="false" data-bk-f="active"><?= e($bookConf['s_on']) ?></button>
      <button type="button" role="radio" aria-checked="false" data-bk-f="repealed"><?= e($bookConf['s_off']) ?></button>
    </div>
    <?php endif; ?>
    <div class="bk-tool-btns">
      <button class="btn small ghost" type="button" data-bk-all="1"><span class="material-symbols-rounded icon-sm">unfold_more</span>ขยายทั้งหมด</button>
      <button class="btn small ghost" type="button" data-bk-all="0"><span class="material-symbols-rounded icon-sm">unfold_less</span>ย่อทั้งหมด</button>
    </div>
    <p class="bk-count" data-bk-count aria-live="polite"></p>
  </div>

  <div class="bk-layout mb-4">
    <details class="bk-nav" data-bk-navbox>
      <summary><span class="material-symbols-rounded icon-sm" aria-hidden="true">toc</span>สารบัญ <small>(<?= count($bookTree) ?> <?= e($bookConf['l1']) ?>)</small></summary>
      <ol>
        <?php foreach ($bookTree as $p): [, $num] = docbook_label_parts($p['label']); ?>
        <li><a href="#t<?= (int)$p['id'] ?>" data-bk-nav="<?= (int)$p['id'] ?>"<?= $useStatus && $p['status'] === 'repealed' ? ' class="is-repealed"' : '' ?>>
          <span class="n<?= mb_strlen($num) > 2 ? ' long' : '' ?>"><?= e($num !== '' ? $num : '•') ?></span><span class="t"><?= e($p['title'] !== '' ? $p['title'] : $p['label']) ?></span></a></li>
        <?php endforeach; ?>
      </ol>
    </details>
    <div class="bk-main" data-bk data-bk-unit="<?= e($bookConf['l1']) ?>">
      <?= docbook_render($bookTree, $bookConf) ?>
      <div class="bk-none" data-bk-none hidden>
        <span class="material-symbols-rounded" aria-hidden="true">search_off</span>
        <p>ไม่พบหัวข้อที่ตรงกับคำค้น</p>
      </div>
    </div>
  </div>
  <?php else: ?>

  <?php if ($q !== ''): ?>
  <p class="text-muted mb-2">ผลการค้นหา "<?= e($q) ?>" — พบ <?= number_format($total) ?> รายการ</p>
  <?php endif; ?>

  <?php if ($docs): ?>
  <div class="card reveal">
    <div class="grid grid-2" style="gap:8px 24px;">
      <?php foreach ($docs as $d): $isExt = !empty($d['ext_url']); ?>
      <a class="list-row" href="<?= e(url('download.php?id=' . $d['id'])) ?>"<?= $isExt ? ' target="_blank" rel="noopener"' : '' ?>>
        <div class="lr-icon" style="background:rgba(234,67,53,.08);color:var(--danger);"><span class="material-symbols-rounded"><?= e($d['cat_icon'] ?: ($isExt ? 'cloud' : 'picture_as_pdf')) ?></span></div>
        <div style="flex:1;">
          <div class="lr-title"><?= e($d['title']) ?>
            <?php if (is_new($d['created_at'])): ?><span class="badge gold" style="font-size:11px;padding:2px 8px;">ใหม่</span><?php endif; ?>
          </div>
          <div class="lr-date"><?= e($d['cat_name'] ?: 'ทั่วไป') ?> · <?php if ($isExt): ?><span class="material-symbols-rounded icon-sm" style="vertical-align:-3px;">link</span> ลิงก์ภายนอก<?php else: ?><?= e(strtoupper((string)$d['ext'])) ?> · <?= e(format_bytes((int)$d['file_size'])) ?><?php endif; ?> · <?= e(thai_date($d['created_at'])) ?><?php if ((int)$d['downloads'] > 0): ?> · เปิด <?= number_format((int)$d['downloads']) ?> ครั้ง<?php endif; ?></div>
        </div>
        <span class="btn small" style="flex-shrink:0;"><span class="material-symbols-rounded icon-sm"><?= $isExt ? 'open_in_new' : 'download' ?></span></span>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
  <?= pagination_html($total, $per, $page, url($base_qs)) ?>
  <?php else: ?>
  <div class="card text-center text-muted reveal" style="padding:48px;">
    <span class="material-symbols-rounded icon-xl" style="color:var(--border);">folder_open</span>
    <p>ไม่พบเอกสาร</p>
  </div>
  <?php endif; ?>
  <?php endif; /* $showBook */ ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
