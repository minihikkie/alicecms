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
foreach ($cats as $c) if ((int)$c['id'] === $cat) $cat_name = $c['name'];

$page_title = $cat_name !== '' ? $cat_name : section_title('documents');
require __DIR__ . '/includes/header.php';
$base_qs = 'documents.php?' . http_build_query(array_filter(['cat' => $cat ?: null, 'q' => $q]));
?>
<div class="container">
  <div class="page-head reveal">
    <div class="crumb"><a href="<?= e(url('index.php')) ?>">หน้าแรก</a> <span class="material-symbols-rounded icon-sm">chevron_right</span> เอกสารเผยแพร่</div>
    <h1><?= e($page_title) ?></h1>
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
    <form class="searchbar" style="max-width:320px;margin:0;margin-left:auto;box-shadow:var(--shadow-soft);" action="<?= e(url('documents.php')) ?>" method="get" role="search">
      <?php if ($cat): ?><input type="hidden" name="cat" value="<?= $cat ?>"><?php endif; ?>
      <span class="material-symbols-rounded" style="color:var(--muted)">search</span>
      <input type="search" name="q" value="<?= e($q) ?>" placeholder="ค้นหาเอกสาร..." aria-label="ค้นหาเอกสาร">
    </form>
  </div>

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
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
