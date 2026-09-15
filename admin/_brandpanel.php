<?php
/**
 * admin/_brandpanel.php — แผงซ้ายของหน้า เข้าสู่ระบบ / ลืมรหัสผ่าน / ตั้งรหัสผ่านใหม่
 * (แสดงเฉพาะจอกว้าง ≥1020px)  ต้องมี $org และ $dept มาก่อนเรียก
 *
 * ตรา AliceCMS เป็นตัวเด่นของแผงนี้ ส่วนชื่อหน่วยงานอยู่ท้ายแผงตัวเล็ก —
 * หน้านี้เป็นหน้าของตัวระบบ ไม่ใช่หน้าเว็บสาธารณะของหน่วยงาน จึงไม่แสดงตราหน่วยงาน
 */
if (!defined('APP_ROOT')) exit('Forbidden');   /* พาร์เชียล — เปิดตรงจาก URL ไม่ได้ */
?>
<aside class="auth-brand">
  <div class="auth-hero">
    <div class="logo auth-logo"><?php require dirname(__DIR__) . '/includes/mark.php'; ?></div>
    <div class="auth-hero-t">
      <h1 class="auth-wordmark">AliceCMS<span class="auth-ver">v<?= e(APP_VERSION) ?></span></h1>
      <p class="auth-tagline">ระบบจัดการเนื้อหาเว็บไซต์สำหรับหน่วยงานราชการ</p>
    </div>
  </div>

  <div class="auth-chips">
    <a class="auth-chip" href="https://github.com/minihikkie/alicecms" target="_blank" rel="noopener">ซอฟต์แวร์โอเพนซอร์ส</a>
    <span class="auth-chip">ยืนยันตัวตนสองชั้น</span>
    <span class="auth-chip">บันทึกประวัติการใช้งาน</span>
  </div>

  <div class="auth-user">
    <div class="auth-user-label">ใช้งานโดย</div>
    <div class="auth-user-name"><?= e($org) ?></div>
    <?php if ($dept): ?><div class="auth-user-dept"><?= e($dept) ?></div><?php endif; ?>
  </div>
</aside>
