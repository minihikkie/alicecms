<?php
/** section: ร้องเรียน-ร้องทุกข์ (การ์ดลิงก์ไปหน้าฟอร์ม) */
if (!defined('APP_ROOT')) exit('Forbidden');
?>
<div class="card text-center reveal" style="padding:18px;">
  <div class="flex items-center justify-center gap-1">
    <span class="material-symbols-rounded" style="color:var(--danger)">support_agent</span>
    <b style="color:var(--ink)"><?= e(section_title('complaint')) ?></b>
  </div>
  <p class="text-muted" style="font-size:13px;margin:8px 0 12px;">แจ้งเรื่องร้องเรียนการทุจริตและประพฤติมิชอบ หรือข้อเสนอแนะการให้บริการ — ข้อมูลผู้แจ้งถูกเก็บเป็นความลับ</p>
  <a class="btn danger" href="<?= e(url('complaint.php')) ?>">แจ้งเรื่องร้องเรียน</a>
</div>
