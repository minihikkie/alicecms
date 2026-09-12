<?php
/** admin/complaints.php — เรื่องร้องเรียน (อ่าน/เปลี่ยนสถานะ ใหม่ → กำลังดำเนินการ → เสร็จสิ้น) */
require dirname(__DIR__) . '/includes/init.php';
require __DIR__ . '/_auth.php';

$statuses = [
    'new'      => ['label' => 'ใหม่',             'badge' => 'danger'],
    'progress' => ['label' => 'กำลังดำเนินการ',   'badge' => 'warning'],
    'done'     => ['label' => 'เสร็จสิ้น',         'badge' => 'success'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['set_status'], $_POST['id']) && isset($statuses[$_POST['set_status']])) {
        db()->prepare('UPDATE complaints SET status = ? WHERE id = ?')
            ->execute([$_POST['set_status'], (int)$_POST['id']]);
        log_action('เปลี่ยนสถานะร้องเรียน', '#' . (int)$_POST['id'] . ' → ' . ($statuses[$_POST['set_status']]['label'] ?? ''));
        flash_set('success', 'เปลี่ยนสถานะเรียบร้อยแล้ว');
        redirect('admin/complaints.php?view=' . (int)$_POST['id']);
    }
    /* บันทึกคำตอบกลับถึงผู้ร้อง (แสดงในหน้าติดตามสถานะ) */
    if (isset($_POST['save_response'], $_POST['id'])) {
        $resp = mb_substr(trim((string)($_POST['response'] ?? '')), 0, 3000);
        db()->prepare('UPDATE complaints SET response = ?, responded_at = NOW() WHERE id = ?')
            ->execute([$resp !== '' ? $resp : null, (int)$_POST['id']]);
        log_action('ตอบกลับร้องเรียน', '#' . (int)$_POST['id']);
        flash_set('success', 'บันทึกคำตอบเรียบร้อยแล้ว');
        redirect('admin/complaints.php?view=' . (int)$_POST['id']);
    }
    if (isset($_POST['delete_id'])) {
        $id = (int)$_POST['delete_id'];
        if (trash_delete('complaints', $id, $ADMIN['username'] ?? '')) {
            log_action('ลบเรื่องร้องเรียน (ลงถังขยะ)', '#' . $id);
            flash_set('success', 'ย้ายเรื่องร้องเรียนลงถังขยะแล้ว — กู้คืนได้ที่เมนู "ถังขยะ"');
        }
        redirect('admin/complaints.php');
    }
}

/* ดูรายละเอียด */
$view = null;
if (isset($_GET['view'])) {
    $st = db()->prepare('SELECT * FROM complaints WHERE id = ?');
    $st->execute([(int)$_GET['view']]);
    $view = $st->fetch();
}

$filter = isset($_GET['status']) && isset($statuses[$_GET['status']]) ? $_GET['status'] : '';
$where  = $filter !== '' ? 'WHERE status = ?' : '';
$params = $filter !== '' ? [$filter] : [];

$st = db()->prepare("SELECT * FROM complaints $where ORDER BY id DESC LIMIT 100");
$st->execute($params);
$rows = $st->fetchAll();

