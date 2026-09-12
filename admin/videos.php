<?php
/** admin/videos.php — วิดีโอความรู้ (YouTube): เพิ่ม/แก้ไข/ลบ/ลากจัดลำดับ */
require dirname(__DIR__) . '/includes/init.php';
require __DIR__ . '/_auth.php';

$errors = [];
$edit = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    /* ── ลากจัดลำดับ (fetch JSON) ── */
    if (($_POST['action'] ?? '') === 'reorder') {
        header('Content-Type: application/json');
        $ids = json_decode((string)($_POST['order'] ?? '[]'), true) ?: [];
        $upd = db()->prepare('UPDATE videos SET sort_order = ? WHERE id = ?');
        $i = 0;
        foreach ($ids as $vid) { $upd->execute([$i++, (int)$vid]); }
        echo json_encode(['ok' => true]);
        exit;
    }

    if (isset($_POST['delete_id'])) {
        $id = (int)$_POST['delete_id'];
        if (trash_delete('videos', $id, $ADMIN['username'] ?? '')) {
            log_action('ลบวิดีโอ (ลงถังขยะ)', '#' . $id);
            flash_set('success', 'ย้ายวิดีโอลงถังขยะแล้ว — กู้คืนได้ที่เมนู "ถังขยะ"');
        }
        redirect('admin/videos.php');
    }

    $id       = (int)($_POST['id'] ?? 0);
    $title    = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 200);
    $desc     = mb_substr(trim((string)($_POST['description'] ?? '')), 0, 500);
    $duration = mb_substr(trim((string)($_POST['duration'] ?? '')), 0, 12);
    $date     = trim((string)($_POST['published_at'] ?? ''));
    $status   = ($_POST['status'] ?? 'published') === 'draft' ? 'draft' : 'published';
    $sort     = (int)($_POST['sort_order'] ?? 0);
    /* เก็บเฉพาะรหัสวิดีโอ 11 ตัวที่แกะได้ ไม่เก็บ URL ที่ผู้ใช้พิมพ์มาตรงๆ
       — ประกอบ URL ใหม่ตอนแสดงผล จึงไม่มีทางที่ลิงก์ปลายทางอื่นจะหลุดเข้า iframe */
    $video_id = youtube_id((string)($_POST['youtube_url'] ?? ''));

    if ($title === '') $errors[] = 'กรุณากรอกชื่อวิดีโอ';
    if ($video_id === '') $errors[] = 'ลิงก์ YouTube ไม่ถูกต้อง — คัดลอกลิงก์จากช่องที่อยู่ของ YouTube มาวางให้ครบ (รองรับทั้งแบบ watch, youtu.be, Shorts และ Live)';
    if ($duration !== '' && !preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $duration)) $errors[] = 'ความยาววิดีโอต้องอยู่ในรูปแบบ นาที:วินาที เช่น 5:42 หรือ 1:05:30';
    if ($date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { $errors[] = 'วันที่ไม่ถูกต้อง'; $date = ''; }

    $cover = null;
    if (!$errors) {
        try { $cover = handle_upload('cover', 'videos', upload_image_exts(), 4); }
        catch (RuntimeException $ex) { $errors[] = $ex->getMessage(); }
    }

    if (!$errors) {
        $dateVal = $date !== '' ? $date : null;
        if ($id > 0) {
            $st = db()->prepare('SELECT cover FROM videos WHERE id = ?');
            $st->execute([$id]);
            $old = $st->fetch();
            if ($cover && $old) delete_upload($old['cover']);
            if (!empty($_POST['remove_cover']) && $old) delete_upload($old['cover']);
            $cov = $cover['path'] ?? (!empty($_POST['remove_cover']) ? null : ($old['cover'] ?? null));
            db()->prepare('UPDATE videos SET title=?, video_id=?, description=?, duration=?, cover=?, published_at=?, status=?, sort_order=? WHERE id=?')
                ->execute([$title, $video_id, $desc, $duration, $cov, $dateVal, $status, $sort, $id]);
            log_action('แก้ไขวิดีโอ', $title);
        } else {
            db()->prepare('INSERT INTO videos (title, video_id, description, duration, cover, published_at, status, sort_order) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([$title, $video_id, $desc, $duration, $cover['path'] ?? null, $dateVal, $status, $sort]);
            log_action('เพิ่มวิดีโอ', $title);
        }
        flash_set('success', 'บันทึกวิดีโอเรียบร้อยแล้ว');
        redirect('admin/videos.php');
    }
}

if (isset($_GET['edit'])) {
    $st = db()->prepare('SELECT * FROM videos WHERE id = ?');
    $st->execute([(int)$_GET['edit']]);
    $edit = $st->fetch();
}

$videos = db()->query('SELECT * FROM videos ORDER BY sort_order ASC, id DESC')->fetchAll();
$sec_on = section_on('video');
$admin_title = 'วิดีโอความรู้';
require __DIR__ . '/_top.php';
?>
<?php if ($errors): ?>
<div class="alert danger"><span class="material-symbols-rounded">error</span>
  <div><?php foreach ($errors as $er): ?><div><?= e($er) ?></div><?php endforeach; ?></div>
</div>
<?php endif; ?>

<?php if (!$sec_on): ?>
<div class="alert warning"><span class="material-symbols-rounded">visibility_off</span>
  <div>ตอนนี้ section วิดีโอถูกปิดอยู่ จึงยังไม่แสดงบนหน้าแรก —
    เปิดได้ที่ <a href="<?= e(url('admin/homepage.php')) ?>">การแสดงผลหน้าแรก</a></div>
</div>
<?php endif; ?>

<div class="card mb-2">
  <div class="section-head" style="margin-bottom:8px;">
    <div><span class="tag">VIDEO</span><h3><?= $edit ? 'แก้ไขวิดีโอ' : 'เพิ่มวิดีโอใหม่' ?></h3>
    <p>อัปโหลดวิดีโอขึ้น YouTube ก่อน แล้วนำลิงก์มาวางที่นี่ — ภาพปกดึงจาก YouTube ให้อัตโนมัติ ไม่ต้องอัปเอง</p></div>
    <?php if ($edit): ?><a class="btn small" href="<?= e(url('admin/videos.php')) ?>">ยกเลิก</a><?php endif; ?>
  </div>
  <form method="post" action="" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">

    <label>ลิงก์ YouTube <span style="color:var(--danger);">*</span></label>
    <input type="text" name="youtube_url" data-yt-input autocomplete="off" required class="mb-2"
           value="<?= e(old('youtube_url', !empty($edit['video_id']) ? 'https://www.youtube.com/watch?v=' . $edit['video_id'] : '')) ?>"
           placeholder="https://www.youtube.com/watch?v=... หรือ https://youtu.be/...">
    <div class="yt-preview mb-2" data-yt-preview></div>

    <label>ชื่อวิดีโอ <span style="color:var(--danger);">*</span></label>
    <input type="text" name="title" value="<?= e(old('title', $edit['title'] ?? '')) ?>" required class="mb-2"
           placeholder="เช่น รู้ทันกลโกงมิจฉาชีพออนไลน์">

    <label>คำอธิบายสั้น <span class="text-muted">(ไม่บังคับ — แสดงใต้ชื่อวิดีโอ)</span></label>
    <textarea name="description" rows="2" class="mb-2" maxlength="500" placeholder="สรุปสั้นๆ ว่าวิดีโอนี้เกี่ยวกับอะไร"><?= e(old('description', $edit['description'] ?? '')) ?></textarea>

    <div class="form-row">
      <div><label>ความยาว <span class="text-muted">(ไม่บังคับ)</span></label>
        <input type="text" name="duration" value="<?= e(old('duration', $edit['duration'] ?? '')) ?>" placeholder="เช่น 5:42" class="mb-2"></div>
      <div><label>วันที่เผยแพร่ <span class="text-muted">(ไม่บังคับ)</span></label>
        <input type="date" name="published_at" value="<?= e(old('published_at', $edit['published_at'] ?? '')) ?>" class="mb-2"></div>
    </div>

    <label>ภาพปกของตัวเอง <span class="text-muted">(ไม่บังคับ — ปกติไม่ต้องใส่ ระบบใช้ภาพปกจาก YouTube ให้อยู่แล้ว)</span></label>
    <?php if (!empty($edit['cover'])): ?>
    <div class="current-file"><img src="<?= e(url($edit['cover'])) ?>" alt="">ภาพปกปัจจุบัน
      <label class="inline-check" style="margin:0;"><input type="checkbox" name="remove_cover" value="1">ลบภาพปก (กลับไปใช้ของ YouTube)</label></div>
    <?php endif; ?>
    <div class="dropzone mb-2" style="padding:16px;">
      <input type="file" name="cover" accept=".jpg,.jpeg,.png,.webp">
      <span class="material-symbols-rounded icon-lg" style="color:var(--blue)">add_photo_alternate</span>
      <p style="margin:4px 0 0;font-size:13px;">คลิกเลือกหรือลากภาพมาวาง</p>
      <div class="dz-filename"></div>
    </div>

    <div class="flex gap-2 items-center" style="flex-wrap:wrap;">
      <div><label>สถานะ</label>
        <select name="status" style="width:150px;">
          <option value="published" <?= ($edit['status'] ?? 'published') === 'published' ? 'selected' : '' ?>>เผยแพร่</option>
          <option value="draft" <?= ($edit['status'] ?? '') === 'draft' ? 'selected' : '' ?>>ซ่อนไว้ก่อน</option>
        </select>
      </div>
      <div><label>ลำดับ</label><input type="number" name="sort_order" value="<?= (int)($edit['sort_order'] ?? (count($videos) + 1)) ?>" class="sort-input"></div>
      <button class="btn primary" type="submit" style="margin-top:20px;"><span class="material-symbols-rounded icon-sm">save</span><?= $edit ? 'บันทึก' : 'เพิ่มวิดีโอ' ?></button>
    </div>
  </form>
</div>

<div class="card">
  <div class="section-head" style="margin-bottom:8px;">
    <div><span class="tag">ALL VIDEOS</span><h3>วิดีโอทั้งหมด (<?= count($videos) ?>)</h3></div>
    <?php if ($videos): ?><a class="btn small" href="<?= e(url('videos.php')) ?>" target="_blank" rel="noopener"><span class="material-symbols-rounded icon-sm">open_in_new</span>ดูหน้าจริง</a><?php endif; ?>
  </div>
  <?php if ($videos): ?>
  <div data-sortlist data-endpoint="videos.php" data-csrf="<?= e(csrf_token()) ?>">
    <p class="text-muted mb-1" style="font-size:13px;">ลากไอคอน <span class="material-symbols-rounded icon-sm" style="vertical-align:-4px;">drag_indicator</span> เพื่อจัดลำดับ — วิดีโอบนสุดจะขึ้นเป็นจอใหญ่ในหน้าแรก</p>
    <ul class="doc-sort">
      <?php foreach ($videos as $i => $v): ?>
      <li class="doc-li" draggable="true" data-id="<?= (int)$v['id'] ?>">
        <div class="doc-row">
          <span class="doc-grip material-symbols-rounded">drag_indicator</span>
          <img src="<?= e(video_thumb_url($v)) ?>" alt="" loading="lazy"
               style="width:64px;aspect-ratio:16/9;object-fit:cover;border-radius:6px;background:#0b0b0f;flex:none;">
          <span class="doc-name">
            <?= e(mb_strimwidth($v['title'], 0, 60, '…')) ?>
            <?php if ($i === 0 && $v['status'] === 'published'): ?><span class="badge" style="font-size:10px;">จอใหญ่</span><?php endif; ?>
          </span>
          <?php if ($v['status'] === 'draft'): ?><span class="badge" style="font-size:11px;">ซ่อนอยู่</span><?php endif; ?>
          <?php if ($v['duration']): ?><span class="lr-date" style="font-size:12px;"><?= e($v['duration']) ?></span><?php endif; ?>
          <span class="doc-actions">
            <a class="btn small" href="https://www.youtube.com/watch?v=<?= e($v['video_id']) ?>" target="_blank" rel="noopener" title="เปิดใน YouTube"><span class="material-symbols-rounded icon-sm">smart_display</span></a>
            <a class="btn small" href="<?= e(url('admin/videos.php?edit=' . $v['id'])) ?>">แก้ไข</a>
            <form method="post" action="" style="display:inline;">
              <?= csrf_field() ?>
              <input type="hidden" name="delete_id" value="<?= (int)$v['id'] ?>">
              <button class="btn small danger" type="submit" data-confirm="ยืนยันลบวิดีโอนี้? (กู้คืนได้จากถังขยะ)">ลบ</button>
            </form>
          </span>
        </div>
      </li>
      <?php endforeach; ?>
    </ul>
  </div>
  <?php else: ?>
  <div class="text-center text-muted" style="padding:28px;">
    <span class="material-symbols-rounded icon-lg">smart_display</span>
    <p style="margin:8px 0 0;">ยังไม่มีวิดีโอ — เพิ่มวิดีโอแรกได้จากฟอร์มด้านบน</p>
  </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/_bottom.php'; ?>
