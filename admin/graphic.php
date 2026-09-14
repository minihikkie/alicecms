<?php
/**
 * admin/graphic.php — ออกแบบภาพประกาศในตัว
 *
 * ทำไมต้องมี: เจ้าหน้าที่ต้องออกไปทำภาพในแอปภายนอกทุกครั้งแล้วค่อยกลับมาอัปโหลด
 * เสียเวลาและภาพออกมาไม่เป็นแนวเดียวกัน หน้านี้ทำให้จบในเว็บเดียว
 *
 * วิธีวาด: Canvas API ของเบราว์เซอร์ ไม่พึ่งไลบรารีภายนอกเลย
 *  - CSP ของระบบอนุญาตสคริปต์เฉพาะ 'self' จะโหลดไลบรารีจาก CDN ไม่ได้อยู่แล้ว
 *  - เบราว์เซอร์จัดรูปอักษรไทย (สระบน-ล่าง วรรณยุกต์) ได้ถูกต้องเอง
 *    ถ้าวาดฝั่งเซิร์ฟเวอร์ด้วย GD ต้องจัดการเองทั้งหมดและมักเพี้ยน
 *  - ตัดบรรทัดด้วย Intl.Segmenter ซึ่งใช้พจนานุกรมตัดคำไทยในตัวเบราว์เซอร์
 *    (ภาษาไทยไม่เว้นวรรคระหว่างคำ ตัดตามช่องว่างแบบภาษาอังกฤษไม่ได้)
 */
require dirname(__DIR__) . '/includes/init.php';
require __DIR__ . '/_auth.php';

const GFX_MAX_MB = 12;

/* ── บันทึกภาพที่วาดเสร็จเข้าคลังสื่อ ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['img'])) {
    try {
        $data = (string)$_POST['img'];
        if (!preg_match('#^data:image/(png|jpeg);base64,#', $data, $m)) {
            throw new RuntimeException('รูปแบบภาพไม่ถูกต้อง');
        }
        $type = $m[1];
        $raw  = base64_decode(substr($data, strpos($data, ',') + 1), true);
        if ($raw === false || $raw === '') throw new RuntimeException('ถอดรหัสภาพไม่สำเร็จ');
        if (strlen($raw) > GFX_MAX_MB * 1048576) {
            throw new RuntimeException('ภาพใหญ่เกิน ' . GFX_MAX_MB . ' MB — ลองลดขนาดภาพพื้นหลัง');
        }

        /* ตรวจจากเนื้อไฟล์จริง ไม่เชื่อส่วนหัวที่ฝั่งเบราว์เซอร์ส่งมา */
        $isPng  = substr($raw, 0, 8) === "\x89PNG\r\n\x1a\n";
        $isJpeg = substr($raw, 0, 3) === "\xFF\xD8\xFF";
        if (!$isPng && !$isJpeg) throw new RuntimeException('ไฟล์ที่ส่งมาไม่ใช่ภาพ PNG หรือ JPEG');
        $info = @getimagesizefromstring($raw);
        if (!$info || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)) {
            throw new RuntimeException('ไฟล์ที่ส่งมาไม่ใช่ภาพที่ระบบรองรับ');
        }
        $ext = $isPng ? 'png' : 'jpg';

        $dir = APP_ROOT . '/uploads/media';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) throw new RuntimeException('สร้างโฟลเดอร์คลังสื่อไม่ได้');
        $name = date('Ymd') . '-' . bin2hex(random_bytes(8)) . '.' . $ext;
        $full = $dir . '/' . $name;

        /* เทียบจำนวนไบต์ที่เขียนได้จริง — ถ้าดิสก์เต็มจะได้ไฟล์ไม่ครบ ต้องลบทิ้งไม่ให้เหลือไฟล์เสีย */
        if (@file_put_contents($full, $raw) !== strlen($raw)) {
            @unlink($full);
            throw new RuntimeException('บันทึกไฟล์ไม่สำเร็จ — พื้นที่ดิสก์อาจเต็ม');
        }

        db()->prepare('INSERT INTO media (path, orig, mime, size) VALUES (?,?,?,?)')
            ->execute(['uploads/media/' . $name, 'ภาพประกาศ.' . $ext, $ext, strlen($raw)]);
        log_action('สร้างภาพประกาศ', $name);
        flash_set('success', 'บันทึกเข้าคลังสื่อแล้ว — เลือกใช้เป็นภาพปกข่าวได้เลย');
    } catch (Throwable $ex) {
        flash_set('danger', $ex->getMessage());
    }
    redirect('admin/graphic.php');
}

/* รูปในคลังสื่อไว้เลือกเป็นพื้นหลัง — แสดงในหน้าเลยจะได้ไม่ต้องสลับหน้าไปมา */
$img_exts = upload_image_exts();
$lib = [];
try {
    foreach (db()->query('SELECT path, mime FROM media ORDER BY created_at DESC, id DESC LIMIT 40') as $r) {
        if (in_array(strtolower($r['mime']), $img_exts, true)) $lib[] = url($r['path']);
    }
} catch (Throwable $e) {}

/* เปิดมาจากปุ่ม "สร้างภาพ" ในหน้าจัดการข่าว → ดึงหัวข้อ เนื้อหา รูปปก มาเติมให้เลย
   จะได้ไม่ต้องพิมพ์ซ้ำ ซึ่งเป็นเหตุผลหลักที่คนขี้เกียจทำภาพประชาสัมพันธ์ */
