<?php
/** admin/stats.php — สถิติผู้เข้าชม (รายวัน 30 วัน / รายเดือน 12 เดือน / หน้ายอดนิยม) */
require dirname(__DIR__) . '/includes/init.php';
require __DIR__ . '/_auth.php';

$view = in_array($_GET['view'] ?? '', ['daily', 'monthly', 'pages'], true) ? $_GET['view'] : 'daily';

/* ชื่อหน้าเป็นภาษาไทยอ่านง่าย */
$page_names = [
    'home' => 'หน้าแรก', 'news' => 'รายการข่าว', 'post' => 'อ่านข่าว', 'documents' => 'เอกสาร',
    'ita' => 'ITA', 'procurement' => 'จัดซื้อจัดจ้าง', 'about' => 'เกี่ยวกับ', 'contact' => 'ติดต่อ',
    'complaint' => 'ร้องเรียน', 'search' => 'ค้นหา', 'personnel' => 'ผู้บริหาร', 'page' => 'หน้าเพจ',
];

$bars = [];
if ($view === 'daily') {
    for ($i = 29; $i >= 0; $i--) $bars[date('Y-m-d', strtotime("-$i day"))] = 0;
    $st = db()->query('SELECT vdate, SUM(views) AS n FROM page_views
                       WHERE vdate >= (CURDATE() - INTERVAL 29 DAY) GROUP BY vdate');
    foreach ($st as $r) $bars[$r['vdate']] = (int)$r['n'];
} elseif ($view === 'monthly') {
    for ($i = 11; $i >= 0; $i--) $bars[date('Y-m', strtotime("-$i month"))] = 0;
    $st = db()->query("SELECT DATE_FORMAT(vdate, '%Y-%m') AS m, SUM(views) AS n FROM page_views
                       WHERE vdate >= DATE_FORMAT(CURDATE() - INTERVAL 11 MONTH, '%Y-%m-01') GROUP BY m");
    foreach ($st as $r) if (isset($bars[$r['m']])) $bars[$r['m']] = (int)$r['n'];
} else {
    $st = db()->query('SELECT page, SUM(views) AS n FROM page_views GROUP BY page ORDER BY n DESC LIMIT 15');
    foreach ($st as $r) $bars[$r['page']] = (int)$r['n'];
}
$max_bar = max(1, $bars ? max($bars) : 1);

$total_all   = (int)db()->query('SELECT COALESCE(SUM(views),0) FROM page_views')->fetchColumn();
$total_month = views_this_month();
$total_today = (int)db()->query('SELECT COALESCE(SUM(views),0) FROM page_views WHERE vdate = CURDATE()')->fetchColumn();

/* ── สถิติเชิงลึก ── */
$total_7d    = (int)db()->query("SELECT COALESCE(SUM(views),0) FROM page_views WHERE vdate >= (CURDATE() - INTERVAL 6 DAY)")->fetchColumn();
$sum_30d     = (int)db()->query("SELECT COALESCE(SUM(views),0) FROM page_views WHERE vdate >= (CURDATE() - INTERVAL 29 DAY)")->fetchColumn();
$avg_day     = (int)round($sum_30d / 30);
$days_active = (int)db()->query("SELECT COUNT(DISTINCT vdate) FROM page_views")->fetchColumn();
$peak        = db()->query("SELECT vdate, SUM(views) AS n FROM page_views GROUP BY vdate ORDER BY n DESC LIMIT 1")->fetch();
$peak_n      = (int)($peak['n'] ?? 0);
$peak_d      = $peak['vdate'] ?? null;
$last_month  = (int)db()->query("SELECT COALESCE(SUM(views),0) FROM page_views
                                 WHERE vdate >= DATE_FORMAT(CURDATE() - INTERVAL 1 MONTH, '%Y-%m-01')
                                   AND vdate <  DATE_FORMAT(CURDATE(), '%Y-%m-01')")->fetchColumn();
$mom = $last_month > 0 ? (int)round(($total_month - $last_month) / $last_month * 100) : null;

$thai_months = ['ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
$admin_title = 'สถิติผู้เข้าชม';
require __DIR__ . '/_top.php';
?>
<div class="grid grid-3 mb-2">
  <div class="card card-compact stat-card">
    <div class="si" style="background:color-mix(in srgb, var(--blue) 10%, transparent);color:var(--blue)"><span class="material-symbols-rounded">today</span></div>
    <div><b data-count="<?= $total_today ?>">0</b><span>วันนี้</span></div>
  </div>
  <div class="card card-compact stat-card">
    <div class="si" style="background:rgba(52,168,83,.1);color:var(--success)"><span class="material-symbols-rounded">calendar_month</span></div>
    <div><b data-count="<?= $total_month ?>">0</b><span>เดือนนี้
      <?php if ($mom !== null): ?><span class="badge <?= $mom >= 0 ? 'success' : 'danger' ?>" style="font-size:10px;padding:1px 6px;margin-left:4px;"><?= $mom >= 0 ? '▲' : '▼' ?> <?= abs($mom) ?>%</span><?php endif; ?>
    </span></div>
  </div>
  <div class="card card-compact stat-card">
    <div class="si" style="background:rgba(156,39,176,.1);color:var(--special)"><span class="material-symbols-rounded">monitoring</span></div>
    <div><b data-count="<?= $total_all ?>">0</b><span>ผู้เข้าชมสะสม</span></div>
  </div>
</div>

<div class="grid grid-3 mb-2">
  <div class="card card-compact stat-card">
    <div class="si" style="background:color-mix(in srgb, var(--blue) 10%, transparent);color:var(--blue)"><span class="material-symbols-rounded">date_range</span></div>
    <div><b data-count="<?= $total_7d ?>">0</b><span>7 วันล่าสุด</span></div>
  </div>
  <div class="card card-compact stat-card">
    <div class="si" style="background:rgba(52,168,83,.1);color:var(--success)"><span class="material-symbols-rounded">trending_up</span></div>
    <div><b data-count="<?= $avg_day ?>">0</b><span>เฉลี่ย/วัน (30 วัน)</span></div>
  </div>
  <div class="card card-compact stat-card">
    <div class="si" style="background:rgba(234,67,53,.1);color:var(--danger)"><span class="material-symbols-rounded">local_fire_department</span></div>
    <div><b><?= number_format($peak_n) ?></b><span>สูงสุด<?= $peak_d ? ' · ' . e(thai_date($peak_d)) : '' ?></span></div>
  </div>
</div>

<div class="card">
  <div class="section-head" style="margin-bottom:4px;">
    <div><span class="tag">ANALYTICS</span><h3>สถิติผู้เข้าชม</h3>
    <p>นับเฉพาะการเข้าชมจากคนจริง (กรองบอท/โปรแกรมอัตโนมัติออกแล้ว) — เก็บแค่จำนวนครั้งต่อหน้าและวันที่ ไม่เก็บข้อมูลส่วนบุคคล</p></div>
    <div class="cats">
      <a class="cat<?= $view === 'daily' ? ' active' : '' ?>" href="<?= e(url('admin/stats.php?view=daily')) ?>">รายวัน</a>
      <a class="cat<?= $view === 'monthly' ? ' active' : '' ?>" href="<?= e(url('admin/stats.php?view=monthly')) ?>">รายเดือน</a>
      <a class="cat<?= $view === 'pages' ? ' active' : '' ?>" href="<?= e(url('admin/stats.php?view=pages')) ?>">หน้ายอดนิยม</a>
    </div>
  </div>

  <?php if ($view === 'pages'): ?>
    <?php if (array_sum($bars) > 0): ?>
    <table class="admin-table">
      <tr><th>หน้า</th><th style="width:55%;">สัดส่วน</th><th>ครั้ง</th></tr>
      <?php foreach ($bars as $pg => $n): ?>
      <tr>
        <td><?= e($page_names[$pg] ?? $pg) ?></td>
        <td><div class="progress"><i style="width:<?= round($n / $max_bar * 100) ?>%"></i></div></td>
        <td class="lr-date"><?= number_format($n) ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
    <?php else: ?>
    <p class="text-muted text-center" style="padding:32px;">ยังไม่มีข้อมูล</p>
    <?php endif; ?>
  <?php else: ?>
    <div class="chart" style="height:190px;">
      <?php foreach ($bars as $k => $n): ?>
      <div class="bar" style="height:<?= max(2, round($n / $max_bar * 100)) ?>%" title="<?= e($k) ?>: <?= number_format($n) ?>"><i><?= $n > 0 ? number_format($n) : '' ?></i></div>
      <?php endforeach; ?>
    </div>
    <div class="chart-x">
      <?php foreach ($bars as $k => $n): ?>
      <span><?= $view === 'daily' ? (int)substr($k, 8, 2) : e($thai_months[(int)substr($k, 5, 2) - 1]) ?></span>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/_bottom.php'; ?>
