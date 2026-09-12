<?php
/** admin/site-info.php — ตั้งค่าเว็บไซต์: ข้อมูลหน่วยงาน, โลโก้/favicon, เกี่ยวกับ, จัดการ section หน้าแรก */
require dirname(__DIR__) . '/includes/init.php';
require __DIR__ . '/_auth.php';
require_admin();
require dirname(__DIR__) . '/includes/blocks.php';

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    /* ── บันทึกข้อมูลหน่วยงาน ── */
    try {
        $logo = handle_upload('logo', 'site', upload_image_exts(), 4);
        if ($logo) { delete_upload(setting('logo')); setting_set('logo', $logo['path']); }
        if (!empty($_POST['remove_logo'])) { delete_upload(setting('logo')); setting_set('logo', ''); }

        $fav = handle_upload('favicon', 'site', ['png'], 2);
        if ($fav) { delete_upload(setting('favicon')); setting_set('favicon', $fav['path']); }
        if (!empty($_POST['remove_favicon'])) { delete_upload(setting('favicon')); setting_set('favicon', ''); }

        $struct = handle_upload('structure_image', 'site', upload_image_exts(), 8);
        if ($struct) { delete_upload(setting('structure_image')); setting_set('structure_image', $struct['path']); }
        if (!empty($_POST['remove_structure'])) { delete_upload(setting('structure_image')); setting_set('structure_image', ''); }

        $fields = [
            'site_name' => 200, 'site_dept' => 200, 'site_vision' => 500, 'site_description' => 300,
            'site_address' => 500, 'site_phone' => 100, 'site_email' => 150, 'site_hours' => 200,
            'social_facebook' => 300, 'social_youtube' => 300, 'social_tiktok' => 300, 'social_line' => 300,
            'logo_icon' => 50, 'about_text' => 20000, 'map_url' => 500, 'map_embed' => 600, 'site_directions' => 1500,
        ];
        foreach ($fields as $f => $max) {
            if (isset($_POST[$f])) setting_set($f, mb_substr(trim((string)$_POST[$f]), 0, $max));
        }
        /* เนื้อหา "เกี่ยวกับหน่วยงาน" แบบบล็อก (รูป/หัวข้อ/ปุ่ม ฯลฯ) — แทนช่องข้อความล้วนเดิม */
        if (isset($_POST['about_blocks'])) {
            setting_set('about_blocks', sanitize_blocks((string)$_POST['about_blocks']));
        }
        /* แถบช่วยการเข้าถึง (checkbox) — เมื่อบันทึกฟอร์มข้อมูลหน่วยงาน */
        if (isset($_POST['save_agency'])) {
            setting_set('a11y_bar', !empty($_POST['a11y_bar']) ? '1' : '0');

            /* URL สวย: เปิดได้เฉพาะเมื่อทดสอบผ่านจริง (กันตั้งค่าแล้วลิงก์ทั้งเว็บพัง 404) */
            $wantPretty = !empty($_POST['pretty_urls']);
            if ($wantPretty !== pretty_urls_on()) {
                if ($wantPretty) {
                    require_once APP_ROOT . '/includes/hardening.php';
                    /* ตรวจแบบ local ก่อน — เครือข่ายที่บล็อกขาออกจะทดสอบด้วย HTTP ไม่ได้ */
                    if (rewrite_module_active() || self_test_pretty_urls(rtrim(abs_url(''), '/'))) {
                        setting_set('pretty_urls', '1');
                    } else {
                        setting_set('pretty_urls', '0');
                        /* ใช้ flash ไม่ใช่ $errors เพราะโค้ดด้านล่าง redirect ทันที $errors จะหายไป */
                        flash_set('danger', 'เปิด URL แบบซ่อน .php ไม่ได้ — ทดสอบแล้วเซิร์ฟเวอร์ยังไม่รองรับ '
                                          . '(ตรวจว่าไฟล์ .htaccess ที่รากเว็บยังมีส่วน PRETTY-URLS อยู่ครบ) '
                                          . 'ค่าอื่นๆ บันทึกเรียบร้อยแล้ว');
                    }
                } else {
                    setting_set('pretty_urls', '0');
                }
            }
        }
        log_action('แก้ไขตั้งค่าเว็บไซต์', '');
        flash_set('success', 'บันทึกการตั้งค่าเรียบร้อยแล้ว');
        redirect('admin/site-info.php');
    } catch (RuntimeException $ex) {
        $errors[] = $ex->getMessage();
    }
}

