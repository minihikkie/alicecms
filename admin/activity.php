<?php
/** admin/activity.php — บันทึกการใช้งานของผู้ดูแล (เฉพาะ admin) */
require dirname(__DIR__) . '/includes/init.php';
require __DIR__ . '/_auth.php';
require_admin();

/* ลบ log เก่าเกิน 90 วัน (ทำเงียบๆ) */
try { db()->exec("DELETE FROM activity_logs WHERE created_at < (NOW() - INTERVAL 90 DAY)"); } catch (Throwable $e) {}

$page = max(1, (int)($_GET['page'] ?? 1));
$per  = 40;
$total = (int)db()->query('SELECT COUNT(*) FROM activity_logs')->fetchColumn();
$logs = db()->query('SELECT * FROM activity_logs ORDER BY id DESC LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per))->fetchAll();

$admin_title = 'บันทึกการใช้งาน';
require __DIR__ . '/_top.php';
?>
<div class="card">
  <div class="section-head" style="margin-bottom:8px;">
    <div><span class="tag"><span class="material-symbols-rounded icon-sm">history</span> ACTIVITY LOG</span><h3>บันทึกการใช้งานระบบ (<?= number_format($total) ?>)</h3>
    <p>บันทึกการเพิ่ม/แก้ไข/ลบข้อมูลของเจ้าหน้าที่ — เก็บย้อนหลัง 90 วัน เพื่อความโปร่งใสและตรวจสอบ</p></div>
  </div>
  <?php if ($logs): ?>
  <table class="admin-table">
    <tr><th>เวลา</th><th>ผู้ใช้</th><th>การกระทำ</th><th>รายละเอียด</th><th>IP</th></tr>
    <?php foreach ($logs as $l): ?>
    <tr>
      <td class="lr-date" style="white-space:nowrap;"><?= e(thai_date($l['created_at'])) ?> <?= e(date('H:i', strtotime($l['created_at']))) ?></td>
      <td><?= e($l['username']) ?></td>
      <td><span class="badge" style="font-size:11px;"><?= e($l['action']) ?></span></td>
      <td><?= e($l['detail']) ?></td>
      <td class="lr-date"><?= e($l['ip']) ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?= pagination_html($total, $per, $page, url('admin/activity.php')) ?>
  <?php else: ?>
  <p class="text-muted text-center" style="padding:32px;">ยังไม่มีบันทึกการใช้งาน</p>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/_bottom.php'; ?>
