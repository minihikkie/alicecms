<?php
/**
 * doc.php — หน้ารายละเอียดเอกสารรายฉบับ (?id=)
 *
 * ปกใหญ่ · ชื่อเรื่อง · กลุ่ม/วันที่เผยแพร่/ชนิดไฟล์/ยอดเปิด · คำอธิบาย · ปุ่มอ่าน/ดาวน์โหลด/แชร์
 * · อยู่ในสารบัญตรงไหน (ถ้าหมวดเป็นแบบสารบัญ) · ฉบับก่อน/ถัดไป · ฉบับอื่นในกลุ่มเดียวกัน
 * ส่งลิงก์หน้านี้ต่อได้สวยกว่าลิงก์ไฟล์ตรงๆ และ Google เก็บหน้าได้ (ไฟล์ PDF/Drive ไม่มีชื่อหน่วยงานกำกับ)
 */
define('PUBLIC_PAGE', 'documents');
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/doc-shelf.php';
require_section_live('documents');

$id = (int)($_GET['id'] ?? 0);
/* SELECT * แยกสองตาราง ไม่ระบุชื่อคอลัมน์ใหม่ตรงๆ — ไม่ error ช่วงอัปเดตที่ไฟล์มาก่อน migration */
$st = db()->prepare('SELECT * FROM documents WHERE id = ?');
$st->execute([$id]);
$doc = $st->fetch();
if (!$doc) { http_response_code(404); require __DIR__ . '/404.php'; exit; }
$doc += ['cover' => null, 'grp' => '', 'pub_date' => null, 'summary' => ''];

$cat = ['id' => null, 'name' => 'ทั่วไป', 'icon' => '', 'style' => '', 'cover' => null, 'layout' => 'list', 'view_conf' => null];
if (!empty($doc['category_id'])) {
    $st = db()->prepare('SELECT * FROM doc_categories WHERE id = ?');
    $st->execute([(int)$doc['category_id']]);
    if ($row = $st->fetch()) $cat = $row + $cat;
}
$conf = docshelf_conf($cat);
$group = docshelf_group($doc, $conf);
/* ชื่อไม่ซ้ำกับตัวแปรใน header.php (วงวนเมนูใช้ $isExt ทับค่าหลัง require) */
$docIsExt = !empty($doc['ext_url']);
$fileHref = url('download.php?id=' . $id);

/* ฉบับอื่นในหมวดเดียวกัน — ใช้ทั้งปุ่มก่อน/ถัดไป และ "ฉบับในกลุ่มเดียวกัน" */
$siblings = [];
if ($cat['id']) {
    $st = db()->prepare('SELECT * FROM documents WHERE category_id = ? ORDER BY sort_order ASC, id DESC LIMIT 1000');
    $st->execute([(int)$cat['id']]);
    $siblings = $st->fetchAll();
}
/* เรียงตามวันที่ (เก่า → ใหม่) แล้วหาตำแหน่งของฉบับนี้ */
usort($siblings, fn($a, $b) => strcmp(docshelf_date($a) . sprintf('%010d', $a['id']), docshelf_date($b) . sprintf('%010d', $b['id'])));
$pos = array_search($id, array_map('intval', array_column($siblings, 'id')), true);
$older = $pos !== false && $pos > 0 ? $siblings[$pos - 1] : null;
$newer = $pos !== false && isset($siblings[$pos + 1]) ? $siblings[$pos + 1] : null;
$related = array_values(array_filter(array_reverse($siblings), fn($s) => (int)$s['id'] !== $id && ($group === '' || docshelf_group($s, $conf) === $group)));
$related = array_slice($related, 0, 6);

