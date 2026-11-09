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

$st = db()->prepare("SELECT d.*, c.name AS cat_name, c.icon AS cat_icon, c.cover AS cat_cover FROM documents d
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
/* หมวดแบบ "ชั้นวางแบบมีปก" (วารสาร/รายงานประจำปี) → ชั้นหนังสือรายฉบับ (includes/doc-shelf.php)
   ?view=list / ค้นหา / ยังไม่มีเอกสาร → รายการแบบเดิม */
$showShelf = false;
$shelfDocs = [];
if ($catRow && ($catRow['layout'] ?? 'list') === 'shelf' && ($_GET['view'] ?? '') !== 'list' && $q === '') {
    require_once __DIR__ . '/includes/doc-shelf.php';
    $shelfConf = docshelf_conf($catRow);
    $st = db()->prepare('SELECT * FROM documents WHERE category_id = ? ORDER BY sort_order ASC, id DESC LIMIT 1000');
    $st->execute([$cat]);
    $shelfDocs = $st->fetchAll();
    $showShelf = (bool)$shelfDocs;
}
/* หมวดแบบรายการที่มีภาพปก (และไม่ได้กำลังค้นหา) → หัวหน้าแบบปกเล่มเข้าชุดกับหน้าสารบัญ */
$listCover = !$showBook && !$showShelf && $catRow && !empty($catRow['cover']) && $q === '';
/* หน้า "ทั้งหมด" (ไม่ได้เลือกหมวด ไม่ได้ค้นหา) → ชั้นหนังสือ: เลือกหมวดจากปก แทนแถบปุ่มหมวด */
$isShelf = $cat === 0 && $q === '' && $cats;
/* หน้าชั้นหนังสือมีชั้น "มาใหม่" และ "ยอดนิยม" (จากยอดเปิดที่ download.php นับอยู่แล้ว)
   ยอดนิยมแสดงเมื่อมีเอกสารที่ถูกเปิดอย่างน้อย 3 รายการ ไม่งั้นจะดูโหรงเหรง */
$newDocs = $popDocs = [];
if ($isShelf) {
    /* d.* (ไม่ระบุ d.cover ตรงๆ) — ระหว่างอัปเดตจากเวอร์ชันเก่า ไฟล์ใหม่มาถึงก่อน migration เพิ่มคอลัมน์
       ถ้าระบุชื่อคอลัมน์ใหม่ หน้านี้จะ error ช่วงสั้นๆ นั้น */
    $sel = 'SELECT d.*, c.name AS cat_name, c.icon AS cat_icon, c.cover AS cat_cover
            FROM documents d LEFT JOIN doc_categories c ON c.id = d.category_id';
    $newDocs = db()->query("$sel ORDER BY d.created_at DESC, d.id DESC LIMIT 6")->fetchAll();
    $popDocs = db()->query("$sel WHERE d.downloads > 0 ORDER BY d.downloads DESC, d.created_at DESC LIMIT 6")->fetchAll();
    if (count($popDocs) < 3) $popDocs = [];
}
/** แถวเอกสารแบบย่อในชั้นมาใหม่/ยอดนิยม */
$miniRow = function (array $d, string $meta, string $lead = ''): string {
    $isExt = !empty($d['ext_url']);
    $href = url('download.php?id=' . (int)$d['id']);
    $thumb = (!empty($d['cover']) || !empty($d['cat_cover']))
        ? '<span class="dmini-cv"><img src="' . e(url(($d['cover'] ?? '') ?: $d['cat_cover'])) . '" alt="" loading="lazy" decoding="async"></span>'
        : '<span class="dmini-ic"><span class="material-symbols-rounded" aria-hidden="true">' . e($d['cat_icon'] ?: 'description') . '</span></span>';
    return '<li class="dmini">' . $lead
         . '<a class="dmini-a" href="' . e($href) . '"' . ($isExt ? ' target="_blank" rel="noopener"' : '') . '>' . $thumb
         . '<span class="dmini-t"><span class="dmini-title">' . e($d['title']) . '</span><span class="dmini-m">' . $meta . '</span></span></a>'
         . share_btn($d['title'], $href, 'd=' . (int)$d['id'], 'sm') . '</li>';
};

$page_title = $cat_name !== '' ? $cat_name : section_title('documents');
if ($showBook && trim((string)($catRow['intro'] ?? '')) !== '') $meta_description = mb_substr(trim($catRow['intro']), 0, 160);
/* แชร์ลิงก์หมวดที่เป็นเล่มไปโซเชียล ใช้ภาพปกเป็นภาพตัวอย่าง */
if (($showBook || $listCover || $showShelf) && !empty($catRow['cover'])) $og_image = $catRow['cover'];
if ($showShelf && trim((string)($catRow['intro'] ?? '')) !== '') $meta_description = mb_substr(trim($catRow['intro']), 0, 160);
if ($listCover && trim((string)($catRow['intro'] ?? '')) !== '') $meta_description = mb_substr(trim($catRow['intro']), 0, 160);
require __DIR__ . '/includes/header.php';
$base_qs = 'documents.php?' . http_build_query(array_filter(['cat' => $cat ?: null, 'q' => $q, 'view' => $isBookCat && !$showBook && ($_GET['view'] ?? '') === 'list' ? 'list' : null]));
?>
<div class="container">
  <div class="page-head reveal">
    <div class="crumb"><a href="<?= e(url('index.php')) ?>">หน้าแรก</a> <span class="material-symbols-rounded icon-sm">chevron_right</span>
      <?php if ($cat_name !== ''): ?><a href="<?= e(url('documents.php')) ?>">เอกสารเผยแพร่</a> <span class="material-symbols-rounded icon-sm">chevron_right</span> <?= e($cat_name) ?><?php else: ?>เอกสารเผยแพร่<?php endif; ?></div>
    <?php if (!$showBook && !$listCover && !$showShelf): ?><h1><?= e($page_title) ?></h1><?php endif; ?>
    <?php if ($isShelf): ?><p class="text-muted dshelf-lead">คู่มือ ระเบียบ วารสาร และเอกสารเผยแพร่ของหน่วยงาน — <?= number_format($total) ?> รายการ ใน <?= count($cats) ?> หมวด</p><?php endif; ?>
    <?php if ($isBookCat && !$showBook && !$listCover && ($_GET['view'] ?? '') === 'list'): ?>
    <a class="btn small mt-1" href="<?= e(url('documents.php?cat=' . $cat)) ?>"><span class="material-symbols-rounded icon-sm">menu_book</span>กลับไปดูแบบสารบัญ</a>
    <?php endif; ?>
  </div>

  <?php if ($isShelf): ?>
  <section class="dshelf reveal" aria-labelledby="dshelf-h">
    <div class="dshelf-head">
      <h2 id="dshelf-h">เลือกหมวดเอกสาร</h2>
      <form class="searchbar" style="max-width:340px;margin:0;box-shadow:var(--shadow-soft);" action="<?= e(url('documents.php')) ?>" method="get" role="search">
        <span class="material-symbols-rounded" style="color:var(--muted)">search</span>
        <input type="search" name="q" placeholder="ค้นหาเอกสารทุกหมวด..." aria-label="ค้นหาเอกสาร">
      </form>
    </div>
    <div class="dshelf-grid" data-reveal-group>
      <?php foreach ($cats as $c): $isB = ($c['layout'] ?? '') === 'book'; ?>
      <a class="dshelf-card reveal" href="<?= e(url('documents.php?cat=' . $c['id'])) ?>">
        <?php if (!empty($c['cover'])): ?>
        <span class="dshelf-cv has-img"><img src="<?= e(url($c['cover'])) ?>" alt="" width="900" height="1200" loading="lazy" decoding="async"></span>
        <?php else: /* ยังไม่มีภาพปก → ปกสีตามสีหมวด พร้อมไอคอนและชื่อ */ ?>
        <span class="dshelf-cv s-<?= e($c['style'] ?: 'blue') ?>" aria-hidden="true">
          <span class="material-symbols-rounded"><?= e($c['icon'] ?: 'folder') ?></span><small><?= e($c['name']) ?></small>
        </span>
        <?php endif; ?>
        <span class="dshelf-t"><?= e($c['name']) ?></span>
        <span class="dshelf-m"><?= number_format((int)$c['n']) ?> รายการ<?php if ($isB): ?> <span class="dshelf-badge"><span class="material-symbols-rounded" aria-hidden="true">menu_book</span>สารบัญ</span><?php endif; ?></span>
      </a>
      <?php endforeach; ?>
    </div>
  </section>
  <?php if ($newDocs): ?>
  <div class="dhot<?= $popDocs ? '' : ' one' ?>">
    <section class="dhot-box reveal" aria-labelledby="dhot-new">
      <h2 id="dhot-new"><span class="material-symbols-rounded" aria-hidden="true">new_releases</span>มาใหม่</h2>
      <ol class="dmini-list">
        <?php foreach ($newDocs as $d): ?>
        <?= $miniRow($d, e(($d['cat_name'] ?: 'ทั่วไป') . ' · ' . thai_date($d['created_at'])) . (is_new($d['created_at']) ? ' <span class="badge gold" style="font-size:10.5px;padding:1px 7px;">ใหม่</span>' : '')) ?>
        <?php endforeach; ?>
      </ol>
    </section>
    <?php if ($popDocs): ?>
    <section class="dhot-box reveal" aria-labelledby="dhot-pop">
      <h2 id="dhot-pop"><span class="material-symbols-rounded" aria-hidden="true">trending_up</span>ยอดนิยม</h2>
      <ol class="dmini-list is-rank">
        <?php foreach ($popDocs as $i => $d): ?>
        <?= $miniRow($d, e(($d['cat_name'] ?: 'ทั่วไป')) . ' · เปิด ' . number_format((int)$d['downloads']) . ' ครั้ง', '<span class="dmini-rank" aria-hidden="true">' . ($i + 1) . '</span>') ?>
        <?php endforeach; ?>
      </ol>
    </section>
    <?php endif; ?>
  </div>
  <?php endif; ?>
  <div class="dshelf-sub reveal"><h2>เอกสารทั้งหมด</h2><span class="text-muted"><?= number_format($total) ?> รายการ</span></div>
  <?php else: ?>
  <div class="flex items-center gap-2 mb-3 reveal" style="flex-wrap:wrap;">
    <nav class="cats dcats" aria-label="หมวดเอกสาร">
      <a class="cat<?= $cat === 0 ? ' active' : '' ?>" href="<?= e(url('documents.php')) ?>"><span class="material-symbols-rounded icon-sm" aria-hidden="true">grid_view</span>ทั้งหมด</a>
      <?php foreach ($cats as $c): ?>
      <a class="cat<?= $cat === (int)$c['id'] ? ' active' : '' ?>" href="<?= e(url('documents.php?cat=' . $c['id'])) ?>"<?= $cat === (int)$c['id'] ? ' aria-current="page"' : '' ?>>
        <?php if (!empty($c['cover'])): ?><span class="cat-cv" aria-hidden="true"><img src="<?= e(url($c['cover'])) ?>" alt="" loading="lazy" decoding="async"></span>
        <?php else: ?><span class="material-symbols-rounded icon-sm" aria-hidden="true"><?= e($c['icon'] ?: 'folder') ?></span><?php endif; ?>
        <?= e($c['name']) ?> <span class="cat-n"><?= (int)$c['n'] ?></span>
      </a>
      <?php endforeach; ?>
    </nav>
    <?php if (!$showBook && !$showShelf): ?>
    <form class="searchbar" style="max-width:320px;margin:0;margin-left:auto;box-shadow:var(--shadow-soft);" action="<?= e(url('documents.php')) ?>" method="get" role="search">
      <?php if ($cat): ?><input type="hidden" name="cat" value="<?= $cat ?>"><?php endif; ?>
      <span class="material-symbols-rounded" style="color:var(--muted)">search</span>
      <input type="search" name="q" value="<?= e($q) ?>" placeholder="ค้นหาเอกสาร..." aria-label="ค้นหาเอกสาร">
    </form>
    <?php endif; ?>
  </div>
  <?php endif; /* $isShelf */ ?>

  <?php if ($showBook):
    $bs = docbook_stats($bookTree);
    $bookUpdated = max(array_map(fn($r) => (string)$r['updated_at'], $bookRows));
    $useStatus = !empty($bookConf['status']);
    $hasRep = $useStatus && $bs['rep_any'] > 0;
  ?>
  <section class="bk-cover reveal<?= !empty($catRow['cover']) ? ' has-img' : '' ?>" aria-labelledby="bk-title">
    <?php if (!empty($catRow['cover'])): /* ภาพปกที่ผู้ดูแลอัปโหลด (3:4) — กดดูขนาดเต็มได้ */ ?>
    <a class="bk-art has-img" href="<?= e(url($catRow['cover'])) ?>" data-lightbox="<?= e(url($catRow['cover'])) ?>" aria-label="ดูภาพปก <?= e($cat_name) ?> ขนาดเต็ม">
      <img src="<?= e(url($catRow['cover'])) ?>" alt="ปก<?= e($cat_name) ?>" width="900" height="1200" fetchpriority="high" decoding="async">
    </a>
    <?php else: ?>
    <div class="bk-art" aria-hidden="true">
      <span class="bk-art-ic material-symbols-rounded"><?= e($bookConf['cat_icon']) ?></span>
      <span class="bk-art-n"><b><?= $bs['part'] ?></b><?= e($bookConf['l1']) ?></span>
    </div>
    <?php endif; ?>
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
  <?php elseif ($showShelf):
    $shGroups = docshelf_groups($shelfDocs, $shelfConf);
    /* ฉบับล่าสุด = วันที่ใหม่สุดเสมอ (ไม่ขึ้นกับการเรียงที่ผู้ดูแลเลือก) */
    $latest = null;
    foreach ($shelfDocs as $d) { if (!$latest || docshelf_date($d) . sprintf('%010d', $d['id']) > docshelf_date($latest) . sprintf('%010d', $latest['id'])) $latest = $d; }
    $showFeat = $shelfConf['featured'] && $latest && count($shelfDocs) > 1;
  ?>
  <section class="dsh-head reveal" aria-labelledby="dsh-title">
    <?php if (!empty($catRow['cover'])): ?>
    <a class="dsh-head-cv" href="<?= e(url($catRow['cover'])) ?>" data-lightbox="<?= e(url($catRow['cover'])) ?>" aria-label="ดูภาพปก <?= e($cat_name) ?> ขนาดเต็ม"><img src="<?= e(url($catRow['cover'])) ?>" alt="" width="900" height="1200"></a>
    <?php endif; ?>
    <div class="dsh-head-t">
      <span class="bk-kicker"><span class="material-symbols-rounded icon-sm" aria-hidden="true"><?= e($catRow['icon'] ?: 'auto_stories') ?></span>เอกสารเผยแพร่</span>
      <h1 id="dsh-title"><?= e($cat_name) ?></h1>
      <?php if (trim((string)($catRow['intro'] ?? '')) !== ''): ?><p class="bk-intro"><?= e($catRow['intro']) ?></p><?php endif; ?>
      <p class="dsh-head-m"><b><?= number_format(count($shelfDocs)) ?></b> ฉบับ<?php if (count($shGroups) > 1): ?> · <?= count($shGroups) ?> กลุ่ม<?php endif; ?><?php if ($latest): ?> · ล่าสุด <?= e(thai_date(docshelf_date($latest))) ?><?php endif; ?></p>
    </div>
  </section>

  <?php if ($showFeat): $fHref = docshelf_href($latest, $shelfConf); ?>
  <section class="dsh-feat reveal" aria-labelledby="dsh-feat-t">
    <a class="dsh-feat-cv" href="<?= e($fHref) ?>"<?= $shelfConf['open'] === 'file' && !empty($latest['ext_url']) ? ' target="_blank" rel="noopener"' : '' ?> tabindex="-1" aria-hidden="true"><?= docshelf_cover($latest, $catRow, 'lg') ?></a>
    <div class="dsh-feat-t">
      <span class="dsh-kick"><span class="material-symbols-rounded icon-sm" aria-hidden="true">auto_awesome</span>ฉบับล่าสุด</span>
      <h2 id="dsh-feat-t"><a href="<?= e($fHref) ?>"<?= $shelfConf['open'] === 'file' && !empty($latest['ext_url']) ? ' target="_blank" rel="noopener"' : '' ?>><?= e($latest['title']) ?></a></h2>
      <p class="dsh-feat-m"><?= e(implode(' · ', array_filter([docshelf_group($latest, $shelfConf), thai_date(docshelf_date($latest), false), docshelf_filemeta($latest)]))) ?></p>
      <?php if (trim((string)$latest['summary']) !== ''): ?><p class="dsh-feat-s"><?= e($latest['summary']) ?></p><?php endif; ?>
      <div class="dsh-feat-a">
        <a class="btn primary" href="<?= e(url('download.php?id=' . (int)$latest['id'])) ?>"<?= !empty($latest['ext_url']) ? ' target="_blank" rel="noopener"' : '' ?>><span class="material-symbols-rounded icon-sm" aria-hidden="true"><?= !empty($latest['ext_url']) ? 'menu_book' : 'download' ?></span><?= !empty($latest['ext_url']) ? 'อ่านฉบับนี้' : 'ดาวน์โหลด' ?></a>
        <?php if ($shelfConf['open'] === 'page'): ?><a class="btn" href="<?= e(url('doc.php?id=' . (int)$latest['id'])) ?>">รายละเอียด</a><?php endif; ?>
        <?= share_btn($latest['title'], url('download.php?id=' . (int)$latest['id']), 'd=' . (int)$latest['id']) ?>
      </div>
    </div>
  </section>
  <?php endif; ?>

  <div class="dsh-tools reveal" data-dsh-tools hidden>
    <label class="bk-search"><span class="material-symbols-rounded" aria-hidden="true">search</span>
      <input type="search" data-dsh-q placeholder="ค้นหาฉบับ เช่น ปีที่ 62, 487, ม.ค." aria-label="ค้นหาฉบับในหมวดนี้" autocomplete="off"></label>
    <?php if (count($shGroups) > 1): ?>
    <nav class="dsh-jump" aria-label="ไปที่กลุ่ม">
      <?php foreach ($shGroups as $i => [$g]): ?><a href="#g<?= $i ?>" data-dsh-jump><?= e($g !== '' ? $g : 'ทั้งหมด') ?></a><?php endforeach; ?>
    </nav>
    <?php endif; ?>
    <div class="bk-seg" role="radiogroup" aria-label="เรียงลำดับ">
      <button type="button" role="radio" aria-checked="<?= $shelfConf['sort'] === 'new' ? 'true' : 'false' ?>" data-dsh-sort="new">ใหม่ → เก่า</button>
      <button type="button" role="radio" aria-checked="<?= $shelfConf['sort'] === 'old' ? 'true' : 'false' ?>" data-dsh-sort="old">เก่า → ใหม่</button>
    </div>
    <p class="bk-count" data-dsh-count aria-live="polite"></p>
  </div>

  <div class="dsh size-<?= e($shelfConf['size']) ?>" data-dsh data-sort="<?= e($shelfConf['sort']) ?>">
    <?php foreach ($shGroups as $i => [$g, $list]): ?>
    <section class="dsh-group" id="g<?= $i ?>" data-dsh-group>
      <?php if ($g !== '' || count($shGroups) > 1): ?>
      <div class="dsh-gh"><h2><?= e($g !== '' ? $g : 'อื่นๆ') ?></h2><span><?= count($list) ?> ฉบับ</span></div>
      <?php endif; ?>
      <div class="dsh-grid">
        <?php foreach ($list as $d): $h = docshelf_href($d, $shelfConf); ?>
        <a class="dsh-card" href="<?= e($h) ?>"<?= $shelfConf['open'] === 'file' && !empty($d['ext_url']) ? ' target="_blank" rel="noopener"' : '' ?>
           data-s="<?= e(mb_strtolower(docshelf_digits($d['title'] . ' ' . $g . ' ' . $d['summary']))) ?>">
          <?= docshelf_cover($d, $catRow) ?>
          <span class="dsh-t"><?= e($d['title']) ?></span>
          <span class="dsh-m"><?= e(thai_date(docshelf_date($d))) ?><?php if (is_new($d['created_at'])): ?> <span class="badge gold" style="font-size:10.5px;padding:1px 7px;">ใหม่</span><?php endif; ?></span>
        </a>
        <?php endforeach; ?>
      </div>
    </section>
    <?php endforeach; ?>
    <div class="bk-none" data-dsh-none hidden><span class="material-symbols-rounded" aria-hidden="true">search_off</span><p>ไม่พบฉบับที่ค้นหา</p></div>
  </div>
  <p class="text-center mb-4"><a class="btn small ghost" href="<?= e(url('documents.php?cat=' . $cat . '&view=list')) ?>"><span class="material-symbols-rounded icon-sm" aria-hidden="true">format_list_bulleted</span>ดูแบบรายการไฟล์</a></p>
  <?php else: ?>

  <?php if ($listCover):
    $st = db()->prepare('SELECT MAX(created_at) FROM documents WHERE category_id = ?');
    $st->execute([$cat]);
    $lastDoc = (string)$st->fetchColumn();
  ?>
  <section class="bk-cover reveal has-img mb-3" aria-labelledby="cat-title">
    <a class="bk-art has-img" href="<?= e(url($catRow['cover'])) ?>" data-lightbox="<?= e(url($catRow['cover'])) ?>" aria-label="ดูภาพปก <?= e($cat_name) ?> ขนาดเต็ม">
      <img src="<?= e(url($catRow['cover'])) ?>" alt="ปก<?= e($cat_name) ?>" width="900" height="1200" fetchpriority="high" decoding="async">
    </a>
    <div class="bk-cover-txt">
      <span class="bk-kicker"><span class="material-symbols-rounded icon-sm" aria-hidden="true"><?= e($catRow['icon'] ?: 'folder') ?></span>เอกสารเผยแพร่</span>
      <h1 id="cat-title"><?= e($cat_name) ?></h1>
      <?php if (trim((string)($catRow['intro'] ?? '')) !== ''): ?><p class="bk-intro"><?= e($catRow['intro']) ?></p><?php endif; ?>
      <dl class="bk-stats">
        <div><dt>เอกสาร</dt><dd><?= number_format((int)$catRow['n']) ?></dd></div>
      </dl>
      <div class="bk-cover-foot">
        <?php if ($lastDoc !== ''): ?><span class="text-muted"><span class="material-symbols-rounded icon-sm" aria-hidden="true">update</span> เพิ่มเอกสารล่าสุด <?= e(thai_date($lastDoc)) ?></span><?php endif; ?>
        <?php if ($isBookCat && ($_GET['view'] ?? '') === 'list'): ?>
        <a class="btn small" href="<?= e(url('documents.php?cat=' . $cat)) ?>"><span class="material-symbols-rounded icon-sm">menu_book</span>กลับไปดูแบบสารบัญ</a>
        <?php endif; ?>
      </div>
    </div>
  </section>
  <?php endif; ?>

  <?php if ($q !== ''): ?>
  <p class="text-muted mb-2">ผลการค้นหา "<?= e($q) ?>" — พบ <?= number_format($total) ?> รายการ</p>
  <?php endif; ?>

  <?php if ($docs): ?>
  <div class="card reveal">
    <div class="grid grid-2" style="gap:8px 24px;">
      <?php foreach ($docs as $d): $isExt = !empty($d['ext_url']); ?>
      <?php $dHref = url('download.php?id=' . $d['id']); $dTarget = $isExt ? ' target="_blank" rel="noopener"' : ''; ?>
      <div class="list-row doc-row">
      <a class="doc-row-a" href="<?= e($dHref) ?>"<?= $dTarget ?>>
        <?php if (!empty($d['cover']) || !empty($d['cat_cover'])): ?><span class="dmini-cv"><img src="<?= e(url(($d['cover'] ?? '') ?: $d['cat_cover'])) ?>" alt="" loading="lazy" decoding="async"></span>
        <?php else: ?><div class="lr-icon" style="background:rgba(234,67,53,.08);color:var(--danger);"><span class="material-symbols-rounded"><?= e($d['cat_icon'] ?: ($isExt ? 'cloud' : 'picture_as_pdf')) ?></span></div><?php endif; ?>
        <div style="flex:1;min-width:0;">
          <div class="lr-title"><?= e($d['title']) ?>
            <?php if (is_new($d['created_at'])): ?><span class="badge gold" style="font-size:11px;padding:2px 8px;">ใหม่</span><?php endif; ?>
          </div>
          <div class="lr-date"><?= e($d['cat_name'] ?: 'ทั่วไป') ?> · <?php if ($isExt): ?><span class="material-symbols-rounded icon-sm" style="vertical-align:-3px;">link</span> ลิงก์ภายนอก<?php else: ?><?= e(strtoupper((string)$d['ext'])) ?> · <?= e(format_bytes((int)$d['file_size'])) ?><?php endif; ?> · <?= e(thai_date($d['created_at'])) ?><?php if ((int)$d['downloads'] > 0): ?> · เปิด <?= number_format((int)$d['downloads']) ?> ครั้ง<?php endif; ?></div>
        </div>
      </a>
      <span class="doc-row-acts">
        <?= share_btn($d['title'], $dHref, 'd=' . (int)$d['id']) ?>
        <a class="btn small" href="<?= e($dHref) ?>"<?= $dTarget ?> title="<?= $isExt ? 'เปิดเอกสาร' : 'ดาวน์โหลด' ?>" aria-label="<?= e(($isExt ? 'เปิด ' : 'ดาวน์โหลด ') . $d['title']) ?>" tabindex="-1"><span class="material-symbols-rounded icon-sm" aria-hidden="true"><?= $isExt ? 'open_in_new' : 'download' ?></span></a>
      </span>
      </div>
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
