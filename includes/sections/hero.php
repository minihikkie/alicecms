<?php
/** section: ค้นหารวมทั้งเว็บ + สถิติ (นับจริงจากฐานข้อมูล) */
if (!defined('APP_ROOT')) exit('Forbidden');

$n_posts = (int)db()->query("SELECT COUNT(*) FROM posts WHERE status = 'published'")->fetchColumn();
$n_docs  = (int)db()->query('SELECT COUNT(*) FROM documents')->fetchColumn();
$n_views = views_total();
?>
<section class="hero reveal" style="padding:40px 0 28px;">
  <h1 style="font-size:clamp(24px,3vw,36px);">ศูนย์ข้อมูลข่าวสาร<br><span class="grad"><?= e(setting('site_name', 'หน่วยงาน')) ?></span></h1>
  <form class="searchbar mt-2" action="<?= e(url('search.php')) ?>" method="get" role="search">
    <span class="material-symbols-rounded" style="color:var(--muted)">search</span>
    <input type="search" name="q" placeholder="ค้นหาข่าว ประกาศ เอกสาร ทั้งเว็บ..." aria-label="ค้นหาทั้งเว็บ">
    <button class="btn primary" type="submit">ค้นหา</button>
  </form>
  <div class="stats" style="margin-top:24px;">
    <div class="stat"><b data-count="<?= $n_posts ?>">0</b><span>ข่าวสาร</span></div>
    <div class="stat"><b data-count="<?= $n_docs ?>">0</b><span>เอกสาร</span></div>
    <div class="stat"><b data-count="<?= $n_views ?>">0</b><span>ผู้เข้าชมสะสม</span></div>
  </div>
</section>
