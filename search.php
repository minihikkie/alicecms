<?php
/** search.php — ค้นหารวมทั้งเว็บ (ข่าว + เอกสาร + จัดซื้อจัดจ้าง) */
define('PUBLIC_PAGE', 'search');
require __DIR__ . '/includes/init.php';

$q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 120);
$posts = $docs = $procs = [];

if ($q !== '') {
    $like = "%$q%";
    $st = db()->prepare("SELECT id, type, title, image, published_at FROM posts
                         WHERE status = 'published' AND (title LIKE ? OR body LIKE ?)
                         ORDER BY published_at DESC LIMIT 15");
    $st->execute([$like, $like]);
    $posts = $st->fetchAll();

    $st = db()->prepare('SELECT d.*, c.name AS cat_name FROM documents d
                         LEFT JOIN doc_categories c ON c.id = d.category_id
                         WHERE d.title LIKE ? ORDER BY d.created_at DESC LIMIT 15');
    $st->execute([$like]);
    $docs = $st->fetchAll();

    $st = db()->prepare('SELECT * FROM procurements WHERE title LIKE ? ORDER BY pdate DESC LIMIT 10');
    $st->execute([$like]);
    $procs = $st->fetchAll();
}
$total = count($posts) + count($docs) + count($procs);

$page_title = 'ค้นหา' . ($q !== '' ? ': ' . $q : '');
require __DIR__ . '/includes/header.php';
?>
<div class="container">
  <div class="page-head reveal text-center">
    <h1>ค้นหา<span class="grad">ทั้งเว็บไซต์</span></h1>
    <form class="searchbar mt-2" style="max-width:560px;margin-left:auto;margin-right:auto;" action="<?= e(url('search.php')) ?>" method="get" role="search">
      <span class="material-symbols-rounded" style="color:var(--muted)">search</span>
      <input type="search" name="q" value="<?= e($q) ?>" placeholder="ค้นหาข่าว ประกาศ เอกสาร..." aria-label="ค้นหา">
      <button class="btn primary" type="submit">ค้นหา</button>
    </form>
    <?php if ($q !== ''): ?><p class="text-muted mt-2">ผลการค้นหา "<?= e($q) ?>" — พบ <?= number_format($total) ?> รายการ</p><?php endif; ?>
  </div>

  <?php if ($q !== '' && $total === 0): ?>
  <div class="card text-center text-muted reveal mb-4" style="padding:48px;">
    <span class="material-symbols-rounded icon-xl" style="color:var(--border);">search_off</span>
    <p>ไม่พบข้อมูลที่ค้นหา ลองใช้คำค้นอื่น</p>
  </div>
  <?php endif; ?>

  <?php if ($posts): ?>
  <div class="card mb-3 reveal">
    <div class="section-head" style="margin-bottom:8px;"><div><span class="tag"><span class="material-symbols-rounded icon-sm">newspaper</span> NEWS</span><h3>ข่าวสาร (<?= count($posts) ?>)</h3></div></div>
    <div class="grid grid-2" style="gap:4px 24px;">
      <?php foreach ($posts as $p): ?>
      <a class="list-row" href="<?= e(url('post.php?id=' . $p['id'])) ?>">
        <div class="pr-thumb"><img class="lazyimg" loading="lazy" src="<?= e(post_image_url($p['image'])) ?>" alt=""></div>
        <div><div class="lr-title"><?= e($p['title']) ?></div><div class="lr-date"><?= e(post_type_label($p['type'])) ?> · <?= e(thai_date($p['published_at'])) ?></div></div>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($docs): ?>
  <div class="card mb-3 reveal">
    <div class="section-head" style="margin-bottom:8px;"><div><span class="tag"><span class="material-symbols-rounded icon-sm">folder_open</span> DOCUMENTS</span><h3>เอกสาร (<?= count($docs) ?>)</h3></div></div>
    <div class="grid grid-2" style="gap:4px 24px;">
      <?php foreach ($docs as $d): ?>
      <a class="list-row" href="<?= e(url('download.php?id=' . $d['id'])) ?>">
        <div class="lr-icon" style="background:rgba(234,67,53,.08);color:var(--danger);"><span class="material-symbols-rounded">picture_as_pdf</span></div>
        <div><div class="lr-title"><?= e($d['title']) ?></div><div class="lr-date"><?= e($d['cat_name'] ?: 'ทั่วไป') ?> · <?= e(format_bytes((int)$d['file_size'])) ?></div></div>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($procs): ?>
  <div class="card mb-4 reveal">
    <div class="section-head" style="margin-bottom:8px;"><div><span class="tag"><span class="material-symbols-rounded icon-sm">shopping_cart</span> PROCUREMENT</span><h3>จัดซื้อจัดจ้าง (<?= count($procs) ?>)</h3></div></div>
    <table class="proc-table">
      <?php foreach ($procs as $p): $pt = proc_types()[$p['ptype']] ?? proc_types()['other']; ?>
      <tr>
        <td><?php if ($p['file']): ?><a href="<?= e(url($p['file'])) ?>" target="_blank" rel="noopener" style="color:var(--text);"><?= e($p['title']) ?></a><?php else: ?><?= e($p['title']) ?><?php endif; ?></td>
        <td><span class="badge <?= e($pt['badge']) ?>"><?= e($pt['label']) ?></span></td>
        <td class="lr-date"><?= e(thai_date($p['pdate'])) ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
  </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
