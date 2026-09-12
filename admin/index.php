<?php
/** admin/index.php — แดชบอร์ด */
require dirname(__DIR__) . '/includes/init.php';
require __DIR__ . '/_auth.php';

/* สถิติภาพรวม */
$n_views_month = views_this_month();
$n_posts = (int)db()->query('SELECT COUNT(*) FROM posts')->fetchColumn();
$n_docs  = (int)db()->query('SELECT COUNT(*) FROM documents')->fetchColumn();
$n_compl = (int)db()->query("SELECT COUNT(*) FROM complaints WHERE status = 'new'")->fetchColumn();

/* กราฟผู้เข้าชม 14 วันล่าสุด */
$days = [];
for ($i = 13; $i >= 0; $i--) $days[date('Y-m-d', strtotime("-$i day"))] = 0;
$st = db()->prepare('SELECT vdate, SUM(views) AS n FROM page_views
                     WHERE vdate >= (CURDATE() - INTERVAL 13 DAY) GROUP BY vdate');
$st->execute();
foreach ($st as $r) $days[$r['vdate']] = (int)$r['n'];
$max_day = max(1, max($days));

/* ข่าวล่าสุด */
$latest = db()->query('SELECT id, title, type, status FROM posts ORDER BY id DESC LIMIT 6')->fetchAll();

/* เรื่องร้องเรียนล่าสุด */
$latest_compl = db()->query('SELECT id, subject, name, status, created_at FROM complaints ORDER BY id DESC LIMIT 6')->fetchAll();
$compl_badge = ['new' => ['danger', 'ใหม่'], 'progress' => ['', 'กำลังดำเนินการ'], 'done' => ['success', 'เสร็จสิ้น']];

/* ตรวจสุขภาพที่เก็บเซสชัน — ถ้าเขียนไม่ได้ ล็อกอินจะหลุดและทุกฟอร์มจะขึ้น CSRF ล้มเหลว
   (เคยเกิดจริงตอนโฮสต์ย้ายเครื่อง) เตือนไว้ให้เห็นก่อนที่จะไปงงกับอาการปลายทาง */
$sess_path   = session_save_path() ?: sys_get_temp_dir();
$sess_broken = !is_dir($sess_path) || !is_writable($sess_path);

$admin_title = 'แดชบอร์ด';
require __DIR__ . '/_top.php';
?>
<?php if ($sess_broken): ?>
<div class="alert danger mb-2"><span class="material-symbols-rounded">error</span>
  <div><b>เซิร์ฟเวอร์เขียนไฟล์เซสชันไม่ได้</b><br>
    ที่เก็บเซสชันปัจจุบัน: <code><?= e($sess_path) ?></code> —
    ถ้าปล่อยไว้จะทำให้ล็อกอินหลุดเอง และฟอร์มทุกหน้าขึ้น "การตรวจสอบความปลอดภัยล้มเหลว (CSRF)"
    กรุณาแจ้งผู้ดูแลโฮสต์ให้ตรวจสิทธิ์เขียนโฟลเดอร์นี้ หรือตรวจว่าพื้นที่ดิสก์เต็มหรือไม่</div>
</div>
<?php endif; ?>
<!-- การ์ดสถิติ -->
<div class="grid grid-4 mb-2">
  <div class="card card-compact stat-card">
    <div class="si" style="background:color-mix(in srgb, var(--blue) 10%, transparent);color:var(--blue)"><span class="material-symbols-rounded">visibility</span></div>
    <div><b data-count="<?= $n_views_month ?>">0</b><span>ผู้เข้าชมเดือนนี้</span></div>
  </div>
  <div class="card card-compact stat-card">
    <div class="si" style="background:rgba(52,168,83,.1);color:var(--success)"><span class="material-symbols-rounded">newspaper</span></div>
    <div><b data-count="<?= $n_posts ?>">0</b><span>ข่าวทั้งหมด</span></div>
  </div>
  <div class="card card-compact stat-card">
    <div class="si" style="background:rgba(249,171,0,.12);color:#B07000"><span class="material-symbols-rounded">folder_open</span></div>
    <div><b data-count="<?= $n_docs ?>">0</b><span>เอกสาร</span></div>
  </div>
  <div class="card card-compact stat-card">
    <div class="si" style="background:rgba(234,67,53,.08);color:var(--danger)"><span class="material-symbols-rounded">support_agent</span></div>
    <div><b data-count="<?= $n_compl ?>">0</b><span>ร้องเรียนรอตอบ</span></div>
  </div>
</div>

<!-- กราฟผู้เข้าชม 14 วัน -->
<div class="card mb-2">
  <div class="section-head" style="margin-bottom:4px;">
    <div><span class="tag">ANALYTICS</span><h3>ผู้เข้าชม 14 วันล่าสุด</h3></div>
    <a class="btn small" href="<?= e(url('admin/stats.php')) ?>">ดูสถิติทั้งหมด <span class="material-symbols-rounded icon-sm">arrow_forward</span></a>
  </div>
  <div class="chart">
    <?php foreach ($days as $d => $n): ?>
    <div class="bar" style="height:<?= round($n / $max_day * 100) ?>%" title="<?= e(thai_date($d)) ?>: <?= number_format($n) ?>"><i><?= $n > 0 ? number_format($n) : '' ?></i></div>
    <?php endforeach; ?>
  </div>
  <div class="chart-x">
    <?php foreach ($days as $d => $n): ?><span><?= (int)substr($d, 8, 2) ?></span><?php endforeach; ?>
  </div>
</div>

<div class="grid grid-2">
  <!-- ตารางข่าวล่าสุด -->
  <div class="card">
    <div class="section-head" style="margin-bottom:8px;">
      <div><span class="tag">POSTS</span><h3>ข่าวล่าสุด</h3></div>
      <a class="btn small primary" href="<?= e(url('admin/post-edit.php')) ?>"><span class="material-symbols-rounded icon-sm">add</span>เพิ่มข่าว</a>
    </div>
    <?php if ($latest): ?>
    <table class="admin-table">
      <tr><th>หัวข้อ</th><th>ประเภท</th><th></th></tr>
      <?php foreach ($latest as $p): $types = post_types(); ?>
      <tr>
        <td><?= e(mb_strimwidth($p['title'], 0, 60, '…')) ?>
          <?php if ($p['status'] === 'draft'): ?><span class="badge" style="font-size:10px;padding:1px 7px;">ร่าง</span><?php endif; ?>
        </td>
        <td><span class="badge <?= e($types[$p['type']]['badge'] ?? '') ?>"><?= e(post_type_label($p['type'])) ?></span></td>
        <td style="white-space:nowrap;"><a class="btn small" href="<?= e(url('admin/post-edit.php?id=' . $p['id'])) ?>">แก้ไข</a></td>
      </tr>
      <?php endforeach; ?>
    </table>
    <?php else: ?>
    <p class="text-muted text-center" style="padding:24px;">ยังไม่มีข่าว — เริ่มเพิ่มข่าวแรกได้เลย</p>
    <?php endif; ?>
  </div>

  <!-- เรื่องร้องเรียนล่าสุด -->
  <div class="card">
    <div class="section-head" style="margin-bottom:8px;">
      <div><span class="tag">COMPLAINTS</span><h3>เรื่องร้องเรียนล่าสุด</h3></div>
      <a class="btn small" href="<?= e(url('admin/complaints.php')) ?>">ดูทั้งหมด <span class="material-symbols-rounded icon-sm">arrow_forward</span></a>
    </div>
    <?php if ($latest_compl): ?>
    <table class="admin-table">
      <tr><th>เรื่อง</th><th>สถานะ</th><th></th></tr>
      <?php foreach ($latest_compl as $c): [$cb, $cl] = $compl_badge[$c['status']] ?? ['', $c['status']]; ?>
      <tr>
        <td><?= e(mb_strimwidth($c['subject'], 0, 50, '…')) ?>
          <div class="text-muted" style="font-size:11.5px;"><?= e($c['name'] ?: 'ไม่ระบุชื่อ') ?> · <?= e(thai_date($c['created_at'])) ?></div>
        </td>
        <td><span class="badge <?= e($cb) ?>" style="font-size:10px;padding:1px 7px;white-space:nowrap;"><?= e($cl) ?></span></td>
        <td style="white-space:nowrap;"><a class="btn small" href="<?= e(url('admin/complaints.php?id=' . $c['id'])) ?>">ดู</a></td>
      </tr>
      <?php endforeach; ?>
    </table>
    <?php else: ?>
    <p class="text-muted text-center" style="padding:24px;">ยังไม่มีเรื่องร้องเรียน</p>
    <?php endif; ?>
  </div>
</div>
<?php require __DIR__ . '/_bottom.php'; ?>
