<?php
/**
 * admin/posters.php — นิทรรศการโปสเตอร์: เพิ่ม/แก้ไข/ลบ/ลากจัดลำดับ + ตั้งลูกเล่นการแสดงผล
 *
 * บันทึกความกว้าง/สูงจริงของภาพลงฐานข้อมูลตอนอัปโหลด เพราะหน้าเว็บใช้ค่านี้
 * จองพื้นที่การ์ดไว้ล่วงหน้า ถ้าไม่มีจะเดาเป็น 3:4 แล้วแถวจะขยับตอนภาพโหลดจริง
 */
require dirname(__DIR__) . '/includes/init.php';
require __DIR__ . '/_auth.php';

$errors = [];
$edit = null;

/* ตัวเลือกลูกเล่น — กำหนดไว้ที่เดียว ใช้ทั้งตอนตรวจค่าที่ส่งมาและตอนวาดฟอร์ม */
$STYLES = [
    'cinema' => ['โรงฉาย (แนะนำ)', 'ฉายทีละใบเต็มเวที ฉากหลังเบลอจากภาพเดียวกัน ซูมช้าๆ แล้วค่อยๆ จางเปลี่ยน — ให้ความรู้สึกเหมือนวิดีโอพรีเซนต์'],
    'stage' => ['ไล่ระดับเวทีกลาง', 'ใบที่อยู่กลางเด่นเต็มที่ ใบข้างจางและเล็กลง — ดูหรูที่สุด เหมาะกับโปสเตอร์ไม่กี่ใบ'],
    'strip' => ['แถบเลื่อนต่อเนื่อง', 'เห็นหลายใบพร้อมกัน เลื่อนดูได้เรื่อยๆ — เหมาะเมื่อมีโปสเตอร์เยอะ'],
    'fade'  => ['จางสลับทีละใบ',    'แสดงทีละใบอยู่กับที่ ค่อยๆ จางเปลี่ยน — เรียบและนิ่งที่สุด'],
];
/* ความสูงคิดจากหน้าจอผู้ใช้ ไม่ใช่ตัวเลขตายตัว — จอกว้างเท่าไรโปสเตอร์ก็ใหญ่ตาม */
$HEIGHTS = [
    'sm'   => ['เตี้ย',   'ประหยัดพื้นที่หน้าแรก เหมาะกับโปสเตอร์แนวนอน'],
    'md'   => ['กลาง',   'พอดีเมื่อหน้าแรกมีหลาย section'],
    'lg'   => ['สูง (แนะนำ)', 'เน้นให้โปสเตอร์เป็นพระเอก — โปสเตอร์แนวตั้งจะใหญ่ชัด'],
    'full' => ['เต็มจอ', 'สูงเกือบเท่าหน้าจอผู้ชม — โปสเตอร์แนวตั้งจะใหญ่ที่สุด'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    /* ── ลากจัดลำดับ (fetch JSON) ── */
    if (($_POST['action'] ?? '') === 'reorder') {
        header('Content-Type: application/json');
        $ids = json_decode((string)($_POST['order'] ?? '[]'), true) ?: [];
        $upd = db()->prepare('UPDATE posters SET sort_order = ? WHERE id = ?');
        $i = 0;
        foreach ($ids as $pid) { $upd->execute([$i++, (int)$pid]); }
        echo json_encode(['ok' => true]);
        exit;
    }

    /* ── บันทึกลูกเล่นการแสดงผล ── */
    if (($_POST['action'] ?? '') === 'display') {
        $style  = isset($STYLES[$_POST['poster_style'] ?? '']) ? $_POST['poster_style'] : 'cinema';
        $height = isset($HEIGHTS[$_POST['poster_height'] ?? '']) ? $_POST['poster_height'] : 'lg';
        $ivl    = max(2000, min(30000, (int)($_POST['poster_interval'] ?? 5000)));
        setting_set('poster_style', $style);
        setting_set('poster_height', $height);
        setting_set('poster_interval', (string)$ivl);
        foreach (['poster_auto', 'poster_caption', 'poster_frame', 'poster_zoom'] as $k) {
            setting_set($k, empty($_POST[$k]) ? '0' : '1');
        }
        log_action('ตั้งค่าการแสดงผลโปสเตอร์', $style . ' / ' . $height);
        flash_set('success', 'บันทึกลูกเล่นการแสดงผลแล้ว');
        redirect('admin/posters.php');
    }

    if (isset($_POST['delete_id'])) {
        $id = (int)$_POST['delete_id'];
        if (trash_delete('posters', $id, $ADMIN['username'] ?? '')) {
            log_action('ลบโปสเตอร์ (ลงถังขยะ)', '#' . $id);
            flash_set('success', 'ย้ายโปสเตอร์ลงถังขยะแล้ว — กู้คืนได้ที่เมนู "ถังขยะ"');
        }
        redirect('admin/posters.php');
    }

    $id      = (int)($_POST['id'] ?? 0);
    $title   = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 200);
    $caption = mb_substr(trim((string)($_POST['caption'] ?? '')), 0, 400);
    $link    = mb_substr(trim((string)($_POST['link_url'] ?? '')), 0, 300);
    $enabled = empty($_POST['enabled']) ? 0 : 1;
    $sort    = (int)($_POST['sort_order'] ?? 0);

    $image = null;
    try { $image = handle_upload('image', 'posters', upload_image_exts(), 8); }
    catch (RuntimeException $ex) { $errors[] = $ex->getMessage(); }

    if ($id === 0 && !$image) $errors[] = 'กรุณาเลือกไฟล์ภาพโปสเตอร์';

    if (!$errors) {
        /* อ่านขนาดจริงจากไฟล์ที่เพิ่งบันทึก — ไม่เชื่อค่าที่ฝั่งเบราว์เซอร์ส่งมา */
        $w = $h = 0;
        if ($image) {
            $sz = @getimagesize(APP_ROOT . '/' . $image['path']);
            if ($sz) { $w = (int)$sz[0]; $h = (int)$sz[1]; }
        }

        if ($id > 0) {
            $st = db()->prepare('SELECT image, img_w, img_h FROM posters WHERE id = ?');
            $st->execute([$id]);
            $old = $st->fetch();
            if ($image && $old) delete_upload($old['image']);
            $img = $image['path'] ?? ($old['image'] ?? '');
            if (!$image && $old) { $w = (int)$old['img_w']; $h = (int)$old['img_h']; }
            db()->prepare('UPDATE posters SET title=?, caption=?, image=?, img_w=?, img_h=?, link_url=?, enabled=?, sort_order=? WHERE id=?')
                ->execute([$title, $caption, $img, $w, $h, $link, $enabled, $sort, $id]);
            log_action('แก้ไขโปสเตอร์', $title !== '' ? $title : '#' . $id);
        } else {
            db()->prepare('INSERT INTO posters (title, caption, image, img_w, img_h, link_url, enabled, sort_order) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([$title, $caption, $image['path'], $w, $h, $link, $enabled, $sort]);
            log_action('เพิ่มโปสเตอร์', $title !== '' ? $title : basename($image['path']));
        }
        flash_set('success', 'บันทึกโปสเตอร์เรียบร้อยแล้ว');
        redirect('admin/posters.php');
    }
}

if (isset($_GET['edit'])) {
    $st = db()->prepare('SELECT * FROM posters WHERE id = ?');
    $st->execute([(int)$_GET['edit']]);
    $edit = $st->fetch();
}

$posters = db()->query('SELECT * FROM posters ORDER BY sort_order ASC, id DESC')->fetchAll();
$sec_on  = section_on('poster');
$cur     = [
    'style'    => setting('poster_style', 'cinema'),
    'height'   => setting('poster_height', 'lg'),
    'auto'     => setting('poster_auto', '1') === '1',
    'interval' => (int)setting('poster_interval', '5000'),
    'caption'  => setting('poster_caption', '1') === '1',
    'frame'    => setting('poster_frame', '1') === '1',
    'zoom'     => setting('poster_zoom', '1') === '1',
];
$admin_title = 'นิทรรศการโปสเตอร์';
require __DIR__ . '/_top.php';
?>
<?php if ($errors): ?>
<div class="alert danger"><span class="material-symbols-rounded">error</span>
  <div><?php foreach ($errors as $er): ?><div><?= e($er) ?></div><?php endforeach; ?></div>
</div>
<?php endif; ?>

<?php if (!$sec_on): ?>
<div class="alert warning"><span class="material-symbols-rounded">visibility_off</span>
  <div>ตอนนี้ section นิทรรศการโปสเตอร์ถูกปิดอยู่ จึงยังไม่แสดงบนหน้าแรก —
    เปิดได้ที่ <a href="<?= e(url('admin/homepage.php')) ?>">การแสดงผลหน้าแรก</a></div>
</div>
<?php endif; ?>

<div class="card mb-2">
  <div class="section-head" style="margin-bottom:8px;">
    <div><span class="tag">POSTER</span><h3><?= $edit ? 'แก้ไขโปสเตอร์' : 'เพิ่มโปสเตอร์ใหม่' ?></h3>
    <p>ใส่ได้ทั้งแนวตั้งและแนวนอน ไม่ต้องครอปให้เท่ากันก่อน — ระบบจัดให้สูงเท่ากันเองโดยไม่ตัดขอบภาพ
      <?php if ($sec_on): ?>· ทำภาพใหม่ได้ที่ <a href="<?= e(url('admin/graphic.php')) ?>">ออกแบบภาพประกาศ</a><?php endif; ?></p></div>
    <?php if ($edit): ?><a class="btn small" href="<?= e(url('admin/posters.php')) ?>">ยกเลิก</a><?php endif; ?>
  </div>
  <form method="post" action="" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">

    <label>ไฟล์ภาพ <?= $edit ? '<span class="text-muted">(เว้นไว้ถ้าไม่เปลี่ยนภาพเดิม)</span>' : '<span style="color:var(--danger);">*</span>' ?></label>
    <?php if (!empty($edit['image'])): ?>
    <div class="current-file">
      <img src="<?= e(url($edit['image'])) ?>" alt="">
      ภาพปัจจุบัน<?= (int)$edit['img_w'] > 0 ? ' — ' . (int)$edit['img_w'] . '×' . (int)$edit['img_h'] . ' พิกเซล' : '' ?>
    </div>
    <?php endif; ?>
    <div class="dropzone mb-2" style="padding:16px;">
      <input type="file" name="image" accept=".jpg,.jpeg,.png,.webp">
      <span class="material-symbols-rounded icon-lg" style="color:var(--blue)">add_photo_alternate</span>
      <p style="margin:4px 0 0;font-size:13px;">คลิกเลือกหรือลากภาพมาวาง (ไม่เกิน 8 MB)</p>
      <div class="dz-filename"></div>
    </div>

    <label>ชื่อโปสเตอร์ <span class="text-muted">(ไม่บังคับ — แสดงใต้ภาพ และใช้เป็นคำอธิบายภาพสำหรับผู้พิการทางสายตา)</span></label>
    <input type="text" name="title" value="<?= e(old('title', $edit['title'] ?? '')) ?>" class="mb-2" maxlength="200"
           placeholder="เช่น รณรงค์ป้องกันการทุจริต ประจำปี 2569">

    <label>คำบรรยาย <span class="text-muted">(ไม่บังคับ)</span></label>
    <textarea name="caption" rows="2" class="mb-2" maxlength="400" placeholder="ข้อความสั้นๆ ใต้ชื่อโปสเตอร์"><?= e(old('caption', $edit['caption'] ?? '')) ?></textarea>

    <label>ลิงก์เมื่อคลิก <span class="text-muted">(ไม่บังคับ — เว้นว่างไว้ ภาพจะเปิดดูขนาดเต็มแทน)</span></label>
    <input type="text" name="link_url" value="<?= e(old('link_url', $edit['link_url'] ?? '')) ?>" class="mb-2" maxlength="300"
           placeholder="เช่น /news หรือ https://...">

    <div class="form-row">
      <div><label>ลำดับ</label>
        <input type="number" name="sort_order" value="<?= (int)($edit['sort_order'] ?? count($posters)) ?>" class="mb-2"></div>
      <div><label>สถานะ</label>
        <label class="inline-check" style="margin-top:8px;">
          <input type="checkbox" name="enabled" value="1" <?= old_checked('enabled', '1', (int)($edit['enabled'] ?? 1) === 1) ? 'checked' : '' ?>>
          <span>แสดงบนหน้าแรก</span>
        </label></div>
    </div>

    <div class="flex gap-2 items-center" style="flex-wrap:wrap;">
      <button class="btn primary" type="submit"><span class="material-symbols-rounded icon-sm">save</span>บันทึก</button>
    </div>
  </form>
</div>

<div class="card mb-2">
  <div class="section-head" style="margin-bottom:8px;">
    <div><span class="tag">DISPLAY</span><h3>ลูกเล่นการแสดงผล</h3>
    <p>ใช้กับทั้งกล่อง ไม่ใช่รายใบ — เปลี่ยนแล้วดูผลได้ทันทีที่หน้าแรก</p></div>
  </div>
  <form method="post" action="">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="display">

    <label>รูปแบบการเล่น</label>
    <div class="sec-grid mb-2" style="grid-template-columns:repeat(auto-fit,minmax(230px,1fr));">
      <?php foreach ($STYLES as $k => [$name, $why]): ?>
      <label class="inline-check" style="align-items:flex-start;">
        <input type="radio" name="poster_style" value="<?= e($k) ?>" <?= $cur['style'] === $k ? 'checked' : '' ?>>
        <span><b><?= e($name) ?></b><br><span class="text-muted" style="font-size:12.5px;"><?= e($why) ?></span></span>
      </label>
      <?php endforeach; ?>
    </div>

    <label>ความสูงของเวที</label>
    <div class="sec-grid mb-2" style="grid-template-columns:repeat(auto-fit,minmax(200px,1fr));">
      <?php foreach ($HEIGHTS as $k => [$name, $why]): ?>
      <label class="inline-check" style="align-items:flex-start;">
        <input type="radio" name="poster_height" value="<?= e($k) ?>" <?= $cur['height'] === $k ? 'checked' : '' ?>>
        <span><b><?= e($name) ?></b><br><span class="text-muted" style="font-size:12.5px;"><?= e($why) ?></span></span>
      </label>
      <?php endforeach; ?>
    </div>

    <label class="inline-check" style="margin-top:6px;">
      <input type="checkbox" name="poster_auto" value="1" <?= $cur['auto'] ? 'checked' : '' ?>>
      <span><b>เลื่อนเองอัตโนมัติ</b> — หยุดให้เองเมื่อผู้ชมเอาเมาส์ไปวางหรือกำลังใช้คีย์บอร์ดอยู่ในกล่อง</span>
    </label>
    <div class="form-row">
      <div><label>เปลี่ยนภาพทุกกี่วินาที</label>
        <input type="number" name="poster_interval" min="2000" max="30000" step="500"
               value="<?= (int)$cur['interval'] ?>" class="mb-2">
        <p class="text-muted" style="font-size:12px;margin-top:-4px;">หน่วยเป็นมิลลิวินาที (5000 = 5 วินาที)</p></div>
      <div></div>
    </div>

    <label class="inline-check">
      <input type="checkbox" name="poster_caption" value="1" <?= $cur['caption'] ? 'checked' : '' ?>>
      <span><b>แสดงชื่อและคำบรรยายใต้ภาพ</b></span>
    </label>
    <label class="inline-check">
      <input type="checkbox" name="poster_frame" value="1" <?= $cur['frame'] ? 'checked' : '' ?>>
      <span><b>ใส่กรอบมนและเงา</b> — ทำให้โปสเตอร์ดูลอยขึ้นมาจากพื้นหลัง</span>
    </label>
    <label class="inline-check mb-2">
      <input type="checkbox" name="poster_zoom" value="1" <?= $cur['zoom'] ? 'checked' : '' ?>>
      <span><b>คลิกที่ภาพเพื่อดูขนาดเต็ม</b> — จำเป็นมากกับโปสเตอร์แนวตั้งที่มีตัวหนังสือเยอะ อ่านจากขนาดย่อไม่ออก</span>
    </label>

    <button class="btn primary" type="submit"><span class="material-symbols-rounded icon-sm">save</span>บันทึกลูกเล่น</button>
  </form>
</div>

<div class="card">
  <div class="section-head" style="margin-bottom:8px;">
    <div><span class="tag">ALL POSTERS</span><h3>โปสเตอร์ทั้งหมด (<?= count($posters) ?>)</h3></div>
    <?php if ($posters): ?><a class="btn small" href="<?= e(url('index.php')) ?>#poster" target="_blank" rel="noopener"><span class="material-symbols-rounded icon-sm">open_in_new</span>ดูหน้าจริง</a><?php endif; ?>
  </div>
  <?php if ($posters): ?>
  <div data-sortlist data-endpoint="posters.php" data-csrf="<?= e(csrf_token()) ?>">
    <p class="text-muted mb-1" style="font-size:13px;">ลากไอคอน <span class="material-symbols-rounded icon-sm" style="vertical-align:-4px;">drag_indicator</span> เพื่อจัดลำดับการแสดง</p>
    <ul class="doc-sort">
      <?php foreach ($posters as $p): ?>
      <li class="doc-li" draggable="true" data-id="<?= (int)$p['id'] ?>">
        <div class="doc-row">
          <span class="doc-grip material-symbols-rounded">drag_indicator</span>
          <img src="<?= e(url($p['image'])) ?>" alt="" loading="lazy"
               style="width:52px;height:52px;object-fit:contain;border-radius:6px;background:var(--surface,#f5f7fb);flex:none;">
          <span class="doc-name">
            <?= e($p['title'] !== '' ? mb_strimwidth($p['title'], 0, 60, '…') : basename($p['image'])) ?>
            <?php if ((int)$p['img_w'] > 0): ?>
            <span class="badge" style="font-size:10px;"><?= (int)$p['img_w'] > (int)$p['img_h'] ? 'แนวนอน' : ((int)$p['img_w'] === (int)$p['img_h'] ? 'จัตุรัส' : 'แนวตั้ง') ?></span>
            <?php endif; ?>
          </span>
          <?php if ((int)$p['enabled'] !== 1): ?><span class="badge" style="font-size:11px;">ซ่อนอยู่</span><?php endif; ?>
          <?php if ((int)$p['img_w'] > 0): ?><span class="lr-date" style="font-size:12px;"><?= (int)$p['img_w'] ?>×<?= (int)$p['img_h'] ?></span><?php endif; ?>
          <span class="doc-actions">
            <a class="btn small" href="<?= e(url('admin/posters.php?edit=' . $p['id'])) ?>">แก้ไข</a>
            <form method="post" action="" style="display:inline;">
              <?= csrf_field() ?>
              <input type="hidden" name="delete_id" value="<?= (int)$p['id'] ?>">
              <button class="btn small danger" type="submit" data-confirm="ยืนยันลบโปสเตอร์นี้? (กู้คืนได้จากถังขยะ)">ลบ</button>
            </form>
          </span>
        </div>
      </li>
      <?php endforeach; ?>
    </ul>
  </div>
  <?php else: ?>
  <div class="text-center text-muted" style="padding:28px;">
    <span class="material-symbols-rounded icon-lg">gallery_thumbnail</span>
    <p style="margin:8px 0 0;">ยังไม่มีโปสเตอร์ — เพิ่มใบแรกได้จากฟอร์มด้านบน</p>
  </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/_bottom.php'; ?>