$admin_title = 'ตั้งค่าเว็บไซต์';
require __DIR__ . '/_top.php';
?>
<?php if ($errors): ?>
<div class="alert danger"><span class="material-symbols-rounded">error</span>
  <div><?php foreach ($errors as $er): ?><div><?= e($er) ?></div><?php endforeach; ?></div>
</div>
<?php endif; ?>

<!-- ข้อมูลหน่วยงาน -->
<form method="post" action="" enctype="multipart/form-data">
  <?= csrf_field() ?>
  <input type="hidden" name="save_agency" value="1">
  <div class="card mb-2">
    <div class="section-head" style="margin-bottom:8px;">
      <div><span class="tag">AGENCY</span><h3>ข้อมูลหน่วยงาน</h3>
      <p>ข้อมูลทั้งหมดแสดงอัตโนมัติทั่วทั้งเว็บ — ไม่ต้องแก้โค้ดใดๆ</p></div>
    </div>
    <div class="form-row">
      <div><label>ชื่อหน่วยงาน <span style="color:var(--danger);">*</span></label>
        <input type="text" name="site_name" value="<?= e(setting('site_name')) ?>" required class="mb-2"></div>
      <div><label>สังกัด</label>
        <input type="text" name="site_dept" value="<?= e(setting('site_dept')) ?>" placeholder="เช่น สำนักงาน... / กระทรวง..." class="mb-2"></div>
    </div>
    <label>วิสัยทัศน์</label>
    <input type="text" name="site_vision" value="<?= e(setting('site_vision')) ?>" class="mb-2">
    <label>คำอธิบายเว็บไซต์ (SEO) <span class="text-muted">— ใช้แสดงตอนค้นหา Google และแชร์ FB/LINE (ไม่เกิน 160 ตัวอักษร)</span></label>
    <input type="text" name="site_description" value="<?= e(setting('site_description')) ?>" maxlength="300" placeholder="เช่น เว็บไซต์ทางการของ... ศูนย์ข้อมูลข่าวสารและบริการประชาชน" class="mb-2">
    <label>ที่อยู่</label>
    <textarea name="site_address" rows="2" class="mb-2"><?= e(setting('site_address')) ?></textarea>
    <div class="form-row">
      <div><label>โทรศัพท์</label><input type="text" name="site_phone" value="<?= e(setting('site_phone')) ?>" class="mb-2"></div>
      <div><label>อีเมล</label><input type="text" name="site_email" value="<?= e(setting('site_email')) ?>" class="mb-2"></div>
    </div>
    <label>เวลาทำการ</label>
    <input type="text" name="site_hours" value="<?= e(setting('site_hours')) ?>" placeholder="เช่น จันทร์–ศุกร์ 08.30–16.30 น." class="mb-2">
    <label>ลิงก์ Google Maps <span class="text-muted">— ไม่บังคับ: ปลายทางของปุ่ม "เปิดใน Google Maps" (ถ้าเว้นว่าง จะค้นหาจากที่อยู่ข้างบนให้เอง)</span></label>
    <input type="text" name="map_url" value="<?= e(setting('map_url')) ?>" placeholder="https://maps.app.goo.gl/..." class="mb-2">
    <label>ฝังแผนที่ (Embed) <span class="text-muted">— ไม่บังคับ: <b>ปกติไม่ต้องกรอก</b> ระบบสร้างแผนที่จากที่อยู่ข้างบนอัตโนมัติ กรอกช่องนี้เฉพาะเมื่ออยากปักหมุดตำแหน่งเองให้ตรงเป๊ะ (Google Maps → แชร์ → ฝังแผนที่ แล้ววางเฉพาะ URL ใน src)</span></label>
    <input type="text" name="map_embed" value="<?= e(setting('map_embed')) ?>" placeholder="https://www.google.com/maps/embed?pb=..." class="mb-2">
    <label>วิธีเดินทาง <span class="text-muted">— แสดงคู่กับแผนที่ในหน้าติดต่อ (ไม่บังคับ) แต่ละบรรทัด = ช่องทางเดินทางหนึ่งแบบ</span></label>
    <textarea name="site_directions" rows="3" class="mb-2" placeholder="รถยนต์: จอดได้ที่ลานจอดหน้าอาคาร&#10;รถประจำทาง: สาย 15, 47 ลงป้ายหน้าสำนักงาน&#10;รถไฟฟ้า: BTS สถานี... ทางออก 2"><?= e(setting('site_directions')) ?></textarea>
    <div class="form-row">
      <div><label>Facebook (URL)</label><input type="text" name="social_facebook" value="<?= e(setting('social_facebook')) ?>" class="mb-2"></div>
      <div><label>YouTube (URL)</label><input type="text" name="social_youtube" value="<?= e(setting('social_youtube')) ?>" class="mb-2"></div>
    </div>
    <div class="form-row">
      <div><label>TikTok (URL)</label><input type="text" name="social_tiktok" value="<?= e(setting('social_tiktok')) ?>" class="mb-2"></div>
      <div><label>LINE (URL)</label><input type="text" name="social_line" value="<?= e(setting('social_line')) ?>" class="mb-2"></div>
    </div>
    <label class="inline-check" style="margin-top:6px;">
      <input type="checkbox" name="a11y_bar" value="1" <?= setting('a11y_bar', '1') === '1' ? 'checked' : '' ?>>
      <span>แสดง<b>แถบช่วยการเข้าถึง</b> (ปรับขนาดตัวอักษร + โหมดสีตัดกัน) — แนะนำเปิดตามมาตรฐานเว็บภาครัฐ</span>
    </label>
    <label class="inline-check" style="margin-top:4px;">
      <input type="checkbox" name="pretty_urls" value="1" <?= pretty_urls_on() ? 'checked' : '' ?>>
      <span><b>URL แบบซ่อน .php</b> (เช่น <code>/news</code> แทน <code>/news.php</code>) — ดูเป็นมืออาชีพกว่า
        ลิงก์เก่ายังใช้ได้ปกติ (ระบบพาไปหน้าใหม่ให้อัตโนมัติ ไม่เสีย SEO)
        <br><span class="text-muted" style="font-size:12.5px;">ระบบทดสอบให้ก่อนเปิดเสมอ — ถ้าเซิร์ฟเวอร์ไม่รองรับจะเปิดไม่ได้และแจ้งเตือน</span></span>
    </label>
  </div>

  <div class="card mb-2">
    <div class="section-head" style="margin-bottom:8px;">
      <div><span class="tag">LOGO</span><h3>โลโก้ / Favicon</h3></div>
    </div>
    <div class="form-row">
      <div>
        <label>โลโก้หน่วยงาน <span class="text-muted">(สี่เหลี่ยมจัตุรัส แนะนำ 256×256)</span></label>
        <?php if (setting('logo')): ?>
        <div class="current-file"><img src="<?= e(url(setting('logo'))) ?>" alt="" style="width:42px;height:42px;">โลโก้ปัจจุบัน
          <label class="inline-check" style="margin:0;"><input type="checkbox" name="remove_logo" value="1">ลบ</label></div>
        <?php endif; ?>
        <div class="dropzone mb-2" style="padding:18px;">
          <input type="file" name="logo" accept=".jpg,.jpeg,.png,.webp">
          <span class="material-symbols-rounded icon-lg" style="color:var(--blue)">add_photo_alternate</span>
          <p style="margin:4px 0 0;font-size:13px;">คลิกเลือกหรือลากรูปมาวาง</p>
          <div class="dz-filename"></div>
        </div>
        <label>ไอคอนสำรอง (เมื่อไม่มีโลโก้ — ชื่อ Material Symbols)</label>
        <input type="text" name="logo_icon" value="<?= e(setting('logo_icon', 'account_balance')) ?>" placeholder="เช่น account_balance, local_police" class="mb-2">
      </div>
      <div>
        <label>Favicon <span class="text-muted">(PNG แนะนำ 64×64)</span></label>
        <?php if (setting('favicon')): ?>
        <div class="current-file"><img src="<?= e(url(setting('favicon'))) ?>" alt="" style="width:24px;height:24px;">Favicon ปัจจุบัน
          <label class="inline-check" style="margin:0;"><input type="checkbox" name="remove_favicon" value="1">ลบ</label></div>
        <?php endif; ?>
        <div class="dropzone mb-2" style="padding:18px;">
          <input type="file" name="favicon" accept=".png">
          <span class="material-symbols-rounded icon-lg" style="color:var(--blue)">image</span>
          <p style="margin:4px 0 0;font-size:13px;">คลิกเลือกหรือลากรูปมาวาง (PNG)</p>
          <div class="dz-filename"></div>
        </div>
      </div>
    </div>
  </div>

  <div class="card mb-2">
    <div class="section-head" style="margin-bottom:10px;">
      <div><span class="tag">ABOUT</span><h3>ข้อมูลหน่วยงาน (ประวัติ ภารกิจ อำนาจหน้าที่)</h3>
      <p>เลือก "เพิ่มบล็อก" เพื่อแทรกรูป หัวข้อ ปุ่ม หรือคำคม สลับกับข้อความได้ตามต้องการ — ใช้ลูกศร ↑↓ จัดลำดับ · ปุ่ม ✕ ลบ</p></div>
    </div>
    <?php
    $about_init = setting('about_blocks') ?: '';
    if ($about_init === '' && setting('about_text') !== '') {
        /* ยังไม่เคยใช้ตัวแก้ไขบล็อก แต่มีข้อความล้วนแบบเดิมอยู่ — seed เป็นบล็อกข้อความก้อนเดียวให้แก้ต่อได้เลย */
        $about_init = json_encode([['type' => 'text', 'text' => setting('about_text')]], JSON_UNESCAPED_UNICODE);
    }
    block_editor_card('about_blocks', $about_init);
    ?>
  </div>

  <div class="card mb-2">
    <div class="section-head" style="margin-bottom:8px;">
      <div><span class="tag">STRUCTURE</span><h3>รูปโครงสร้างหน่วยงาน</h3></div>
    </div>
    <label>รูปโครงสร้างหน่วยงาน (org chart) <span class="text-muted">— ใช้เฉพาะเมื่อยังไม่ได้สร้างผังในเมนู "ผังโครงสร้างหน่วยงาน"</span></label>
    <div class="alert info mb-1" style="font-size:12.5px;">
      <span class="material-symbols-rounded icon-sm">tips_and_updates</span>
      <div>แนะนำให้สร้างผังโครงสร้างแบบแก้ไขได้เองในเมนู <a href="<?= e(url('admin/org-chart.php')) ?>"><b>"ผังโครงสร้างหน่วยงาน"</b></a> แทนการอัปโหลดรูป — ไม่ต้องพึ่งโปรแกรมข้างนอก แก้ไขได้ตลอด (ถ้าสร้างผังไว้แล้ว จะแสดงผังแทนรูปนี้โดยอัตโนมัติ)</div>
    </div>
    <?php if (setting('structure_image')): ?>
    <div class="current-file"><img src="<?= e(url(setting('structure_image'))) ?>" alt="">รูปปัจจุบัน
      <label class="inline-check" style="margin:0;"><input type="checkbox" name="remove_structure" value="1">ลบ</label></div>
    <?php endif; ?>
    <div class="dropzone mb-2" style="padding:18px;">
      <input type="file" name="structure_image" accept=".jpg,.jpeg,.png,.webp">
      <span class="material-symbols-rounded icon-lg" style="color:var(--blue)">account_tree</span>
      <p style="margin:4px 0 0;font-size:13px;">คลิกเลือกหรือลากรูปมาวาง</p>
      <div class="dz-filename"></div>
    </div>
  </div>

  <button class="btn primary large mb-2" type="submit"><span class="material-symbols-rounded">save</span>บันทึกการตั้งค่า</button>
</form>

<div class="card">
  <div class="flex items-center gap-2" style="flex-wrap:wrap;">
    <span class="material-symbols-rounded" style="color:var(--blue)">web</span>
    <div style="flex:1;min-width:200px;">
      <b>เปิด/ปิด เรียงลำดับ ส่วนต่างๆ ของหน้าแรก</b>
      <p class="text-muted" style="margin:2px 0 0;font-size:13px;">ย้ายไปอยู่ที่หน้า "การแสดงผลหน้าแรก" แล้ว เพื่อรวมการจัดการไว้ที่เดียว</p>
    </div>
    <a class="btn" href="<?= e(url('admin/homepage.php')) ?>"><span class="material-symbols-rounded icon-sm">tune</span>ไปที่การแสดงผลหน้าแรก</a>
  </div>
</div>
<?php require __DIR__ . '/_bottom.php'; ?>
