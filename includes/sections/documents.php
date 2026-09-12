<?php
/** section: เอกสารเผยแพร่ (section เด่น) — ค้นหา + การ์ดหมวด (นับจริง) + เอกสารล่าสุด 4 */
if (!defined('APP_ROOT')) exit('Forbidden');

$cats = db()->query('SELECT c.*, (SELECT COUNT(*) FROM documents d WHERE d.category_id = c.id) AS n
                     FROM doc_categories c ORDER BY c.sort_order ASC, c.id ASC')->fetchAll();
$docs = db()->query('SELECT d.*, c.name AS cat_name, c.icon AS cat_icon, c.style AS cat_style
                     FROM documents d LEFT JOIN doc_categories c ON c.id = d.category_id
                     ORDER BY d.created_at DESC LIMIT 4')->fetchAll();
$n_docs = (int)db()->query('SELECT COUNT(*) FROM documents')->fetchColumn();

/* สีไอคอนตามสไตล์หมวด */
$style_css = [
    ''        => 'background:color-mix(in srgb, var(--blue) 10%, transparent);color:var(--blue);',
    'special' => 'background:rgba(156,39,176,.1);color:var(--special);',
    'warning' => 'background:rgba(249,171,0,.12);color:#B07000;',
    'danger'  => 'background:rgba(234,67,53,.08);color:var(--danger);',
];
?>
<section class="block mb-4" id="docs">
  <div class="card card-spacious reveal" style="background:linear-gradient(135deg, color-mix(in srgb, var(--blue) 8%, transparent), rgba(255,255,255,.92) 55%); border:2px solid color-mix(in srgb, var(--blue) 28%, transparent);">
    <div class="section-head">
      <div>
        <span class="tag"><span class="material-symbols-rounded icon-sm">folder_open</span> DOCUMENTS</span>
        <h2><?= e(section_title('documents')) ?></h2>
        <p>คู่มือ แบบฟอร์ม ระเบียบ และเอกสารต่างๆ — ดาวน์โหลดฟรี รวม <?= number_format($n_docs) ?> รายการ</p>
      </div>
      <form class="searchbar" style="max-width:340px;margin:0;box-shadow:var(--shadow-soft);" action="<?= e(url('documents.php')) ?>" method="get" role="search">
        <span class="material-symbols-rounded" style="color:var(--muted)">search</span>
        <input type="search" name="q" placeholder="ค้นหาเอกสาร..." aria-label="ค้นหาเอกสาร">
      </form>
    </div>
    <?php if ($cats): ?>
    <div class="tool-grid" data-reveal-group>
      <?php foreach ($cats as $c): ?>
      <a class="tool <?= e($c['style']) ?> reveal" href="<?= e(url('documents.php?cat=' . $c['id'])) ?>" style="padding:22px 18px;">
        <div class="ti" style="width:54px;height:54px;"><span class="material-symbols-rounded icon-lg"><?= e($c['icon'] ?: 'folder') ?></span></div>
        <div><div class="tt"><?= e($c['name']) ?></div><div class="ts"><?= number_format((int)$c['n']) ?> รายการ</div></div>
      </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <?php if ($docs): ?>
    <div class="grid grid-2 mt-3" style="gap:8px 24px;">
      <?php foreach ($docs as $d): ?>
      <a class="list-row" href="<?= e(url('download.php?id=' . $d['id'])) ?>">
        <div class="lr-icon" style="<?= $style_css[$d['cat_style'] ?? ''] ?? $style_css[''] ?>"><span class="material-symbols-rounded"><?= e($d['cat_icon'] ?: 'picture_as_pdf') ?></span></div>
        <div style="flex:1;">
          <div class="lr-title"><?= e($d['title']) ?>
            <?php if (is_new($d['created_at'])): ?><span class="badge gold" style="font-size:11px;padding:2px 8px;">ใหม่</span><?php endif; ?>
          </div>
          <div class="lr-date"><?= e($d['cat_name'] ?: 'ทั่วไป') ?> · <?= e(strtoupper((string)$d['ext'])) ?> · <?= e(format_bytes((int)$d['file_size'])) ?></div>
        </div>
        <span class="btn small" style="flex-shrink:0;"><span class="material-symbols-rounded icon-sm">download</span></span>
      </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <div class="text-center mt-3">
      <a class="btn primary large" href="<?= e(url('documents.php')) ?>"><span class="material-symbols-rounded">folder_open</span>ดูเอกสารทั้งหมด <?= number_format($n_docs) ?> รายการ</a>
    </div>
  </div>
</section>
