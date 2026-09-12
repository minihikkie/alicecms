<?php
/**
 * services.php — หน้ารวมบริการออนไลน์ (e-Service)
 * รวมแบบฟอร์ม/บริการที่เปิดใช้งานไว้ในที่เดียว ให้ประชาชนหาเจอ
 * (เดิมสร้างแบบฟอร์มได้ แต่ไม่มีหน้ารวม ประชาชนต้องรู้ลิงก์เอง)
 */
define('PUBLIC_PAGE', 'services');
require __DIR__ . '/includes/init.php';

$forms = db()->query("SELECT slug, title, description FROM forms WHERE status = 'published' ORDER BY id ASC")->fetchAll();

$page_title = 'บริการออนไลน์ (e-Service)';
require __DIR__ . '/includes/header.php';
?>
<div class="container">
  <div class="page-head reveal">
    <div class="crumb"><a href="<?= e(url('index.php')) ?>">หน้าแรก</a>
      <span class="material-symbols-rounded icon-sm">chevron_right</span> บริการออนไลน์</div>
    <h1><?= e($page_title) ?></h1>
    <p class="text-muted">ยื่นคำขอ แจ้งเรื่อง และใช้บริการของหน่วยงานผ่านออนไลน์ ไม่ต้องเดินทางมาที่สำนักงาน</p>
  </div>

  <?php if ($forms): ?>
  <div class="grid grid-2 mb-4" data-reveal-group>
    <?php foreach ($forms as $f): ?>
    <a class="tool reveal" href="<?= e(url('form.php?slug=' . urlencode($f['slug']))) ?>" style="padding:22px 20px;">
      <div class="ti" style="width:52px;height:52px;"><span class="material-symbols-rounded icon-lg">assignment</span></div>
      <div>
        <div class="tt"><?= e($f['title']) ?></div>
        <?php if (trim((string)$f['description']) !== ''): ?>
        <div class="ts"><?= e(mb_strimwidth($f['description'], 0, 90, '…')) ?></div>
        <?php else: ?><div class="ts">กรอกแบบฟอร์มออนไลน์</div><?php endif; ?>
      </div>
    </a>
    <?php endforeach; ?>
  </div>
  <?php else: ?>
  <div class="card text-center text-muted" style="padding:44px 16px;">
    <span class="material-symbols-rounded" style="font-size:46px;opacity:.35;">assignment</span>
    <p style="margin:10px 0 0;">ยังไม่มีบริการออนไลน์เปิดให้ใช้งานในขณะนี้</p>
  </div>
  <?php endif; ?>

  <!-- บริการอื่นที่ระบบมีอยู่แล้ว -->
  <div class="grid grid-2 mb-4" data-reveal-group>
    <?php if (section_live('complaint')): ?>
    <a class="tool reveal" href="<?= e(url('complaint.php')) ?>" style="padding:22px 20px;">
      <div class="ti" style="background:rgba(234,67,53,.1);color:var(--danger);width:52px;height:52px;">
        <span class="material-symbols-rounded icon-lg">support_agent</span></div>
      <div><div class="tt">ร้องเรียน–ร้องทุกข์</div><div class="ts">แจ้งเรื่องพร้อมติดตามสถานะได้</div></div>
    </a>
    <a class="tool reveal" href="<?= e(url('complaint-track.php')) ?>" style="padding:22px 20px;">
      <div class="ti" style="width:52px;height:52px;"><span class="material-symbols-rounded icon-lg">travel_explore</span></div>
      <div><div class="tt">ติดตามสถานะเรื่องร้องเรียน</div><div class="ts">ใช้เลขรับเรื่องที่ได้รับ</div></div>
    </a>
    <?php endif; ?>
    <?php if (section_live('documents')): ?>
    <a class="tool reveal" href="<?= e(url('documents.php')) ?>" style="padding:22px 20px;">
      <div class="ti" style="width:52px;height:52px;"><span class="material-symbols-rounded icon-lg">folder_open</span></div>
      <div><div class="tt">ดาวน์โหลดเอกสาร/แบบฟอร์ม</div><div class="ts">เอกสารเผยแพร่ของหน่วยงาน</div></div>
    </a>
    <?php endif; ?>
    <a class="tool reveal" href="<?= e(url('contact.php')) ?>" style="padding:22px 20px;">
      <div class="ti" style="width:52px;height:52px;"><span class="material-symbols-rounded icon-lg">contact_support</span></div>
      <div><div class="tt">ติดต่อสอบถาม</div><div class="ts">ช่องทางติดต่อหน่วยงาน</div></div>
    </a>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
