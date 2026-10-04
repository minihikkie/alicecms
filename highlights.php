<?php
/**
 * highlights.php — คลังข่าวด่วน · ประชาสัมพันธ์ย้อนหลัง
 *
 * กล่องบนหน้าแรกแสดงเฉพาะเรื่องที่ยังอยู่ในช่วงเวลา พอหมดเวลาเรื่องจะหายจากหน้าแรก
 * แต่ประชาชนยังตามมาดูย้อนหลังได้ที่นี่ — กรองตามสถานะ/แหล่งที่มา และค้นหาได้
 * ?h=<id> = ลิงก์แชร์ของเรื่องหนึ่ง เปิดหน้ามาแล้วเลื่อนไปที่เรื่องนั้นพร้อมไฮไลต์
 */
define('PUBLIC_PAGE', 'highlights');
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/highlights.php';

require_section_live('highlight');

$per   = 12;
$page  = max(1, (int)($_GET['page'] ?? 1));
$tab   = in_array($_GET['tab'] ?? '', ['live', 'past'], true) ? $_GET['tab'] : 'all';
$plats = hl_platforms();
$plat  = isset($plats[$_GET['p'] ?? '']) ? $_GET['p'] : '';
$q     = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 80);
$focus = max(0, (int)($_GET['h'] ?? 0));

/* ไม่แสดงเรื่องที่ยังไม่ถึงเวลาเริ่ม และเรื่องที่ซ่อนไว้ — เป็นความลับของผู้ดูแลจนกว่าจะถึงเวลา */
$where  = ["status = 'published'", '(starts_at IS NULL OR starts_at <= :now)'];
$params = [':now' => hl_now()];
if ($tab === 'live') { $where[] = '(ends_at IS NULL OR ends_at > :now2)'; $params[':now2'] = hl_now(); }
if ($tab === 'past') { $where[] = '(ends_at IS NOT NULL AND ends_at <= :now2)'; $params[':now2'] = hl_now(); }
if ($plat !== '')    { $where[] = 'platform = :plat'; $params[':plat'] = $plat; }
if ($q !== '')       { $where[] = '(title LIKE :q1 OR summary LIKE :q2)'; $params[':q1'] = $params[':q2'] = '%' . $q . '%'; }
$sqlWhere = implode(' AND ', $where);

/* ลิงก์แชร์ชี้มาที่เรื่องเดียว — หาให้ว่าอยู่หน้าไหน จะได้เปิดมาเจอเลยไม่ต้องไล่หา */
if ($focus > 0 && !isset($_GET['page'])) {
    try {
        $st = db()->prepare("SELECT COALESCE(starts_at, created_at) AS d, id FROM highlights WHERE id = ? AND status = 'published'");
        $st->execute([$focus]);
        if ($row = $st->fetch()) {
            $c = db()->prepare("SELECT COUNT(*) FROM highlights WHERE $sqlWhere AND (COALESCE(starts_at, created_at) > :d OR (COALESCE(starts_at, created_at) = :d2 AND id > :id))");
            $c->execute($params + [':d' => $row['d'], ':d2' => $row['d'], ':id' => $row['id']]);
            $page = intdiv((int)$c->fetchColumn(), $per) + 1;
        }
    } catch (Throwable $e) {}
}

