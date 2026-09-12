<?php
/** admin/slides.php — จัดการแบนเนอร์สไลด์ (ตัวจัดเต็ม: ลูกเล่นภาพ + แต่งข้อความ + ปุ่ม/ไล่สี) */
require dirname(__DIR__) . '/includes/init.php';
require __DIR__ . '/_auth.php';

$errors = [];
$edit = null;

/* ── ตั้งค่าสไลเดอร์รวม (ความเร็ว + รูปแบบเปลี่ยนสไลด์) ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'slider_settings') {
    $iv = max(2000, min(15000, (int)($_POST['slider_interval_sec'] ?? 6) * 1000));
    $tr = in_array($_POST['slider_transition'] ?? '', ['fade', 'slide'], true) ? $_POST['slider_transition'] : 'fade';
    setting_set('slider_interval', (string)$iv);
    setting_set('slider_transition', $tr);
    flash_set('success', 'บันทึกการตั้งค่าสไลเดอร์แล้ว');
    redirect('admin/slides.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['action'])) {
    /* ลบสไลด์ */
    if (isset($_POST['delete_id'])) {
        $id = (int)$_POST['delete_id'];
        if (trash_delete('slides', $id, $ADMIN['username'] ?? '')) {
            log_action('ลบสไลด์ (ลงถังขยะ)', '#' . $id);
            flash_set('success', 'ย้ายสไลด์ลงถังขยะแล้ว — กู้คืนได้ที่เมนู "ถังขยะ"');
        }
        redirect('admin/slides.php');
    }

    /* เพิ่ม/แก้ไขสไลด์ */
    $id        = (int)($_POST['id'] ?? 0);
    $title     = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 250);
    $subtitle  = mb_substr(trim((string)($_POST['subtitle'] ?? '')), 0, 500);
    $link_text = mb_substr(trim((string)($_POST['link_text'] ?? '')), 0, 100);
    $link_url  = safe_link_url((string)($_POST['link_url'] ?? ''));
    $sort      = (int)($_POST['sort_order'] ?? 0);
    $enabled   = !empty($_POST['enabled']) ? 1 : 0;
    /* ── จัดวาง ── */
    $focus_x   = max(0, min(100, (int)($_POST['focus_x'] ?? 50)));
    $focus_y   = max(0, min(100, (int)($_POST['focus_y'] ?? 50)));
    /* ── ครอบตัดภาพ (ratio 0..1) ── */
    $crop_apply = !empty($_POST['crop_apply']);
    $crop_x = max(0, min(1, (float)($_POST['crop_x'] ?? 0)));
    $crop_y = max(0, min(1, (float)($_POST['crop_y'] ?? 0)));
    $crop_w = max(0.02, min(1, (float)($_POST['crop_w'] ?? 1)));
    $crop_h = max(0.02, min(1, (float)($_POST['crop_h'] ?? 1)));
    if ($crop_apply) { $focus_x = 50; $focus_y = 50; }   /* ครอบตัดแล้วภาพพอดีเฟรม ไม่ต้องใช้จุดโฟกัส */
    $ta_in     = (string)($_POST['text_align'] ?? '');
    $t_align   = in_array($ta_in, ['left','center','right'], true) ? $ta_in : 'center';
    $tv_in     = (string)($_POST['text_valign'] ?? '');
    $t_valign  = in_array($tv_in, ['top','middle','bottom'], true) ? $tv_in : 'middle';
    $t_theme   = ($_POST['text_theme'] ?? '') === 'dark' ? 'dark' : 'light';
    $overlay   = max(0, min(80, (int)($_POST['overlay'] ?? 35)));
    /* ── v1.1.0: ลูกเล่นภาพ ── */
    $kenburns  = !empty($_POST['kenburns']) ? 1 : 0;
    $duration  = max(0, min(30, (int)($_POST['duration'] ?? 0)));
    /* ── แต่งข้อความ ── */
    $ts_in      = (string)($_POST['title_size'] ?? '');
    $title_size = in_array($ts_in, ['sm','md','lg','xl'], true) ? $ts_in : 'md';
    $text_color = (!empty($_POST['text_color_on']) && valid_hex((string)($_POST['text_color'] ?? ''))) ? $_POST['text_color'] : '';
    $text_shadow = !empty($_POST['text_shadow']) ? 1 : 0;
    /* ── ปุ่ม ── */
    $bs_in     = (string)($_POST['btn_style'] ?? '');
    $btn_style = in_array($bs_in, ['solid','outline'], true) ? $bs_in : 'solid';
    $btn_color = (!empty($_POST['btn_color_on']) && valid_hex((string)($_POST['btn_color'] ?? ''))) ? $_POST['btn_color'] : '';
    $link_text2 = mb_substr(trim((string)($_POST['link_text2'] ?? '')), 0, 100);
    $link_url2  = safe_link_url((string)($_POST['link_url2'] ?? ''));
    /* ── พื้นหลังไล่สี (เมื่อไม่มีรูป) ── */
    $grad_on   = !empty($_POST['grad_on']);
    $grad_from = ($grad_on && valid_hex((string)($_POST['grad_from'] ?? ''))) ? $_POST['grad_from'] : '';
    $grad_to   = ($grad_on && valid_hex((string)($_POST['grad_to'] ?? ''))) ? $_POST['grad_to'] : '';

    /* ข้อความไม่บังคับ — แต่สไลด์ต้องมีอย่างน้อย: รูปภาพ / ข้อความ / พื้นหลังไล่สี */
    $has_new_img = !empty($_FILES['image']['name']);
    $has_old_img = false;
    if ($id > 0) {
        $st = db()->prepare('SELECT image FROM slides WHERE id = ?');
        $st->execute([$id]);
        $has_old_img = !empty($st->fetchColumn());
    }
    if ($title === '' && $subtitle === '' && !$has_new_img && !$has_old_img && !$grad_on) {
        $errors[] = 'สไลด์ต้องมีอย่างน้อยรูปภาพ หรือข้อความ (อย่างใดอย่างหนึ่ง)';
    }

    $image = null;
    if (!$errors) {
        try { $image = handle_upload('image', 'slides', upload_image_exts(), 8); }
        catch (RuntimeException $ex) { $errors[] = $ex->getMessage(); }
    }

    if (!$errors) {
        $cols = ['title','subtitle','link_text','link_url','sort_order','enabled','focus_x','focus_y',
                 'text_align','text_valign','text_theme','overlay','kenburns','duration','title_size',
                 'text_color','text_shadow','btn_style','btn_color','link_text2','link_url2','grad_from','grad_to'];
        $vals = [$title,$subtitle,$link_text,$link_url,$sort,$enabled,$focus_x,$focus_y,
                 $t_align,$t_valign,$t_theme,$overlay,$kenburns,$duration,$title_size,
                 $text_color,$text_shadow,$btn_style,$btn_color,$link_text2,$link_url2,$grad_from,$grad_to];
        $finalImage = null;
        if ($id > 0) {
            $st = db()->prepare('SELECT image FROM slides WHERE id = ?');
            $st->execute([$id]);
            $old = $st->fetch();
            if ($image && $old) delete_upload($old['image']);
            $set = implode('=?, ', $cols) . '=?, image=COALESCE(?, image)';
            $vals2 = array_merge($vals, [$image['path'] ?? null, $id]);
            db()->prepare("UPDATE slides SET $set WHERE id=?")->execute($vals2);
            $finalImage = $image['path'] ?? ($old['image'] ?? null);
            flash_set('success', 'บันทึกสไลด์เรียบร้อยแล้ว');
        } else {
            $allc = array_merge($cols, ['image']);
            $ph = implode(',', array_fill(0, count($allc), '?'));
            $vals2 = array_merge($vals, [$image['path'] ?? null]);
            db()->prepare('INSERT INTO slides (' . implode(',', $allc) . ") VALUES ($ph)")->execute($vals2);
            $finalImage = $image['path'] ?? null;
            flash_set('success', 'เพิ่มสไลด์เรียบร้อยแล้ว');
        }
        /* ครอบตัดไฟล์ภาพให้พอดีแบนเนอร์ 1600×640 (เมื่อผู้ใช้ปรับกรอบ) */
        if ($crop_apply && $finalImage) {
            $abs = APP_ROOT . '/' . $finalImage;
            $real = realpath($abs);
            $base = realpath(APP_ROOT . '/uploads');
            if ($real && $base && str_starts_with($real, $base)) {
                crop_image($real, $crop_x, $crop_y, $crop_w, $crop_h, 1600, 640);
            }
        }
        redirect('admin/slides.php');
    }
}

