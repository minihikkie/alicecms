<?php
/** admin/media.php — คลังสื่อกลาง (Media Library): อัปโหลด/เลือกซ้ำ/ลบ */
require dirname(__DIR__) . '/includes/init.php';
require __DIR__ . '/_auth.php';

$pick = isset($_GET['pick']);   /* โหมดเลือกรูป (เปิดจากที่อื่น) */
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['delete_id'])) {
        $st = db()->prepare('SELECT path FROM media WHERE id = ?');
        $st->execute([(int)$_POST['delete_id']]);
        if ($m = $st->fetch()) {
            if (trash_delete('media', (int)$_POST['delete_id'], $ADMIN['username'] ?? '')) {
                log_action('ลบสื่อ (ลงถังขยะ)', basename($m['path']));
                flash_set('success', 'ย้ายไฟล์ลงถังขยะแล้ว — กู้คืนได้ที่เมนู "ถังขยะ"');
            }
        }
        redirect('admin/media.php' . ($pick ? '?pick=1' : ''));
    }
    /* อัปโหลดหลายไฟล์ */
    try {
        $files = handle_uploads_multi('files', 'media', null, 25);
        if ($files) {
            $st = db()->prepare('INSERT INTO media (path, orig, mime, size) VALUES (?,?,?,?)');
            foreach ($files as $f) $st->execute([$f['path'], $f['orig'], $f['ext'], $f['size']]);
            log_action('อัปโหลดสื่อ', count($files) . ' ไฟล์');
            flash_set('success', 'อัปโหลด ' . count($files) . ' ไฟล์เรียบร้อย');
        } else {
            flash_set('danger', 'กรุณาเลือกไฟล์ที่จะอัปโหลด');
        }
    } catch (RuntimeException $ex) {
        flash_set('danger', $ex->getMessage());
    }
    redirect('admin/media.php' . ($pick ? '?pick=1' : ''));
}

$items = db()->query('SELECT * FROM media ORDER BY created_at DESC, id DESC')->fetchAll();
$img_exts = upload_image_exts();
$total_size = array_sum(array_column($items, 'size'));

$admin_title = 'คลังสื่อ';
require __DIR__ . '/_top.php';
?>
<div class="card mb-2">
  <div class="section-head" style="margin-bottom:8px;">
    <div><span class="tag"><span class="material-symbols-rounded icon-sm">perm_media</span> MEDIA</span><h3>คลังสื่อกลาง</h3>
    <p>อัปโหลดรูป/ไฟล์ไว้ที่เดียว แล้วนำไปใช้ซ้ำได้ทุกที่ — <?= count($items) ?> ไฟล์ · <?= e(format_bytes((int)$total_size)) ?></p></div>
  </div>
  <form method="post" action="" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <div class="dropzone mb-2" style="padding:22px;">
      <input type="file" name="files[]" multiple accept=".jpg,.jpeg,.png,.webp,.gif,.svg,.pdf,.doc,.docx,.xls,.xlsx,.zip">
      <span class="material-symbols-rounded icon-lg" style="color:var(--blue)">cloud_upload</span>
      <p style="margin:4px 0 0;font-size:13px;"><b>ลากไฟล์มาวาง</b> หรือคลิกเลือก (อัปได้หลายไฟล์ · ไม่เกิน 25 MB/ไฟล์)</p>
      <div class="dz-filename"></div>
    </div>
    <button class="btn primary" type="submit"><span class="material-symbols-rounded icon-sm">upload</span>อัปโหลดเข้าคลัง</button>
  </form>
</div>

<div class="card">
  <div class="section-head" style="margin-bottom:8px;">
    <div><span class="tag">ALL FILES</span><h3>ไฟล์ในคลัง (<?= count($items) ?>)</h3>
    <?php if ($pick): ?><p>คลิกรูปเพื่อเลือกใช้</p><?php else: ?><p>กด "คัดลอกลิงก์" แล้วนำไปวางในช่องรูป/ลิงก์ที่ต้องการ</p><?php endif; ?></div>
  </div>
  <?php if ($items): ?>
  <div class="media-grid">
    <?php foreach ($items as $m): $is_img = in_array(strtolower($m['mime']), $img_exts, true); $u = url($m['path']); ?>
    <div class="media-card" data-url="<?= e($u) ?>" data-path="<?= e($m['path']) ?>">
      <div class="media-thumb<?= $pick ? ' pickable' : '' ?>"<?= $pick ? ' role="button" tabindex="0"' : '' ?>>
        <?php if ($is_img): ?>
        <img src="<?= e($u) ?>" alt="<?= e($m['orig']) ?>" loading="lazy">
        <?php else: ?>
        <span class="material-symbols-rounded media-ficon"><?= e(strtolower($m['mime']) === 'pdf' ? 'picture_as_pdf' : 'description') ?></span>
        <span class="media-ext"><?= e(strtoupper($m['mime'])) ?></span>
        <?php endif; ?>
      </div>
      <div class="media-meta">
        <div class="media-name" title="<?= e($m['orig']) ?>"><?= e($m['orig'] ?: basename($m['path'])) ?></div>
        <div class="media-sub"><?= e(format_bytes((int)$m['size'])) ?></div>
      </div>
      <div class="media-actions">
        <button type="button" class="btn small" data-copy="<?= e($m['path']) ?>"><span class="material-symbols-rounded icon-sm">content_copy</span>คัดลอกลิงก์</button>
        <a class="btn small" href="<?= e($u) ?>" target="_blank" rel="noopener" title="เปิด"><span class="material-symbols-rounded icon-sm">open_in_new</span></a>
        <form method="post" action="<?= $pick ? '?pick=1' : '' ?>" style="display:inline;">
          <?= csrf_field() ?><input type="hidden" name="delete_id" value="<?= (int)$m['id'] ?>">
          <button class="btn small danger" type="submit" data-confirm="ลบไฟล์นี้ถาวร?"><span class="material-symbols-rounded icon-sm">delete</span></button>
        </form>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php else: ?>
  <p class="text-muted text-center" style="padding:32px;">ยังไม่มีไฟล์ในคลัง — อัปโหลดด้านบนได้เลย</p>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/_bottom.php'; ?>
