<?php
/** admin/theme.php — ธีมสำเร็จรูป + สีทั้งชุด + ภาพฉากหลัง + หน้าตาเว็บ + อนิเมชัน */
require dirname(__DIR__) . '/includes/init.php';
require __DIR__ . '/_auth.php';
require_admin();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    /* ── ปุ่ม "คืนค่าเริ่มต้น" — ล้างทุกอย่างกลับเป็นธีมมาตรฐาน ── */
    if (!empty($_POST['reset_theme'])) {
        $d = theme_defaults();
        setting_set('theme_color', $d['color']);   setting_set('theme_color_dark', $d['dark']);
        setting_set('theme_ink', $d['ink']);       setting_set('theme_text', $d['text']);
        setting_set('theme_muted', $d['muted']);   setting_set('theme_bg', $d['bg']);
        setting_set('theme_card', $d['card']);     setting_set('theme_radius', $d['radius']);
        setting_set('theme_shadow', $d['shadow']); setting_set('theme_glow', $d['glow']);
        setting_set('font_family', $d['font']);    setting_set('layout_width', $d['width']);
        setting_set('header_layout', $d['header']);
        delete_upload(setting('bg_image'));
        setting_set('bg_image', ''); setting_set('bg_scope', 'off');
        log_action('คืนค่าธีมเริ่มต้น', '');
        flash_set('success', 'คืนค่าธีมเริ่มต้นเรียบร้อยแล้ว');
        redirect('admin/theme.php');
    }

    $d = theme_defaults();
    /* รับค่าสี: ถ้าไม่ใช่ hex ที่ถูกต้องให้ใช้ค่าเริ่มต้นแทน ไม่ปล่อยค่าเพี้ยนลงฐานข้อมูล */
    $hexIn = function (string $field, string $fallback): string {
        $v = strtoupper(trim((string)($_POST[$field] ?? '')));
        return valid_hex($v) ? $v : $fallback;
    };
    $color = $hexIn('theme_color', $d['color']);
    /* สีเข้มเว้นว่างไว้ = ให้ระบบคำนวณจากสีหลักเอง */
    $darkRaw = strtoupper(trim((string)($_POST['theme_color_dark'] ?? '')));
    $dark    = valid_hex($darkRaw) ? $darkRaw : strtoupper(shade_hex($color));

    setting_set('theme_color', $color);
    setting_set('theme_color_dark', $dark);
    setting_set('theme_ink',   $hexIn('theme_ink',   $d['ink']));
    setting_set('theme_text',  $hexIn('theme_text',  $d['text']));
    setting_set('theme_muted', $hexIn('theme_muted', $d['muted']));
    setting_set('theme_bg',    $hexIn('theme_bg',    $d['bg']));
    setting_set('theme_card',  $hexIn('theme_card',  $d['card']));
    setting_set('theme_radius', isset(theme_radius_sets()[$_POST['theme_radius'] ?? '']) ? $_POST['theme_radius'] : $d['radius']);
    setting_set('theme_shadow', isset(theme_shadow_sets()[$_POST['theme_shadow'] ?? '']) ? $_POST['theme_shadow'] : $d['shadow']);
    setting_set('theme_glow', !empty($_POST['theme_glow']) ? '1' : '0');

    $anim = in_array($_POST['anim_level'] ?? '', ['full', 'min', 'off'], true) ? $_POST['anim_level'] : 'full';
    setting_set('anim_level', $anim);

    /* ── หน้าตา: ฟอนต์ / ความกว้าง / หัวเว็บ / footer ── */
    $fonts = site_fonts();
    setting_set('font_family', isset($fonts[$_POST['font_family'] ?? '']) ? $_POST['font_family'] : 'Prompt');
    setting_set('layout_width', in_array($_POST['layout_width'] ?? '', ['normal','wide','full'], true) ? $_POST['layout_width'] : 'normal');
    setting_set('header_layout', ($_POST['header_layout'] ?? '') === 'center' ? 'center' : 'left');
    setting_set('header_sticky', !empty($_POST['header_sticky']) ? '1' : '0');
    setting_set('footer_about', mb_substr(trim((string)($_POST['footer_about'] ?? '')), 0, 500));

    /* ── ภาพฉากหลัง ── */
    try {
        $up = handle_upload('bg_image', 'theme', upload_image_exts(), 8);
        if ($up) { delete_upload(setting('bg_image')); setting_set('bg_image', $up['path']); }
        if (!empty($_POST['remove_bg'])) { delete_upload(setting('bg_image')); setting_set('bg_image', ''); }
    } catch (RuntimeException $ex) {
        $errors[] = 'ภาพฉากหลัง: ' . $ex->getMessage();
    }
    setting_set('bg_scope',   in_array($_POST['bg_scope'] ?? '', ['page','top'], true) ? $_POST['bg_scope'] : 'off');
    setting_set('bg_overlay', (string)max(0, min(95, (int)($_POST['bg_overlay'] ?? 70))));
    setting_set('bg_blur',    (string)max(0, min(20, (int)($_POST['bg_blur'] ?? 0))));
    setting_set('bg_focus_x', (string)max(0, min(100, (int)($_POST['bg_focus_x'] ?? 50))));
    setting_set('bg_focus_y', (string)max(0, min(100, (int)($_POST['bg_focus_y'] ?? 50))));

    log_action('แก้ไขธีมเว็บไซต์', $color . ' / อนิเมชัน ' . $anim . ' / ฉากหลัง ' . setting('bg_scope'));
    if ($errors) flash_set('danger', implode(' · ', $errors));
    else flash_set('success', 'บันทึกธีมเรียบร้อยแล้ว — มีผลทั้งเว็บทันที');
    redirect('admin/theme.php');
}