if (isset($_GET['edit'])) {
    $st = db()->prepare('SELECT * FROM slides WHERE id = ?');
    $st->execute([(int)$_GET['edit']]);
    $edit = $st->fetch();
}

$slides = db()->query('SELECT * FROM slides ORDER BY sort_order ASC, id ASC')->fetchAll();
$g_interval   = (int)setting('slider_interval', '6000');
$g_transition = setting('slider_transition', 'fade');

/* ค่าเริ่มต้นสำหรับฟอร์ม */
$fx = (int)($edit['focus_x'] ?? 50); $fy = (int)($edit['focus_y'] ?? 50);
$ta = $edit['text_align'] ?? 'center'; $tv = $edit['text_valign'] ?? 'middle';
$tt = $edit['text_theme'] ?? 'light'; $ov = (int)($edit['overlay'] ?? 35);
$e_kb   = (int)($edit['kenburns'] ?? 0);
$e_dur  = (int)($edit['duration'] ?? 0);
$e_tsz  = $edit['title_size'] ?? 'md';
$e_tcol = $edit['text_color'] ?? '';
$e_tsh  = isset($edit) ? (int)($edit['text_shadow'] ?? 1) : 1;
$e_bsty = $edit['btn_style'] ?? 'solid';
$e_bcol = $edit['btn_color'] ?? '';
$e_lt2  = $edit['link_text2'] ?? '';
$e_lu2  = $edit['link_url2'] ?? '';
$e_gf   = $edit['grad_from'] ?? '';
$e_gt   = $edit['grad_to'] ?? '';
$imgUrl = !empty($edit['image']) ? url($edit['image']) : '';
$sizePx = ['sm' => 26, 'md' => 34, 'lg' => 44, 'xl' => 56];

