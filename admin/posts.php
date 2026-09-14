<?php
/** admin/posts.php — รายการข่าวทั้งหมด (กรอง/ค้นหา/ลบ) */
require dirname(__DIR__) . '/includes/init.php';
require __DIR__ . '/_auth.php';

/* ลบข่าว (พร้อมไฟล์แนบ) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    $id = (int)$_POST['delete_id'];
    /* ย้ายลงถังขยะ (กู้คืนได้ภายใน 30 วัน) พร้อมไฟล์แนบทั้งหมด */
    if (trash_delete('posts', $id, $ADMIN['username'] ?? '')) {
        log_action('ลบข่าว (ลงถังขยะ)', '#' . $id);
        flash_set('success', 'ย้ายข่าวลงถังขยะแล้ว — กู้คืนได้ที่เมนู "ถังขยะ"');
    } else {
        flash_set('danger', 'ลบไม่สำเร็จ กรุณาลองใหม่');
    }
    redirect('admin/posts.php' . (isset($_POST['back_qs']) ? '?' . $_POST['back_qs'] : ''));
}

$types = post_types();
$type  = isset($_GET['type']) && isset($types[$_GET['type']]) ? $_GET['type'] : '';
$q     = trim((string)($_GET['q'] ?? ''));
$page  = max(1, (int)($_GET['page'] ?? 1));
$per   = 20;

$where  = ['1=1'];
$params = [];
if ($type !== '') { $where[] = 'type = ?'; $params[] = $type; }
if ($q !== '')    { $where[] = 'title LIKE ?'; $params[] = "%$q%"; }
$wsql = implode(' AND ', $where);

$st = db()->prepare("SELECT COUNT(*) FROM posts WHERE $wsql");
$st->execute($params);
$total = (int)$st->fetchColumn();

$st = db()->prepare("SELECT * FROM posts WHERE $wsql ORDER BY id DESC LIMIT $per OFFSET " . (($page - 1) * $per));
$st->execute($params);
$posts = $st->fetchAll();

$qs = http_build_query(array_filter(['type' => $type, 'q' => $q]));
$admin_title = 'จัดการข่าว';
require __DIR__ . '/_top.php';
?>
<div class="card">
  <div class="section-head" style="margin-bottom:12px;">
    <div><span class="tag">POSTS</span><h3>จัดการข่าว (<?= number_format($total) ?>)</h3></div>
    <a class="btn primary" href="<?= e(url('admin/post-edit.php')) ?>"><span class="material-symbols-rounded icon-sm">add</span>เพิ่มข่าวใหม่</a>
  </div>

  <div class="flex items-center gap-2 mb-2" style="flex-wrap:wrap;">
    <div class="cats">
      <a class="cat<?= $type === '' ? ' active' : '' ?>" href="<?= e(url('admin/posts.php')) ?>">ทั้งหมด</a>
      <?php foreach ($types as $tk => $tv): ?>
      <a class="cat<?= $type === $tk ? ' active' : '' ?>" href="<?= e(url('admin/posts.php?type=' . $tk)) ?>"><?= e($tv['label']) ?></a>
      <?php endforeach; ?>
    </div>
    <form class="searchbar" style="max-width:280px;margin:0;margin-left:auto;box-shadow:none;border:1px solid var(--border);" action="" method="get">
      <?php if ($type !== ''): ?><input type="hidden" name="type" value="<?= e($type) ?>"><?php endif; ?>
      <span class="material-symbols-rounded" style="color:var(--muted)">search</span>
      <input type="search" name="q" value="<?= e($q) ?>" placeholder="ค้นหาหัวข้อ...">
    </form>
  </div>

  <?php if ($posts): ?>
  <table class="admin-table">
    <tr><th>หัวข้อ</th><th>ประเภท</th><th>สถานะ</th><th>วันที่</th><th>ยอดอ่าน</th><th></th></tr>
    <?php foreach ($posts as $p): ?>
    <tr>
      <td style="max-width:340px;"><?= e(mb_strimwidth($p['title'], 0, 80, '…')) ?></td>
      <td><span class="badge <?= e($types[$p['type']]['badge'] ?? '') ?>"><?= e(post_type_label($p['type'])) ?></span></td>
      <td><?= $p['status'] === 'published'
            ? '<span class="badge success" style="font-size:11px;">เผยแพร่</span>'
            : '<span class="badge" style="font-size:11px;">ร่าง</span>' ?></td>
      <td class="lr-date" style="white-space:nowrap;"><?= e(thai_date($p['published_at'] ?: $p['created_at'])) ?></td>
      <td class="lr-date"><?= number_format((int)$p['views']) ?></td>
      <td style="white-space:nowrap;">
        <a class="btn small" href="<?= e(url('admin/post-edit.php?id=' . $p['id'])) ?>">แก้ไข</a>
        <a class="btn small" href="<?= e(url('admin/graphic.php?post=' . $p['id'])) ?>"
           title="สร้างภาพประชาสัมพันธ์จากข่าวนี้ เพื่อนำไปโพสต์ช่องทางอื่น">
          <span class="material-symbols-rounded icon-sm">image</span>สร้างภาพ</a>
        <form method="post" action="" style="display:inline;">
          <?= csrf_field() ?>
          <input type="hidden" name="delete_id" value="<?= (int)$p['id'] ?>">
          <input type="hidden" name="back_qs" value="<?= e($qs) ?>">
          <button class="btn small danger" type="submit" data-confirm="ยืนยันลบข่าวนี้? ไฟล์แนบจะถูกลบด้วย">ลบ</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?= pagination_html($total, $per, $page, url('admin/posts.php' . ($qs ? "?$qs" : ''))) ?>
  <?php else: ?>
  <p class="text-muted text-center" style="padding:32px;">ไม่พบข่าว</p>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/_bottom.php'; ?>
