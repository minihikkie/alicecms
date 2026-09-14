<?php
/**
 * admin/graphic.php — ออกแบบภาพประกาศในตัว
 *
 * ทำไมต้องมี: เจ้าหน้าที่ต้องออกไปทำภาพประกาศในแอปภายนอกทุกครั้ง แล้วค่อยกลับมาอัปโหลด
 * เสียเวลาและภาพออกมาไม่เป็นแนวเดียวกัน หน้านี้ทำให้จบในเว็บเดียว
 *
 * วิธีวาด: ใช้ Canvas API ของเบราว์เซอร์ ไม่พึ่งไลบรารีภายนอกเลย
 *  - CSP ของระบบอนุญาตสคริปต์เฉพาะ 'self' อยู่แล้ว จะโหลดไลบรารีจาก CDN ไม่ได้
 *  - เบราว์เซอร์จัดรูปอักษรไทย (สระบน/ล่าง วรรณยุกต์) ให้ถูกต้องเองอยู่แล้ว
 *    ถ้าไปวาดฝั่งเซิร์ฟเวอร์ด้วย GD ต้องจัดการเรื่องนี้เองทั้งหมดและมักเพี้ยน
 *  - ตัดบรรทัดภาษาไทยด้วย Intl.Segmenter ซึ่งใช้พจนานุกรมในตัวเบราว์เซอร์
 *    (ภาษาไทยไม่เว้นวรรคระหว่างคำ ตัดตามช่องว่างแบบภาษาอังกฤษไม่ได้)
 */
require dirname(__DIR__) . '/includes/init.php';
require __DIR__ . '/_auth.php';

/* ── บันทึกภาพที่วาดเสร็จเข้าคลังสื่อ ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['png'])) {
    try {
        $data = (string)$_POST['png'];
        $pfx  = 'data:image/png;base64,';
        if (strncmp($data, $pfx, strlen($pfx)) !== 0) throw new RuntimeException('รูปแบบภาพไม่ถูกต้อง');
        $raw = base64_decode(substr($data, strlen($pfx)), true);
        if ($raw === false || $raw === '') throw new RuntimeException('ถอดรหัสภาพไม่สำเร็จ');
        if (strlen($raw) > 8 * 1048576) throw new RuntimeException('ภาพใหญ่เกิน 8 MB');

        /* ตรวจจากเนื้อไฟล์จริง ไม่เชื่อส่วนหัวที่ฝั่งเบราว์เซอร์ส่งมา */
        if (substr($raw, 0, 8) !== "\x89PNG\r\n\x1a\n") throw new RuntimeException('ไฟล์ที่ส่งมาไม่ใช่ PNG');
        $info = @getimagesizefromstring($raw);
        if (!$info || $info[2] !== IMAGETYPE_PNG)       throw new RuntimeException('ไฟล์ที่ส่งมาไม่ใช่ PNG');

        $dir = APP_ROOT . '/uploads/media';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) throw new RuntimeException('สร้างโฟลเดอร์คลังสื่อไม่ได้');
        $name = date('Ymd') . '-' . bin2hex(random_bytes(8)) . '.png';
        $full = $dir . '/' . $name;

        /* เทียบจำนวนไบต์ที่เขียนได้จริง — ถ้าดิสก์เต็มจะได้ไฟล์ไม่ครบ ต้องลบทิ้งไม่ให้เหลือไฟล์เสีย */
        $wrote = @file_put_contents($full, $raw);
        if ($wrote !== strlen($raw)) {
            @unlink($full);
            throw new RuntimeException('บันทึกไฟล์ไม่สำเร็จ — พื้นที่ดิสก์อาจเต็ม');
        }

        $path = 'uploads/media/' . $name;
        db()->prepare('INSERT INTO media (path, orig, mime, size) VALUES (?,?,?,?)')
            ->execute([$path, 'ภาพประกาศ.png', 'png', strlen($raw)]);
        log_action('สร้างภาพประกาศ', $name);
        flash_set('success', 'บันทึกเข้าคลังสื่อแล้ว — เลือกใช้เป็นภาพปกข่าวได้เลย');
    } catch (Throwable $ex) {
        flash_set('danger', $ex->getMessage());
    }
    redirect('admin/graphic.php');
}