$T          = theme_tokens();
$fonts_list = site_fonts();
$cur_anim   = setting('anim_level', 'full');
$cur_sticky = setting('header_sticky', '1');
$cur_footer = setting('footer_about', '');
$cur_bgimg  = setting('bg_image', '');
$cur_scope  = in_array(setting('bg_scope', 'off'), ['page','top'], true) ? setting('bg_scope') : 'off';
$cur_ov     = max(0, min(95, (int)setting('bg_overlay', '70')));
$cur_blur   = max(0, min(20, (int)setting('bg_blur', '0')));
$cur_fx     = max(0, min(100, (int)setting('bg_focus_x', '50')));
$cur_fy     = max(0, min(100, (int)setting('bg_focus_y', '50')));

/* ค่าความต่างของสีตามเกณฑ์ WCAG — เว็บหน่วยงานราชการต้องผ่าน AA (4.5:1 สำหรับตัวอักษรปกติ)
   [ชื่อคู่สี, token ตัวอักษร, token พื้นหลัง] — token ถูกส่งต่อให้ JS คำนวณใหม่สดๆ ตอนปรับสี
   'on' = สีตัวอักษรบนปุ่ม ซึ่งระบบเลือกขาว/ดำให้เองตามความสว่างของสีหลัก */
$ct = [
    ['เนื้อความบนพื้นการ์ด',   'text',  'card'],
    ['หัวข้อบนพื้นการ์ด',      'ink',   'card'],
    ['ข้อความรองบนพื้นการ์ด',  'muted', 'card'],
    ['เนื้อความบนพื้นหน้าเว็บ','text',  'bg'],
    ['ตัวอักษรบนปุ่มสีหลัก',   'on',    'color'],
];
/** สีจริงของ token หนึ่งตัวสำหรับการวัดค่าความต่าง */
function ct_color(array $t, string $tok): string {
    return $tok === 'on' ? on_color($t['color']) : $t[$tok];
}

/** มินิพรีวิวหน้าเว็บหนึ่งชิ้น — ใช้ตัวแปร CSS ชุดเดียวกับหน้าเว็บจริงทุกตัว
 *  ธีมที่เห็นในตัวอย่างจึงเป็นธีมเดียวกับที่จะได้จริง ไม่ใช่ภาพถ่ายที่ล้าสมัย */
