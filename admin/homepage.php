<?php
/** admin/homepage.php — การแสดงผลหน้าแรก + เมนู: แยกสวิตช์ "แสดงบนหน้าแรก" กับ "แสดงในเมนู" ออกจากกัน
 *  เปิด/ปิด เรียงลำดับ และตั้งชื่อหัวข้อ section ทั้งหมดจากที่เดียว */
require dirname(__DIR__) . '/includes/init.php';
require __DIR__ . '/_auth.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_sections'])) {
    $defaults = section_defaults();
    foreach (array_keys($defaults) as $key) {
        $nav = section_nav_target($key);   // มีลิงก์ในเมนูให้ตั้งค่าได้ไหม (null = ไม่มี)

        if ($key === 'personnel') {
            /* ผู้บริหาร: ไม่อยู่บนหน้าแรกเสมอ (enabled=0) — การแสดงผลคุมด้วย "แสดงในเมนู" */
            $inmenu = !empty($_POST['sec_in_menu'][$key]) ? 1 : 0;
            db()->prepare('UPDATE sections SET enabled=0, in_menu=? WHERE skey=?')->execute([$inmenu, $key]);
            continue;
        }

        $enabled = !empty($_POST['sec_enabled'][$key]) ? 1 : 0;
        /* section ที่มีลิงก์เมนู → ใช้สวิตช์เมนูของตัวเอง; section อื่น (ไม่มีลิงก์เมนู) → ผูกตามหน้าแรก */
        $inmenu  = $nav ? (!empty($_POST['sec_in_menu'][$key]) ? 1 : 0) : $enabled;
        $sort    = (int)($_POST['sec_sort'][$key] ?? 0);
        $title   = mb_substr(trim((string)($_POST['sec_title'][$key] ?? '')), 0, 150);
        db()->prepare('UPDATE sections SET enabled=?, in_menu=?, sort_order=?, custom_title=? WHERE skey=?')
            ->execute([$enabled, $inmenu, $sort, $title, $key]);
    }
    log_action('แก้ไขการแสดงผลหน้าแรก/เมนู', '');
    flash_set('success', 'บันทึกการแสดงผลเรียบร้อยแล้ว');
    redirect('admin/homepage.php');
}