$admin_title = 'เรื่องร้องเรียน';
require __DIR__ . '/_top.php';
?>
<?php if ($view): ?>
<div class="card mb-2">
  <div class="section-head" style="margin-bottom:8px;">
    <div><span class="tag">เลขรับเรื่อง <?= e($view['ref_code'] ?: '#' . (int)$view['id']) ?></span><h3><?= e($view['subject']) ?></h3>
    <p>รับเรื่องเมื่อ <?= e(thai_date($view['created_at'], false)) ?></p></div>
    <a class="btn small" href="<?= e(url('admin/complaints.php')) ?>"><span class="material-symbols-rounded icon-sm">arrow_back</span>กลับรายการ</a>
  </div>

  <div class="grid grid-2 mb-2">
    <div class="alert info" style="margin:0;"><span class="material-symbols-rounded">person</span>
      <div style="font-size:14px;"><b>ผู้แจ้ง:</b> <?= e($view['name'] ?: 'ไม่ระบุชื่อ') ?><br>
      <b>ช่องทางติดต่อกลับ:</b> <?= e($view['contact'] ?: 'ไม่ระบุ') ?></div>
    </div>
    <div class="alert warning" style="margin:0;"><span class="material-symbols-rounded">flag</span>
      <div style="font-size:14px;"><b>สถานะปัจจุบัน:</b>
        <span class="badge <?= e($statuses[$view['status']]['badge']) ?>"><?= e($statuses[$view['status']]['label']) ?></span>
      </div>
    </div>
  </div>

  <label>รายละเอียดเรื่องร้องเรียน</label>
  <div class="card card-compact mb-2" style="background:rgba(255,255,255,.7);">
    <div class="post-body" style="font-size:14px;"><?= nl2br(e($view['detail'])) ?></div>
  </div>

  <?php if ($view['file']): ?>
  <a class="btn mb-2" href="<?= e(url('admin/complaint-file.php?id=' . (int)$view['id'])) ?>" target="_blank" rel="noopener">
    <span class="material-symbols-rounded icon-sm">attach_file</span>ดูไฟล์แนบ
  </a>
  <?php endif; ?>

  <!-- คำตอบกลับถึงผู้ร้อง (แสดงในหน้าติดตามสถานะของประชาชน) -->
  <form method="post" action="" class="mb-2">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int)$view['id'] ?>">
    <label>คำตอบ/ผลการดำเนินการ <span class="text-muted">(ผู้ร้องเห็นได้ในหน้าติดตามสถานะด้วยเลขรับเรื่อง)</span></label>
    <textarea name="response" rows="3" class="mb-1" placeholder="เช่น ดำเนินการแก้ไขเรียบร้อยแล้ว..."><?= e($view['response'] ?? '') ?></textarea>
    <button class="btn small primary" type="submit" name="save_response" value="1"><span class="material-symbols-rounded icon-sm">save</span>บันทึกคำตอบ</button>
    <?php if (!empty($view['responded_at'])): ?><span class="text-muted" style="font-size:12px;margin-left:8px;">ตอบล่าสุด <?= e(thai_date($view['responded_at'])) ?></span><?php endif; ?>
  </form>

  <div class="flex gap-1" style="flex-wrap:wrap;">
    <?php foreach ($statuses as $sk => $sv): if ($sk === $view['status']) continue; ?>
    <form method="post" action="" style="display:inline;">
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= (int)$view['id'] ?>">
      <input type="hidden" name="set_status" value="<?= e($sk) ?>">
      <button class="btn <?= $sk === 'done' ? 'success' : ($sk === 'progress' ? '' : 'danger') ?>" type="submit">
        <span class="material-symbols-rounded icon-sm"><?= $sk === 'done' ? 'check_circle' : ($sk === 'progress' ? 'pending' : 'fiber_new') ?></span>
        เปลี่ยนเป็น «<?= e($sv['label']) ?>»
      </button>
    </form>
    <?php endforeach; ?>
    <form method="post" action="" style="display:inline;margin-left:auto;">
      <?= csrf_field() ?>
      <input type="hidden" name="delete_id" value="<?= (int)$view['id'] ?>">
      <button class="btn small danger" type="submit" data-confirm="ยืนยันลบเรื่องร้องเรียนนี้ถาวร?">ลบเรื่องนี้</button>
    </form>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <div class="section-head" style="margin-bottom:8px;">
    <div><span class="tag">COMPLAINTS</span><h3>เรื่องร้องเรียนทั้งหมด</h3></div>
  </div>
  <div class="cats mb-2">
    <a class="cat<?= $filter === '' ? ' active' : '' ?>" href="<?= e(url('admin/complaints.php')) ?>">ทั้งหมด</a>
    <?php foreach ($statuses as $sk => $sv): ?>
    <a class="cat<?= $filter === $sk ? ' active' : '' ?>" href="<?= e(url('admin/complaints.php?status=' . $sk)) ?>"><?= e($sv['label']) ?></a>
    <?php endforeach; ?>
  </div>
  <?php if ($rows): ?>
  <table class="admin-table">
    <tr><th>#</th><th>เรื่อง</th><th>ผู้แจ้ง</th><th>วันที่</th><th>สถานะ</th><th></th></tr>
    <?php foreach ($rows as $c): ?>
    <tr>
      <td class="lr-date"><?= (int)$c['id'] ?></td>
      <td style="max-width:280px;"><?= e(mb_strimwidth($c['subject'], 0, 60, '…')) ?>
        <?php if ($c['file']): ?><span class="material-symbols-rounded icon-sm" style="color:var(--muted);" title="มีไฟล์แนบ">attach_file</span><?php endif; ?>
      </td>
      <td class="lr-date"><?= e(mb_strimwidth($c['name'] ?: 'ไม่ระบุ', 0, 25, '…')) ?></td>
      <td class="lr-date" style="white-space:nowrap;"><?= e(thai_date($c['created_at'])) ?></td>
      <td><span class="badge <?= e($statuses[$c['status']]['badge'] ?? '') ?>" style="font-size:11px;"><?= e($statuses[$c['status']]['label'] ?? $c['status']) ?></span></td>
      <td><a class="btn small primary" href="<?= e(url('admin/complaints.php?view=' . $c['id'])) ?>">อ่าน</a></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php else: ?>
  <p class="text-muted text-center" style="padding:32px;">ไม่มีเรื่องร้องเรียน<?= $filter !== '' ? 'ในสถานะนี้' : '' ?></p>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/_bottom.php'; ?>
