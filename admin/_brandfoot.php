<?php
/**
 * admin/_brandfoot.php — ล็อกอัปตรา AliceCMS ท้ายแผงแบรนด์
 * ใช้ร่วมกันที่หน้า เข้าสู่ระบบ / ลืมรหัสผ่าน / ตั้งรหัสผ่านใหม่
 *
 * แยกเป็นไฟล์เดียวเพราะสามหน้านี้ต้องเหมือนกันเป๊ะ ก่อนหน้านี้เขียนซ้ำสามที่แล้วแก้ไม่ครบ
 */
?>
<div class="auth-foot">
  <div class="logo"><?php require dirname(__DIR__) . '/includes/mark.php'; ?></div>
  <div class="auth-foot-t">
    <div class="auth-foot-name">AliceCMS<span class="auth-foot-ver">v<?= e(APP_VERSION) ?></span></div>
    <p class="auth-foot-desc">ระบบจัดการเนื้อหาเว็บไซต์สำหรับหน่วยงานราชการ</p>
    <div class="auth-foot-meta">
      <a class="auth-chip" href="https://github.com/minihikkie/alicecms" target="_blank" rel="noopener">ซอฟต์แวร์โอเพนซอร์ส</a>
      <span class="auth-chip">ยืนยันตัวตนสองชั้น</span>
      <span class="auth-chip">บันทึกประวัติการใช้งาน</span>
    </div>
  </div>
</div>