$pre = [
    'kicker' => 'ประชาสัมพันธ์', 'head' => '', 'body' => '', 'bg' => '', 'from' => 0,
    'date'   => thai_date(date('Y-m-d')),
];
$pid = (int)($_GET['post'] ?? 0);
if ($pid) {
    try {
        $st = db()->prepare('SELECT id, type, title, body, image, published_at, created_at FROM posts WHERE id = ?');
        $st->execute([$pid]);
        if ($p = $st->fetch()) {
            $pre['kicker'] = post_type_label($p['type']);
            $pre['head']   = $p['title'];
            $pre['body']   = meta_excerpt($p['body'], 180);
            $pre['bg']     = $p['image'] ? url($p['image']) : '';
            $pre['from']   = (int)$p['id'];
            $pre['date']   = thai_date($p['published_at'] ?: $p['created_at']);
        }
    } catch (Throwable $e) {}
}

$page_title = 'ออกแบบภาพประกาศ';
$theme = valid_hex(setting('theme_color', '')) ? setting('theme_color') : '#1A73E8';
$cfg = [
    'logo'  => setting('logo') ? url(setting('logo')) : '',
    'color' => $theme,
    'lib'   => $lib,
    'bg'    => $pre['bg'],
];
require __DIR__ . '/_top.php';
?>
<div class="section-head">
  <div><span class="tag"><span class="material-symbols-rounded icon-sm">design_services</span> STUDIO</span>
  <h2>ออกแบบภาพประกาศ</h2></div>
</div>
<?php if ($pre['from']): ?>
<div class="alert success mb-2" style="font-size:13.5px;">
  <span class="material-symbols-rounded">auto_awesome</span>
  <div>ดึงข้อมูลจากข่าวมาให้แล้ว — ปรับแต่งได้ตามต้องการ แล้วกดดาวน์โหลดไปโพสต์ช่องทางอื่นได้เลย
    &nbsp;·&nbsp; <a href="<?= e(url('admin/post-edit.php?id=' . $pre['from'])) ?>">กลับไปแก้ไขข่าว</a></div>
</div>
<?php else: ?>
<p class="text-muted mb-2" style="font-size:14px;">
  กรอกข้อความ ใส่รูป เลือกธีม แล้วบันทึกเข้าคลังสื่อหรือดาวน์โหลดไปโพสต์ช่องทางอื่นได้ทันที
  &nbsp;·&nbsp; <span class="text-muted">อยากได้เร็วกว่านี้? กดปุ่ม <b>สร้างภาพ</b> ที่หน้าจัดการข่าว ระบบจะเติมข้อมูลให้เอง</span>
</p>
<?php endif; ?>

