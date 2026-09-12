<?php
/** admin/theme.php — ธีมสีเว็บไซต์ + ระดับอนิเมชัน */
require dirname(__DIR__) . '/includes/init.php';
require __DIR__ . '/_auth.php';
require_admin();

/* สีสำเร็จรูป 6 สี: [ชื่อ, สีหลัก, สีเข้ม] */
$presets = [
    ['น้ำเงิน (ดีฟอลต์)', '#1A73E8', '#1557B0'],
    ['กรมท่า',            '#0F4C9C', '#093567'],
    ['เขียว',              '#16A34A', '#0F7A36'],
    ['ม่วง',               '#7C3AED', '#5B21B6'],
    ['ส้ม',                '#EA580C', '#B23F06'],
    ['เลือดหมู',           '#B91C1C', '#7F1212'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $color = strtoupper(trim((string)($_POST['theme_color'] ?? '')));
    /* เลือก "กำหนดเอง" → ใช้ค่าจาก color picker */
    if ($color === '') $color = strtoupper(trim((string)($_POST['custom_color'] ?? '')));
    if (!valid_hex($color)) $color = '#1A73E8';

    /* ถ้าเป็นสีสำเร็จรูปใช้สีเข้มที่กำหนดไว้ ถ้าเลือกเองคำนวณอัตโนมัติ */
    $dark = shade_hex($color);
    foreach ($presets as [$n, $c, $d]) {
        if (strtoupper($c) === $color) { $dark = $d; break; }
    }

    $anim = in_array($_POST['anim_level'] ?? '', ['full', 'min', 'off'], true) ? $_POST['anim_level'] : 'full';

    setting_set('theme_color', $color);
    setting_set('theme_color_dark', strtoupper($dark));
    setting_set('anim_level', $anim);

    /* ── Appearance: ฟอนต์ / ความกว้าง / หัวเว็บ / footer ── */
    $fonts = site_fonts();
    setting_set('font_family', isset($fonts[$_POST['font_family'] ?? '']) ? $_POST['font_family'] : 'Prompt');
    setting_set('layout_width', in_array($_POST['layout_width'] ?? '', ['normal','wide','full'], true) ? $_POST['layout_width'] : 'normal');
    setting_set('header_layout', ($_POST['header_layout'] ?? '') === 'center' ? 'center' : 'left');
    setting_set('header_sticky', !empty($_POST['header_sticky']) ? '1' : '0');
    setting_set('footer_about', mb_substr(trim((string)($_POST['footer_about'] ?? '')), 0, 500));

    log_action('แก้ไขธีมเว็บไซต์', $color . ' / อนิเมชัน ' . $anim);
    flash_set('success', 'บันทึกธีมเรียบร้อยแล้ว — มีผลทั้งเว็บทันที');
    redirect('admin/theme.php');
}

$cur_color = valid_hex(setting('theme_color', '')) ? strtoupper(setting('theme_color')) : '#1A73E8';
$cur_anim  = setting('anim_level', 'full');
$is_preset = false;
foreach ($presets as [$n, $c, $d]) if (strtoupper($c) === $cur_color) $is_preset = true;
$fonts_list = site_fonts();
$cur_font   = isset($fonts_list[setting('font_family', 'Prompt')]) ? setting('font_family', 'Prompt') : 'Prompt';
$cur_lw     = setting('layout_width', 'normal');
$cur_hl     = setting('header_layout', 'left');
$cur_sticky = setting('header_sticky', '1');
$cur_footer = setting('footer_about', '');

$admin_title = 'ธีมเว็บไซต์';
require __DIR__ . '/_top.php';
?>
<form method="post" action="">
  <?= csrf_field() ?>
  <div class="card mb-2">
    <div class="section-head" style="margin-bottom:8px;">
      <div><span class="tag"><span class="material-symbols-rounded icon-sm">palette</span> THEME</span><h3>ธีมสีเว็บไซต์</h3>
      <p>เปลี่ยนสีหลักของทั้งเว็บได้ทันที — <b>คลิกเพื่อดูตัวอย่างสด</b> แล้วกดบันทึก</p></div>
    </div>
    <div class="flex gap-1" style="flex-wrap:wrap;align-items:center;">
      <?php foreach ($presets as [$name, $c, $d]): ?>
      <button class="cat<?= $cur_color === strtoupper($c) ? ' active' : '' ?>" type="button"
              data-theme-color="<?= e($c) ?>" data-theme-dark="<?= e($d) ?>">
        <i class="swatch" style="background:<?= e($c) ?>;"></i><?= e($name) ?>
        <input type="radio" name="theme_color" value="<?= e($c) ?>" <?= $cur_color === strtoupper($c) ? 'checked' : '' ?> style="display:none;">
      </button>
      <?php endforeach; ?>
      <label style="display:inline-flex;align-items:center;gap:8px;margin:0;font-size:13px;color:var(--muted);">
        กำหนดเอง
        <input type="radio" name="theme_color" value="" id="preset-custom" <?= !$is_preset ? 'checked' : '' ?> style="display:none;">
        <input type="color" id="custom-color" name="custom_color" value="<?= e($cur_color) ?>">
      </label>
    </div>
  </div>

  <div class="card mb-2">
    <div class="section-head" style="margin-bottom:8px;">
      <div><span class="tag"><span class="material-symbols-rounded icon-sm">animation</span> ANIMATION</span><h3>ระดับอนิเมชัน</h3>
      <p>บางหน่วยงานอาจต้องการเว็บนิ่งๆ — ผู้ใช้ที่ตั้งค่า "ลดการเคลื่อนไหว" ในเครื่อง จะไม่เห็นอนิเมชันเสมอไม่ว่าตั้งค่าใด</p></div>
    </div>
    <div class="grid grid-3">
      <label class="card card-compact inline-check" style="cursor:pointer;<?= $cur_anim === 'full' ? 'border-color:var(--blue);box-shadow:var(--shadow-blue);' : '' ?>">
        <input type="radio" name="anim_level" value="full" <?= $cur_anim === 'full' ? 'checked' : '' ?>>
        <div><b style="color:var(--ink);">จัดเต็ม (แนะนำ)</b><br><span class="text-muted" style="font-size:13px;">scroll reveal, count-up, Ken Burns, shimmer, ticker ครบ</span></div>
      </label>
      <label class="card card-compact inline-check" style="cursor:pointer;<?= $cur_anim === 'min' ? 'border-color:var(--blue);box-shadow:var(--shadow-blue);' : '' ?>">
        <input type="radio" name="anim_level" value="min" <?= $cur_anim === 'min' ? 'checked' : '' ?>>
        <div><b style="color:var(--ink);">น้อย</b><br><span class="text-muted" style="font-size:13px;">เฉพาะ fade และ hover พื้นฐาน</span></div>
      </label>
      <label class="card card-compact inline-check" style="cursor:pointer;<?= $cur_anim === 'off' ? 'border-color:var(--blue);box-shadow:var(--shadow-blue);' : '' ?>">
        <input type="radio" name="anim_level" value="off" <?= $cur_anim === 'off' ? 'checked' : '' ?>>
        <div><b style="color:var(--ink);">ปิดทั้งหมด</b><br><span class="text-muted" style="font-size:13px;">เว็บนิ่งสนิท ไม่มีการเคลื่อนไหว</span></div>
      </label>
    </div>
  </div>

  <div class="card mb-2">
    <div class="section-head" style="margin-bottom:8px;">
      <div><span class="tag"><span class="material-symbols-rounded icon-sm">tune</span> APPEARANCE</span><h3>หน้าตาเว็บไซต์</h3>
      <p>ฟอนต์ ความกว้างเนื้อหา และรูปแบบหัวเว็บ — มีผลทั้งเว็บ</p></div>
    </div>
    <div class="form-row">
      <div><label>ฟอนต์ตัวอักษร</label>
        <select name="font_family" class="mb-2">
          <?php foreach ($fonts_list as $fname => $_): ?>
          <option value="<?= e($fname) ?>" <?= $cur_font === $fname ? 'selected' : '' ?>><?= e($fname) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div><label>ความกว้างเนื้อหา</label>
        <select name="layout_width" class="mb-2">
          <option value="normal" <?= $cur_lw === 'normal' ? 'selected' : '' ?>>ปกติ (1180px)</option>
          <option value="wide" <?= $cur_lw === 'wide' ? 'selected' : '' ?>>กว้าง (1360px)</option>
          <option value="full" <?= $cur_lw === 'full' ? 'selected' : '' ?>>เต็มจอ (1640px)</option>
        </select></div>
    </div>
    <div class="form-row">
      <div><label>รูปแบบหัวเว็บ</label>
        <select name="header_layout" class="mb-2">
          <option value="left" <?= $cur_hl === 'left' ? 'selected' : '' ?>>โลโก้ซ้าย · เมนูขวา (ดีฟอลต์)</option>
          <option value="center" <?= $cur_hl === 'center' ? 'selected' : '' ?>>โลโก้กลาง · เมนูใต้</option>
        </select></div>
      <div><label>การปักหัวเว็บ</label>
        <label class="inline-check" style="margin-top:6px;"><input type="checkbox" name="header_sticky" value="1" <?= $cur_sticky === '1' ? 'checked' : '' ?>>ปักหัวเว็บไว้ด้านบนตลอด (sticky)</label></div>
    </div>
    <label>ข้อความแนะนำหน่วยงาน <span class="text-muted">(แสดงใต้โลโก้ในส่วนท้ายเว็บ)</span></label>
    <textarea name="footer_about" rows="2" class="mb-1" maxlength="500" placeholder="เช่น ที่อยู่ เวลาทำการ หรือคำอธิบายสั้นๆ"><?= e($cur_footer) ?></textarea>
  </div>

  <button class="btn primary large" type="submit"><span class="material-symbols-rounded">save</span>บันทึกธีม</button>
</form>

<div class="card mt-2">
  <div class="section-head" style="margin-bottom:8px;">
    <div><span class="tag"><span class="material-symbols-rounded icon-sm">security</span> SECURITY</span><h3>ความปลอดภัยของระบบ</h3></div>
  </div>
  <div class="grid grid-4">
    <div class="alert success" style="margin:0;"><span class="material-symbols-rounded">key</span><div style="font-size:13px;"><b>รหัสผ่าน</b><br>เข้ารหัส bcrypt</div></div>
    <div class="alert success" style="margin:0;"><span class="material-symbols-rounded">shield</span><div style="font-size:13px;"><b>กัน SQL Injection / XSS / CSRF</b><br>ทุกฟอร์ม</div></div>
    <div class="alert success" style="margin:0;"><span class="material-symbols-rounded">upload_file</span><div style="font-size:13px;"><b>ตรวจไฟล์อัปโหลด</b><br>whitelist + MIME จริง</div></div>
    <div class="alert warning" style="margin:0;"><span class="material-symbols-rounded">history</span><div style="font-size:13px;"><b>Login ผิด 5 ครั้ง</b><br>ล็อก 15 นาที + บันทึก log</div></div>
  </div>
</div>
<?php require __DIR__ . '/_bottom.php'; ?>
