<?php
/**
 * admin/custom-sections.php — กล่องอิสระบนหน้าแรก: สร้าง/แก้ไข/ลบ/เปิดปิด
 *
 * เนื้อหาใช้ Block Builder ชุดเดียวกับหน้าเพจ (includes/blocks.php)
 * ส่วนการเปิด/ปิดและลำดับใช้ตาราง sections ร่วมกับ section มาตรฐาน
 * ผู้ดูแลจึงเรียงสลับกล่องอิสระกับ section เดิมได้จากหน้า "การแสดงผลหน้าแรก"
 */
require dirname(__DIR__) . '/includes/init.php';
require __DIR__ . '/_auth.php';
require_admin();
require dirname(__DIR__) . '/includes/blocks.php';

$errors = [];
$edit = null;

/* ตัวเลือกหน้าตา — กำหนดที่เดียว ใช้ทั้งตอนตรวจค่าและตอนวาดฟอร์ม */
$BGS = [
    'card'  => ['การ์ดขาว',      'เหมือน section อื่นบนหน้าแรก — ค่าแนะนำ'],
    'plain' => ['ไม่มีการ์ด',    'กลืนไปกับพื้นหน้าเว็บ เหมาะกับข้อความสั้นๆ'],
    'tint'  => ['พื้นสีธีมอ่อน',  'เน้นให้กล่องนี้ต่างจากกล่องอื่นแบบนุ่มนวล'],
    'dark'  => ['พื้นเข้ม',       'ตัวหนังสือสีขาว เด่นที่สุด ใช้เท่าที่จำเป็น'],
    'grad'  => ['ไล่สีธีม',       'ตัวหนังสือสีขาว เหมาะกับกล่องเชิญชวน'],
];
$WIDTHS = [
    'box'  => ['จำกัดความกว้าง', 'บรรทัดไม่ยาวเกินไป อ่านง่ายกว่าเมื่อเป็นข้อความยาว'],
    'full' => ['เต็มความกว้าง',  'เหมาะกับรูปภาพ แกลเลอรี หรือวิดีโอ'],
];

/* ทุกกล่องต้องมีแถวคู่ใน sections ไม่งั้นจะเปิด/จัดลำดับไม่ได้
   (เกิดได้เมื่อกู้คืนกล่องจากถังขยะ ซึ่งคืนมาแค่แถวใน custom_sections) */
function cs_sync_sections(): void {
    $have = [];
    foreach (db()->query("SELECT skey FROM sections WHERE skey LIKE 'custom-%'") as $r) $have[$r['skey']] = true;
    $mx = (int)db()->query('SELECT COALESCE(MAX(sort_order),0) FROM sections')->fetchColumn();
    $ins = db()->prepare("INSERT IGNORE INTO sections (skey, enabled, in_menu, sort_order, custom_title) VALUES (?, 1, 0, ?, '')");
    foreach (custom_sections_all() as $key => $c) {
        if (!isset($have[$key])) $ins->execute([$key, ++$mx]);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (isset($_POST['delete_id'])) {
        $id = (int)$_POST['delete_id'];
        if (trash_delete('custom_sections', $id, $ADMIN['username'] ?? '')) {
            /* ลบแถวคู่ใน sections ด้วย ไม่งั้นจะเหลือรายการค้างในหน้าจัดลำดับ */
            db()->prepare('DELETE FROM sections WHERE skey = ?')->execute(['custom-' . $id]);
            log_action('ลบกล่องอิสระ (ลงถังขยะ)', '#' . $id);
            flash_set('success', 'ย้ายกล่องลงถังขยะแล้ว — กู้คืนได้ที่เมนู "ถังขยะ"');
        }
        redirect('admin/custom-sections.php');
    }

    /* เปิด/ปิดเร็วจากหน้านี้ (ตัวเดียวกับสวิตช์ในหน้าการแสดงผลหน้าแรก) */
    if (isset($_POST['toggle_id'])) {
        $key = 'custom-' . (int)$_POST['toggle_id'];
        db()->prepare('UPDATE sections SET enabled = 1 - enabled WHERE skey = ?')->execute([$key]);
        log_action('สลับการแสดงกล่องอิสระ', $key);
        redirect('admin/custom-sections.php');
    }

    $id     = (int)($_POST['id'] ?? 0);
    $title  = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 150);
    $lead   = mb_substr(trim((string)($_POST['lead'] ?? '')), 0, 400);
    $bg     = isset($BGS[$_POST['bg'] ?? '']) ? $_POST['bg'] : 'card';
    $width  = isset($WIDTHS[$_POST['width'] ?? '']) ? $_POST['width'] : 'box';
    $align  = ($_POST['align'] ?? 'left') === 'center' ? 'center' : 'left';
    $head   = empty($_POST['show_head']) ? 0 : 1;
    $blocks = sanitize_blocks((string)($_POST['blocks'] ?? ''));

    if ($title === '' && $head) $errors[] = 'กรุณาตั้งชื่อกล่อง หรือปิดสวิตช์ "แสดงหัวข้อกล่อง"';
    if ($title === '' && !$head) $title = 'กล่องอิสระ';

    if (!$errors) {
        if ($id > 0) {
            db()->prepare('UPDATE custom_sections SET title=?, lead=?, blocks=?, bg=?, width=?, align=?, show_head=? WHERE id=?')
                ->execute([$title, $lead, $blocks, $bg, $width, $align, $head, $id]);
            log_action('แก้ไขกล่องอิสระ', $title);
        } else {
            db()->prepare('INSERT INTO custom_sections (title, lead, blocks, bg, width, align, show_head) VALUES (?,?,?,?,?,?,?)')
                ->execute([$title, $lead, $blocks, $bg, $width, $align, $head]);
            $newId = (int)db()->lastInsertId();
            /* สร้างแถวคู่ใน sections ให้ทันที ต่อท้ายลำดับสุดท้าย */
            $mx = (int)db()->query('SELECT COALESCE(MAX(sort_order),0) FROM sections')->fetchColumn();
            db()->prepare("INSERT IGNORE INTO sections (skey, enabled, in_menu, sort_order, custom_title) VALUES (?, 1, 0, ?, '')")
                ->execute(['custom-' . $newId, $mx + 1]);
            log_action('สร้างกล่องอิสระ', $title);
        }
        flash_set('success', 'บันทึกกล่องเรียบร้อยแล้ว — ดูผลได้ที่หน้าแรก');
        redirect('admin/custom-sections.php');
    }
}