<div class="gfx-wrap">
  <div>
    <!-- ── รูปแบบและขนาด ── -->
    <div class="card mb-2">
      <label>รูปแบบ</label>
      <div class="gfx-tpl mb-2">
        <button type="button" class="gfx-t on" data-tpl="poster"><span class="material-symbols-rounded">newspaper</span>โปสเตอร์ข่าว</button>
        <button type="button" class="gfx-t" data-tpl="card"><span class="material-symbols-rounded">web_asset</span>การ์ดประกาศ</button>
        <button type="button" class="gfx-t" data-tpl="bold"><span class="material-symbols-rounded">format_size</span>ข้อความเน้น</button>
        <button type="button" class="gfx-t" data-tpl="photo"><span class="material-symbols-rounded">image</span>รูปเต็มพื้น</button>
        <button type="button" class="gfx-t" data-tpl="banner"><span class="material-symbols-rounded">vertical_split</span>รูปครึ่งบน</button>
      </div>
      <label for="gSize">ขนาด</label>
      <select id="gSize">
        <option value="1080x1620">โปสเตอร์แนวตั้ง 1080×1620 — สัดส่วน 2:3</option>
        <option value="1080x1350">แนวตั้ง 1080×1350 — Instagram / Line</option>
        <option value="1080x1080">จัตุรัส 1080×1080 — Facebook / Line</option>
        <option value="1200x630">แนวนอน 1200×630 — ภาพปกลิงก์</option>
      </select>
    </div>

    <!-- ── หัวกระดาษหน่วยงาน ── -->
    <div class="card mb-2">
      <label for="gOrg">ชื่อหน่วยงาน (บนหัวโปสเตอร์)</label>
      <input type="text" id="gOrg" class="mb-2" maxlength="80" value="<?= e(setting('site_name', '')) ?>" placeholder="เช่น กองกฎหมาย">
      <label for="gDept">สังกัด</label>
      <input type="text" id="gDept" class="mb-2" maxlength="120" value="<?= e(setting('site_dept', '')) ?>" placeholder="เช่น สำนักงานตำรวจแห่งชาติ">
      <label for="gDate">วันที่ (มุมล่าง)</label>
      <input type="text" id="gDate" maxlength="60" value="<?= e($pre['date']) ?>" placeholder="เช่น 14 ก.ย. 2569">
    </div>

    <!-- ── ข้อความ ── -->
    <div class="card mb-2">
      <label for="gKicker">ป้ายบนสุด</label>
      <input type="text" id="gKicker" class="mb-2" maxlength="40" value="<?= e($pre['kicker']) ?>" placeholder="เช่น ประกาศ / แจ้งเตือน">
      <label for="gHead">หัวข้อ</label>
      <textarea id="gHead" class="mb-2" rows="2" maxlength="200" placeholder="หัวข้อประกาศ"><?= e($pre['head'] !== '' ? $pre['head'] : 'ประกาศสำนักงานกฎหมายและคดี') ?></textarea>
      <label for="gBody">เนื้อหา</label>
      <textarea id="gBody" class="mb-2" rows="4" maxlength="800" placeholder="รายละเอียด — ขึ้นบรรทัดใหม่ได้"><?= e($pre['body'] !== '' ? $pre['body'] : 'เรื่อง เจตนารมณ์การป้องกันและแก้ไขปัญหาการล่วงละเมิดหรือคุกคามทางเพศในการทำงาน') ?></textarea>
      <label for="gFoot">ข้อความมุมล่าง</label>
      <input type="text" id="gFoot" maxlength="60" value="<?= e(setting('site_name', '')) ?>" placeholder="ชื่อหน่วยงาน">
    </div>

    <!-- ── ภาพพื้นหลัง ── -->
    <div class="card mb-2">
      <label>ภาพพื้นหลัง</label>
      <div class="flex gap-2 mb-2" style="flex-wrap:wrap;">
        <label class="btn small" style="margin:0;cursor:pointer;">
          <span class="material-symbols-rounded icon-sm">upload</span>อัปโหลดจากเครื่อง
          <input type="file" id="gFile" accept="image/*" hidden>
        </label>
        <button type="button" class="btn small" id="gClearImg" hidden>
          <span class="material-symbols-rounded icon-sm">close</span>เอารูปออก</button>
      </div>
      <?php if ($lib): ?>
      <div class="gfx-lib mb-2" id="gLib"></div>
      <p class="text-muted" style="font-size:11.5px;margin:0 0 10px;">คลิกเพื่อใช้รูปจากคลังสื่อ</p>
      <?php else: ?>
      <p class="text-muted" style="font-size:12px;margin:0 0 10px;">ยังไม่มีรูปในคลังสื่อ — อัปโหลดจากเครื่องได้เลย</p>
      <?php endif; ?>
      <label for="gOverlay">ความเข้มฉากมืดทับรูป <span id="gOverlayV" class="text-muted"></span></label>
      <input type="range" id="gOverlay" min="0" max="85" value="45" style="width:100%;">
    </div>

    <!-- ── ปรับแต่ง ── -->
    <div class="card mb-2">
      <label for="gScale">ขนาดตัวอักษร <span id="gScaleV" class="text-muted"></span></label>
      <input type="range" id="gScale" min="70" max="135" value="100" class="mb-2" style="width:100%;">
      <label for="gVal">ตำแหน่งข้อความแนวตั้ง</label>
      <select id="gVal" class="mb-2">
        <option value="mid">กึ่งกลาง</option>
        <option value="top">ชิดบน</option>
        <option value="bot">ชิดล่าง</option>
      </select>
      <label>ธีมสี</label>
      <div class="gfx-themes mb-2" id="gThemes"></div>
      <div class="form-row">
        <div>
          <label for="gColor">หรือเลือกสีเอง</label>
          <div class="flex gap-2 items-center mb-2">
            <input type="color" id="gColor" value="<?= e($theme) ?>">
            <button type="button" class="btn small" id="gReset">สีธีมเว็บ</button>
          </div>
        </div>
        <div>
          <label for="gIcon">ไอคอน</label>
          <select id="gIcon" class="mb-2">
            <option value="campaign">ประกาศ (โทรโข่ง)</option>
            <option value="gavel">กฎหมาย (ค้อน)</option>
            <option value="warning">คำเตือน</option>
            <option value="event">กิจกรรม</option>
            <option value="description">เอกสาร</option>
            <option value="verified">รับรอง</option>
            <option value="">ไม่ใส่ไอคอน</option>
          </select>
        </div>
      </div>
      <label class="inline-check"><input type="checkbox" id="gLogo" <?= $cfg['logo'] ? 'checked' : '' ?> <?= $cfg['logo'] ? '' : 'disabled' ?>>
        แสดงตราหน่วยงาน<?= $cfg['logo'] ? '' : ' (ยังไม่ได้อัปโหลดโลโก้)' ?></label>
      <label class="inline-check" style="margin-top:8px;"><input type="checkbox" id="gPlate">
        รองพื้นขาวหลังตรา <span class="text-muted" style="font-size:12px;">(เปิดถ้าตราสีเข้มแล้วจมบนพื้นสี)</span></label>
    </div>

    <div class="flex gap-2" style="flex-wrap:wrap;">
      <button type="button" class="btn primary" id="gDownload">
        <span class="material-symbols-rounded icon-sm">download</span>ดาวน์โหลด</button>
      <form method="post" action="" id="gForm" style="margin:0;">
        <?= csrf_field() ?>
        <input type="hidden" name="img" id="gImgData">
        <button type="submit" class="btn">
          <span class="material-symbols-rounded icon-sm">perm_media</span>บันทึกเข้าคลังสื่อ</button>
      </form>
    </div>
  </div>

  <!-- ── พรีวิว ── -->
  <div class="card gfx-stage">
    <canvas id="gCv" width="1080" height="1080"></canvas>
    <p class="text-muted" id="gDim" style="font-size:12px;margin:10px 0 0;text-align:center;"></p>
  </div>
</div>

<style>
.gfx-wrap { display: grid; grid-template-columns: minmax(0,400px) minmax(0,1fr); gap: 16px; align-items: start; }
@media (max-width: 980px) { .gfx-wrap { grid-template-columns: minmax(0,1fr); } }
.gfx-stage { position: sticky; top: 16px; display: flex; flex-direction: column; align-items: center; }
@media (max-width: 980px) { .gfx-stage { position: static; } }
#gCv { width: 100%; max-width: 440px; height: auto; border-radius: 12px;
       border: 1px solid var(--border); box-shadow: var(--shadow-soft); background: #fff; }