$admin_title = 'แบนเนอร์สไลด์';
require __DIR__ . '/_top.php';
?>
<!-- ── ตั้งค่าสไลเดอร์รวม ── -->
<div class="card mb-2">
  <div class="section-head" style="margin-bottom:8px;">
    <div><span class="tag"><span class="material-symbols-rounded icon-sm">tune</span> SLIDER</span><h3>ตั้งค่าสไลเดอร์รวม</h3>
    <p>ใช้กับสไลด์ทุกอัน — ความเร็วเปลี่ยนและรูปแบบการเปลี่ยน</p></div>
  </div>
  <form method="post" action="" class="flex gap-2 items-center" style="flex-wrap:wrap;">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="slider_settings">
    <div><label>ความเร็วเปลี่ยน (วินาที)</label>
      <input type="number" name="slider_interval_sec" min="2" max="15" step="1" value="<?= round($g_interval / 1000) ?>" class="sort-input" style="width:90px;"></div>
    <div><label>รูปแบบการเปลี่ยน</label>
      <select name="slider_transition" style="width:150px;">
        <option value="fade" <?= $g_transition === 'fade' ? 'selected' : '' ?>>จางหาย (Fade)</option>
        <option value="slide" <?= $g_transition === 'slide' ? 'selected' : '' ?>>เลื่อนเข้า (Slide)</option>
      </select></div>
    <button class="btn small" type="submit" style="margin-top:20px;"><span class="material-symbols-rounded icon-sm">save</span>บันทึกค่ารวม</button>
  </form>
</div>

