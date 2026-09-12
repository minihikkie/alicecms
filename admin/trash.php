<?php
/** admin/trash.php — ถังขยะ: กู้คืนสิ่งที่ลบไป หรือลบถาวร (เก็บอัตโนมัติ 30 วัน) */
require dirname(__DIR__) . '/includes/init.php';
require __DIR__ . '/_auth.php';

/* ลบของเก่าเกินกำหนดอัตโนมัติทุกครั้งที่เปิดหน้านี้ */
$purged_auto = trash_autopurge();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['restore_id'])) {
        [$ok, $msg] = trash_restore((int)$_POST['restore_id']);
        log_action($ok ? 'กู้คืนจากถังขยะ' : 'กู้คืนล้มเหลว', mb_substr($msg, 0, 200));
        flash_set($ok ? 'success' : 'danger', $msg);
        redirect('admin/trash.php');
    }
    if (isset($_POST['purge_id'])) {
        $ok = trash_purge((int)$_POST['purge_id']);
        log_action('ลบถาวรจากถังขยะ', '#' . (int)$_POST['purge_id']);
        flash_set($ok ? 'success' : 'danger', $ok ? 'ลบถาวรเรียบร้อยแล้ว' : 'ลบไม่สำเร็จ');
        redirect('admin/trash.php');
    }
    if (isset($_POST['empty_all'])) {
        require_admin();
        $n = 0;
        foreach (db()->query('SELECT id FROM trash')->fetchAll(PDO::FETCH_COLUMN) as $tid) {
            if (trash_purge((int)$tid)) $n++;
        }
        log_action('ล้างถังขยะทั้งหมด', $n . ' รายการ');
        flash_set('success', 'ล้างถังขยะแล้ว ' . number_format($n) . ' รายการ');
        redirect('admin/trash.php');
    }
}

$items = db()->query('SELECT * FROM trash ORDER BY deleted_at DESC, id DESC')->fetchAll();
$admin_title = 'ถังขยะ';
require __DIR__ . '/_top.php';
?>
<div class="card mb-2">
  <div class="section-head" style="margin-bottom:8px;">
    <div><span class="tag"><span class="material-symbols-rounded icon-sm">delete</span> TRASH</span>
      <h3>ถังขยะ (<?= number_format(count($items)) ?>)</h3>
      <p>สิ่งที่ลบไปจะเก็บไว้ที่นี่ <b><?= TRASH_KEEP_DAYS ?> วัน</b> กู้คืนได้ทุกเมื่อ พ้นกำหนดแล้วระบบจะลบถาวรอัตโนมัติ</p></div>
    <?php if ($items && is_admin_role()): ?>
    <form method="post" action="" style="margin:0;">
      <?= csrf_field() ?>
      <input type="hidden" name="empty_all" value="1">
      <button class="btn danger small" type="submit"
              data-confirm="ล้างถังขยะทั้งหมด <?= count($items) ?> รายการอย่างถาวร? กู้คืนไม่ได้อีก">
        <span class="material-symbols-rounded icon-sm">delete_forever</span>ล้างถังขยะ
      </button>
    </form>
    <?php endif; ?>
  </div>

  <?php if ($purged_auto > 0): ?>
  <div class="alert" style="font-size:13px;"><span class="material-symbols-rounded">auto_delete</span>
    <div>ลบรายการที่เกิน <?= TRASH_KEEP_DAYS ?> วันออกอัตโนมัติแล้ว <?= number_format($purged_auto) ?> รายการ</div></div>
  <?php endif; ?>

  <?php if ($items): ?>
  <table class="admin-table">
    <tr><th>รายการ</th><th style="width:150px;">ประเภท</th><th style="width:150px;">ลบเมื่อ</th><th style="width:120px;">ลบโดย</th><th style="width:180px;"></th></tr>
    <?php foreach ($items as $t):
      $days_left = TRASH_KEEP_DAYS - (int)floor((time() - strtotime((string)$t['deleted_at'])) / 86400);
      $nfiles = count(json_decode((string)($t['files'] ?? ''), true) ?: []);
    ?>
    <tr>
      <td>
        <b style="color:var(--ink);"><?= e(mb_strimwidth($t['label'], 0, 70, '…')) ?></b>
        <?php if ($nfiles): ?><span class="badge" style="font-size:10px;margin-left:4px;"><span class="material-symbols-rounded icon-sm">attach_file</span><?= $nfiles ?> ไฟล์</span><?php endif; ?>
        <br><span class="lr-date">เหลืออีก <?= max(0, $days_left) ?> วันก่อนลบถาวร</span>
      </td>
      <td><span class="badge" style="font-size:11px;"><?= e($t['kind']) ?></span></td>
      <td class="lr-date" style="white-space:nowrap;"><?= e(thai_date($t['deleted_at'])) ?></td>
      <td class="lr-date"><?= e($t['deleted_by'] ?: '—') ?></td>
      <td style="white-space:nowrap;">
        <form method="post" action="" style="display:inline;">
          <?= csrf_field() ?>
          <input type="hidden" name="restore_id" value="<?= (int)$t['id'] ?>">
          <button class="btn small primary" type="submit"><span class="material-symbols-rounded icon-sm">restore_from_trash</span>กู้คืน</button>
        </form>
        <form method="post" action="" style="display:inline;">
          <?= csrf_field() ?>
          <input type="hidden" name="purge_id" value="<?= (int)$t['id'] ?>">
          <button class="btn small danger" type="submit"
                  data-confirm="ลบ «<?= e(mb_strimwidth($t['label'], 0, 40, '…')) ?>» อย่างถาวร? กู้คืนไม่ได้อีก">ลบถาวร</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php else: ?>
  <div class="text-center text-muted" style="padding:40px 16px;">
    <span class="material-symbols-rounded" style="font-size:48px;opacity:.35;">delete</span>
    <p style="margin:8px 0 0;">ถังขยะว่าง — ยังไม่มีอะไรถูกลบ</p>
  </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/_bottom.php'; ?>