.gfx-tpl { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
.gfx-t { display: flex; align-items: center; gap: 7px; padding: 10px 12px; font-size: 13px; font-family: inherit;
  border: 1.5px solid var(--border); border-radius: 10px; background: #fff; color: var(--text); cursor: pointer; text-align: left; }
.gfx-t:hover { border-color: var(--blue); }
.gfx-t.on { border-color: var(--blue); background: color-mix(in srgb, var(--blue) 8%, transparent); color: var(--blue-700); font-weight: 500; }
.gfx-t .material-symbols-rounded { font-size: 19px; flex-shrink: 0; }
.gfx-themes { display: grid; grid-template-columns: repeat(auto-fill, minmax(72px, 1fr)); gap: 7px; }
.gfx-th { padding: 0; border: 1.5px solid var(--border); border-radius: 9px; background: #fff;
  cursor: pointer; overflow: hidden; font-family: inherit; }
.gfx-th:hover { border-color: var(--blue); }
.gfx-th.on { border-color: var(--blue); box-shadow: 0 0 0 3px color-mix(in srgb, var(--blue) 16%, transparent); }
.gfx-th i { display: block; height: 30px; }
.gfx-th span { display: block; font-size: 10.5px; color: var(--muted); padding: 4px 2px; line-height: 1.2; }
.gfx-lib { display: grid; grid-template-columns: repeat(auto-fill, minmax(56px, 1fr)); gap: 6px; max-height: 168px; overflow-y: auto; }
.gfx-lib img { width: 100%; aspect-ratio: 1; object-fit: cover; border-radius: 7px; cursor: pointer;
  border: 2px solid transparent; display: block; }
.gfx-lib img:hover, .gfx-lib img.on { border-color: var(--blue); }
</style>

<script nonce="<?= e(CSP_NONCE) ?>">
(function () {
  var CFG = <?= json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  var cv = document.getElementById('gCv'), ctx = cv.getContext('2d');
  var logoImg = null, bgImg = null, ready = false, tpl = 'poster';

  var $ = function (id) { return document.getElementById(id); };
  var F = {
    size: $('gSize'), kicker: $('gKicker'), head: $('gHead'), body: $('gBody'), foot: $('gFoot'),
    org: $('gOrg'), dept: $('gDept'), date: $('gDate'),
    overlay: $('gOverlay'), scale: $('gScale'), val: $('gVal'), color: $('gColor'),
    icon: $('gIcon'), logo: $('gLogo'), plate: $('gPlate')
  };

  /* ── ตัวช่วยวาด ── */
  function rr(x, y, w, h, r) {
    ctx.beginPath(); ctx.moveTo(x + r, y);
    ctx.arcTo(x + w, y, x + w, y + h, r); ctx.arcTo(x + w, y + h, x, y + h, r);
    ctx.arcTo(x, y + h, x, y, r); ctx.arcTo(x, y, x + w, y, r); ctx.closePath();
  }
  function shade(hex, pct) {
    var n = parseInt(hex.slice(1), 16);
    var f = function (v) { return Math.max(0, Math.min(255, Math.round(v * pct))); };
    return 'rgb(' + f((n >> 16) & 255) + ',' + f((n >> 8) & 255) + ',' + f(n & 255) + ')';
  }
  /* วาดรูปให้เต็มกรอบแบบ cover — ครอบส่วนเกินทิ้ง ไม่บีบรูปให้ผิดสัดส่วน */
  function cover(img, x, y, w, h) {
    var r = Math.max(w / img.naturalWidth, h / img.naturalHeight);
    var iw = img.naturalWidth * r, ih = img.naturalHeight * r;
    ctx.save(); ctx.beginPath(); ctx.rect(x, y, w, h); ctx.clip();
    ctx.drawImage(img, x + (w - iw) / 2, y + (h - ih) / 2, iw, ih);
    ctx.restore();
  }

  /* ภาษาไทยไม่เว้นวรรคระหว่างคำ — Intl.Segmenter ใช้พจนานุกรมในตัวเบราว์เซอร์จึงตัดตรงรอยต่อคำจริง
     เบราว์เซอร์เก่าที่ไม่มีให้ถอยไปตัดทีละอักขระ (อ่านได้ แต่รอยตัดไม่สวยเท่า) */
  var segmenter = null;
  try { segmenter = new Intl.Segmenter('th', { granularity: 'word' }); } catch (e) {}
  function tokens(s) {
    if (!segmenter) return s.split('');
    var out = [], it = segmenter.segment(s)[Symbol.iterator]();
    for (var r = it.next(); !r.done; r = it.next()) out.push(r.value.segment);
    return out;
  }
  function wrap(text, maxW) {
    var lines = [];
    String(text).split('\n').forEach(function (para) {
      if (para === '') { lines.push(''); return; }
      var line = '';
      tokens(para).forEach(function (t) {
        var test = line + t;
        if (line !== '' && ctx.measureText(test).width > maxW) { lines.push(line); line = t.replace(/^\s+/, ''); }
        else line = test;
      });
      lines.push(line);
    });
    return lines;
  }
  function drawLines(lines, cx, y, lh) {
    lines.forEach(function (l, i) { ctx.fillText(l, cx, y + i * lh); });
    return y + lines.length * lh;
  }
  function drawIcon(name, cx, cy, size, color) {
    if (!name) return false;
    ctx.save();
    ctx.font = size + 'px "Material Symbols Rounded"';
    ctx.textAlign = 'center'; ctx.textBaseline = 'middle'; ctx.fillStyle = color;
    ctx.fillText(name, cx, cy); ctx.restore();
    return true;
  }
  function drawLogo(cx, cy, size) {
    if (!F.logo.checked || !logoImg || !logoImg.complete || !logoImg.naturalWidth) return false;
    var r = Math.min(size / logoImg.naturalWidth, size / logoImg.naturalHeight);
    var w = logoImg.naturalWidth * r, h = logoImg.naturalHeight * r;
    ctx.drawImage(logoImg, cx - w / 2, cy - h / 2, w, h);
    return true;
  }

  /* วางก้อนเนื้อหาตามตำแหน่งแนวตั้งที่เลือก — วัดความสูงก่อนเสมอ
     ถ้าวาดไล่จากบนลงล่างเลย ข้อความสั้นจะกองอยู่ครึ่งบนและเหลือที่ว่างครึ่งล่างทั้งแผ่น */
  function placeY(top, avail, blockH) {
    var v = F.val.value;
    if (v === 'top') return top;
    if (v === 'bot') return top + Math.max(0, avail - blockH);
    return top + Math.max(0, Math.round((avail - blockH) / 2));
  }

  /* ── บล็อกข้อความกลาง ใช้ร่วมทุกเทมเพลต ── */
  function textBlock(o) {
    var W = o.W, s = +F.scale.value / 100, cx = o.cx, maxW = o.maxW;
    var kick = F.kicker.value.trim();
    var markH = o.mark ? Math.round(o.H * .115 * s) : 0;
    var kickH = kick ? Math.round(o.H * .085 * s) : 0;
    var headLH = Math.round(W * .070 * s), bodyLH = Math.round(W * .056 * s);
    var headF = '700 ' + Math.round(W * .052 * s) + 'px Prompt, sans-serif';
    var bodyF = (o.bodyWeight || '500') + ' ' + Math.round(W * .039 * s) + 'px Prompt, sans-serif';

    ctx.font = headF; var headLines = wrap(F.head.value, maxW);
    ctx.font = bodyF; var bodyLines = wrap(F.body.value, maxW);
    var gap = Math.round(o.H * .028);
    var blockH = markH + kickH + headLines.length * headLH + (bodyLines.length ? gap + bodyLines.length * bodyLH : 0);

    var y = placeY(o.top, o.avail, blockH) + Math.round(o.H * .03);
    ctx.textAlign = 'center';

    if (o.mark) {
      if (!drawLogo(cx, y, Math.round(W * .105 * s))) drawIcon(F.icon.value, cx, y, Math.round(W * .10 * s), o.iconColor);
      y += markH;
    }
    if (kick) {
      ctx.font = '600 ' + Math.round(W * .031 * s) + 'px Prompt, sans-serif';
      if (o.kickChip) {
        var kw = ctx.measureText(kick).width, kh = Math.round(W * .057 * s);
        ctx.fillStyle = o.kickBg;
        rr(cx - kw / 2 - kh * .45, y - kh * .70, kw + kh * .9, kh, kh / 2); ctx.fill();
        ctx.fillStyle = o.kickFg; ctx.textBaseline = 'middle';
        ctx.fillText(kick, cx, y - kh * .20);
      } else {
        ctx.fillStyle = o.kickFg; ctx.textBaseline = 'alphabetic';
        ctx.fillText(kick, cx, y);
      }
      y += kickH;
    }
    ctx.textBaseline = 'alphabetic';
    ctx.fillStyle = o.headColor; ctx.font = headF;
    y = drawLines(headLines, cx, y, headLH);
    if (bodyLines.length) {
      y += gap;
      ctx.fillStyle = o.bodyColor; ctx.font = bodyF;
      drawLines(bodyLines, cx, y, bodyLH);
    }
  }

  function footer(W, H, x, w, color, rule) {
    var foot = F.foot.value.trim();
    if (!foot) return;
    var fy = H - Math.round(H * .055);
    if (rule) {
      ctx.strokeStyle = rule; ctx.lineWidth = Math.max(1, W * .0015);
      ctx.beginPath();
      ctx.moveTo(x, fy - Math.round(H * .032)); ctx.lineTo(x + w, fy - Math.round(H * .032));
      ctx.stroke();
    }
    ctx.fillStyle = color; ctx.textAlign = 'center'; ctx.textBaseline = 'alphabetic';
    ctx.font = '400 ' + Math.round(W * .027) + 'px Prompt, sans-serif';
    ctx.fillText(foot, x + w / 2, fy);
  }

  /* ── เทมเพลต ── */
  function tplCard(W, H, c) {
    var dark = shade(c, .62);
    if (bgImg) {
      cover(bgImg, 0, 0, W, H);
      ctx.fillStyle = 'rgba(0,0,0,' + (F.overlay.value / 100) + ')'; ctx.fillRect(0, 0, W, H);
    } else {
      ctx.fillStyle = dark; ctx.fillRect(0, 0, W, H);
      ctx.fillStyle = shade(c, .78);
      ctx.beginPath(); ctx.moveTo(0, 0); ctx.lineTo(W, 0); ctx.lineTo(W, H * .30);
      ctx.quadraticCurveTo(W * .5, H * .40, 0, H * .24); ctx.closePath(); ctx.fill();
    }
    var pad = Math.round(W * .075);
    var cardY = Math.round(H * .13), cardH = H - cardY - pad, cardW = W - pad * 2;
    ctx.fillStyle = '#fff'; rr(pad, cardY, cardW, cardH, Math.round(W * .045)); ctx.fill();

    var footH = F.foot.value.trim() ? Math.round(H * .085) : Math.round(H * .03);
    textBlock({ W: W, H: H, cx: W / 2, maxW: cardW - Math.round(W * .12),
      top: cardY, avail: cardH - footH, mark: true, kickChip: true,
      kickBg: c, kickFg: '#fff', headColor: dark, bodyColor: c, iconColor: c });
    footer(W, H, pad + Math.round(W * .06), cardW - Math.round(W * .12), 'rgba(0,0,0,.55)', 'rgba(0,0,0,.12)');
  }

  function tplBold(W, H, c) {
    if (bgImg) {
      cover(bgImg, 0, 0, W, H);
      ctx.fillStyle = 'rgba(0,0,0,' + (F.overlay.value / 100) + ')'; ctx.fillRect(0, 0, W, H);
    } else {
      ctx.fillStyle = c; ctx.fillRect(0, 0, W, H);
      ctx.fillStyle = 'rgba(255,255,255,.10)';
      ctx.beginPath(); ctx.arc(W * .88, H * .12, W * .30, 0, Math.PI * 2); ctx.fill();
      ctx.beginPath(); ctx.arc(W * .10, H * .92, W * .24, 0, Math.PI * 2); ctx.fill();
    }
    textBlock({ W: W, H: H, cx: W / 2, maxW: W - Math.round(W * .20),
      top: Math.round(H * .10), avail: H - Math.round(H * .20), mark: true, kickChip: false,
      kickFg: 'rgba(255,255,255,.85)', headColor: '#fff', bodyColor: 'rgba(255,255,255,.92)',
      bodyWeight: '400', iconColor: 'rgba(255,255,255,.92)' });
    footer(W, H, Math.round(W * .10), W - Math.round(W * .20), 'rgba(255,255,255,.75)', '');
  }

  function tplPhoto(W, H, c) {
    if (bgImg) cover(bgImg, 0, 0, W, H);
    else { ctx.fillStyle = shade(c, .55); ctx.fillRect(0, 0, W, H); }
    /* ไล่เข้มจากล่างขึ้นบน — ข้อความอยู่ครึ่งล่างจึงต้องมืดตรงนั้นมากกว่า */
    var g = ctx.createLinearGradient(0, H * .25, 0, H);
    var a = F.overlay.value / 100;
    g.addColorStop(0, 'rgba(0,0,0,' + (a * .25) + ')');
    g.addColorStop(1, 'rgba(0,0,0,' + Math.min(.95, a + .28) + ')');
    ctx.fillStyle = g; ctx.fillRect(0, 0, W, H);
    ctx.fillStyle = c; ctx.fillRect(0, H - Math.round(H * .012), W, Math.round(H * .012));

    textBlock({ W: W, H: H, cx: W / 2, maxW: W - Math.round(W * .16),
      top: Math.round(H * .34), avail: Math.round(H * .52), mark: true, kickChip: true,
      kickBg: c, kickFg: '#fff', headColor: '#fff', bodyColor: 'rgba(255,255,255,.92)',
      bodyWeight: '400', iconColor: '#fff' });
    footer(W, H, Math.round(W * .08), W - Math.round(W * .16), 'rgba(255,255,255,.8)', '');
  }

  function tplBanner(W, H, c) {
    var split = Math.round(H * .50);
    if (bgImg) {
      cover(bgImg, 0, 0, W, split);
      ctx.fillStyle = 'rgba(0,0,0,' + (F.overlay.value / 100 * .5) + ')'; ctx.fillRect(0, 0, W, split);
    } else {
      ctx.fillStyle = shade(c, .78); ctx.fillRect(0, 0, W, split);
      drawIcon(F.icon.value, W / 2, split / 2, Math.round(W * .18), 'rgba(255,255,255,.35)');
    }
    ctx.fillStyle = c; ctx.fillRect(0, split, W, H - split);

    var footH = F.foot.value.trim() ? Math.round(H * .085) : Math.round(H * .03);
    textBlock({ W: W, H: H, cx: W / 2, maxW: W - Math.round(W * .14),
      top: split, avail: H - split - footH, mark: false, kickChip: false,
      kickFg: 'rgba(255,255,255,.85)', headColor: '#fff', bodyColor: 'rgba(255,255,255,.90)',
      bodyWeight: '400', iconColor: '#fff' });
    footer(W, H, Math.round(W * .07), W - Math.round(W * .14), 'rgba(255,255,255,.75)', '');
  }

  /* โปสเตอร์ข่าว — วางแบบสื่อประชาสัมพันธ์ราชการ
     หัวกระดาษ (ตรา + ชื่อหน่วยงาน + สังกัด) → รูปใหญ่ → เนื้อหาบนพื้นขาว → แถบท้าย
     หัวกระดาษคือสิ่งที่ทำให้ดูเป็นเอกสารทางการ ไม่ใช่ภาพที่ใครก็ทำได้ */
  function tplPoster(W, H, c) {
    var s = +F.scale.value / 100;
    var dark = shade(c, .55);
    ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, W, H);

    /* ── หัวกระดาษ ── */
    var hdrH = Math.round(H * .112);
    ctx.fillStyle = c; ctx.fillRect(0, 0, W, hdrH);
    var pad = Math.round(W * .062);
    /* ตราวางบนพื้นสีตรงๆ ไม่มีแผ่นขาวรอง จะได้กลืนไปกับหัวกระดาษเหมือนตราบนหัวจดหมายจริง
       แต่เปิดแผ่นขาวได้ เผื่อหน่วยงานที่ตราเป็นสีเข้มแล้วจมหายไปบนพื้นสีเข้ม */
    var plate = F.plate.checked;
    var badge = Math.round(hdrH * (plate ? .70 : .86));
    var bx = pad, by = (hdrH - badge) / 2;
    if (plate) { ctx.fillStyle = '#fff'; rr(bx, by, badge, badge, Math.round(badge * .26)); ctx.fill(); }
    if (!drawLogo(bx + badge / 2, by + badge / 2, Math.round(badge * (plate ? .76 : 1)))) {
      drawIcon(F.icon.value, bx + badge / 2, by + badge / 2, Math.round(badge * .62), plate ? c : '#fff');
    }
    var tx = bx + badge + Math.round(W * .030);
    var org = F.org.value.trim(), dept = F.dept.value.trim();
    ctx.textAlign = 'left'; ctx.textBaseline = 'alphabetic'; ctx.fillStyle = '#fff';
    if (org) {
      ctx.font = '700 ' + Math.round(W * .040) + 'px Prompt, sans-serif';
      ctx.fillText(org, tx, dept ? hdrH * .47 : hdrH * .60);
    }
    if (dept) {
      ctx.font = '400 ' + Math.round(W * .025) + 'px Prompt, sans-serif';
      ctx.fillStyle = 'rgba(255,255,255,.85)';
      ctx.fillText(dept, tx, org ? hdrH * .73 : hdrH * .60);
    }

    /* ── รูปใหญ่ ── */
    var imgTop = hdrH, imgH = Math.round(H * .345);
    if (bgImg) cover(bgImg, 0, imgTop, W, imgH);
    else {
      ctx.fillStyle = shade(c, .90); ctx.fillRect(0, imgTop, W, imgH);
      drawIcon(F.icon.value, W / 2, imgTop + imgH / 2, Math.round(W * .16), 'rgba(255,255,255,.45)');
    }
    /* เส้นสีคั่นบางๆ ใต้รูป ช่วยแยกรูปกับเนื้อหาให้คม */
    ctx.fillStyle = c; ctx.fillRect(0, imgTop + imgH - Math.round(H * .006), W, Math.round(H * .006));

    /* ── เนื้อหา ── */
    var footH = Math.round(H * .085);
    var top = imgTop + imgH, avail = H - top - footH;
    var cx = W / 2, maxW = W - pad * 2;
    var kick = F.kicker.value.trim();
    var kickH = kick ? Math.round(H * .062 * s) : 0;
    var headLH = Math.round(W * .062 * s), bodyLH = Math.round(W * .043 * s);
    ctx.font = '700 ' + Math.round(W * .048 * s) + 'px Prompt, sans-serif';
    var headLines = wrap(F.head.value, maxW);
    ctx.font = '400 ' + Math.round(W * .031 * s) + 'px Prompt, sans-serif';
    var bodyLines = wrap(F.body.value, maxW);
    var gap = Math.round(H * .022);
    var blockH = kickH + headLines.length * headLH + (bodyLines.length ? gap + bodyLines.length * bodyLH : 0);
    var y = placeY(top, avail, blockH) + Math.round(H * .045);

    ctx.textAlign = 'center';
    if (kick) {
      ctx.font = '600 ' + Math.round(W * .026 * s) + 'px Prompt, sans-serif';
      var kw = ctx.measureText(kick).width, kh = Math.round(W * .048 * s);
      ctx.fillStyle = c;
      rr(cx - kw / 2 - kh * .45, y - kh * .72, kw + kh * .9, kh, kh / 2); ctx.fill();
      ctx.fillStyle = '#fff'; ctx.textBaseline = 'middle';
      ctx.fillText(kick, cx, y - kh * .22);
      y += kickH;
    }
    ctx.textBaseline = 'alphabetic';
    ctx.fillStyle = dark;
    ctx.font = '700 ' + Math.round(W * .048 * s) + 'px Prompt, sans-serif';
    y = drawLines(headLines, cx, y, headLH);
    if (bodyLines.length) {
      y += gap;
      ctx.fillStyle = 'rgba(15,23,42,.72)';
      ctx.font = '400 ' + Math.round(W * .031 * s) + 'px Prompt, sans-serif';
      drawLines(bodyLines, cx, y, bodyLH);
    }

    /* ── แถบท้าย: วันที่ซ้าย ข้อความมุมล่างขวา ── */
    ctx.fillStyle = shade(c, .40); ctx.fillRect(0, H - footH, W, footH);
    var fy = H - footH / 2;
    ctx.textBaseline = 'middle';
    ctx.font = '400 ' + Math.round(W * .026) + 'px Prompt, sans-serif';
    var dt = F.date.value.trim(), ft = F.foot.value.trim();
    if (dt) { ctx.textAlign = 'left';  ctx.fillStyle = 'rgba(255,255,255,.92)'; ctx.fillText(dt, pad, fy); }
    if (ft) { ctx.textAlign = 'right'; ctx.fillStyle = 'rgba(255,255,255,.92)'; ctx.fillText(ft, W - pad, fy); }
    ctx.textBaseline = 'alphabetic';
  }

  var TPL = { poster: tplPoster, card: tplCard, bold: tplBold, photo: tplPhoto, banner: tplBanner };

  function render() {
    if (!ready) return;
    var wh = F.size.value.split('x'), W = +wh[0], H = +wh[1];
    if (cv.width !== W || cv.height !== H) { cv.width = W; cv.height = H; }
    ctx.clearRect(0, 0, W, H);
    (TPL[tpl] || tplCard)(W, H, F.color.value || CFG.color);
    $('gDim').textContent = 'ขนาดจริง ' + W + ' × ' + H + ' พิกเซล';
    $('gOverlayV').textContent = F.overlay.value + '%';
    $('gScaleV').textContent = F.scale.value + '%';
  }

  /* ── ผูกการควบคุม ── */
  Object.keys(F).forEach(function (k) {
    F[k].addEventListener('input', render);
    F[k].addEventListener('change', render);
  });
  Array.prototype.forEach.call(document.querySelectorAll('.gfx-t'), function (b) {
    b.addEventListener('click', function () {
      Array.prototype.forEach.call(document.querySelectorAll('.gfx-t'), function (x) { x.classList.remove('on'); });
      b.classList.add('on'); tpl = b.dataset.tpl; render();
    });
  });
  $('gReset').addEventListener('click', function () {
    F.color.value = CFG.color; markTheme(CFG.color); render();
  });

  /* ธีมสีคัดมาแล้ว — ผู้ใช้ส่วนใหญ่เลือกสีเองแล้วออกมาไม่เข้ากัน
     ทุกสีในชุดนี้เข้มพอให้ตัวอักษรขาวอ่านออกบนพื้น และเข้ากับงานราชการ */
  var THEMES = [
    ['ทางการ',     '#1B3A6B'], ['ราชการ',      '#A8201A'],
    ['น้ำเงินสด',   '#1A73E8'], ['เขียวมรกต',   '#0F766E'],
    ['ม่วงหรูหรา', '#5B21B6'], ['ส้มอบอุ่น',    '#C2410C'],
    ['เทาสุขุม',   '#334155'], ['ชมพูเข้ม',     '#9D174D']
  ];
  var themeBox = $('gThemes');
  function markTheme(hex) {
    Array.prototype.forEach.call(themeBox.children, function (b) {
      b.classList.toggle('on', (b.dataset.c || '').toLowerCase() === String(hex).toLowerCase());
    });
  }
  THEMES.forEach(function (t) {
    var b = document.createElement('button');
    b.type = 'button'; b.className = 'gfx-th'; b.dataset.c = t[1]; b.title = t[0];
    var i = document.createElement('i'); i.style.background = t[1];
    var s = document.createElement('span'); s.textContent = t[0];
    b.appendChild(i); b.appendChild(s);
    b.addEventListener('click', function () { F.color.value = t[1]; markTheme(t[1]); render(); });
    themeBox.appendChild(b);
  });
  F.color.addEventListener('input', function () { markTheme(F.color.value); });
  markTheme(F.color.value);

  function setBg(src, el) {
    var im = new Image();
    im.onload = function () { bgImg = im; $('gClearImg').hidden = false; render(); };
    im.onerror = function () { alert('เปิดไฟล์ภาพไม่ได้'); };
    im.src = src;
    Array.prototype.forEach.call(document.querySelectorAll('.gfx-lib img'), function (x) { x.classList.remove('on'); });
    if (el) el.classList.add('on');
  }
  $('gFile').addEventListener('change', function (ev) {
    var f = ev.target.files && ev.target.files[0];
    if (!f) return;
    var fr = new FileReader();
    fr.onload = function () { setBg(fr.result, null); };
    fr.readAsDataURL(f);
  });
  $('gClearImg').addEventListener('click', function () {
    bgImg = null; this.hidden = true; $('gFile').value = '';
    Array.prototype.forEach.call(document.querySelectorAll('.gfx-lib img'), function (x) { x.classList.remove('on'); });
    render();
  });

  var libBox = $('gLib');
  if (libBox) {
    CFG.lib.forEach(function (u) {
      var im = document.createElement('img');
      im.src = u; im.loading = 'lazy'; im.alt = '';
      im.addEventListener('click', function () { setBg(u, im); });
      libBox.appendChild(im);
    });
  }

  /* ── ส่งออก ── */
  /* มีรูปถ่ายให้ใช้ JPEG — PNG ของภาพถ่ายใหญ่กว่าหลายเท่าและชนเพดานอัปโหลดง่าย
     ไม่มีรูปใช้ PNG เพราะพื้นสีเรียบกับตัวอักษรจะคมกว่าและไฟล์เล็กอยู่แล้ว */
  function exportData() {
    try {
      return bgImg ? cv.toDataURL('image/jpeg', 0.92) : cv.toDataURL('image/png');
    } catch (err) {
      alert('บันทึกภาพไม่ได้ เพราะมีรูปที่โหลดมาจากโดเมนอื่นอยู่ในภาพ\n\n'
          + 'วิธีแก้: อัปโหลดรูปเข้าระบบโดยตรง แทนการใช้ลิงก์ภายนอก');
      return null;
    }
  }
  $('gDownload').addEventListener('click', function () {
    var d = exportData(); if (!d) return;
    var a = document.createElement('a');
    a.download = 'ประกาศ-' + Date.now() + (bgImg ? '.jpg' : '.png');
    a.href = d; a.click();
  });
  $('gForm').addEventListener('submit', function (ev) {
    var d = exportData();
    if (!d) { ev.preventDefault(); return; }
    $('gImgData').value = d;
  });

  /* วาดหลังฟอนต์พร้อมเท่านั้น — ถ้าวาดก่อน ตัวอักษรจะตกไปใช้ฟอนต์สำรองแล้วความกว้างเพี้ยน
     ทำให้ตัดบรรทัดผิดและภาพที่บันทึกไม่ตรงกับที่เห็นบนจอ */
  function start() { ready = true; render(); }
  var fonts = ['700 40px Prompt', '600 40px Prompt', '500 40px Prompt', '400 40px Prompt',
               '100px "Material Symbols Rounded"'];
  if (document.fonts && document.fonts.load) {
    Promise.all(fonts.map(function (f) { return document.fonts.load(f, 'ประกาศ'); }))
      .then(function () { return document.fonts.ready; }).then(start).catch(start);
  } else { start(); }

  if (CFG.logo) {
    logoImg = new Image();
    logoImg.onload = render;
    logoImg.onerror = function () { logoImg = null; render(); };
    logoImg.src = CFG.logo;
  }

  /* รูปปกข่าวที่ส่งมาจากปุ่ม "สร้างภาพ" — โหลดเป็นพื้นหลังให้เลย
     ไม่ต้องสลับเทมเพลต เพราะโปสเตอร์ข่าว (ค่าเริ่มต้น) มีช่องใส่รูปอยู่แล้ว */
  if (CFG.bg) {
    var b = new Image();
    b.onload = function () { bgImg = b; $('gClearImg').hidden = false; render(); };
    b.onerror = function () { render(); };
    b.src = CFG.bg;
  }
})();
</script>
<?php require __DIR__ . '/_bottom.php'; ?>
