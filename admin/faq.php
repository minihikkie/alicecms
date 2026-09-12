<?php
/** admin/faq.php — จัดการคำถามที่พบบ่อย */
require dirname(__DIR__) . '/includes/init.php';
require __DIR__ . '/_auth.php';

$errors = [];
$edit = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['delete_id'])) {
        if (trash_delete('faqs', (int)$_POST['delete_id'], $ADMIN['username'] ?? '')) {
            log_action('ลบคำถามที่พบบ่อย (ลงถังขยะ)', '#' . (int)$_POST['delete_id']);
            flash_set('success', 'ย้ายคำถามลงถังขยะแล้ว — กู้คืนได้ที่เมนู "ถังขยะ"');
        }
        redirect('admin/faq.php');
    }

    $id       = (int)($_POST['id'] ?? 0);
    $question = mb_substr(trim((string)($_POST['question'] ?? '')), 0, 500);
    $answer   = trim((string)($_POST['answer'] ?? ''));
    $sort     = (int)($_POST['sort_order'] ?? 0);

    if ($question === '') $errors[] = 'กรุณากรอกคำถาม';
    if ($answer === '')   $errors[] = 'กรุณากรอกคำตอบ';

    if (!$errors) {
        if ($id > 0) {
            db()->prepare('UPDATE faqs SET question=?, answer=?, sort_order=? WHERE id=?')
                ->execute([$question, $answer, $sort, $id]);
        } else {
            db()->prepare('INSERT INTO faqs (question, answer, sort_order) VALUES (?,?,?)')
                ->execute([$question, $answer, $sort]);
        }
        flash_set('success', 'บันทึกคำถามเรียบร้อยแล้ว');
        redirect('admin/faq.php');
    }
}

if (isset($_GET['edit'])) {
    $st = db()->prepare('SELECT * FROM faqs WHERE id = ?');
    $st->execute([(int)$_GET['edit']]);
    $edit = $st->fetch();
}

$faqs = db()->query('SELECT * FROM faqs ORDER BY sort_order ASC, id ASC')->fetchAll();
$admin_title = 'FAQ';
require __DIR__ . '/_top.php';
?>
<?php if ($errors): ?>
<div class="alert danger"><span class="material-symbols-rounded">error</span>
  <div><?php foreach ($errors as $er): ?><div><?= e($er) ?></div><?php endforeach; ?></div>
</div>
<?php endif; ?>

<div class="card mb-2">
  <div class="section-head" style="margin-bottom:8px;">
    <div><span class="tag">FAQ</span><h3><?= $edit ? 'แก้ไขคำถาม' : 'เพิ่มคำถามใหม่' ?></h3></div>
    <?php if ($edit): ?><a class="btn small" href="<?= e(url('admin/faq.php')) ?>">ยกเลิก</a><?php endif; ?>
  </div>
  <form method="post" action="">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
    <label>คำถาม <span style="color:var(--danger);">*</span></label>
    <input type="text" name="question" value="<?= e(old('question', $edit['question'] ?? '')) ?>" required class="mb-2" placeholder="เช่น ติดต่อราชการได้วัน-เวลาไหน?">
    <label>คำตอบ <span style="color:var(--danger);">*</span></label>
    <textarea name="answer" rows="3" required class="mb-2" placeholder="คำตอบ..."><?= e(old('answer', $edit['answer'] ?? '')) ?></textarea>
    <div class="flex gap-2 items-center">
      <div><label>ลำดับ</label><input type="number" name="sort_order" value="<?= (int)($edit['sort_order'] ?? (count($faqs) + 1)) ?>" class="sort-input"></div>
      <button class="btn primary" type="submit" style="margin-top:20px;"><span class="material-symbols-rounded icon-sm">save</span><?= $edit ? 'บันทึกการแก้ไข' : 'เพิ่มคำถาม' ?></button>
    </div>
  </form>
</div>

<div class="card">
  <div class="section-head" style="margin-bottom:8px;">
    <div><span class="tag">ALL FAQ</span><h3>คำถามทั้งหมด (<?= count($faqs) ?>)</h3></div>
  </div>
  <?php if ($faqs): ?>
  <table class="admin-table">
    <tr><th>ลำดับ</th><th>คำถาม</th><th></th></tr>
    <?php foreach ($faqs as $f): ?>
    <tr>
      <td><?= (int)$f['sort_order'] ?></td>
      <td><?= e(mb_strimwidth($f['question'], 0, 80, '…')) ?></td>
      <td style="white-space:nowrap;">
        <a class="btn small" href="<?= e(url('admin/faq.php?edit=' . $f['id'])) ?>">แก้ไข</a>
        <form method="post" action="" style="display:inline;">
          <?= csrf_field() ?>
          <input type="hidden" name="delete_id" value="<?= (int)$f['id'] ?>">
          <button class="btn small danger" type="submit" data-confirm="ยืนยันลบคำถามนี้?">ลบ</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php else: ?>
  <p class="text-muted text-center" style="padding:24px;">ยังไม่มีคำถาม</p>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/_bottom.php'; ?>
