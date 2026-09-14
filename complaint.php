<?php
/** complaint.php — ฟอร์มร้องเรียน-ร้องทุกข์ออนไลน์ (บันทึกลงฐานข้อมูล) */
define('PUBLIC_PAGE', 'complaint');
require __DIR__ . '/includes/init.php';

/* section นี้ถูกปิดอยู่ → ตอบ 404 ไม่ให้เข้าถึงหน้าโดยตรง */
require_section_live("complaint");

$sent   = false;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    /* honeypot กันบอทสแปม — ช่องนี้ซ่อนไว้ มนุษย์จะไม่กรอก */
    if (trim((string)($_POST['website'] ?? '')) !== '') {
        $errors[] = 'ไม่สามารถส่งข้อมูลได้';
    }

    $name    = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 150);
    $contact = mb_substr(trim((string)($_POST['contact'] ?? '')), 0, 200);
    $subject = mb_substr(trim((string)($_POST['subject'] ?? '')), 0, 250);
    $detail  = mb_substr(trim((string)($_POST['detail'] ?? '')), 0, 5000);

    if ($subject === '') $errors[] = 'กรุณากรอกเรื่องที่ร้องเรียน';
    if ($detail === '')  $errors[] = 'กรุณากรอกรายละเอียด';

    /* ตรวจ captcha (บวกเลขง่ายๆ กันบอท — เสริม honeypot) */
    if (!isset($_SESSION['captcha_sum']) || (int)($_POST['captcha'] ?? -1) !== (int)$_SESSION['captcha_sum']) {
        $errors[] = 'ผลรวมตัวเลขยืนยันไม่ถูกต้อง กรุณาลองใหม่';
    }

    /* PDPA — ต้องได้รับความยินยอมก่อนเก็บข้อมูลส่วนบุคคล */
    if ($pdpaErr = pdpa_consent_error()) $errors[] = $pdpaErr;

    $file = null;
    if (!$errors) {
        try {
            $file = handle_upload('attachment', 'complaints', null, 10);
        } catch (RuntimeException $ex) {
            $errors[] = $ex->getMessage();
        }
    }

    if (!$errors) {
        /* เลขรับเรื่อง: R+ปีเดือน-สุ่ม6ตัว (ใช้เป็นรหัสติดตามที่ผู้ร้องเก็บไว้ — สุ่มเพื่อกันคนอื่นเดา) */
        $ref_code = 'R' . date('ym') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $st = db()->prepare("INSERT INTO complaints (ref_code, name, contact, subject, detail, file, status)
                             VALUES (?, ?, ?, ?, ?, ?, 'new')");
        $st->execute([$ref_code, $name, $contact, $subject, $detail, $file['path'] ?? null]);
        /* แจ้งเตือนอีเมลถึงเจ้าหน้าที่ (ถ้าเปิดใช้) */
        require_once __DIR__ . '/includes/mailer.php';
        notify_new_complaint($ref_code, $subject);
        $sent = true;
    }
}

/* สุ่มเลข captcha ใหม่ทุกครั้งที่แสดงฟอร์ม */
$ca = random_int(1, 9); $cb = random_int(1, 9);
$_SESSION['captcha_sum'] = $ca + $cb;

