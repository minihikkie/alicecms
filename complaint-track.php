<?php
/**
 * complaint-track.php — ติดตามสถานะเรื่องร้องเรียนด้วยเลขรับเรื่อง
 * ค้นจาก ref_code (สุ่มกันเดา) แสดงเฉพาะสถานะ + คำตอบจากเจ้าหน้าที่ ไม่เปิดข้อมูลส่วนตัวซ้ำ
 */
define('PUBLIC_PAGE', 'complaint');
require __DIR__ . '/includes/init.php';

/* section นี้ถูกปิดอยู่ → ตอบ 404 ไม่ให้เข้าถึงหน้าโดยตรง */
require_section_live("complaint");

$statuses = [
    'new'      => ['label' => 'รับเรื่องแล้ว — รอตรวจสอบ', 'badge' => 'warning', 'icon' => 'fiber_new'],
    'progress' => ['label' => 'กำลังดำเนินการ',            'badge' => '',        'icon' => 'pending'],
    'done'     => ['label' => 'ดำเนินการเสร็จสิ้น',         'badge' => 'success', 'icon' => 'check_circle'],
];

$ref = mb_substr(trim((string)($_GET['ref'] ?? $_POST['ref'] ?? '')), 0, 30);
$row = null;
$searched = ($ref !== '');
if ($searched) {
    $st = db()->prepare('SELECT subject, status, response, responded_at, created_at FROM complaints WHERE ref_code = ?');
    $st->execute([$ref]);
    $row = $st->fetch();
}

$page_title = 'ติดตามสถานะเรื่องร้องเรียน';
require __DIR__ . '/includes/header.php';
?>
<div class="container">
  <div class="page-head reveal text-center">
    <h1>ติดตาม<span class="grad">สถานะเรื่องร้องเรียน</span></h1>
    <p class="text-muted">กรอกเลขรับเรื่องที่ได้รับตอนส่งเรื่อง เพื่อตรวจสอบความคืบหน้า</p>
  </div>

  <form class="searchbar mb-3 reveal" style="max-width:480px;margin-left:auto;margin-right:auto;" method="get" action="<?= e(url('complaint-track.php')) ?>" role="search">
    <span class="material-symbols-rounded" style="color:var(--muted)">confirmation_number</span>
    <input type="text" name="ref" value="<?= e($ref) ?>" placeholder="เช่น R2506-A1B2C3" aria-label="เลขรับเรื่อง" required>
    <button class="btn primary" type="submit">ค้นหา</button>
  </form>

  <?php if ($searched): ?>
    <?php if ($row): ?>
    <div class="card card-spacious reveal mb-4">
      <div class="text-center mb-2">
        <span class="material-symbols-rounded" style="font-size:56px;color:var(--<?= $row['status']==='done'?'success':($row['status']==='new'?'warning':'blue') ?>);"><?= e($statuses[$row['status']]['icon'] ?? 'help') ?></span>
        <h3 style="margin:8px 0 2px;">สถานะ: <?= e($statuses[$row['status']]['label'] ?? $row['status']) ?></h3>
        <span class="badge <?= e($statuses[$row['status']]['badge'] ?? '') ?>">เลขรับเรื่อง <?= e($ref) ?></span>
      </div>
      <div class="list-row" style="border:none;"><div><div class="lr-title">เรื่อง</div><div class="lr-date"><?= e($row['subject']) ?></div></div></div>
      <div class="list-row" style="border:none;"><div><div class="lr-title">วันที่รับเรื่อง</div><div class="lr-date"><?= e(thai_date($row['created_at'], false)) ?></div></div></div>
      <?php if (!empty($row['response'])): ?>
      <div class="alert success mt-2" style="text-align:left;">
        <span class="material-symbols-rounded">support_agent</span>
        <div>
          <b>คำตอบจากเจ้าหน้าที่</b>
          <div style="margin-top:4px;"><?= nl2br(e($row['response'])) ?></div>
          <?php if ($row['responded_at']): ?><div class="lr-date mt-1">ตอบเมื่อ <?= e(thai_date($row['responded_at'], false)) ?></div><?php endif; ?>
        </div>
      </div>
      <?php endif; ?>
    </div>
    <?php else: ?>
    <div class="card text-center text-muted reveal mb-4" style="padding:40px;">
      <span class="material-symbols-rounded icon-xl" style="color:var(--border);">search_off</span>
      <p>ไม่พบเรื่องร้องเรียนที่ตรงกับเลขรับเรื่องนี้<br>กรุณาตรวจสอบเลขให้ถูกต้อง</p>
    </div>
    <?php endif; ?>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
