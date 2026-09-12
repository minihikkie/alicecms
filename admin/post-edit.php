<?php
/** admin/post-edit.php — เพิ่ม/แก้ไขข่าว (อัปโหลดรูปปก + ไฟล์แนบ, สถานะร่าง/เผยแพร่) */
require dirname(__DIR__) . '/includes/init.php';
require __DIR__ . '/_auth.php';

$id   = (int)($_GET['id'] ?? 0);
$post = null;
$existing_atts = [];
if ($id > 0) {
    $st = db()->prepare('SELECT * FROM posts WHERE id = ?');
    $st->execute([$id]);
    $post = $st->fetch();
    if (!$post) redirect('admin/posts.php');
    $st = db()->prepare('SELECT * FROM post_attachments WHERE post_id = ? ORDER BY id ASC');
    $st->execute([$id]);
    $existing_atts = $st->fetchAll();
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $type   = isset(post_types()[$_POST['type'] ?? '']) ? $_POST['type'] : 'activity';
    $title  = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 250);
    $body   = trim((string)($_POST['body'] ?? ''));
    $status = ($_POST['status'] ?? '') === 'draft' ? 'draft' : 'published';
    $video_in = trim((string)($_POST['video_url'] ?? ''));
    /* เก็บเฉพาะ URL ที่เป็น YouTube ที่ถูกต้อง (กันลิงก์ขยะ) */
    $video_url = ($video_in !== '' && youtube_id($video_in) !== '') ? mb_substr($video_in, 0, 300) : '';
    if ($video_in !== '' && $video_url === '') $errors[] = 'ลิงก์วิดีโอต้องเป็น YouTube ที่ถูกต้อง';

    if ($title === '') $errors[] = 'กรุณากรอกหัวข้อข่าว';

    $image = $attach = null; $multi = [];
    if (!$errors) {
        try {
            $image  = handle_upload('image', 'posts', upload_image_exts(), 8);
            $attach = handle_upload('attachment', 'posts', null, 25);
            $multi  = handle_uploads_multi('attachments', 'posts', null, 25);
        } catch (RuntimeException $ex) {
            $errors[] = $ex->getMessage();
        }
    }

    if (!$errors) {
        if ($post) {
            $post_id = $id;
            /* แก้ไข — ถ้าอัปโหลดไฟล์ใหม่ ลบไฟล์เก่าทิ้ง */
            if ($image)  delete_upload($post['image']);
            if ($attach) delete_upload($post['attachment']);
            if (!empty($_POST['remove_image']))  { delete_upload($post['image']);  $post['image'] = null; }
            if (!empty($_POST['remove_attach'])) { delete_upload($post['attachment']); $post['attachment'] = null; $post['attachment_size'] = 0; }

            /* ตั้งวันเผยแพร่ครั้งแรกเมื่อเปลี่ยนจากร่างเป็นเผยแพร่ */
            $published_at = $post['published_at'];
            if ($status === 'published' && !$published_at) $published_at = date('Y-m-d H:i:s');

            $st = db()->prepare('UPDATE posts SET type=?, title=?, body=?, image=?, attachment=?,
                                 attachment_size=?, video_url=?, status=?, published_at=? WHERE id=?');
            $st->execute([
                $type, $title, $body,
                $image['path'] ?? $post['image'],
                $attach['path'] ?? $post['attachment'],
                $attach['size'] ?? (int)$post['attachment_size'],
                $video_url, $status, $published_at, $id,
            ]);
            log_action('แก้ไขข่าว', mb_substr($title, 0, 60));
            flash_set('success', 'บันทึกการแก้ไขเรียบร้อยแล้ว');
        } else {
            $st = db()->prepare("INSERT INTO posts (type, title, body, image, attachment, attachment_size, video_url, status, published_at)
                                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $st->execute([
                $type, $title, $body,
                $image['path'] ?? null,
                $attach['path'] ?? null,
                $attach['size'] ?? 0,
                $video_url, $status,
                $status === 'published' ? date('Y-m-d H:i:s') : null,
            ]);
            $post_id = (int)db()->lastInsertId();
            log_action('เพิ่มข่าว', mb_substr($title, 0, 60));
            flash_set('success', $status === 'published' ? 'เผยแพร่ข่าวเรียบร้อยแล้ว' : 'บันทึกฉบับร่างเรียบร้อยแล้ว');
        }

        /* ลบไฟล์แนบเดิมที่เลือก */
        if ($post && !empty($_POST['del_att']) && is_array($_POST['del_att'])) {
            foreach ($_POST['del_att'] as $aid) {
                $a = db()->prepare('SELECT file FROM post_attachments WHERE id = ? AND post_id = ?');
                $a->execute([(int)$aid, $post_id]);
                if ($row = $a->fetch()) { delete_upload($row['file']); db()->prepare('DELETE FROM post_attachments WHERE id = ?')->execute([(int)$aid]); }
            }
        }
        /* บันทึกไฟล์แนบใหม่ (หลายไฟล์) */
        foreach ($multi as $mf) {
            db()->prepare('INSERT INTO post_attachments (post_id, name, file, file_size, ext) VALUES (?,?,?,?,?)')
                ->execute([$post_id, $mf['orig'] ?? basename($mf['path']), $mf['path'], $mf['size'], $mf['ext']]);
        }

        redirect(!empty($_POST['quick']) ? 'admin/index.php' : 'admin/posts.php');
    }

    /* error → คงค่าที่กรอกไว้ */
    $post = array_merge($post ?? ['id' => 0, 'image' => null, 'attachment' => null, 'attachment_size' => 0, 'views' => 0],
                        ['type' => $type, 'title' => $title, 'body' => $body, 'status' => $status, 'video_url' => $video_in]);
}

$admin_title = $id ? 'แก้ไขข่าว' : 'เพิ่มข่าวใหม่';
require __DIR__ . '/_top.php';
?>
<div class="card">
  <div class="section-head" style="margin-bottom:12px;">
    <div><span class="tag">POST</span><h3><?= $id ? 'แก้ไขข่าว' : 'เพิ่มข่าวใหม่' ?></h3></div>
    <a class="btn small" href="<?= e(url('admin/posts.php')) ?>"><span class="material-symbols-rounded icon-sm">arrow_back</span>กลับรายการข่าว</a>
  </div>

  <?php if ($errors): ?>
  <div class="alert danger"><span class="material-symbols-rounded">error</span>
    <div><?php foreach ($errors as $er): ?><div><?= e($er) ?></div><?php endforeach; ?></div>
  </div>
  <?php endif; ?>

  <form method="post" action="" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <div class="form-row">
      <div>
        <label>ประเภทข่าว</label>
        <select name="type" class="mb-2">
          <?php foreach (post_types() as $tk => $tv): ?>
          <option value="<?= e($tk) ?>" <?= old_checked('type', $tk, ($post['type'] ?? '') === $tk) ? 'selected' : '' ?>><?= e($tv['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label>สถานะ</label>
        <select name="status" class="mb-2">
          <option value="published" <?= old_checked('status', 'published', ($post['status'] ?? 'published') === 'published') ? 'selected' : '' ?>>เผยแพร่</option>
          <option value="draft" <?= old_checked('status', 'draft', ($post['status'] ?? '') === 'draft') ? 'selected' : '' ?>>ฉบับร่าง</option>
        </select>
      </div>
    </div>

    <label>หัวข้อข่าว <span style="color:var(--danger);">*</span></label>
    <input type="text" name="title" value="<?= e(old('title', $post['title'] ?? '')) ?>" required class="mb-2" placeholder="หัวข้อข่าว...">

    <label>เนื้อหา</label>
    <textarea name="body" rows="8" class="mb-2" placeholder="รายละเอียดข่าว... (ขึ้นบรรทัดใหม่ได้ตามปกติ)"><?= e(old('body', $post['body'] ?? '')) ?></textarea>

    <div class="form-row">
      <div>
        <label>รูปภาพปก <span class="text-muted">(JPG, PNG, WebP ≤ 8 MB — ระบบย่อรูปอัตโนมัติ)</span></label>
        <?php if (!empty($post['image'])): ?>
        <div class="current-file">
          <img src="<?= e(url($post['image'])) ?>" alt="">รูปปัจจุบัน
          <label class="inline-check" style="margin:0;"><input type="checkbox" name="remove_image" value="1">ลบรูป</label>
        </div>
        <?php endif; ?>
        <div class="dropzone mb-2" style="padding:18px;">
          <input type="file" name="image" accept=".jpg,.jpeg,.png,.webp">
          <span class="material-symbols-rounded icon-lg" style="color:var(--blue)">add_photo_alternate</span>
          <p style="margin:4px 0 0;font-size:13px;"><b>ลากรูปมาวาง</b> หรือคลิกเลือก</p>
          <div class="dz-filename"></div>
        </div>
      </div>
      <div>
        <label>ไฟล์แนบ <span class="text-muted">(PDF, DOC, XLS, ZIP ≤ 25 MB)</span></label>
        <?php if (!empty($post['attachment'])): ?>
        <div class="current-file">
          <span class="material-symbols-rounded icon-sm" style="color:var(--danger);">attach_file</span>
          <?= e(basename($post['attachment'])) ?> (<?= e(format_bytes((int)$post['attachment_size'])) ?>)
          <label class="inline-check" style="margin:0;"><input type="checkbox" name="remove_attach" value="1">ลบไฟล์</label>
        </div>
        <?php endif; ?>
        <div class="dropzone mb-2" style="padding:18px;">
          <input type="file" name="attachment" accept=".jpg,.jpeg,.png,.webp,.pdf,.doc,.docx,.xls,.xlsx,.zip">
          <span class="material-symbols-rounded icon-lg" style="color:var(--blue)">upload_file</span>
          <p style="margin:4px 0 0;font-size:13px;"><b>ลากไฟล์มาวาง</b> หรือคลิกเลือก</p>
          <div class="dz-filename"></div>
        </div>
      </div>
    </div>

    <label>วิดีโอ YouTube <span class="text-muted">(ไม่บังคับ — วางลิงก์ YouTube จะฝังวิดีโอในหน้าข่าว)</span></label>
    <input type="text" name="video_url" value="<?= e(old('video_url', $post['video_url'] ?? '')) ?>" class="mb-2" placeholder="https://www.youtube.com/watch?v=...">

    <label>ไฟล์แนบเพิ่มเติม (หลายไฟล์) <span class="text-muted">(เลือกได้หลายไฟล์พร้อมกัน ≤ 25 MB ต่อไฟล์)</span></label>
    <?php if ($existing_atts): ?>
    <div class="mb-2">
      <?php foreach ($existing_atts as $att): ?>
      <div class="current-file" style="display:flex;">
        <span class="material-symbols-rounded icon-sm" style="color:var(--danger);">description</span>
        <span style="flex:1;"><?= e($att['name'] ?: basename($att['file'])) ?> (<?= e(format_bytes((int)$att['file_size'])) ?>)</span>
        <label class="inline-check" style="margin:0;"><input type="checkbox" name="del_att[]" value="<?= (int)$att['id'] ?>">ลบ</label>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <div class="dropzone mb-2" style="padding:18px;">
      <input type="file" name="attachments[]" multiple accept=".jpg,.jpeg,.png,.webp,.pdf,.doc,.docx,.xls,.xlsx,.zip">
      <span class="material-symbols-rounded icon-lg" style="color:var(--blue)">library_add</span>
      <p style="margin:4px 0 0;font-size:13px;"><b>เลือกหลายไฟล์</b> เพื่อแนบเพิ่ม</p>
      <div class="dz-filename"></div>
    </div>

    <div class="flex gap-1 mt-2">
      <button class="btn primary large" type="submit"><span class="material-symbols-rounded">save</span><?= $id ? 'บันทึกการแก้ไข' : 'บันทึกข่าว' ?></button>
      <a class="btn large" href="<?= e(url('admin/posts.php')) ?>">ยกเลิก</a>
    </div>
  </form>
</div>
<?php require __DIR__ . '/_bottom.php'; ?>