function tp_preview(array $t): string {
    $vars = theme_css_vars($t, true);
    $dark = theme_is_dark($t) ? ' tp-dark' : '';
    return '<span class="tp-prev' . $dark . '" style="' . e($vars) . '" aria-hidden="true">'
         . '<span class="tp-bar"><span class="tp-logo"></span><span class="tp-nav"><i></i><i></i><i></i></span></span>'
         . '<span class="tp-hero"><span class="tp-t"></span><span class="tp-s"></span><span class="tp-btn"></span></span>'
         . '<span class="tp-row"><span class="tp-c"><i></i><i></i></span><span class="tp-c"><i></i><i></i></span></span>'
         . '</span>';
}

$admin_title = 'ธีมเว็บไซต์';
require __DIR__ . '/_top.php';
?>
<?php /* ส่งตารางความโค้งมุมไปให้ JS ใช้ตอนวาดตัวอย่างสด — อ่านจาก PHP ตัวจริง
        จะได้ไม่ต้องเขียนค่าซ้ำไว้ในไฟล์ JS แล้วลืมแก้ตามกันภายหลัง */ ?>
<form method="post" action="" enctype="multipart/form-data" id="themeForm"
      data-radius-map="<?= e(json_encode(array_map(fn($r) => [$r[0], $r[1]], theme_radius_sets()))) ?>">
  <?= csrf_field() ?>

  <!-- ══ ธีมสำเร็จรูป ══ -->
  <div class="card mb-2">
    <div class="section-head" style="margin-bottom:10px;">
      <div><span class="tag"><span class="material-symbols-rounded icon-sm">palette</span> THEME</span><h3>ธีมสำเร็จรูป</h3>
      <p>คลิกชุดที่ชอบ ระบบจะเติมสี ฟอนต์ ความโค้งมุม และเงาให้ครบทั้งชุด — <b>แก้รายตัวต่อได้ด้านล่าง</b> แล้วกดบันทึก</p></div>
    </div>
    <div class="tp-grid">
      <?php foreach (theme_presets() as $pk => $p):
        $pt = theme_preset_tokens($pk);
        /* ส่ง token ไปให้ JS เติมลงช่องต่างๆ ตอนคลิก — ค่าชุดเดียวกับที่ PHP ใช้วาดตัวอย่าง */
        $json = json_encode($pt, JSON_UNESCAPED_UNICODE); ?>
      <button type="button" class="tp-card" data-tk="<?= e($json) ?>" title="<?= e($p['desc']) ?>">
        <?= tp_preview($pt) ?>
        <span class="tp-meta"><b><?= e($p['name']) ?></b><small><?= e($p['desc']) ?></small></span>
      </button>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- ══ สีของธีม ══ -->
  <div class="card mb-2">
    <div class="section-head" style="margin-bottom:10px;">
      <div><span class="tag"><span class="material-symbols-rounded icon-sm">tune</span> COLORS</span><h3>ปรับสีเอง</h3>
      <p>ทุกช่องมีผลกับทั้งเว็บทันทีที่บันทึก ตัวอย่างด้านขวาอัปเดตตามที่เลือก</p></div>
    </div>
    <div class="tp-tune">
      <div class="tp-fields">
        <?php
        $colorFields = [
            ['theme_color', 'สีหลัก (ปุ่ม/ลิงก์)', $T['color']],
            ['theme_color_dark', 'สีหลักแบบเข้ม (ตอนชี้เมาส์)', $T['dark']],
            ['theme_ink',   'สีหัวข้อ',            $T['ink']],
            ['theme_text',  'สีเนื้อความ',         $T['text']],
            ['theme_muted', 'สีข้อความรอง',        $T['muted']],
            ['theme_bg',    'สีพื้นหน้าเว็บ',      $T['bg']],
            ['theme_card',  'สีพื้นการ์ด',         $T['card']],
        ];
        foreach ($colorFields as [$f, $lb, $v]): ?>
        <label class="tp-cf"><span><?= e($lb) ?></span>
          <input type="color" name="<?= e($f) ?>" id="<?= e($f) ?>" value="<?= e($v) ?>" data-tp="<?= e($f) ?>">
          <code><?= e($v) ?></code>
        </label>
        <?php endforeach; ?>
        <label class="tp-cf"><span>ความโค้งมุม</span>
          <select name="theme_radius" id="theme_radius" class="tp-sel">
            <?php foreach (theme_radius_sets() as $rk => $rv): ?>
            <option value="<?= e($rk) ?>" <?= $T['radius'] === $rk ? 'selected' : '' ?>><?= e($rv[2]) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="tp-cf"><span>ระดับเงา</span>
          <select name="theme_shadow" id="theme_shadow" class="tp-sel">
            <?php foreach (theme_shadow_sets() as $sk => $sv): ?>
            <option value="<?= e($sk) ?>" <?= $T['shadow'] === $sk ? 'selected' : '' ?>><?= e($sv) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="inline-check" style="margin-top:4px;"><input type="checkbox" name="theme_glow" id="theme_glow" value="1" <?= $T['glow'] === '1' ? 'checked' : '' ?>>แสงเรืองสีธีมที่มุมบนของหน้า</label>
      </div>
      <div class="tp-live">
        <?= tp_preview($T) ?>
        <p class="text-muted" style="font-size:12px;margin:8px 0 0;">ตัวอย่างสด — เงาและแสงเรืองจะเห็นผลเต็มรูปแบบหลังกดบันทึก</p>
      </div>
    </div>

    <div class="tp-contrast">
      <div class="tp-ct-head"><span class="material-symbols-rounded icon-sm">accessibility_new</span>
        ค่าความต่างของสี (เกณฑ์ WCAG AA ของเว็บหน่วยงานรัฐ ต้องได้ 4.5 : 1 ขึ้นไป)</div>
      <?php $anyBad = false;
      foreach ($ct as [$lb, $fg, $bgk]):
        $r  = contrast_ratio(ct_color($T, $fg), ct_color($T, $bgk));
        $ok = $r >= 4.5;
        if (!$ok) $anyBad = true; ?>
      <div class="tp-ct-row" data-fg="<?= e($fg) ?>" data-bgk="<?= e($bgk) ?>">
        <span><?= e($lb) ?></span>
        <b class="<?= $ok ? 'ok' : 'bad' ?>"><?= number_format($r, 2) ?> : 1</b>
        <span class="material-symbols-rounded icon-sm <?= $ok ? 'ok' : 'bad' ?>"><?= $ok ? 'check_circle' : 'error' ?></span>
      </div>
      <?php endforeach; ?>
      <p class="text-muted" id="ctWarn" style="font-size:12px;margin:6px 0 0;<?= $anyBad ? '' : 'display:none;' ?>">มีคู่สีที่ยังไม่ผ่านเกณฑ์ — ผู้สูงอายุและผู้ที่สายตาเลือนรางอาจอ่านไม่ออก แนะนำให้ปรับสีนั้นให้เข้มขึ้นหรือจางลง</p>
    </div>
  </div>

  <!-- ══ ภาพฉากหลัง ══ -->
  <div class="card mb-2">
    <div class="section-head" style="margin-bottom:10px;">
      <div><span class="tag"><span class="material-symbols-rounded icon-sm">image</span> BACKGROUND</span><h3>ภาพฉากหลังเว็บ</h3>
      <p>ใส่ภาพอาคารหน่วยงาน ภาพบรรยากาศ หรือลายพื้น แล้วปรับม่านบังให้ตัวอักษรยังอ่านง่าย</p></div>
    </div>

    <div class="grid grid-3 mb-2">
      <?php foreach ([
        ['off',  'block',       'ไม่ใช้ภาพ',      'ใช้สีพื้นของธีมอย่างเดียว (เร็วที่สุด)'],
        ['page', 'wallpaper',   'ทั้งหน้า',        'ภาพติดอยู่กับที่ เนื้อหาเลื่อนผ่านด้านบน'],
        ['top',  'vertical_align_top', 'เฉพาะหัวเว็บ', 'ภาพเป็นแถบด้านบนแล้วจางหายลงไปในเนื้อหา'],
      ] as [$sv, $ic, $sl, $sd]): ?>
      <label class="card card-compact inline-check" style="cursor:pointer;<?= $cur_scope === $sv ? 'border-color:var(--blue);box-shadow:var(--shadow-blue);' : '' ?>">
        <input type="radio" name="bg_scope" value="<?= e($sv) ?>" <?= $cur_scope === $sv ? 'checked' : '' ?>>
        <div><b style="color:var(--ink);"><span class="material-symbols-rounded icon-sm"><?= e($ic) ?></span> <?= e($sl) ?></b><br>
        <span class="text-muted" style="font-size:13px;"><?= e($sd) ?></span></div>
      </label>
      <?php endforeach; ?>
    </div>

    <div class="form-row">
      <div>
        <label>ไฟล์ภาพ <span class="text-muted">(JPG / PNG / WebP ไม่เกิน 8 MB — ระบบย่อให้เหลือกว้าง 1600px อัตโนมัติ)</span></label>
        <input type="file" name="bg_image" accept=".jpg,.jpeg,.png,.webp">
        <?php if ($cur_bgimg): ?>
        <div class="bgprev-wrap mt-1">
          <img class="bgprev" src="<?= e(url($cur_bgimg)) ?>" alt="ภาพฉากหลังปัจจุบัน">
          <label class="inline-check"><input type="checkbox" name="remove_bg" value="1">ลบภาพนี้</label>
        </div>
        <?php else: ?>
        <p class="text-muted" style="font-size:13px;margin:6px 0 0;">ยังไม่มีภาพฉากหลัง</p>
        <?php endif; ?>
      </div>
      <div>
        <label>ความทึบของม่านบัง — <b id="ovOut"><?= $cur_ov ?>%</b></label>
        <input type="range" name="bg_overlay" id="bg_overlay" min="0" max="95" step="5" value="<?= $cur_ov ?>" class="mb-1">
        <p class="text-muted" style="font-size:12px;margin:0 0 10px;">ยิ่งมากยิ่งอ่านง่าย ยิ่งน้อยยิ่งเห็นภาพชัด — แนะนำ 60–80% สำหรับภาพถ่าย</p>
        <label>ความเบลอของภาพ — <b id="blOut"><?= $cur_blur ?>px</b></label>
        <input type="range" name="bg_blur" id="bg_blur" min="0" max="20" step="1" value="<?= $cur_blur ?>">
      </div>
    </div>

    <div class="form-row">
      <div><label>จุดสนใจแนวนอน — <b id="fxOut"><?= $cur_fx ?>%</b> <span class="text-muted">(0 = ซ้าย, 100 = ขวา)</span></label>
        <input type="range" name="bg_focus_x" id="bg_focus_x" min="0" max="100" step="5" value="<?= $cur_fx ?>"></div>
      <div><label>จุดสนใจแนวตั้ง — <b id="fyOut"><?= $cur_fy ?>%</b> <span class="text-muted">(0 = บน, 100 = ล่าง)</span></label>
        <input type="range" name="bg_focus_y" id="bg_focus_y" min="0" max="100" step="5" value="<?= $cur_fy ?>"></div>
    </div>
    <p class="text-muted" style="font-size:12px;margin:2px 0 0;">จุดสนใจคือส่วนของภาพที่จะไม่ถูกตัดทิ้งเวลาเปิดบนมือถือ — ถ้าภาพมีอาคารอยู่กลางภาพให้คงไว้ที่ 50%</p>
  </div>

  <!-- ══ หน้าตาเว็บไซต์ ══ -->
  <div class="card mb-2">
    <div class="section-head" style="margin-bottom:8px;">
      <div><span class="tag"><span class="material-symbols-rounded icon-sm">web</span> LAYOUT</span><h3>หน้าตาเว็บไซต์</h3>
      <p>ฟอนต์ ความกว้างเนื้อหา และรูปแบบหัวเว็บ — มีผลทั้งเว็บ</p></div>
    </div>
    <div class="form-row">
      <div><label>ฟอนต์ตัวอักษร</label>
        <select name="font_family" id="font_family" class="mb-2">
          <?php foreach ($fonts_list as $fname => $_): ?>
          <option value="<?= e($fname) ?>" <?= $T['font'] === $fname ? 'selected' : '' ?>><?= e($fname) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div><label>ความกว้างเนื้อหา</label>
        <select name="layout_width" id="layout_width" class="mb-2">
          <option value="normal" <?= $T['width'] === 'normal' ? 'selected' : '' ?>>ปกติ (1180px)</option>
          <option value="wide" <?= $T['width'] === 'wide' ? 'selected' : '' ?>>กว้าง (1360px)</option>
          <option value="full" <?= $T['width'] === 'full' ? 'selected' : '' ?>>เต็มจอ (1640px)</option>
        </select></div>
    </div>
    <div class="form-row">
      <div><label>รูปแบบหัวเว็บ</label>
        <select name="header_layout" id="header_layout" class="mb-2">
          <option value="left" <?= $T['header'] === 'left' ? 'selected' : '' ?>>โลโก้ซ้าย · เมนูขวา (ดีฟอลต์)</option>
          <option value="center" <?= $T['header'] === 'center' ? 'selected' : '' ?>>โลโก้กลาง · เมนูใต้</option>
        </select></div>
      <div><label>การปักหัวเว็บ</label>
        <label class="inline-check" style="margin-top:6px;"><input type="checkbox" name="header_sticky" value="1" <?= $cur_sticky === '1' ? 'checked' : '' ?>>ปักหัวเว็บไว้ด้านบนตลอด (sticky)</label></div>
    </div>
    <label>ข้อความแนะนำหน่วยงาน <span class="text-muted">(แสดงใต้โลโก้ในส่วนท้ายเว็บ)</span></label>
    <textarea name="footer_about" rows="2" class="mb-1" maxlength="500" placeholder="เช่น ที่อยู่ เวลาทำการ หรือคำอธิบายสั้นๆ"><?= e($cur_footer) ?></textarea>
  </div>

  <!-- ══ อนิเมชัน ══ -->
  <div class="card mb-2">
    <div class="section-head" style="margin-bottom:8px;">
      <div><span class="tag"><span class="material-symbols-rounded icon-sm">animation</span> ANIMATION</span><h3>ระดับอนิเมชัน</h3>
      <p>บางหน่วยงานอาจต้องการเว็บนิ่งๆ — ผู้ใช้ที่ตั้งค่า "ลดการเคลื่อนไหว" ในเครื่อง จะไม่เห็นอนิเมชันเสมอไม่ว่าตั้งค่าใด</p></div>
    </div>
    <div class="grid grid-3">
      <?php foreach ([
        ['full', 'จัดเต็ม (แนะนำ)', 'scroll reveal, count-up, Ken Burns, shimmer, ticker ครบ'],
        ['min',  'น้อย',            'เฉพาะ fade และ hover พื้นฐาน'],
        ['off',  'ปิดทั้งหมด',      'เว็บนิ่งสนิท ไม่มีการเคลื่อนไหว'],
      ] as [$av, $al, $ad]): ?>
      <label class="card card-compact inline-check" style="cursor:pointer;<?= $cur_anim === $av ? 'border-color:var(--blue);box-shadow:var(--shadow-blue);' : '' ?>">
        <input type="radio" name="anim_level" value="<?= e($av) ?>" <?= $cur_anim === $av ? 'checked' : '' ?>>
        <div><b style="color:var(--ink);"><?= e($al) ?></b><br><span class="text-muted" style="font-size:13px;"><?= e($ad) ?></span></div>
      </label>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="flex gap-1" style="flex-wrap:wrap;align-items:center;">
    <button class="btn primary large" type="submit"><span class="material-symbols-rounded">save</span>บันทึกธีม</button>
    <a class="btn small" href="<?= e(url('index.php')) ?>" target="_blank" rel="noopener"><span class="material-symbols-rounded icon-sm">open_in_new</span>เปิดหน้าเว็บดูผล</a>
    <button class="btn danger small" type="submit" name="reset_theme" value="1"
            data-confirm="คืนค่าธีมกลับเป็นค่าเริ่มต้นทั้งหมด รวมถึงลบภาพฉากหลัง — ยืนยันหรือไม่?">
      <span class="material-symbols-rounded icon-sm">restart_alt</span>คืนค่าเริ่มต้น</button>
  </div>
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
