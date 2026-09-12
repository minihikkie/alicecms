<?php
/** section: จัดซื้อจัดจ้าง — ตาราง 3 รายการล่าสุด (เรนเดอร์เฉพาะการ์ด) */
if (!defined('APP_ROOT')) exit('Forbidden');

$procs = db()->query('SELECT * FROM procurements ORDER BY pdate DESC, id DESC LIMIT 3')->fetchAll();
?>
<div class="card reveal" id="proc">
  <div class="section-head" style="margin-bottom:8px;">
    <div><span class="tag"><span class="material-symbols-rounded icon-sm">shopping_cart</span> PROCUREMENT</span><h3><?= e(section_title('procurement')) ?></h3></div>
    <a class="btn small" href="<?= e(url('procurement.php')) ?>">ทั้งหมด</a>
  </div>
  <?php if ($procs): ?>
  <table class="proc-table">
    <tr><th>เรื่อง</th><th>ประเภท</th><th>วันที่</th></tr>
    <?php foreach ($procs as $p): $pt = proc_types()[$p['ptype']] ?? proc_types()['other']; ?>
    <tr>
      <td><?php if ($p['file']): ?><a href="<?= e(url($p['file'])) ?>" target="_blank" rel="noopener" style="color:var(--text);"><?= e($p['title']) ?></a><?php else: ?><?= e($p['title']) ?><?php endif; ?></td>
      <td><span class="badge <?= e($pt['badge']) ?>"><?= e($pt['label']) ?></span></td>
      <td class="lr-date"><?= e(thai_date($p['pdate'])) ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php else: ?>
  <div class="text-center text-muted" style="padding:24px;">ยังไม่มีประกาศจัดซื้อจัดจ้าง</div>
  <?php endif; ?>
</div>
