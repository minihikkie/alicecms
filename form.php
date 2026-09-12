<?php
/** form.php — แสดงและรับแบบฟอร์ม/บริการออนไลน์ที่สร้างจากแอดมิน */
define('PUBLIC_PAGE', 'form');
require __DIR__ . '/includes/init.php';
require __DIR__ . '/includes/forms.php';

$slug = preg_replace('/[^\p{L}\p{N}\-_]/u', '', (string)($_GET['slug'] ?? ''));
$form = $slug !== '' ? form_by_slug($slug) : null;

if (!$form) {
    http_response_code(404);
    $page_title = 'ไม่พบแบบฟอร์ม';
    require __DIR__ . '/includes/header.php';
    echo '<div class="container"><div class="page-head reveal text-center"><h1>ไม่พบแบบฟอร์มที่ต้องการ</h1>'
       . '<a class="btn primary mt-2" href="' . e(url('index.php')) . '"><span class="material-symbols-rounded icon-sm">home</span>กลับหน้าแรก</a></div></div>';
    require __DIR__ . '/includes/footer.php';
    exit;
}

$fields = json_decode((string)$form['fields'], true);
if (!is_array($fields)) $fields = [];
$sent = false; $errors = [];
$closed = $form['status'] === 'closed';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$closed) {
    /* honeypot กันบอท */
    if (trim((string)($_POST['website'] ?? '')) !== '') $errors[] = 'ไม่สามารถส่งข้อมูลได้';
    /* PDPA — ต้องได้รับความยินยอมก่อนเก็บข้อมูลส่วนบุคคล */
    if ($pdpaErr = pdpa_consent_error()) $errors[] = $pdpaErr;

    $data = [];      /* { label => value } */
    $post = $_POST['field'] ?? [];
    foreach ($fields as $i => $f) {
        $type  = (string)($f['type'] ?? 'text');
        $label = (string)($f['label'] ?? ('ช่อง ' . ($i + 1)));
        $req   = !empty($f['required']);
        $val   = '';

        if ($type === 'file') {
            if (!empty($_FILES['ffile']['name'][$i])) {
                try {
                    $r = store_upload($_FILES['ffile']['name'][$i], $_FILES['ffile']['tmp_name'][$i],
                        (int)$_FILES['ffile']['error'][$i], (int)$_FILES['ffile']['size'][$i], 'forms', upload_all_exts(), 10);
                    $val = $r['orig'] . ' (' . abs_url($r['path']) . ')';
                } catch (RuntimeException $ex) { $errors[] = $label . ': ' . $ex->getMessage(); }
            }
        } elseif ($type === 'checkbox') {
            $arr = isset($post[$i]) && is_array($post[$i]) ? array_map('strval', $post[$i]) : [];
            $val = implode(', ', array_map(fn($x) => mb_substr(trim($x), 0, 1000), $arr));
        } else {
            $val = mb_substr(trim((string)($post[$i] ?? '')), 0, 5000);
        }

        if ($req && $val === '') $errors[] = 'กรุณากรอก "' . $label . '"';
        if ($type === 'email' && $val !== '' && !filter_var($val, FILTER_VALIDATE_EMAIL)) $errors[] = '"' . $label . '" อีเมลไม่ถูกต้อง';
        $data[$label] = $val;
    }

    if (!$errors) {
        db()->prepare('INSERT INTO form_responses (form_id, data) VALUES (?, ?)')
            ->execute([(int)$form['id'], json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
        /* แจ้งเตือนอีเมล */
        $to = trim((string)$form['notify_email']);
        if ($to !== '' && setting('notify_enabled', '0') === '1') {
            require_once __DIR__ . '/includes/mailer.php';
            $body = "มีผู้ส่งแบบฟอร์ม \"" . $form['title'] . "\" ที่เว็บไซต์\n\n";
            foreach ($data as $k => $v) $body .= $k . ": " . $v . "\n";
            $body .= "\nเวลา: " . date('d/m/Y H:i') . " น.";
            $err = '';
            @send_mail($to, '[' . setting('site_name', 'เว็บไซต์') . '] แบบฟอร์มใหม่: ' . $form['title'], $body, $err);
        }
        $sent = true;
    }
}

$page_title = $form['title'];
require __DIR__ . '/includes/header.php';
?>
<div class="container">
  <div class="page-head reveal text-center">
    <h1><?= e($form['title']) ?></h1>
    <?php if ($form['description']): ?><p class="text-muted"><?= e($form['description']) ?></p><?php endif; ?>
  </div>
  <div class="complaint-form mb-4">
    <?php if ($sent): ?>
    <div class="card card-spacious text-center reveal">
      <span class="material-symbols-rounded" style="font-size:64px;color:var(--success);">check_circle</span>
      <h2 style="margin:12px 0 6px;">ส่งข้อมูลเรียบร้อยแล้ว</h2>
      <p class="text-muted"><?= e($form['success_msg'] ?: 'ขอบคุณที่ติดต่อ เจ้าหน้าที่จะดำเนินการต่อไป') ?></p>
      <a class="btn mt-2" href="<?= e(url('index.php')) ?>"><span class="material-symbols-rounded icon-sm">home</span>กลับหน้าแรก</a>
    </div>
    <?php elseif ($closed): ?>
    <div class="card card-spacious text-center reveal">
      <span class="material-symbols-rounded" style="font-size:56px;color:var(--muted);">lock</span>
      <h2 style="margin:12px 0 6px;">ปิดรับแบบฟอร์มชั่วคราว</h2>
      <p class="text-muted">ขออภัย ขณะนี้ยังไม่เปิดรับข้อมูลผ่านแบบฟอร์มนี้</p>
    </div>
    <?php else: ?>
    <?php if ($errors): ?>
    <div class="alert danger"><span class="material-symbols-rounded">error</span>
      <div><?php foreach ($errors as $er): ?><div><?= e($er) ?></div><?php endforeach; ?></div></div>
    <?php endif; ?>
    <form class="card card-spacious reveal" method="post" enctype="multipart/form-data" action="<?= e(url('form.php?slug=' . urlencode($slug))) ?>">
      <?= csrf_field() ?>
      <div class="hp-field" aria-hidden="true"><label>เว็บไซต์<input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
      <?php if (!$fields): ?>
      <p class="text-muted text-center" style="padding:20px;">แบบฟอร์มนี้ยังไม่มีช่องกรอก</p>
      <?php else: foreach ($fields as $i => $f) echo render_form_field($f, $i, $_POST['field'][$i] ?? null); endif; ?>
      <?= pdpa_consent_box() ?>
      <button class="btn primary large mt-2" type="submit" style="width:100%;justify-content:center;">
        <span class="material-symbols-rounded">send</span>ส่งแบบฟอร์ม
      </button>
    </form>
    <?php endif; ?>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
