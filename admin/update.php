<?php
/** admin/update.php — ตรวจสอบและติดตั้งฟีเจอร์ใหม่ผ่าน admin (เฉพาะผู้ดูแลระบบ) */
require dirname(__DIR__) . '/includes/init.php';
require __DIR__ . '/_auth.php';
require_admin();
require dirname(__DIR__) . '/includes/updater.php';

$result = null;     // ผลการอัปเดต
$check  = null;     // ผลการตรวจสอบ
$cerr   = null;

/* ── บันทึก URL เซิร์ฟเวอร์อัปเดต ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_url') {
    $u = trim((string)($_POST['update_url'] ?? ''));
    if ($u === '' || preg_match('#^https?://#i', $u)) {
        setting_set('update_url', $u);
        flash_set('success', 'บันทึก URL เซิร์ฟเวอร์อัปเดตแล้ว');
    } else {
        flash_set('danger', 'URL ต้องขึ้นต้นด้วย http:// หรือ https://');
    }
    redirect('admin/update.php');
}

/* ── ดำเนินการอัปเดต ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update') {
    @set_time_limit(300);
    @ignore_user_abort(true);
    $m = up_fetch_manifest($cerr);
    if (!$m) {
        $result = ['ok' => false, 'log' => [], 'error' => 'ดึงข้อมูลอัปเดตไม่ได้ — ' . $cerr, 'backup' => ''];
    } elseif (!version_compare($m['version'], up_current_version(), '>')) {
        $result = ['ok' => false, 'log' => [], 'error' => 'ไม่มีเวอร์ชันใหม่กว่าให้ติดตั้ง', 'backup' => ''];
    } else {
        $result = up_perform($m);
    }
}

/* ── อัปเดตด้วยไฟล์ที่อัปโหลดเอง (สำหรับเซิร์ฟเวอร์ที่ต่อออกอินเทอร์เน็ตไม่ได้) ──
   ขั้น 1: อัปโหลด update.json + .zip แล้วตรวจสอบก่อน (ยังไม่เขียนทับ) */