/* กล่องอิสระที่กู้คืนจากถังขยะจะไม่มีแถวคู่ใน sections — สร้างให้ก่อนแสดงรายการ */
foreach (custom_sections_all() as $ck => $cv) {
    $mx = (int)db()->query('SELECT COALESCE(MAX(sort_order),0) FROM sections')->fetchColumn();
    db()->prepare("INSERT IGNORE INTO sections (skey, enabled, in_menu, sort_order, custom_title) VALUES (?, 1, 0, ?, '')")
       ->execute([$ck, $mx + 1]);
}
unset($GLOBALS['_sections_cache']);
$sections = sections_all();
$defaults = section_defaults();
$admin_title = 'การแสดงผลหน้าแรก & เมนู';
require __DIR__ . '/_top.php';
?>
<form method="post" action="">
  <?= csrf_field() ?>
  <input type="hidden" name="save_sections" value="1">
  <div class="card">
    <div class="section-head" style="margin-bottom:8px;">
      <div><span class="tag">HOMEPAGE</span><h3>การแสดงผลหน้าแรก &amp; เมนู</h3>
      <p>คุมแต่ละส่วนของเว็บไซต์จากที่เดียว — <b>แยกอิสระ</b>ระหว่าง "แสดงบนหน้าแรก" กับ "แสดงในเมนูด้านบน"</p></div>
      <a class="btn small" href="<?= e(url('index.php')) ?>" target="_blank" rel="noopener"><span class="material-symbols-rounded icon-sm">open_in_new</span>ดูหน้าจริง</a>
    </div>

    <div class="alert" style="background:color-mix(in srgb, var(--blue) 6%, transparent);border:1px solid color-mix(in srgb, var(--blue) 22%, transparent);margin-bottom:14px;">
      <span class="material-symbols-rounded" style="color:var(--blue);">info</span>
      <div style="font-size:13px;line-height:1.7;">
        <b>หน้าแรก</b> = แสดงบล็อกนั้นในหน้าแรกของเว็บ &nbsp;·&nbsp; <b>เมนู</b> = แสดงลิงก์เข้าหน้านั้นในแถบเมนูด้านบน<br>
        ตั้งแยกกันได้ เช่น เอา "เอกสาร" ออกจากหน้าแรกแต่ยังคงลิงก์ไว้ในเมนู — เครื่องหมาย “—” คือส่วนที่ไม่มีตัวเลือกนั้น
      </div>
    </div>

    <table class="admin-table">
      <tr>
        <th style="width:64px;" class="text-center">หน้าแรก</th>
        <th style="width:64px;" class="text-center">เมนู</th>
        <th style="width:80px;">ลำดับ</th>
        <th>ส่วนของเว็บไซต์</th>
        <th>ชื่อหัวข้อ (เว้นว่าง = ใช้ชื่อมาตรฐาน)</th>
      </tr>
      <?php foreach ($sections as $key => $row):
        $nav     = section_nav_target($key);
        $on_home = (int)$row['enabled'] === 1;
        $on_menu = (int)($row['in_menu'] ?? 1) === 1;
      ?>
      <?php if ($key === 'personnel'): ?>
      <tr style="background:color-mix(in srgb, var(--blue) 4%, transparent);">
        <td class="text-center lr-date" title="ผู้บริหารไม่แสดงบนหน้าแรก">—</td>
        <td class="text-center"><input type="checkbox" name="sec_in_menu[<?= e($key) ?>]" value="1" <?= $on_menu ? 'checked' : '' ?>></td>
        <td class="text-center lr-date">—</td>
        <td><b style="color:var(--ink);"><?= e($defaults[$key] ?? $key) ?></b>
          <span class="badge" style="font-size:10px;padding:1px 7px;margin-left:4px;">เมนูเท่านั้น</span><br>
          <span class="lr-date"><?= e($key) ?></span></td>
        <td class="text-muted" style="font-size:12.5px;">เข้าถึงผ่านเมนูด้านบน ไม่อยู่บนหน้าแรก — จัดการรายชื่อที่ <a href="<?= e(url('admin/personnel.php')) ?>">ผู้บริหาร</a></td>
      </tr>
      <?php else: ?>
      <tr>
        <td class="text-center"><input type="checkbox" name="sec_enabled[<?= e($key) ?>]" value="1" <?= $on_home ? 'checked' : '' ?>></td>
        <td class="text-center">
          <?php if ($nav): ?>
            <input type="checkbox" name="sec_in_menu[<?= e($key) ?>]" value="1" <?= $on_menu ? 'checked' : '' ?>>
          <?php else: ?>
            <span class="lr-date" title="ส่วนนี้ไม่มีลิงก์แยกในเมนู">—</span>
          <?php endif; ?>
        </td>
        <td><input type="number" name="sec_sort[<?= e($key) ?>]" value="<?= (int)$row['sort_order'] ?>" class="sort-input"></td>
        <td><b style="color:var(--ink);"><?= e($defaults[$key] ?? $key) ?></b>
          <?php if ($nav): ?><span class="badge" style="font-size:10px;padding:1px 7px;margin-left:4px;">เมนู: <?= e($nav['label']) ?></span><?php endif; ?>
          <br><span class="lr-date"><?= e($key) ?></span></td>
        <td><input type="text" name="sec_title[<?= e($key) ?>]" value="<?= e($row['custom_title']) ?>" placeholder="<?= e($defaults[$key] ?? '') ?>" style="padding:9px 14px;"></td>
      </tr>
      <?php endif; ?>
      <?php endforeach; ?>
    </table>
    <button class="btn primary mt-2" type="submit"><span class="material-symbols-rounded icon-sm">save</span>บันทึกการแสดงผล</button>
  </div>
</form>
<?php require __DIR__ . '/_bottom.php'; ?>
