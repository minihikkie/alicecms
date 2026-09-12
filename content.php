<?php
/** content.php — หน้าเว็บของประเภทเนื้อหากำหนดเอง (รายการ + รายละเอียด) */
define('PUBLIC_PAGE', 'content');
require __DIR__ . '/includes/init.php';
require __DIR__ . '/includes/content.php';

$type_slug = preg_replace('/[^\p{L}\p{N}\-_]/u', '', (string)($_GET['type'] ?? ''));
$type = $type_slug !== '' ? content_type_by_slug($type_slug, true) : null;

if (!$type) {
    http_response_code(404);
    $page_title = 'ไม่พบเนื้อหา';
    require __DIR__ . '/includes/header.php';
    echo '<div class="container"><div class="page-head reveal text-center"><h1>ไม่พบเนื้อหาที่ต้องการ</h1>'
       . '<a class="btn primary mt-2" href="' . e(url('index.php')) . '"><span class="material-symbols-rounded icon-sm">home</span>กลับหน้าแรก</a></div></div>';
    require __DIR__ . '/includes/footer.php';
    exit;
}

$fields = content_type_fields($type);
$item_id = (int)($_GET['id'] ?? 0);

/* ── รายละเอียดรายการเดียว ── */
if ($item_id) {
    $st = db()->prepare("SELECT * FROM content_items WHERE id = ? AND type_id = ? AND status = 'published'");
    $st->execute([$item_id, (int)$type['id']]);
    $item = $st->fetch();
    if (!$item) { http_response_code(404); $item = null; }
    $data = $item ? (json_decode((string)$item['data'], true) ?: []) : [];
    $page_title = $item ? $item['title'] : 'ไม่พบรายการ';
    require __DIR__ . '/includes/header.php';
    ?>
    <div class="container">
      <div class="page-head reveal">
        <div class="crumb"><a href="<?= e(url('index.php')) ?>">หน้าแรก</a> <span class="material-symbols-rounded icon-sm">chevron_right</span>
          <a href="<?= e(url('content.php?type=' . $type['slug'])) ?>"><?= e($type['name_plural'] ?: $type['name']) ?></a></div>
        <?php if ($item): ?><h1><?= e($item['title']) ?></h1><?php endif; ?>
      </div>
      <?php if ($item): ?>
      <div class="card card-spacious reveal">
        <?php if (!empty($item['image'])): ?>
        <div class="ci-cover"><img src="<?= e(url($item['image'])) ?>" alt="<?= e($item['title']) ?>"></div>
        <?php endif; ?>
        <dl class="ci-fields">
          <?php foreach ($fields as $f): $lb = (string)($f['label'] ?? ''); if ($lb === '') continue;
            $val = (string)($data[$lb] ?? ''); if (trim($val) === '') continue; ?>
          <dt><?= e($lb) ?></dt>
          <dd><?= render_field_value($f, $val) ?></dd>
          <?php endforeach; ?>
        </dl>
      </div>
      <?php else: ?>
      <div class="card text-center text-muted" style="padding:48px;">ไม่พบรายการที่ต้องการ</div>
      <?php endif; ?>
    </div>
    <?php
    require __DIR__ . '/includes/footer.php';
    exit;
}

/* ── รายการทั้งหมดของประเภทนี้ ── */
$page  = max(1, (int)($_GET['page'] ?? 1));
$per   = 12;
$cst = db()->prepare("SELECT COUNT(*) FROM content_items WHERE type_id = ? AND status='published'");
$cst->execute([(int)$type['id']]);
$total = (int)$cst->fetchColumn();
$st = db()->prepare("SELECT * FROM content_items WHERE type_id = ? AND status='published' ORDER BY sort_order ASC, id DESC LIMIT $per OFFSET " . (($page - 1) * $per));
$st->execute([(int)$type['id']]);
$items = $st->fetchAll();

$page_title = $type['name_plural'] ?: $type['name'];
require __DIR__ . '/includes/header.php';
?>
<div class="container">
  <div class="page-head reveal text-center">
    <div class="crumb" style="justify-content:center;"><a href="<?= e(url('index.php')) ?>">หน้าแรก</a> <span class="material-symbols-rounded icon-sm">chevron_right</span> <?= e($type['name_plural'] ?: $type['name']) ?></div>
    <h1><span class="grad"><?= e($type['name_plural'] ?: $type['name']) ?></span></h1>
  </div>
  <?php if ($items): ?>
  <div class="grid grid-3" data-reveal-group>
    <?php foreach ($items as $it): $data = json_decode((string)$it['data'], true) ?: []; $ex = content_item_excerpt($type, $data); ?>
    <a class="card news-card reveal" href="<?= e(url('content.php?type=' . $type['slug'] . '&id=' . $it['id'])) ?>">
      <?php if (!empty($type['has_image'])): ?>
      <div class="news-thumb"><img class="lazyimg" loading="lazy" src="<?= e($it['image'] ? url($it['image']) : url('assets/img/default-news.svg')) ?>" alt="<?= e($it['title']) ?>"></div>
      <?php endif; ?>
      <div class="nc-body">
        <div class="nc-title"><?= e($it['title']) ?></div>
        <?php if ($ex): ?><div class="lr-date" style="white-space:normal;"><?= e($ex) ?></div><?php endif; ?>
      </div>
    </a>
    <?php endforeach; ?>
  </div>
  <?= pagination_html($total, $per, $page, url('content.php?type=' . $type['slug'])) ?>
  <?php else: ?>
  <div class="card text-center text-muted reveal" style="padding:48px;">
    <span class="material-symbols-rounded icon-xl" style="color:var(--border);"><?= e($type['icon'] ?: 'category') ?></span>
    <p style="margin-top:10px;">ยังไม่มีรายการในหมวดนี้</p>
  </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