<div class="card mb-2">
  <div class="section-head" style="margin-bottom:8px;">
    <div><span class="tag">SLIDE</span><h3><?= $edit ? 'แก้ไขสไลด์' : 'เพิ่มสไลด์ใหม่' ?></h3></div>
    <?php if ($edit): ?><a class="btn small" href="<?= e(url('admin/slides.php')) ?>">ยกเลิกการแก้ไข</a><?php endif; ?>
  </div>

  <?php if ($errors): ?>
  <div class="alert danger"><span class="material-symbols-rounded">error</span>
    <div><?php foreach ($errors as $er): ?><div><?= e($er) ?></div><?php endforeach; ?></div>
  </div>
  <?php endif; ?>

  <form method="post" action="" enctype="multipart/form-data" id="slideForm">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
    <input type="hidden" name="focus_x" id="focus_x" value="<?= $fx ?>">
    <input type="hidden" name="focus_y" id="focus_y" value="<?= $fy ?>">
    <input type="hidden" name="text_align" id="text_align" value="<?= e($ta) ?>">
    <input type="hidden" name="text_valign" id="text_valign" value="<?= e($tv) ?>">
    <input type="hidden" name="text_theme" id="text_theme" value="<?= e($tt) ?>">
    <!-- ครอบตัด (ratio 0..1 ของภาพต้นฉบับ) — JS เขียนค่าให้ -->
    <input type="hidden" name="crop_apply" id="crop_apply" value="0">
    <input type="hidden" name="crop_x" id="crop_x" value="0">
    <input type="hidden" name="crop_y" id="crop_y" value="0">
    <input type="hidden" name="crop_w" id="crop_w" value="1">
    <input type="hidden" name="crop_h" id="crop_h" value="1">

    <!-- พรีวิว + ครอบตัด (WYSIWYG) -->
    <label>ตัวอย่าง & ครอบตัดภาพ <span class="text-muted">— ลากภาพเพื่อเลื่อน · เลื่อนแถบเพื่อซูม</span></label>
    <div class="slide-prev <?= $tt === 'dark' ? 'txt-dark' : '' ?><?= $e_tsh ? '' : ' no-shadow' ?>" id="slidePreview"
         data-img="<?= e($imgUrl) ?>"
         style="--ov:<?= $ov / 100 ?>;<?= ($e_gf && $e_gt) ? '--gf:' . e($e_gf) . ';--gt:' . e($e_gt) . ';' : '' ?>">
      <img id="spImg" alt="" <?= $imgUrl ? 'src="' . e($imgUrl) . '"' : '' ?><?= $imgUrl ? '' : ' style="display:none;"' ?>>
      <div class="sp-grad" id="spGrad"></div>
      <div class="sp-text" id="spText">
        <h2 id="spTitle" style="font-size:<?= $sizePx[$e_tsz] ?? 34 ?>px;<?= $e_tcol ? 'color:' . e($e_tcol) . ';' : '' ?><?= empty($edit['title']) ? 'display:none;' : '' ?>"><?= e($edit['title'] ?? '') ?></h2>
        <p id="spSub" <?= empty($edit['subtitle']) ? 'style="display:none;"' : '' ?>><?= e($edit['subtitle'] ?? '') ?></p>
        <div class="sp-btns">
          <span class="sp-btn" id="spBtn" <?= empty($edit['link_text']) ? 'style="display:none;"' : '' ?>><?= e($edit['link_text'] ?? '') ?></span>
          <span class="sp-btn sp-btn2" id="spBtn2" <?= empty($e_lt2) ? 'style="display:none;"' : '' ?>><?= e($e_lt2) ?></span>
        </div>
      </div>
    </div>
    <div class="crop-bar" id="cropBar" style="<?= $imgUrl ? '' : 'display:none;' ?>">
      <span class="material-symbols-rounded icon-sm">zoom_in</span>
      <input type="range" id="cropZoom" min="100" max="300" step="1" value="100" style="flex:1;">
      <button type="button" class="btn small" id="cropReset"><span class="material-symbols-rounded icon-sm">restart_alt</span>รีเซ็ต</button>
    </div>

    <div class="form-row mt-2">
      <!-- คอลัมน์ซ้าย: เนื้อหา + ปุ่ม -->
      <div>
        <div class="slide-fieldset">
          <div class="sf-head"><span class="material-symbols-rounded icon-sm">title</span>เนื้อหา</div>
          <label>หัวข้อ <span class="text-muted" style="font-weight:400;font-size:11.5px;">(ไม่บังคับ · กด Enter ขึ้นบรรทัดใหม่ · เว้นว่าง = โชว์แค่ภาพ)</span></label>
          <textarea name="title" id="sf_title" class="mb-2" rows="2" maxlength="250" style="resize:vertical;line-height:1.4;"><?= e(old('title', $edit['title'] ?? '')) ?></textarea>
          <label>คำอธิบายสั้น <span class="text-muted" style="font-weight:400;font-size:11.5px;">(ไม่บังคับ · ขึ้นหลายบรรทัดได้)</span></label>
          <textarea name="subtitle" id="sf_sub" class="mb-2" rows="3" maxlength="500" style="resize:vertical;line-height:1.4;"><?= e(old('subtitle', $edit['subtitle'] ?? '')) ?></textarea>
        </div>

        <div class="slide-fieldset">
          <div class="sf-head"><span class="material-symbols-rounded icon-sm">smart_button</span>ปุ่มกด(CTA)</div>
          <div class="form-row">
            <div><label>ปุ่มที่ 1 — ข้อความ</label><input type="text" name="link_text" id="sf_btn" value="<?= e(old('link_text', $edit['link_text'] ?? '')) ?>" placeholder="เช่น ดูรายละเอียด" class="mb-2"></div>
            <div><label>ลิงก์</label><input type="text" name="link_url" value="<?= e(old('link_url', $edit['link_url'] ?? '')) ?>" placeholder="https://..." class="mb-2"></div>
          </div>
          <div class="form-row">
            <div><label>ปุ่มที่ 2 — ข้อความ</label><input type="text" name="link_text2" id="sf_btn2" value="<?= e($e_lt2) ?>" placeholder="(ไม่บังคับ)" class="mb-2"></div>
            <div><label>ลิงก์</label><input type="text" name="link_url2" value="<?= e($e_lu2) ?>" placeholder="https://..." class="mb-2"></div>
          </div>
          <div class="flex gap-2 items-center" style="flex-wrap:wrap;">
            <div><label>สไตล์ปุ่ม</label>
              <select name="btn_style" id="sf_btnstyle" style="width:130px;">
                <option value="solid" <?= $e_bsty === 'solid' ? 'selected' : '' ?>>ทึบ (Solid)</option>
                <option value="outline" <?= $e_bsty === 'outline' ? 'selected' : '' ?>>ขอบ (Outline)</option>
              </select></div>
            <div><label>สีปุ่ม</label>
              <div class="color-field<?= $e_bcol ? '' : ' cleared' ?>">
                <input type="color" name="btn_color" id="sf_btncolor" value="<?= e($e_bcol ?: '#1a73e8') ?>" <?= $e_bcol ? '' : 'disabled' ?>>
                <label class="inline-check" style="margin:0;font-size:12px;"><input type="checkbox" name="btn_color_on" id="sf_btncolor_on" value="1" <?= $e_bcol ? 'checked' : '' ?>>กำหนดเอง</label>
              </div></div>
          </div>
        </div>

        <div class="flex gap-2 items-center mt-2">
          <div><label>ลำดับ</label><input type="number" name="sort_order" value="<?= (int)($edit['sort_order'] ?? (count($slides) + 1)) ?>" class="sort-input"></div>
          <label class="inline-check" style="margin-top:20px;"><input type="checkbox" name="enabled" value="1" <?= !isset($edit) || !empty($edit['enabled']) ? 'checked' : '' ?>>แสดงบนเว็บ</label>
        </div>
      </div>

      <!-- คอลัมน์ขวา: รูป + จัดวาง + ลูกเล่น + ข้อความ -->
      <div>
        <div class="slide-fieldset">
          <div class="sf-head"><span class="material-symbols-rounded icon-sm">image</span>รูปภาพ & การจัดวาง</div>
          <label>รูปภาพ <span class="text-muted">(แนะนำ 1600×640 — ไม่ใส่จะเป็นพื้นไล่สี)</span></label>
          <?php if (!empty($edit['image'])): ?>
          <div class="current-file"><img src="<?= e($imgUrl) ?>" alt="">รูปปัจจุบัน</div>
          <?php endif; ?>
          <div class="dropzone mb-2" style="padding:16px;">
            <input type="file" name="image" id="sf_img" accept=".jpg,.jpeg,.png,.webp">
            <span class="material-symbols-rounded icon-lg" style="color:var(--blue)">add_photo_alternate</span>
            <p style="margin:4px 0 0;font-size:13px;"><b>ลากรูปมาวาง</b> หรือคลิกเลือก</p>
            <div class="dz-filename"></div>
          </div>
          <label>ตำแหน่งข้อความ</label>
          <div class="pos-grid mb-2" id="posGrid">
            <?php foreach (['top','middle','bottom'] as $vy): foreach (['left','center','right'] as $vx):
              $on = ($vy === $tv && $vx === $ta); ?>
            <button type="button" class="pos-cell<?= $on ? ' on' : '' ?>" data-va="<?= $vy ?>" data-ha="<?= $vx ?>" aria-label="<?= $vy ?> <?= $vx ?>"></button>
            <?php endforeach; endforeach; ?>
          </div>
          <label>ความเข้มฉากหลัง — <span id="ovVal"><?= $ov ?></span>%</label>
          <input type="range" name="overlay" id="sf_overlay" min="0" max="80" step="5" value="<?= $ov ?>" style="width:100%;">
        </div>

        <div class="slide-fieldset">
          <div class="sf-head"><span class="material-symbols-rounded icon-sm">animation</span>ลูกเล่นภาพ</div>
          <label class="inline-check"><input type="checkbox" name="kenburns" id="sf_kenburns" value="1" <?= $e_kb ? 'checked' : '' ?>>ภาพค่อยๆ ซูม (Ken Burns)</label>
          <div class="mt-2"><label>ระยะแสดงสไลด์นี้ (วินาที) <span class="text-muted">— 0 = ใช้ค่ารวม</span></label>
            <input type="number" name="duration" min="0" max="30" step="1" value="<?= $e_dur ?>" class="sort-input"></div>
        </div>

        <div class="slide-fieldset">
          <div class="sf-head"><span class="material-symbols-rounded icon-sm">format_color_text</span>แต่งข้อความ</div>
          <label>ขนาดหัวข้อ</label>
          <div class="flex gap-1 mb-2" id="sizeGroup">
            <?php foreach (['sm' => 'เล็ก','md' => 'กลาง','lg' => 'ใหญ่','xl' => 'ใหญ่มาก'] as $sv => $sl): ?>
            <button type="button" class="btn small size-btn<?= $e_tsz === $sv ? ' primary' : '' ?>" data-size="<?= $sv ?>" data-px="<?= $sizePx[$sv] ?>"><?= $sl ?></button>
            <?php endforeach; ?>
            <input type="hidden" name="title_size" id="sf_titlesize" value="<?= e($e_tsz) ?>">
          </div>
          <div class="flex gap-2 items-center" style="flex-wrap:wrap;">
            <div><label>สีข้อความ (กำหนดเอง)</label>
              <div class="color-field<?= $e_tcol ? '' : ' cleared' ?>">
                <input type="color" name="text_color" id="sf_textcolor" value="<?= e($e_tcol ?: '#ffffff') ?>" <?= $e_tcol ? '' : 'disabled' ?>>
                <label class="inline-check" style="margin:0;font-size:12px;"><input type="checkbox" name="text_color_on" id="sf_textcolor_on" value="1" <?= $e_tcol ? 'checked' : '' ?>>กำหนดเอง</label>
              </div></div>
            <div><label>โทนสีตามธีม</label>
              <div class="flex gap-1">
                <button type="button" class="btn small theme-btn<?= $tt === 'light' ? ' primary' : '' ?>" data-theme="light"><span class="material-symbols-rounded icon-sm">light_mode</span>สว่าง</button>
                <button type="button" class="btn small theme-btn<?= $tt === 'dark' ? ' primary' : '' ?>" data-theme="dark"><span class="material-symbols-rounded icon-sm">dark_mode</span>เข้ม</button>
              </div></div>
          </div>
          <label class="inline-check mt-2"><input type="checkbox" name="text_shadow" id="sf_textshadow" value="1" <?= $e_tsh ? 'checked' : '' ?>>เงาข้อความ (อ่านง่ายบนภาพ)</label>
        </div>

        <div class="slide-fieldset">
          <?php $gradOn = ($e_gf && $e_gt); ?>
          <label class="inline-check mb-2"><input type="checkbox" name="grad_on" id="sf_grad_on" value="1" <?= $gradOn ? 'checked' : '' ?>>ใช้ไล่สีกำหนดเอง <span class="text-muted">(ไม่เลือก = ไล่สีตามธีม)</span></label>
          <div class="flex gap-2 items-center" style="flex-wrap:wrap;">
            <div><label>สีเริ่ม</label>
              <div class="color-field<?= $gradOn ? '' : ' cleared' ?>"><input type="color" name="grad_from" id="sf_gradfrom" value="<?= e($e_gf ?: '#1a73e8') ?>" <?= $gradOn ? '' : 'disabled' ?>></div></div>
            <div><label>สีจบ</label>
              <div class="color-field<?= $gradOn ? '' : ' cleared' ?>"><input type="color" name="grad_to" id="sf_gradto" value="<?= e($e_gt ?: '#7c3aed') ?>" <?= $gradOn ? '' : 'disabled' ?>></div></div>
          </div>
        </div>
      </div>
    </div>
    <button class="btn primary mt-2" type="submit"><span class="material-symbols-rounded icon-sm">save</span><?= $edit ? 'บันทึกการแก้ไข' : 'เพิ่มสไลด์' ?></button>
  </form>