cs_sync_sections();

if (isset($_GET['edit'])) {
    $st = db()->prepare('SELECT * FROM custom_sections WHERE id = ?');
    $st->execute([(int)$_GET['edit']]);
    $edit = $st->fetch() ?: null;
}

$boxes = db()->query('SELECT * FROM custom_sections ORDER BY id ASC')->fetchAll();
$secs  = sections_all();
$init_json = $edit['blocks'] ?? null;
$admin_title = 'กล่องอิสระหน้าแรก';
require __DIR__ . '/_top.php';
?>
<?php if ($errors): ?>
<div class="alert danger"><span class="material-symbols-rounded">error</span>
  <div><?php foreach ($errors as $er): ?><div><?= e($er) ?></div><?php endforeach; ?></div>
</div>
<?php endif; ?>

<div class="card mb-2">
  <div class="section-head" style="margin-bottom:8px;">
    <div><span class="tag">CUSTOM</span><h3><?= $edit ? 'แก้ไขกล่อง' : 'สร้างกล่องใหม่' ?></h3>
    <p>กล่องที่ใส่อะไรก็ได้บนหน้าแรก — หัวข้อ ข้อความ รูป แกลเลอรี วิดีโอ ปุ่มกด คำถาม-คำตอบ
      แล้วจัดลำดับสลับกับ section มาตรฐานได้ที่ <a href="<?= e(url('admin/homepage.php')) ?>">การแสดงผลหน้าแรก</a></p></div>
    <?php if ($edit): ?><a class="btn small" href="<?= e(url('admin/custom-sections.php')) ?>">ยกเลิก</a><?php endif; ?>
  </div>

  <form method="post" action="">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">

    <label>ชื่อกล่อง <span class="text-muted">(แสดงเป็นหัวข้อบนหน้าแรก)</span></label>
    <input type="text" name="title" value="<?= e(old('title', $edit['title'] ?? '')) ?>" class="mb-2" maxlength="150"
           placeholder="เช่น บริการออนไลน์ของหน่วยงาน">

    <label>คำโปรยใต้หัวข้อ <span class="text-muted">(ไม่บังคับ)</span></label>
    <input type="text" name="lead" value="<?= e(old('lead', $edit['lead'] ?? '')) ?>" class="mb-2" maxlength="400"
           placeholder="อธิบายสั้นๆ ว่ากล่องนี้เกี่ยวกับอะไร">

    <label class="inline-check mb-2">
      <input type="checkbox" name="show_head" value="1" <?= old_checked('show_head', '1', (int)($edit['show_head'] ?? 1) === 1) ? 'checked' : '' ?>>
      <span><b>แสดงหัวข้อกล่อง</b> — ปิดไว้ถ้าอยากให้เห็นแต่เนื้อหาล้วนๆ</span>
    </label>

    <label>พื้นหลังกล่อง</label>
    <div class="sec-grid mb-2" style="grid-template-columns:repeat(auto-fit,minmax(215px,1fr));">
      <?php foreach ($BGS as $k => [$name, $why]): ?>
      <label class="inline-check" style="align-items:flex-start;">
        <input type="radio" name="bg" value="<?= e($k) ?>" <?= ($edit['bg'] ?? 'card') === $k ? 'checked' : '' ?>>
        <span><b><?= e($name) ?></b><br><span class="text-muted" style="font-size:12.5px;"><?= e($why) ?></span></span>
      </label>
      <?php endforeach; ?>
    </div>

    <div class="form-row">
      <div>
        <label>ความกว้างเนื้อหา</label>
        <?php foreach ($WIDTHS as $k => [$name, $why]): ?>
        <label class="inline-check" style="align-items:flex-start;">
          <input type="radio" name="width" value="<?= e($k) ?>" <?= ($edit['width'] ?? 'box') === $k ? 'checked' : '' ?>>
          <span><b><?= e($name) ?></b><br><span class="text-muted" style="font-size:12.5px;"><?= e($why) ?></span></span>
        </label>
        <?php endforeach; ?>
      </div>
      <div>
        <label>การจัดวางข้อความ</label>
        <label class="inline-check"><input type="radio" name="align" value="left" <?= ($edit['align'] ?? 'left') !== 'center' ? 'checked' : '' ?>><span>ชิดซ้าย</span></label>
        <label class="inline-check"><input type="radio" name="align" value="center" <?= ($edit['align'] ?? '') === 'center' ? 'checked' : '' ?>><span>กึ่งกลาง</span></label>
      </div>
    </div>

    <label style="margin-top:10px;">เนื้อหาในกล่อง</label>
    <?php block_editor_card('blocks', $init_json); ?>

    <div class="flex gap-2 items-center mt-2" style="flex-wrap:wrap;">
      <button class="btn primary" type="submit"><span class="material-symbols-rounded icon-sm">save</span>บันทึกกล่อง</button>
    </div>
  </form>