$page_title = 'ร้องเรียน-ร้องทุกข์';
require __DIR__ . '/includes/header.php';
?>
<div class="container">
  <div class="page-head reveal text-center">
    <h1>แจ้งเรื่อง<span class="grad">ร้องเรียน-ร้องทุกข์</span></h1>
    <p class="text-muted">ข้อมูลของท่านจะถูกเก็บเป็นความลับ และส่งถึงเจ้าหน้าที่ผู้รับผิดชอบโดยตรง</p>
    <?php if (!$sent): ?><a class="btn small" href="<?= e(url('complaint-track.php')) ?>"><span class="material-symbols-rounded icon-sm">search</span>มีเลขรับเรื่องแล้ว? ติดตามสถานะ</a><?php endif; ?>
  </div>

  <div class="complaint-form mb-4">
    <?php if ($sent): ?>
    <div class="card card-spacious text-center reveal">
      <span class="material-symbols-rounded" style="font-size:64px;color:var(--success);">check_circle</span>
      <h2 style="margin:12px 0 6px;">ส่งเรื่องร้องเรียนเรียบร้อยแล้ว</h2>
      <p class="text-muted">เจ้าหน้าที่จะตรวจสอบและดำเนินการโดยเร็วที่สุด ขอบคุณที่แจ้งข้อมูล</p>
      <div class="alert info" style="text-align:left;margin:16px 0;">
        <span class="material-symbols-rounded">confirmation_number</span>
        <div>
          <b>เลขรับเรื่องของท่าน</b>
          <div style="font-size:22px;font-weight:700;color:var(--blue-700);letter-spacing:1px;margin:2px 0;"><?= e($ref_code) ?></div>
          <span style="font-size:13px;">กรุณาเก็บเลขนี้ไว้เพื่อใช้ <b>ติดตามสถานะ</b> เรื่องร้องเรียน (เลขนี้แสดงเพียงครั้งเดียว)</span>
        </div>
      </div>
      <div class="flex gap-1 justify-center" style="flex-wrap:wrap;">
        <a class="btn primary" href="<?= e(url('complaint-track.php?ref=' . urlencode($ref_code))) ?>"><span class="material-symbols-rounded icon-sm">search</span>ติดตามสถานะ</a>
        <a class="btn" href="<?= e(url('index.php')) ?>"><span class="material-symbols-rounded icon-sm">home</span>กลับหน้าแรก</a>
      </div>
    </div>
    <?php else: ?>

    <?php if ($errors): ?>
    <div class="alert danger"><span class="material-symbols-rounded">error</span>
      <div><?php foreach ($errors as $er): ?><div><?= e($er) ?></div><?php endforeach; ?></div>
    </div>
    <?php endif; ?>

    <form class="card card-spacious reveal" method="post" enctype="multipart/form-data" action="<?= e(url('complaint.php')) ?>">
      <?= csrf_field() ?>
      <!-- honeypot กันสแปม -->
      <div class="hp-field" aria-hidden="true"><label>เว็บไซต์<input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>

      <div class="form-2col">
        <div>
          <label for="c-name">ชื่อ-นามสกุล <span class="text-muted">(ไม่บังคับ)</span></label>
          <input type="text" id="c-name" name="name" value="<?= e($_POST['name'] ?? '') ?>" placeholder="ระบุหรือไม่ระบุก็ได้">
        </div>
        <div>
          <label for="c-contact">ช่องทางติดต่อกลับ <span class="text-muted">(ไม่บังคับ)</span></label>
          <input type="text" id="c-contact" name="contact" value="<?= e($_POST['contact'] ?? '') ?>" placeholder="โทรศัพท์ / อีเมล / LINE">
        </div>
      </div>
      <div class="mt-2">
        <label for="c-subject">เรื่องที่ร้องเรียน <span style="color:var(--danger);">*</span></label>
        <input type="text" id="c-subject" name="subject" value="<?= e($_POST['subject'] ?? '') ?>" required placeholder="สรุปเรื่องที่ต้องการร้องเรียน">
      </div>
      <div class="mt-2">
        <label for="c-detail">รายละเอียด <span style="color:var(--danger);">*</span></label>
        <textarea id="c-detail" name="detail" rows="6" required placeholder="อธิบายเหตุการณ์ สถานที่ วันเวลา และรายละเอียดที่เกี่ยวข้อง"><?= e($_POST['detail'] ?? '') ?></textarea>
      </div>
      <div class="mt-2">
        <label for="c-file">ไฟล์แนบ <span class="text-muted">(ไม่บังคับ — รูปภาพ/PDF/เอกสาร ไม่เกิน 10 MB)</span></label>
        <input type="file" id="c-file" name="attachment" accept=".jpg,.jpeg,.png,.webp,.pdf,.doc,.docx,.xls,.xlsx,.zip" style="padding:10px;">
      </div>
      <div class="mt-2">
        <label for="c-captcha">ยืนยันว่าไม่ใช่บอท <span style="color:var(--danger);">*</span></label>
        <div class="captcha-box">
          <span class="captcha-q"><?= $ca ?> + <?= $cb ?> = ?</span>
          <input type="number" id="c-captcha" name="captcha" required placeholder="ผลรวม" autocomplete="off" inputmode="numeric">
        </div>
      </div>
      <div class="alert info mt-2" style="font-size:13px;">
        <span class="material-symbols-rounded">lock</span>
        <div>ข้อมูลผู้แจ้งถูกเก็บเป็นความลับตามกฎหมาย และใช้เพื่อการตรวจสอบเรื่องร้องเรียนเท่านั้น</div>
      </div>
      <?= pdpa_consent_box() ?>
      <button class="btn danger large mt-2" type="submit" style="width:100%;justify-content:center;">
        <span class="material-symbols-rounded">send</span>ส่งเรื่องร้องเรียน
      </button>
    </form>
    <?php endif; ?>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