$page_title = 'ออกแบบภาพประกาศ';
$theme = valid_hex(setting('theme_color', '')) ? setting('theme_color') : '#1A73E8';
$cfg = [
    'logo' => setting('logo') ? url(setting('logo')) : '',
    'org'  => setting('site_name', 'หน่วยงาน'),
    'dept' => setting('site_dept', ''),
    'color'=> $theme,
];
require __DIR__ . '/_top.php';
?>
<div class="section-head">
  <div><span class="tag"><span class="material-symbols-rounded icon-sm">design_services</span> STUDIO</span>
  <h2>ออกแบบภาพประกาศ</h2></div>
</div>
<p class="text-muted mb-2" style="font-size:14px;">
  กรอกข้อความแล้วดูผลทันที เสร็จแล้วบันทึกเข้าคลังสื่อเพื่อใช้เป็นภาพปกข่าวได้เลย ไม่ต้องออกไปทำในแอปอื่น
</p>

<div class="gfx-wrap">
  <!-- ── ฝั่งตั้งค่า ── -->
  <div class="card">
    <label for="gTpl">รูปแบบ</label>
    <select id="gTpl" class="mb-2">
      <option value="card">การ์ดประกาศ — มีกรอบขาว เหมาะกับข้อความยาว</option>
      <option value="bold">ข้อความเน้น — ตัวใหญ่เต็มพื้น เหมาะกับประกาศสั้น</option>
    </select>

    <label for="gSize">ขนาด</label>
    <select id="gSize" class="mb-2">
      <option value="1080x1080">จัตุรัส 1080×1080 — Facebook / Line</option>
      <option value="1080x1350">แนวตั้ง 1080×1350 — Instagram / Line</option>
      <option value="1200x630">แนวนอน 1200×630 — ภาพปกลิงก์</option>
    </select>

    <label for="gKicker">ป้ายบนสุด</label>
    <input type="text" id="gKicker" class="mb-2" maxlength="40" value="ประกาศ" placeholder="เช่น ประกาศ / แจ้งเตือน">

    <label for="gHead">หัวข้อ</label>
    <textarea id="gHead" class="mb-2" rows="2" maxlength="160" placeholder="หัวข้อประกาศ">ประกาศสำนักงานกฎหมายและคดี</textarea>

    <label for="gBody">เนื้อหา</label>
    <textarea id="gBody" class="mb-2" rows="5" maxlength="600" placeholder="รายละเอียดประกาศ">เรื่อง เจตนารมณ์การป้องกันและแก้ไขปัญหาการล่วงละเมิดหรือคุกคามทางเพศในการทำงาน</textarea>

    <label for="gFoot">ข้อความมุมล่าง</label>
    <input type="text" id="gFoot" class="mb-2" maxlength="60" value="<?= e($cfg['org']) ?>" placeholder="ชื่อหน่วยงาน">

    <div class="form-row">
      <div>
        <label for="gColor">สีหลัก</label>
        <div class="flex gap-2 items-center mb-2">
          <input type="color" id="gColor" value="<?= e($theme) ?>">
          <button type="button" class="btn small" id="gReset">ใช้สีธีมเว็บ</button>
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
          <option value="">ไม่ใส่ไอคอน</option>
        </select>
      </div>
    </div>

    <label class="inline-check mb-2"><input type="checkbox" id="gLogo" <?= $cfg['logo'] ? 'checked' : '' ?> <?= $cfg['logo'] ? '' : 'disabled' ?>>
      แสดงตราหน่วยงาน<?= $cfg['logo'] ? '' : ' (ยังไม่ได้อัปโหลดโลโก้)' ?></label>

    <div class="flex gap-2" style="flex-wrap:wrap;">
      <button type="button" class="btn primary" id="gDownload">
        <span class="material-symbols-rounded icon-sm">download</span>ดาวน์โหลด PNG</button>
      <form method="post" action="" id="gForm" style="margin:0;">
        <?= csrf_field() ?>
        <input type="hidden" name="png" id="gPng">
        <button type="submit" class="btn" id="gSave">
          <span class="material-symbols-rounded icon-sm">perm_media</span>บันทึกเข้าคลังสื่อ</button>
      </form>
    </div>
  </div>

  <!-- ── ฝั่งพรีวิว ── -->
  <div class="card gfx-stage">
    <canvas id="gCv" width="1080" height="1080"></canvas>
    <p class="text-muted" id="gDim" style="font-size:12px;margin:10px 0 0;text-align:center;"></p>
  </div>