</div>

<div class="card">
  <div class="section-head" style="margin-bottom:8px;">
    <div><span class="tag">ALL SLIDES</span><h3>สไลด์ทั้งหมด (<?= count($slides) ?>)</h3></div>
  </div>
  <?php if ($slides): ?>
  <table class="admin-table">
    <tr><th>ลำดับ</th><th>รูป</th><th>หัวข้อ</th><th>ลูกเล่น</th><th>สถานะ</th><th></th></tr>
    <?php foreach ($slides as $s): ?>
    <tr>
      <td><?= (int)$s['sort_order'] ?></td>
      <td><?php if ($s['image']): ?><img class="thumb-sm" src="<?= e(url($s['image'])) ?>" alt=""><?php else: ?><span class="text-muted" style="font-size:12px;">ไล่สี</span><?php endif; ?></td>
      <td><?= e(mb_strimwidth($s['title'], 0, 50, '…')) ?></td>
      <td style="font-size:11px;color:var(--text);">
        <?php if (!empty($s['kenburns'])): ?><span class="badge" style="font-size:10px;">ซูม</span> <?php endif; ?>
        <?php if (!empty($s['link_text2'])): ?><span class="badge" style="font-size:10px;">2 ปุ่ม</span> <?php endif; ?>
        <?php if (($s['title_size'] ?? 'md') !== 'md'): ?><span class="badge" style="font-size:10px;">หัวข้อ <?= e($s['title_size']) ?></span><?php endif; ?>
      </td>
      <td><?= $s['enabled'] ? '<span class="badge success" style="font-size:11px;">แสดง</span>' : '<span class="badge" style="font-size:11px;">ซ่อน</span>' ?></td>
      <td style="white-space:nowrap;">
        <a class="btn small" href="<?= e(url('admin/slides.php?edit=' . $s['id'])) ?>">แก้ไข</a>
        <form method="post" action="" style="display:inline;">
          <?= csrf_field() ?>
          <input type="hidden" name="delete_id" value="<?= (int)$s['id'] ?>">
          <button class="btn small danger" type="submit" data-confirm="ยืนยันลบสไลด์นี้?">ลบ</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php else: ?>
  <p class="text-muted text-center" style="padding:24px;">ยังไม่มีสไลด์</p>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/_bottom.php'; ?>
