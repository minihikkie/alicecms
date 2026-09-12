<?php
/**
 * index.php — หน้าแรก
 * เรนเดอร์ section ตามที่ admin เปิดใช้ + เรียงตาม sort_order
 * ทุก section กว้างเต็มกล่อง เรียงลงทีละอัน (ไม่จับคู่ 2 คอลัมน์)
 */
define('PUBLIC_PAGE', 'home');
require __DIR__ . '/includes/init.php';
require __DIR__ . '/includes/header.php';

/* รายชื่อ section ที่เปิดใช้ เรียงตามลำดับที่ตั้งใน admin */
$enabled = [];
foreach (sections_all() as $key => $row) {
    if ($key === 'personnel') continue;            // ผู้บริหารเข้าผ่านเมนูเท่านั้น ไม่แสดงบนหน้าแรก
    if ((int)$row['enabled'] === 1) $enabled[] = $key;
}

$SEC_DIR = APP_ROOT . '/includes/sections/';

/* การ์ดที่เรนเดอร์เป็น "ชิ้นส่วน" (ไม่ครอบ section เอง) ต้องมีตัวครอบจาก index */
$render_section = function (string $key) use ($SEC_DIR) {
    $file = $SEC_DIR . $key . '.php';
    if (is_file($file)) include $file;
};
?>
<div class="container">
<?php
/* section ที่เรนเดอร์เป็น "ชิ้นส่วน" (ยังไม่ครอบ <section> เอง) — index ครอบ full-width ให้ */
$fragments = ['ita', 'procurement', 'faq', 'complaint', 'contact'];
$n = count($enabled);
foreach ($enabled as $key) {
    if (in_array($key, $fragments, true)) {
        echo '<section class="block mb-4">';
        $render_section($key);
        echo '</section>';
    } else {
        $render_section($key); // slider, chief, hero, activity, pr, announce, documents, links ครอบ section เอง
    }
}

if ($n === 0): ?>
  <section class="hero">
    <h1>ยินดีต้อนรับสู่ <span class="grad"><?= e(setting('site_name', 'เว็บไซต์หน่วยงาน')) ?></span></h1>
    <p class="lead">ยังไม่ได้เปิดใช้ section ใดบนหน้าแรก — เข้าระบบจัดการเพื่อตั้งค่า</p>
  </section>
<?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