/* อยู่ในสารบัญตรงไหน (หมวดแบบสารบัญที่ผูกเอกสารนี้ไว้) */
$tocRefs = [];
try {
    $st = db()->prepare('SELECT t.id, t.label, t.title, t.category_id, p.label AS p_label, p.title AS p_title
                         FROM doc_toc t LEFT JOIN doc_toc p ON p.id = t.parent_id WHERE t.document_id = ? ORDER BY t.sort_order LIMIT 10');
    $st->execute([$id]);
    $tocRefs = $st->fetchAll();
} catch (Throwable $e) {}

$page_title = $doc['title'];
$meta_description = trim((string)$doc['summary']) !== '' ? mb_substr(trim($doc['summary']), 0, 160)
    : mb_substr($doc['title'] . ' — ' . $cat['name'] . ($group !== '' ? ' ' . $group : ''), 0, 160);
if (!empty($doc['cover'])) $og_image = $doc['cover'];
elseif (!empty($cat['cover'])) $og_image = $cat['cover'];
$og_type = 'article';
require __DIR__ . '/includes/header.php';
$catHref = $cat['id'] ? url('documents.php?cat=' . (int)$cat['id']) : url('documents.php');
?>
<div class="container">
  <div class="page-head reveal">
    <div class="crumb"><a href="<?= e(url('index.php')) ?>">หน้าแรก</a> <span class="material-symbols-rounded icon-sm">chevron_right</span>
      <a href="<?= e(url('documents.php')) ?>">เอกสารเผยแพร่</a> <span class="material-symbols-rounded icon-sm">chevron_right</span>
      <a href="<?= e($catHref) ?>"><?= e($cat['name']) ?></a></div>
  </div>

  <article class="ddoc reveal" aria-labelledby="ddoc-t">
    <div class="ddoc-cv">
      <?php if (!empty($doc['cover'])): ?>
      <a href="<?= e(url($doc['cover'])) ?>" data-lightbox="<?= e(url($doc['cover'])) ?>" aria-label="ดูภาพปกขนาดเต็ม"><?= docshelf_cover($doc, $cat, 'lg') ?></a>
      <?php else: ?><?= docshelf_cover($doc, $cat, 'lg') ?><?php endif; ?>
    </div>
    <div class="ddoc-t">
      <span class="bk-kicker"><span class="material-symbols-rounded icon-sm" aria-hidden="true"><?= e($cat['icon'] ?: 'description') ?></span><?= e($cat['name']) ?></span>
      <h1 id="ddoc-t"><?= e($doc['title']) ?></h1>
      <dl class="ddoc-meta">
        <?php if ($group !== ''): ?><div><dt>กลุ่ม</dt><dd><?= e($group) ?></dd></div><?php endif; ?>
        <div><dt>เผยแพร่</dt><dd><?= e(thai_date(docshelf_date($doc), false)) ?></dd></div>
        <div><dt>ไฟล์</dt><dd><?= e(docshelf_filemeta($doc)) ?></dd></div>
        <?php if ((int)$doc['downloads'] > 0): ?><div><dt>เปิดแล้ว</dt><dd><?= number_format((int)$doc['downloads']) ?> ครั้ง</dd></div><?php endif; ?>
      </dl>
      <?php if (trim((string)$doc['summary']) !== ''): ?><p class="ddoc-s"><?= nl2br(e($doc['summary'])) ?></p><?php endif; ?>
      <div class="ddoc-a">
        <a class="btn primary large" href="<?= e($fileHref) ?>"<?= $docIsExt ? ' target="_blank" rel="noopener"' : '' ?>>
          <span class="material-symbols-rounded" aria-hidden="true"><?= $docIsExt ? 'menu_book' : 'download' ?></span><?= $docIsExt ? 'อ่านเอกสาร' : 'ดาวน์โหลด' ?></a>
        <?= share_btn($doc['title'], url('doc.php?id=' . $id), 'd=' . $id, 'lg') ?>
      </div>
      <?php if ($tocRefs): ?>
      <div class="ddoc-toc">
        <b><span class="material-symbols-rounded icon-sm" aria-hidden="true">account_tree</span>อยู่ในสารบัญ</b>
        <?php foreach ($tocRefs as $r): ?>
        <a href="<?= e(url('documents.php?cat=' . (int)$r['category_id']) . '#t' . (int)$r['id']) ?>"><?= e(trim(($r['p_label'] ? $r['p_label'] . ' › ' : '') . $r['label'] . ' ' . $r['title'])) ?></a>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </article>

  <?php if ($older || $newer): ?>
  <nav class="ddoc-nav reveal" aria-label="ฉบับก่อนและถัดไป">
    <?php if ($older): ?><a class="prev" href="<?= e(url('doc.php?id=' . (int)$older['id'])) ?>"><span class="material-symbols-rounded" aria-hidden="true">arrow_back</span><span><small>ฉบับก่อนหน้า</small><?= e($older['title']) ?></span></a><?php else: ?><span></span><?php endif; ?>
    <?php if ($newer): ?><a class="next" href="<?= e(url('doc.php?id=' . (int)$newer['id'])) ?>"><span><small>ฉบับถัดไป</small><?= e($newer['title']) ?></span><span class="material-symbols-rounded" aria-hidden="true">arrow_forward</span></a><?php endif; ?>
  </nav>
  <?php endif; ?>

  <?php if ($related): ?>
  <section class="ddoc-rel reveal" aria-labelledby="ddoc-rel">
    <div class="dsh-gh"><h2 id="ddoc-rel"><?= e($group !== '' ? 'ฉบับอื่นใน' . $group : 'เอกสารอื่นในหมวดนี้') ?></h2>
      <a class="btn small" href="<?= e($catHref) ?>">ดูทั้งหมด</a></div>
    <div class="dsh size-sm"><div class="dsh-grid">
      <?php foreach ($related as $d): ?>
      <a class="dsh-card" href="<?= e(url('doc.php?id=' . (int)$d['id'])) ?>">
        <?= docshelf_cover($d, $cat) ?>
        <span class="dsh-t"><?= e($d['title']) ?></span>
        <span class="dsh-m"><?= e(thai_date(docshelf_date($d))) ?></span>
      </a>
      <?php endforeach; ?>
    </div></div>
  </section>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
