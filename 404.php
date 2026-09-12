<?php
/** 404.php — หน้าไม่พบข้อมูล (เรียกตรงหรือ include จากหน้าอื่นก็ได้) */
if (!defined('APP_ROOT')) {
    require __DIR__ . '/includes/init.php';
}
http_response_code(404);
$page_title = 'ไม่พบหน้าที่ต้องการ';
require APP_ROOT . '/includes/header.php';
?>
<div class="container">
  <div class="err-wrap">
    <div class="err-code">404</div>
    <h2>ไม่พบหน้าที่คุณต้องการ</h2>
    <p class="lead" style="margin:0 auto 24px;">หน้านี้อาจถูกย้าย ลบ หรือพิมพ์ที่อยู่ไม่ถูกต้อง<br>ลองค้นหาข้อมูลที่ต้องการ หรือกลับไปหน้าแรก</p>
    <form class="searchbar mb-3" style="max-width:480px;" action="<?= e(url('search.php')) ?>" method="get" role="search">
      <span class="material-symbols-rounded" style="color:var(--muted)">search</span>
      <input type="search" name="q" placeholder="ค้นหาข่าว ประกาศ เอกสาร..." aria-label="ค้นหา">
      <button class="btn primary" type="submit">ค้นหา</button>
    </form>
    <a class="btn primary large" href="<?= e(url('index.php')) ?>"><span class="material-symbols-rounded">home</span>กลับหน้าแรก</a>
  </div>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