$manual_check    = null;   // manifest ที่ตรวจผ่านแล้ว รอยืนยัน
$manual_inbox    = '';     // ชื่อไฟล์ .zip ที่เก็บรอไว้ใน storage/updates
$manual_errors   = [];
$resultFromManual = false; // true = $result มาจากทางอัปโหลดไฟล์เอง (ไม่ต้องไปตรวจอัปเดตอัตโนมัติซ้ำ)

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'manual_inspect') {
    $mf = $_FILES['manifest_file'] ?? null;
    $pf = $_FILES['package_file'] ?? null;

    if (!$mf || ($mf['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $manual_errors[] = 'กรุณาเลือกไฟล์ update.json ให้ถูกต้อง';
    } elseif (strtolower(pathinfo($mf['name'], PATHINFO_EXTENSION)) !== 'json') {
        $manual_errors[] = 'ไฟล์ manifest ต้องเป็นไฟล์ .json';
    }
    if (!$pf || ($pf['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $code = $pf['error'] ?? UPLOAD_ERR_NO_FILE;
        $manual_errors[] = ($code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE)
            ? 'ไฟล์ .zip ใหญ่เกินกว่าเซิร์ฟเวอร์นี้จะรับได้'
            : 'กรุณาเลือกไฟล์ .zip ของแพ็กเกจอัปเดต';
    } elseif (strtolower(pathinfo($pf['name'], PATHINFO_EXTENSION)) !== 'zip') {
        $manual_errors[] = 'ไฟล์แพ็กเกจต้องเป็นไฟล์ .zip';
    }

    if (!$manual_errors) {
        $raw  = (string)file_get_contents($mf['tmp_name']);
        $perr = null;
        $pm   = up_parse_manifest($raw, $perr);
        if (!$pm) {
            $manual_errors[] = (string)$perr;
        } elseif (!version_compare($pm['version'], up_current_version(), '>')) {
            $manual_errors[] = 'ไฟล์นี้เป็นเวอร์ชัน ' . $pm['version'] . ' ไม่ใหม่กว่าเวอร์ชันปัจจุบัน (v' . up_current_version() . ')';
        } else {
            $dest = up_storage() . '/manual-' . date('Ymd-His') . '.zip';
            if (!@move_uploaded_file($pf['tmp_name'], $dest)) {
                $manual_errors[] = 'บันทึกไฟล์ที่อัปโหลดไม่ได้ (storage/updates เขียนไม่ได้?)';
            } else {
                $manual_check = $pm;
                $manual_inbox = basename($dest);
            }
        }
    }
}

/* ขั้น 2: ยืนยันแล้ว → ติดตั้งจริง (ตรวจ SHA-256/ลายเซ็นซ้ำในนี้เหมือนเดิมทุกประการ)
   ไฟล์ที่ตรวจ+สำรองฐานข้อมูล+คัดลอกไฟล์ใหม่ อาจใช้เวลานานกว่าที่ gateway ของบางเครือข่าย
   (เช่นหน่วยงานราชการ) จะรอไหว — ถ้ารอนานเกินจะตัดการเชื่อมต่อเองแล้วโชว์ 504 ให้ผู้ดูแลเห็น
   ทั้งที่ฝั่งเซิร์ฟเวอร์ยังทำงานต่อได้ปกติ (ไม่ได้ล้มเหลวจริง) สับสนเปล่าๆ ทุกครั้งที่มีอัปเดต */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'manual_apply') {
    @set_time_limit(300);
    @ignore_user_abort(true);
    $name = basename((string)($_POST['inbox'] ?? ''));
    $path = up_storage() . '/' . $name;
    $perr = null;
    $pm   = up_parse_manifest((string)($_POST['manifest_json'] ?? ''), $perr);

    if (!$pm) {
        $result = ['ok' => false, 'log' => [], 'error' => 'ข้อมูลแพ็กเกจไม่ถูกต้อง — กรุณาอัปโหลดใหม่อีกครั้ง', 'backup' => ''];
    } elseif ($name === '' || !is_file($path)) {
        $result = ['ok' => false, 'log' => [], 'error' => 'ไม่พบไฟล์ที่อัปโหลดไว้ — กรุณาอัปโหลดใหม่อีกครั้ง', 'backup' => ''];
    } elseif (function_exists('fastcgi_finish_request')) {
        /* ตอบกลับเบราว์เซอร์ให้เสร็จก่อน (ตัดการเชื่อมต่อสะอาดๆ) แล้วค่อยติดตั้งจริงต่อ
           เบื้องหลังบนเซิร์ฟเวอร์ — gateway จะไม่มีทางรอจนหมดเวลาอีกเพราะได้คำตอบเร็วมาก */
        ob_start();
        $admin_title = 'อัปเดตระบบ';
        require __DIR__ . '/_top.php';
        ?>
        <div class="card">
          <div class="section-head" style="margin-bottom:8px;">
            <div><span class="tag warning"><span class="material-symbols-rounded icon-sm">hourglass_top</span> กำลังติดตั้ง</span>
            <h3>กำลังติดตั้งเวอร์ชัน <?= e($pm['version']) ?>...</h3>
            <p>ไม่ต้องปิดหน้านี้ — ระบบกำลังทำงานอยู่เบื้องหลังบนเซิร์ฟเวอร์ หน้านี้จะโหลดใหม่อัตโนมัติเพื่อแสดงผลลัพธ์</p></div>
          </div>
          <div class="alert info" style="font-size:13px;"><span class="material-symbols-rounded">info</span>
            <div>ขั้นตอนนี้อาจใช้เวลาสักครู่ขึ้นกับปริมาณข้อมูลในเว็บ — ถ้าเบราว์เซอร์ขึ้น error การเชื่อมต่อ (เช่น 504) ระหว่างนี้ ไม่ต้องกังวล ระบบยังทำงานต่อตามปกติ แค่รีเฟรชหน้านี้ใหม่ภายหลังได้เลย</div>
          </div>
        </div>
        <script nonce="<?= e(CSP_NONCE) ?>">setTimeout(function(){ location.href = <?= json_encode(url('admin/update.php')) ?>; }, 8000);</script>
        <?php
        require __DIR__ . '/_bottom.php';
        $html = ob_get_clean();
        header('Content-Length: ' . strlen($html));
        echo $html;
        fastcgi_finish_request();

        /* ── จากบรรทัดนี้ไป ไม่มีใครรอผลลัพธ์อยู่แล้ว (เชื่อมต่อกับเบราว์เซอร์ปิดไปแล้ว) ── */
        $bgResult = up_perform($pm, null, $path);
        @unlink($path);
        @file_put_contents(up_storage() . '/manual-result.json', json_encode($bgResult, JSON_UNESCAPED_UNICODE));
        exit;
    } else {
        /* fallback: โฮสต์นี้ไม่รองรับ fastcgi_finish_request (เช่น mod_php/CGI) — ทำแบบเดิม (รอจนจบ) */
        $result = up_perform($pm, null, $path);
        $resultFromManual = true;
        @unlink($path);
    }
}

/* ── อ่านผลลัพธ์ของการติดตั้งเบื้องหลัง (จากหน้าที่รีเฟรชอัตโนมัติหลัง fastcgi_finish_request) ──
   แสดงครั้งเดียวแล้วลบทิ้ง เหมือน flash message */
if (!$result) {
    $manualResultFile = up_storage() . '/manual-result.json';
    if (is_file($manualResultFile)) {
        $decoded = json_decode((string)@file_get_contents($manualResultFile), true);
        if (is_array($decoded)) { $result = $decoded; $resultFromManual = true; }
        @unlink($manualResultFile);
    }
}

/* ── ตรวจสอบอัปเดต (อัตโนมัติผ่านเครือข่าย) — ข้ามขั้นนี้หลังอัปเดตด้วยไฟล์อัปโหลดเอง
   เพราะเซิร์ฟเวอร์กลุ่มนี้ต่อออกอินเทอร์เน็ตไม่ได้อยู่แล้ว จะขึ้น error ซ้อนให้สับสนเปล่าๆ ── */
if (($_GET['action'] ?? '') === 'check' || ($result && !$resultFromManual)) {
    $check = up_check($cerr);
}

$cur          = up_current_version();
$build        = defined('APP_BUILD') ? APP_BUILD : '';
$last_update  = setting('last_update_at', '');
$update_url   = up_manifest_url();
$backups      = up_backups();
$up_max       = ini_get('upload_max_filesize');
$post_max     = ini_get('post_max_size');

$admin_title = 'อัปเดตระบบ';
require __DIR__ . '/_top.php';
?>
<div class="card mb-2">
  <div class="section-head" style="margin-bottom:8px;">
    <div><span class="tag"><span class="material-symbols-rounded icon-sm">system_update</span> UPDATE</span><h3>อัปเดตฟีเจอร์ใหม่</h3>
    <p>ตรวจสอบและติดตั้งเวอร์ชันใหม่ของระบบ — สำรองข้อมูลให้อัตโนมัติก่อนทุกครั้ง</p></div>
    <?php if (!$result): ?>
    <a class="btn primary" href="<?= e(url('admin/update.php?action=check')) ?>"><span class="material-symbols-rounded icon-sm">refresh</span>ตรวจสอบอัปเดต</a>
    <?php endif; ?>
  </div>

  <div class="grid grid-2">
    <div class="card" style="background:rgba(255,255,255,.7);">
      <div class="flex items-center gap-1 mb-1"><span class="material-symbols-rounded" style="color:var(--blue);">deployed_code</span><b style="color:var(--ink);">เวอร์ชันปัจจุบัน</b></div>
      <div style="font-size:30px;font-weight:700;color:var(--blue-700);letter-spacing:.5px;">v<?= e($cur) ?></div>
      <p class="text-muted" style="font-size:13px;margin:2px 0 0;">
        <?php if ($build): ?>build <?= e($build) ?><?php endif; ?>
        <?php if ($last_update): ?> · อัปเดตล่าสุด <?= e(date('d/m/Y H:i', strtotime($last_update))) ?> น.<?php endif; ?>
      </p>
    </div>
    <div class="card" style="background:rgba(255,255,255,.7);">
      <div class="flex items-center gap-1 mb-1"><span class="material-symbols-rounded" style="color:var(--success);">dns</span><b style="color:var(--ink);">เซิร์ฟเวอร์อัปเดต</b></div>
      <form method="post" action="" class="flex gap-1 items-center" style="flex-wrap:wrap;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_url">
        <input type="text" name="update_url" value="<?= e($update_url) ?>" placeholder="http://.../update.json" style="flex:1;min-width:180px;">
        <button class="btn small" type="submit"><span class="material-symbols-rounded icon-sm">save</span>บันทึก</button>
      </form>
      <p class="text-muted" style="font-size:12px;margin:6px 0 0;">URL ของไฟล์ manifest (update.json) ที่ระบบจะไปตรวจเวอร์ชันใหม่</p>
    </div>
  </div>
</div>

<?php /* ───── ผลการอัปเดต ───── */ ?>
<?php if ($result): ?>
<div class="card mb-2">
  <div class="section-head" style="margin-bottom:8px;">
    <div><span class="tag <?= $result['ok'] ? 'success' : 'danger' ?>"><span class="material-symbols-rounded icon-sm"><?= $result['ok'] ? 'check_circle' : 'error' ?></span> <?= $result['ok'] ? 'สำเร็จ' : 'ล้มเหลว' ?></span>
    <h3><?= $result['ok'] ? 'อัปเดตเรียบร้อยแล้ว' : 'อัปเดตไม่สำเร็จ' ?></h3></div>
  </div>
  <?php if (!$result['ok'] && $result['error']): ?>
  <div class="alert danger"><span class="material-symbols-rounded">error</span><div><?= e($result['error']) ?></div></div>
  <?php endif; ?>
  <?php if ($result['log']): ?>
  <pre style="background:#0f172a;color:#cbd5e1;padding:14px 16px;border-radius:10px;font-size:13px;line-height:1.7;overflow:auto;max-height:360px;white-space:pre-wrap;"><?php foreach ($result['log'] as $line) echo e($line) . "\n"; ?></pre>
  <?php endif; ?>
  <?php if ($result['ok']): ?>
  <div class="alert success mt-2"><span class="material-symbols-rounded">verified</span>
    <div>ระบบอัปเดตเป็นเวอร์ชันใหม่แล้ว — สำรองข้อมูลก่อนอัปเดตอยู่ที่ <code>storage/updates/<?= e($result['backup']) ?></code>
    <br><a href="<?= e(url('admin/update.php')) ?>">โหลดหน้านี้ใหม่</a> เพื่อดูเวอร์ชันล่าสุด</div>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php /* ───── ผลการตรวจสอบ (เมื่อยังไม่ได้กดอัปเดต) ───── */ ?>
<?php if ($check && !$result): ?>
<div class="card mb-2">
  <?php if (!$check['ok']): ?>
    <div class="alert danger"><span class="material-symbols-rounded">error</span><div>ตรวจสอบอัปเดตไม่ได้ — <?= e($cerr ?: 'ไม่ทราบสาเหตุ') ?></div></div>
  <?php elseif (!$check['available']): ?>
    <div class="alert success"><span class="material-symbols-rounded">check_circle</span><div><b>ใช้เวอร์ชันล่าสุดอยู่แล้ว</b> (v<?= e($check['current']) ?>) — ไม่มีอัปเดตใหม่</div></div>
  <?php else: $m = $check['manifest']; ?>
    <div class="section-head" style="margin-bottom:8px;">
      <div><span class="tag warning"><span class="material-symbols-rounded icon-sm">new_releases</span> มีอัปเดตใหม่</span>
      <h3>เวอร์ชัน <?= e($m['version']) ?> พร้อมติดตั้ง</h3>
      <p>เวอร์ชันปัจจุบัน v<?= e($check['current']) ?> → ใหม่ v<?= e($m['version']) ?><?php if ($m['released']): ?> · ออกเมื่อ <?= e($m['released']) ?><?php endif; ?><?php if ($m['size']): ?> · <?= e(format_bytes($m['size'])) ?><?php endif; ?></p></div>
    </div>
    <?php if ($m['notes']): ?>
    <div class="card" style="background:rgba(255,255,255,.7);">
      <b style="color:var(--ink);">มีอะไรใหม่</b>
      <ul style="margin:8px 0 0;padding-left:20px;line-height:1.9;">
        <?php foreach ($m['notes'] as $note): ?><li><?= e($note) ?></li><?php endforeach; ?>
      </ul>
    </div>
    <?php endif; ?>
    <div class="alert info mt-2" style="font-size:13px;"><span class="material-symbols-rounded">shield</span>
      <div>เมื่อกดอัปเดต ระบบจะ <b>สำรองไฟล์และฐานข้อมูล</b> → ตรวจ SHA-256 → ติดตั้งไฟล์ใหม่ → อัปเดตฐานข้อมูลอัตโนมัติ (ไฟล์ <code>config.php</code> และโฟลเดอร์ <code>uploads</code> จะไม่ถูกแตะต้อง)</div>
    </div>
    <form method="post" action="" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').innerHTML='กำลังอัปเดต...';">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="update">
      <button class="btn primary large mt-2" type="submit" data-confirm="ยืนยันอัปเดตเป็นเวอร์ชัน <?= e($m['version']) ?>? ระบบจะสำรองข้อมูลก่อนอัตโนมัติ">
        <span class="material-symbols-rounded">download</span>อัปเดตเป็น v<?= e($m['version']) ?> เลย
      </button>
    </form>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php /* ───── อัปเดตด้วยไฟล์ที่อัปโหลดเอง (สำหรับเครือข่ายที่ต่อออกอินเทอร์เน็ตไม่ได้) ───── */ ?>
<div class="card mb-2">
  <div class="section-head" style="margin-bottom:8px;">
    <div><span class="tag"><span class="material-symbols-rounded icon-sm">upload_file</span> ทางเลือก</span>
    <h3>อัปเดตด้วยไฟล์ที่อัปโหลดเอง</h3>
    <p>ใช้เมื่อปุ่ม "ตรวจสอบอัปเดต" ข้างบนใช้ไม่ได้ (เช่น ขึ้น Connection refused หรือ HTTP 403) — มักเกิดจากเครือข่ายองค์กร/หน่วยงานปิดกั้นการเชื่อมต่อออกจากเซิร์ฟเวอร์เว็บ วิธีนี้ไม่ต้องพึ่งการเชื่อมต่อออกเลย เพราะไฟล์อัปเดตส่งผ่านเบราว์เซอร์ของคุณโดยตรง</p></div>
  </div>

  <?php if ($manual_errors): ?>
  <div class="alert danger"><span class="material-symbols-rounded">error</span>
    <div><?php foreach ($manual_errors as $er): ?><div><?= e($er) ?></div><?php endforeach; ?></div>
  </div>
  <?php endif; ?>

  <?php if ($manual_check): $pm = $manual_check; ?>
  <!-- ขั้นยืนยันก่อนติดตั้งจริง -->
  <div class="section-head" style="margin-bottom:8px;">
    <div><span class="tag warning"><span class="material-symbols-rounded icon-sm">fact_check</span> ตรวจไฟล์แล้ว</span>
    <h3>เวอร์ชัน <?= e($pm['version']) ?> พร้อมติดตั้ง</h3>
    <p>เวอร์ชันปัจจุบัน v<?= e($cur) ?> → ใหม่ v<?= e($pm['version']) ?><?php if ($pm['released']): ?> · ออกเมื่อ <?= e($pm['released']) ?><?php endif; ?></p></div>
  </div>
  <?php if ($pm['notes']): ?>
  <div class="card" style="background:rgba(255,255,255,.7);">
    <b style="color:var(--ink);">มีอะไรใหม่</b>
    <ul style="margin:8px 0 0;padding-left:20px;line-height:1.9;">
      <?php foreach ($pm['notes'] as $note): ?><li><?= e($note) ?></li><?php endforeach; ?>
    </ul>
  </div>
  <?php endif; ?>
  <div class="alert info mt-2" style="font-size:13px;"><span class="material-symbols-rounded">shield</span>
    <div>ลายเซ็นดิจิทัลของไฟล์จะถูกตรวจอีกครั้งก่อนติดตั้งจริง — ถ้าไฟล์ถูกแก้ไขหรือไม่ตรงกับ update.json ระบบจะปฏิเสธการติดตั้งเอง เมื่อกดติดตั้ง ระบบจะ <b>สำรองไฟล์และฐานข้อมูล</b> ก่อนเสมอ (ไฟล์ <code>config.php</code> และโฟลเดอร์ <code>uploads</code> จะไม่ถูกแตะต้อง)</div>
  </div>
  <form method="post" action="" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').innerHTML='กำลังติดตั้ง...';">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="manual_apply">
    <input type="hidden" name="inbox" value="<?= e($manual_inbox) ?>">
    <input type="hidden" name="manifest_json" value='<?= e(json_encode($pm, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>'>
    <button class="btn primary large mt-2" type="submit" data-confirm="ยืนยันติดตั้งเวอร์ชัน <?= e($pm['version']) ?>? ระบบจะสำรองข้อมูลก่อนอัตโนมัติ">
      <span class="material-symbols-rounded">install_desktop</span>ติดตั้งเป็น v<?= e($pm['version']) ?> เลย
    </button>
    <a class="btn mt-2" href="<?= e(url('admin/update.php')) ?>">ยกเลิก</a>
  </form>

  <?php else: ?>
  <!-- ฟอร์มอัปโหลด -->
  <form method="post" action="" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="manual_inspect">
    <div class="form-row">
      <div>
        <label>1) ไฟล์ manifest (<code>update.json</code>)</label>
        <input type="file" name="manifest_file" accept=".json" required
               style="display:block;width:100%;padding:9px;border:1px dashed var(--border);border-radius:10px;margin-bottom:8px;">
      </div>
      <div>
        <label>2) ไฟล์แพ็กเกจ (<code>alicecms-x.x.x.zip</code>)</label>
        <input type="file" name="package_file" accept=".zip" required
               style="display:block;width:100%;padding:9px;border:1px dashed var(--border);border-radius:10px;margin-bottom:8px;">
      </div>
    </div>
    <button class="btn" type="submit"><span class="material-symbols-rounded icon-sm">fact_check</span>ตรวจสอบไฟล์</button>
    <p class="text-muted" style="font-size:11.5px;margin-top:8px;">ไฟล์ทั้งสองต้องเป็นชุดเดียวกันจากผู้พัฒนา — ขนาดอัปโหลดสูงสุดของเซิร์ฟเวอร์: <?= e($up_max) ?> (POST <?= e($post_max) ?>)</p>
  </form>
  <?php endif; ?>
</div>

<?php /* ───── รายการสำรองก่อนอัปเดต ───── */ ?>
<?php if ($backups): ?>
<div class="card">
  <div class="section-head" style="margin-bottom:8px;">
    <div><span class="tag"><span class="material-symbols-rounded icon-sm">history</span> BACKUPS</span><h3>สำรองก่อนอัปเดต (<?= count($backups) ?>)</h3>
    <p>เก็บไว้ใน <code>storage/updates/</code> บนเซิร์ฟเวอร์ — ใช้กู้คืนด้วยตนเองได้หากจำเป็น</p></div>
  </div>
  <table class="admin-table">
    <tr><th>ชุดสำรอง</th><th>เวลา</th><th>ขนาด</th></tr>
    <?php foreach ($backups as $b): ?>
    <tr>
      <td><span class="material-symbols-rounded icon-sm" style="color:var(--blue);vertical-align:-4px;">folder_zip</span> <?= e($b['name']) ?></td>
      <td class="lr-date"><?= $b['time'] ? e(date('d/m/Y H:i', $b['time'])) : '-' ?></td>
      <td><?= e(format_bytes($b['size'])) ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
</div>
<?php endif; ?>
<?php require __DIR__ . '/_bottom.php'; ?>
