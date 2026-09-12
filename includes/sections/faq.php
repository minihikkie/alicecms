<?php
/** section: คำถามที่พบบ่อย (FAQ accordion ลื่นๆ) — เรนเดอร์เฉพาะคอลัมน์ */
if (!defined('APP_ROOT')) exit('Forbidden');

$faqs = db()->query('SELECT * FROM faqs ORDER BY sort_order ASC, id ASC LIMIT 8')->fetchAll();
?>
<div class="reveal">
  <div class="section-head" style="margin-bottom:12px;">
    <div><span class="tag"><span class="material-symbols-rounded icon-sm">quiz</span> FAQ</span><h3><?= e(section_title('faq')) ?></h3></div>
  </div>
  <?php if ($faqs): foreach ($faqs as $i => $f): ?>
  <div class="faq-item<?= $i === 0 ? ' open' : '' ?>">
    <button class="faq-q" type="button" aria-expanded="<?= $i === 0 ? 'true' : 'false' ?>">
      <span class="material-symbols-rounded fq-icon">help</span><?= e($f['question']) ?>
      <span class="material-symbols-rounded fq-arrow">expand_more</span>
    </button>
    <div class="faq-a"><div><div class="fa-body"><?= nl2br(e($f['answer'])) ?></div></div></div>
  </div>
  <?php endforeach; else: ?>
  <div class="card text-center text-muted" style="padding:24px;">ยังไม่มีคำถามที่พบบ่อย</div>
  <?php endif; ?>
</div>
