<?php
/**
 * faq.php — คำถามที่พบบ่อยทั้งหมด (พร้อมค้นหา)
 * เดิมแสดงได้แค่ไม่กี่ข้อบนหน้าแรก ไม่มีหน้ารวมให้ดูครบ
 */
define('PUBLIC_PAGE', 'faq');
require __DIR__ . '/includes/init.php';

/* section นี้ถูกปิดอยู่ → ตอบ 404 ไม่ให้เข้าถึงหน้าโดยตรง */
require_section_live("faq");

$q = trim((string)($_GET['q'] ?? ''));
if ($q !== '') {
    $st = db()->prepare('SELECT * FROM faqs WHERE question LIKE ? OR answer LIKE ? ORDER BY sort_order ASC, id ASC');
    $st->execute(["%$q%", "%$q%"]);
    $faqs = $st->fetchAll();
} else {
    $faqs = db()->query('SELECT * FROM faqs ORDER BY sort_order ASC, id ASC')->fetchAll();
}

$page_title = section_title('faq');
require __DIR__ . '/includes/header.php';
?>
<div class="container">
  <div class="page-head reveal">
    <div class="crumb"><a href="<?= e(url('index.php')) ?>">หน้าแรก</a>
      <span class="material-symbols-rounded icon-sm">chevron_right</span> คำถามที่พบบ่อย</div>
    <h1><?= e($page_title) ?></h1>
    <p class="text-muted">รวมคำถามที่ประชาชนสอบถามบ่อย พร้อมคำตอบจากหน่วยงาน</p>
  </div>

  <form class="searchbar mb-3 reveal" style="max-width:460px;" action="<?= e(url('faq.php')) ?>" method="get" role="search">
    <span class="material-symbols-rounded" style="color:var(--muted)">search</span>
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="ค้นหาคำถาม..." aria-label="ค้นหาคำถามที่พบบ่อย">
    <button class="btn primary" type="submit">ค้นหา</button>
  </form>

  <?php if ($faqs): ?>
  <div class="card reveal mb-4">
    <?php foreach ($faqs as $f): ?>
    <details class="faq-item">
      <summary><?= e($f['question']) ?></summary>
      <div class="faq-a"><?= nl2br(e($f['answer'])) ?></div>
    </details>
    <?php endforeach; ?>
  </div>
  <?php else: ?>
  <div class="card text-center text-muted" style="padding:40px 16px;">
    <span class="material-symbols-rounded" style="font-size:44px;opacity:.35;">quiz</span>
    <p style="margin:10px 0 0;"><?= $q !== '' ? 'ไม่พบคำถามที่ตรงกับ "' . e($q) . '"' : 'ยังไม่มีคำถามที่พบบ่อย' ?></p>
    <?php if ($q !== ''): ?><a class="btn mt-2" href="<?= e(url('faq.php')) ?>">ดูคำถามทั้งหมด</a><?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="card card-spacious text-center reveal mb-4">
    <span class="material-symbols-rounded icon-lg" style="color:var(--blue);">contact_support</span>
    <h3 style="margin:8px 0 4px;">ไม่พบคำตอบที่ต้องการ?</h3>
    <p class="text-muted" style="font-size:14px;">ติดต่อสอบถามเจ้าหน้าที่ได้โดยตรง</p>
    <a class="btn primary" href="<?= e(url('contact.php')) ?>"><span class="material-symbols-rounded icon-sm">call</span>ติดต่อหน่วยงาน</a>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
