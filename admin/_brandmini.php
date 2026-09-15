<?php
/**
 * admin/_brandmini.php — หัวแบรนด์ย่อในการ์ดฟอร์ม โผล่เฉพาะจอเล็กที่แผงซ้ายถูกซ่อน
 * ต้องมี $org มาก่อนเรียก
 *
 * ยังคงบอกชื่อหน่วยงานไว้ เพราะคนที่เปิดหน้านี้ต้องเห็นว่ากำลังเข้าเว็บของหน่วยงานไหน
 * แต่ให้อยู่บรรทัดรองใต้ชื่อระบบ
 */
if (!defined('APP_ROOT')) exit('Forbidden');   /* พาร์เชียล — เปิดตรงจาก URL ไม่ได้ */
?>
<div class="auth-mini">
  <div class="logo auth-mini-logo"><?php require dirname(__DIR__) . '/includes/mark.php'; ?></div>
  <div>
    <div class="auth-mini-name">AliceCMS<span class="auth-mini-ver">v<?= e(APP_VERSION) ?></span></div>
    <div class="auth-mini-sub"><?= e($org) ?></div>
  </div>
</div>