$total = 0; $list = [];
try {
    $c = db()->prepare("SELECT COUNT(*) FROM highlights WHERE $sqlWhere");
    $c->execute($params);
    $total = (int)$c->fetchColumn();
    $st = db()->prepare("SELECT * FROM highlights WHERE $sqlWhere
                         ORDER BY COALESCE(starts_at, created_at) DESC, id DESC
                         LIMIT $per OFFSET " . (($page - 1) * $per));
    $st->execute($params);
    $list = $st->fetchAll();
} catch (Throwable $e) {}

/* จำนวนต่อแหล่งที่มา — โชว์เฉพาะชิปของแหล่งที่มีเรื่องจริง */
$platCount = [];
try {
    foreach (db()->query("SELECT platform, COUNT(*) n FROM highlights WHERE status = 'published' GROUP BY platform") as $r) {
        $platCount[$r['platform']] = (int)$r['n'];
    }
} catch (Throwable $e) {}

$qs = function (array $over) use ($tab, $plat, $q): string {
    $p = array_filter(array_merge(['tab' => $tab === 'all' ? '' : $tab, 'p' => $plat, 'q' => $q], $over), fn($v) => $v !== '');
    return url('highlights.php' . ($p ? '?' . http_build_query($p) : ''));
};

$page_title = section_title('highlight');
$meta_description = 'คลังเรื่องด่วนและสื่อประชาสัมพันธ์ของ' . setting('site_name', 'หน่วยงาน') . ' ทั้งที่กำลังประชาสัมพันธ์และย้อนหลัง';
require __DIR__ . '/includes/header.php';
?>
<div class="container">
  <div class="page-head reveal">
    <div class="crumb"><a href="<?= e(url('index.php')) ?>">หน้าแรก</a> <span class="material-symbols-rounded icon-sm">chevron_right</span> <?= e($page_title) ?></div>
    <h1><?= e($page_title) ?></h1>
    <p class="text-muted">เรื่องด่วนและสื่อประชาสัมพันธ์ทั้งหมด รวมถึงเรื่องที่ผ่านมาแล้ว — กดที่ภาพเพื่อดูวิดีโอหรือเปิดโพสต์ต้นฉบับ</p>
  </div>

  <div class="hl-filters card card-compact reveal mb-2">
    <div class="cats" role="tablist" aria-label="สถานะ">
      <?php foreach (['all' => 'ทั้งหมด', 'live' => 'กำลังประชาสัมพันธ์', 'past' => 'ย้อนหลัง'] as $k => $lb): ?>
      <a class="cat<?= $tab === $k ? ' active' : '' ?>" href="<?= e($qs(['tab' => $k === 'all' ? '' : $k])) ?>"
         role="tab" aria-selected="<?= $tab === $k ? 'true' : 'false' ?>"><?= e($lb) ?></a>
      <?php endforeach; ?>
    </div>
    <form class="hl-search" method="get" action="<?= e(url('highlights.php')) ?>" role="search">
      <?php if ($tab !== 'all'): ?><input type="hidden" name="tab" value="<?= e($tab) ?>"><?php endif; ?>
      <?php if ($plat !== ''): ?><input type="hidden" name="p" value="<?= e($plat) ?>"><?php endif; ?>
      <span class="material-symbols-rounded icon-sm" aria-hidden="true">search</span>
      <input type="search" name="q" value="<?= e($q) ?>" placeholder="ค้นหาเรื่อง..." aria-label="ค้นหาเรื่อง">
    </form>
    <?php if (count($platCount) > 1): ?>
    <div class="hl-platchips">
      <a class="hl-chip<?= $plat === '' ? ' on' : '' ?>" href="<?= e($qs(['p' => ''])) ?>">ทุกแหล่ง</a>
      <?php foreach ($plats as $k => [$lb, $col]): if (empty($platCount[$k])) continue; ?>
      <a class="hl-chip<?= $plat === $k ? ' on' : '' ?>" href="<?= e($qs(['p' => $k])) ?>"<?= $col ? ' style="--pc:' . e($col) . ';"' : '' ?>>
        <?= hl_platform_icon($k) ?><?= e($lb) ?> <small><?= $platCount[$k] ?></small></a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <?php if ($list): ?>
  <div class="hl-grid mb-3" data-reveal-group<?= $focus ? ' data-hl-focus="' . $focus . '"' : '' ?>>
    <?php foreach ($list as $h): ?><?= hl_card($h, 'grid') ?><?php endforeach; ?>
  </div>
  <?= pagination_html($total, $per, $page, $qs([])) ?>
  <?php else: ?>
  <div class="card text-center text-muted reveal mb-4" style="padding:48px;">
    <span class="material-symbols-rounded icon-xl" style="color:var(--border);">campaign</span>
    <p><?= $q !== '' || $plat !== '' || $tab !== 'all' ? 'ไม่พบเรื่องตามที่เลือก' : 'ยังไม่มีเรื่องประชาสัมพันธ์' ?></p>
    <?php if ($q !== '' || $plat !== '' || $tab !== 'all'): ?><a class="btn small" href="<?= e(url('highlights.php')) ?>">ดูทั้งหมด</a><?php endif; ?>
  </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