</div>

<div class="card">
  <div class="section-head" style="margin-bottom:8px;">
    <div><span class="tag">ALL BOXES</span><h3>กล่องทั้งหมด (<?= count($boxes) ?>)</h3></div>
    <?php if ($boxes): ?><a class="btn small" href="<?= e(url('admin/homepage.php')) ?>"><span class="material-symbols-rounded icon-sm">sort</span>จัดลำดับบนหน้าแรก</a><?php endif; ?>
  </div>
  <?php if ($boxes): ?>
  <table class="admin-table">
    <tr><th>ชื่อกล่อง</th><th>พื้นหลัง</th><th>เนื้อหา</th><th>สถานะ</th><th></th></tr>
    <?php foreach ($boxes as $b):
      $key = 'custom-' . $b['id'];
      $on  = (int)($secs[$key]['enabled'] ?? 0) === 1;
      $n   = count(json_decode((string)$b['blocks'], true) ?: []);
    ?>
    <tr>
      <td><?= e(mb_strimwidth($b['title'], 0, 60, '…')) ?>
        <?php if ((int)$b['show_head'] !== 1): ?><span class="badge" style="font-size:10px;">ซ่อนหัวข้อ</span><?php endif; ?></td>
      <td><span class="badge" style="font-size:11px;"><?= e($BGS[$b['bg']][0] ?? $b['bg']) ?></span></td>
      <td class="lr-date"><?= $n ?> บล็อก</td>
      <td><?= $on
            ? '<span class="badge success" style="font-size:11px;">แสดงอยู่</span>'
            : '<span class="badge" style="font-size:11px;">ซ่อนอยู่</span>' ?></td>
      <td style="white-space:nowrap;">
        <form method="post" action="" style="display:inline;">
          <?= csrf_field() ?>
          <input type="hidden" name="toggle_id" value="<?= (int)$b['id'] ?>">
          <button class="btn small" type="submit"><?= $on ? 'ซ่อน' : 'แสดง' ?></button>
        </form>
        <a class="btn small" href="<?= e(url('admin/custom-sections.php?edit=' . $b['id'])) ?>">แก้ไข</a>
        <form method="post" action="" style="display:inline;">
          <?= csrf_field() ?>
          <input type="hidden" name="delete_id" value="<?= (int)$b['id'] ?>">
          <button class="btn small danger" type="submit" data-confirm="ยืนยันลบกล่องนี้? (กู้คืนได้จากถังขยะ)">ลบ</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php else: ?>
  <div class="text-center text-muted" style="padding:28px;">
    <span class="material-symbols-rounded icon-lg">dashboard_customize</span>
    <p style="margin:8px 0 0;">ยังไม่มีกล่องอิสระ — สร้างกล่องแรกได้จากฟอร์มด้านบน</p>
  </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/_bottom.php'; ?>