</div>

<style>
.gfx-wrap { display: grid; grid-template-columns: minmax(0,380px) minmax(0,1fr); gap: 16px; align-items: start; }
@media (max-width: 900px) { .gfx-wrap { grid-template-columns: minmax(0,1fr); } }
.gfx-stage { display: flex; flex-direction: column; align-items: center; }
#gCv { width: 100%; max-width: 460px; height: auto; border-radius: 12px;
       border: 1px solid var(--border); box-shadow: var(--shadow-soft); background: #fff; }
</style>

<script nonce="<?= e(CSP_NONCE) ?>">
(function () {
  var CFG = <?= json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  var cv = document.getElementById('gCv'), ctx = cv.getContext('2d');
  var logoImg = null, ready = false;

  var $ = function (id) { return document.getElementById(id); };
  var F = {
    tpl: $('gTpl'), size: $('gSize'), kicker: $('gKicker'), head: $('gHead'),
    body: $('gBody'), foot: $('gFoot'), color: $('gColor'), icon: $('gIcon'), logo: $('gLogo')
  };

  /* ── ตัวช่วยวาด ── */
  function rr(x, y, w, h, r) {
    ctx.beginPath();
    ctx.moveTo(x + r, y);
    ctx.arcTo(x + w, y, x + w, y + h, r);
    ctx.arcTo(x + w, y + h, x, y + h, r);
    ctx.arcTo(x, y + h, x, y, r);
    ctx.arcTo(x, y, x + w, y, r);
    ctx.closePath();
  }

  function shade(hex, pct) {
    var n = parseInt(hex.slice(1), 16), r = (n >> 16) & 255, g = (n >> 8) & 255, b = n & 255;
    r = Math.max(0, Math.min(255, Math.round(r * pct)));
    g = Math.max(0, Math.min(255, Math.round(g * pct)));
    b = Math.max(0, Math.min(255, Math.round(b * pct)));
    return 'rgb(' + r + ',' + g + ',' + b + ')';
  }

  /* แบ่งข้อความเป็นหน่วยที่ตัดบรรทัดได้ — ภาษาไทยไม่เว้นวรรคระหว่างคำ
     Intl.Segmenter ใช้พจนานุกรมในตัวเบราว์เซอร์ จึงตัดตรงรอยต่อคำจริง
     เบราว์เซอร์เก่าที่ไม่มีให้ถอยไปตัดทีละอักขระ (อ่านได้ แต่รอยตัดไม่สวยเท่า) */
  var segmenter = null;
  try { segmenter = new Intl.Segmenter('th', { granularity: 'word' }); } catch (e) {}
  function tokens(s) {
    if (segmenter) {
      var out = [];
      var it = segmenter.segment(s)[Symbol.iterator]();
      for (var r = it.next(); !r.done; r = it.next()) out.push(r.value.segment);
      return out;
    }
    return s.split('');
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

  function drawLines(lines, cx, y, lh, align) {
    ctx.textAlign = align || 'center';
    lines.forEach(function (l, i) { ctx.fillText(l, cx, y + i * lh); });
    return y + lines.length * lh;
  }

  function drawIcon(name, cx, cy, size, color) {
    if (!name) return;
    ctx.save();
    ctx.font = size + 'px "Material Symbols Rounded"';
    ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
    ctx.fillStyle = color;
    ctx.fillText(name, cx, cy);
    ctx.restore();
  }

  function drawLogo(cx, cy, size) {
    if (!F.logo.checked || !logoImg || !logoImg.complete || !logoImg.naturalWidth) return false;
    var r = Math.min(size / logoImg.naturalWidth, size / logoImg.naturalHeight);
    var w = logoImg.naturalWidth * r, h = logoImg.naturalHeight * r;
    ctx.drawImage(logoImg, cx - w / 2, cy - h / 2, w, h);
    return true;
  }

  /* ── เทมเพลต ── */
  function tplCard(W, H, c) {
    var dark = shade(c, .62);
    ctx.fillStyle = dark; ctx.fillRect(0, 0, W, H);

    /* โค้งอ่อนๆ ด้านบน ให้พื้นไม่เป็นสี่เหลี่ยมทื่อ */
    ctx.fillStyle = shade(c, .78);
    ctx.beginPath();
    ctx.moveTo(0, 0); ctx.lineTo(W, 0); ctx.lineTo(W, H * .30);
    ctx.quadraticCurveTo(W * .5, H * .40, 0, H * .24);
    ctx.closePath(); ctx.fill();

    var pad = Math.round(W * .075);
    var cardX = pad, cardY = Math.round(H * .13), cardW = W - pad * 2, cardH = H - cardY - pad;
    ctx.fillStyle = '#fff';
    rr(cardX, cardY, cardW, cardH, Math.round(W * .045)); ctx.fill();

    var cx = W / 2;
    var maxW = cardW - Math.round(W * .12);
    var kick = F.kicker.value.trim();

    /* วัดความสูงของทุกชิ้นก่อน แล้วค่อยหาจุดเริ่มให้ก้อนเนื้อหาอยู่กึ่งกลางการ์ด
       ถ้าวาดไล่จากบนลงล่างเลย ข้อความสั้นจะกองอยู่ครึ่งบนและเหลือที่ว่างครึ่งล่างทั้งแผ่น */
    var markH = Math.round(H * .115);                         /* โลโก้หรือไอคอน */
    var kickH = kick ? Math.round(H * .085) : 0;
    var headLH = Math.round(W * .072), bodyLH = Math.round(W * .058);
    ctx.font = '700 ' + Math.round(W * .052) + 'px Prompt, sans-serif';
    var headLines = wrap(F.head.value, maxW);
    ctx.font = '500 ' + Math.round(W * .040) + 'px Prompt, sans-serif';
    var bodyLines = wrap(F.body.value, maxW);
    var gap = Math.round(H * .030);
    var blockH = markH + kickH + headLines.length * headLH + gap + bodyLines.length * bodyLH;

    var footH = F.foot.value.trim() ? Math.round(H * .085) : Math.round(H * .03);
    var avail = cardH - footH;
    var y = cardY + Math.max(Math.round(H * .055), Math.round((avail - blockH) / 2)) + Math.round(H * .035);

    if (!drawLogo(cx, y, Math.round(W * .105))) drawIcon(F.icon.value, cx, y, Math.round(W * .10), c);
    y += markH;

    if (kick) {
      ctx.font = '600 ' + Math.round(W * .032) + 'px Prompt, sans-serif';
      var kw = ctx.measureText(kick).width, kh = Math.round(W * .058);
      ctx.fillStyle = c;
      rr(cx - kw / 2 - kh * .45, y - kh * .70, kw + kh * .9, kh, kh / 2); ctx.fill();
      ctx.fillStyle = '#fff'; ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
      ctx.fillText(kick, cx, y - kh * .20);
      y += kickH;
    }

    ctx.textBaseline = 'alphabetic';
    ctx.fillStyle = dark;
    ctx.font = '700 ' + Math.round(W * .052) + 'px Prompt, sans-serif';
    y = drawLines(headLines, cx, y, headLH);

    y += gap;
    ctx.fillStyle = c;
    ctx.font = '500 ' + Math.round(W * .040) + 'px Prompt, sans-serif';
    drawLines(bodyLines, cx, y, bodyLH);

    var foot = F.foot.value.trim();
    if (foot) {
      var fy = cardY + cardH - Math.round(H * .045);
      ctx.strokeStyle = 'rgba(0,0,0,.12)'; ctx.lineWidth = Math.max(1, W * .0015);
      ctx.beginPath();
      ctx.moveTo(cardX + Math.round(W * .06), fy - Math.round(H * .032));
      ctx.lineTo(cardX + cardW - Math.round(W * .06), fy - Math.round(H * .032));
      ctx.stroke();
      ctx.fillStyle = 'rgba(0,0,0,.55)'; ctx.textAlign = 'right';
      ctx.font = '400 ' + Math.round(W * .028) + 'px Prompt, sans-serif';
      ctx.fillText(foot, cardX + cardW - Math.round(W * .06), fy);
    }
  }

  function tplBold(W, H, c) {
    ctx.fillStyle = c; ctx.fillRect(0, 0, W, H);
    ctx.fillStyle = 'rgba(255,255,255,.10)';
    ctx.beginPath(); ctx.arc(W * .88, H * .12, W * .30, 0, Math.PI * 2); ctx.fill();
    ctx.beginPath(); ctx.arc(W * .10, H * .92, W * .24, 0, Math.PI * 2); ctx.fill();

    var cx = W / 2, maxW = W - Math.round(W * .20);
    var kick = F.kicker.value.trim();

    /* วัดก่อนวาดเหมือนเทมเพลตการ์ด เพื่อให้ก้อนเนื้อหาอยู่กึ่งกลางภาพเสมอ ไม่ว่าข้อความสั้นหรือยาว */
    var markH = Math.round(H * .125);
    var kickH = kick ? Math.round(H * .090) : 0;      /* เว้นให้พอ ไม่งั้นหางอักษรของป้ายไปทับหัวข้อ */
    var headLH = Math.round(W * .092), bodyLH = Math.round(W * .056);
    ctx.font = '700 ' + Math.round(W * .068) + 'px Prompt, sans-serif';
    var headLines = wrap(F.head.value, maxW);
    ctx.font = '400 ' + Math.round(W * .038) + 'px Prompt, sans-serif';
    var bodyLines = wrap(F.body.value, maxW);
    var gap = Math.round(H * .030);
    var blockH = markH + kickH + headLines.length * headLH + gap + bodyLines.length * bodyLH;

    var y = Math.max(Math.round(H * .12), Math.round((H - Math.round(H * .10) - blockH) / 2)) + Math.round(H * .04);

    if (!drawLogo(cx, y, Math.round(W * .13))) drawIcon(F.icon.value, cx, y, Math.round(W * .13), 'rgba(255,255,255,.92)');
    y += markH;

    if (kick) {
      ctx.fillStyle = 'rgba(255,255,255,.85)'; ctx.textAlign = 'center'; ctx.textBaseline = 'alphabetic';
      ctx.font = '600 ' + Math.round(W * .030) + 'px Prompt, sans-serif';
      ctx.fillText(kick, cx, y);
      y += kickH;
    }

    ctx.fillStyle = '#fff'; ctx.textAlign = 'center'; ctx.textBaseline = 'alphabetic';
    ctx.font = '700 ' + Math.round(W * .068) + 'px Prompt, sans-serif';
    y = drawLines(headLines, cx, y, headLH);

    y += gap;
    ctx.fillStyle = 'rgba(255,255,255,.90)';
    ctx.font = '400 ' + Math.round(W * .038) + 'px Prompt, sans-serif';
    drawLines(bodyLines, cx, y, bodyLH);

    var foot = F.foot.value.trim();
    if (foot) {
      ctx.fillStyle = 'rgba(255,255,255,.75)'; ctx.textAlign = 'center';
      ctx.font = '400 ' + Math.round(W * .028) + 'px Prompt, sans-serif';
      ctx.fillText(foot, cx, H - Math.round(H * .065));
    }
  }

  function render() {
    if (!ready) return;
    var wh = F.size.value.split('x'), W = +wh[0], H = +wh[1];
    if (cv.width !== W || cv.height !== H) { cv.width = W; cv.height = H; }
    ctx.clearRect(0, 0, W, H);
    var c = F.color.value || CFG.color;
    if (F.tpl.value === 'bold') tplBold(W, H, c); else tplCard(W, H, c);
    $('gDim').textContent = 'ขนาดจริง ' + W + ' × ' + H + ' พิกเซล';
  }

  Object.keys(F).forEach(function (k) {
    F[k].addEventListener('input', render);
    F[k].addEventListener('change', render);
  });
  $('gReset').addEventListener('click', function () { F.color.value = CFG.color; render(); });

  /* ถ้ามีภาพข้ามโดเมนถูกวาดลง canvas เบราว์เซอร์จะล็อกไม่ให้อ่านภาพกลับออกมา (tainted canvas)
     แล้ว toDataURL() จะโยน SecurityError — ปกติไม่เกิดเพราะโลโก้อยู่โดเมนเดียวกัน
     แต่ถ้าหน่วยงานตั้งโลโก้เป็น URL ภายนอกจะเจอ จึงต้องบอกสาเหตุให้ชัด ไม่ปล่อยให้ปุ่มเงียบ */
  function exportPng() {
    try { return cv.toDataURL('image/png'); }
    catch (err) {
      alert('บันทึกภาพไม่ได้ เพราะโลโก้ของหน่วยงานถูกโหลดมาจากโดเมนอื่น\n\n'
          + 'วิธีแก้: ไปที่ ตั้งค่าเว็บไซต์ แล้วอัปโหลดไฟล์โลโก้เข้าระบบโดยตรง '
          + 'แทนการใส่ลิงก์ภายนอก หรือเอาเครื่องหมายถูกหน้า "แสดงตราหน่วยงาน" ออกก่อน');
      return null;
    }
  }

  $('gDownload').addEventListener('click', function () {
    var data = exportPng();
    if (!data) return;
    var a = document.createElement('a');
    a.download = 'ประกาศ-' + Date.now() + '.png';
    a.href = data;
    a.click();
  });

  $('gForm').addEventListener('submit', function (ev) {
    var data = exportPng();
    if (!data) { ev.preventDefault(); return; }
    $('gPng').value = data;
  });

  /* วาดหลังฟอนต์พร้อมเท่านั้น — ถ้าวาดก่อน ตัวอักษรจะตกไปใช้ฟอนต์สำรองแล้วความกว้างเพี้ยน
     ทำให้การตัดบรรทัดผิดและภาพที่บันทึกไม่ตรงกับที่เห็น */
  function start() { ready = true; render(); }
  var fonts = [
    '700 40px Prompt', '600 40px Prompt', '500 40px Prompt', '400 40px Prompt',
    '100px "Material Symbols Rounded"'
  ];
  if (document.fonts && document.fonts.load) {
    Promise.all(fonts.map(function (f) { return document.fonts.load(f, 'ประกาศ'); }))
      .then(function () { return document.fonts.ready; })
      .then(start).catch(start);
  } else { start(); }

  if (CFG.logo) {
    logoImg = new Image();
    logoImg.onload = render;
    logoImg.onerror = function () { logoImg = null; render(); };
    logoImg.src = CFG.logo;
  }
})();
</script>
<?php require __DIR__ . '/_bottom.php'; ?>
