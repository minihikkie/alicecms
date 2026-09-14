<?php
/** procurement.php — ประกาศจัดซื้อจัดจ้าง (กรองตามประเภท + แบ่งหน้า) */
define('PUBLIC_PAGE', 'procurement');
require __DIR__ . '/includes/init.php';

/* section นี้ถูกปิดอยู่ → ตอบ 404 ไม่ให้เข้าถึงหน้าโดยตรง */
require_section_live("procurement");

$ptypes = proc_types();
$ptype  = isset($_GET['ptype']) && isset($ptypes[$_GET['ptype']]) ? $_GET['ptype'] : '';
$page   = max(1, (int)($_GET['page'] ?? 1));
$per    = 20;

$where  = ['1=1'];
$params = [];
if ($ptype !== '') { $where[] = 'ptype = ?'; $params[] = $ptype; }
$wsql = implode(' AND ', $where);

$st = db()->prepare("SELECT COUNT(*) FROM procurements WHERE $wsql");
$st->execute($params);
$total = (int)$st->fetchColumn();

$st = db()->prepare("SELECT * FROM procurements WHERE $wsql ORDER BY pdate DESC, id DESC
                     LIMIT $per OFFSET " . (($page - 1) * $per));
$st->execute($params);
$procs = $st->fetchAll();

$page_title = section_title('procurement');
require __DIR__ . '/includes/header.php';
$base_qs = 'procurement.php?' . http_build_query(array_filter(['ptype' => $ptype]));
?>
<div class="container">
  <div class="page-head reveal">
    <div class="crumb"><a href="<?= e(url('index.php')) ?>">หน้าแรก</a> <span class="material-symbols-rounded icon-sm">chevron_right</span> จัดซื้อจัดจ้าง</div>
    <h1><?= e($page_title) ?></h1>
  </div>

  <div class="cats mb-3 reveal">
    <a class="cat<?= $ptype === '' ? ' active' : '' ?>" href="<?= e(url('procurement.php')) ?>">ทั้งหมด</a>
    <?php foreach ($ptypes as $tk => $tv): ?>
    <a class="cat<?= $ptype === $tk ? ' active' : '' ?>" href="<?= e(url('procurement.php?ptype=' . $tk)) ?>"><?= e($tv['label']) ?></a>
    <?php endforeach; ?>
  </div>

  <?php if ($procs): ?>
  <div class="card reveal">
    <div class="table-scroll">
    <table class="proc-table">
      <tr><th>เรื่อง</th><th>ประเภท</th><th>วันที่</th><th>ไฟล์แนบ</th></tr>
      <?php foreach ($procs as $p): $pt = $ptypes[$p['ptype']] ?? $ptypes['other']; ?>
      <tr>
        <td><?= e($p['title']) ?></td>
        <td><span class="badge <?= e($pt['badge']) ?>"><?= e($pt['label']) ?></span></td>
        <td class="lr-date" style="white-space:nowrap;"><?= e(thai_date($p['pdate'])) ?></td>
        <td>
          <?php if (!empty($p['ext_url'])): ?>
          <a class="btn small" href="<?= e($p['ext_url']) ?>" target="_blank" rel="noopener"><span class="material-symbols-rounded icon-sm">open_in_new</span>เปิดลิงก์</a>
          <?php elseif ($p['file']): ?>
          <a class="btn small" href="<?= e(url($p['file'])) ?>" target="_blank" rel="noopener"><span class="material-symbols-rounded icon-sm">download</span><?= e(format_bytes((int)$p['file_size'])) ?></a>
          <?php else: ?><span class="text-muted" style="font-size:12px;">—</span><?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>
    </div>
  </div>
  <?= pagination_html($total, $per, $page, url($base_qs)) ?>
  <?php else: ?>
  <div class="card text-center text-muted reveal" style="padding:48px;">
    <span class="material-symbols-rounded icon-xl" style="color:var(--border);">shopping_cart</span>
    <p>ยังไม่มีประกาศจัดซื้อจัดจ้าง</p>
  </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
