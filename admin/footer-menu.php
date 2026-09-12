<?php
/** admin/footer-menu.php — จัดการลิงก์ท้ายเว็บ (footer) 3 คอลัมน์ + โหมดคุมเอง (ค่าเริ่มต้น = อัตโนมัติเหมือนเดิมทุกประการ) */
require dirname(__DIR__) . '/includes/init.php';
require __DIR__ . '/_auth.php';
require_admin();

/* หน้าระบบที่มักใส่ใน footer */
$sys_targets = [
    'news.php?type=activity' => 'กิจกรรม', 'news.php?type=pr' => 'ประชาสัมพันธ์', 'news.php?type=announce' => 'ประกาศ',
    'documents.php' => 'เอกสารเผยแพร่', 'procurement.php' => 'จัดซื้อจัดจ้าง', 'ita.php' => 'ITA / OIT',
    'faq.php' => 'คำถามที่พบบ่อย', 'services.php' => 'บริการออนไลน์', 'complaint.php' => 'ร้องเรียน-ร้องทุกข์',
    'about.php' => 'เกี่ยวกับหน่วยงาน', 'contact.php' => 'ติดต่อ', 'sitemap-page.php' => 'แผนผังเว็บไซต์',
];

$errors = [];
$edit = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    /* ── ลากเรียง (ต่อคอลัมน์) — fetch JSON ── */
    if (($_POST['action'] ?? '') === 'reorder') {
        header('Content-Type: application/json');
        $col = max(1, min(3, (int)($_POST['cat'] ?? 1)));
        $ids = json_decode((string)($_POST['order'] ?? '[]'), true) ?: [];
        $upd = db()->prepare('UPDATE footer_links SET sort_order = ? WHERE id = ? AND col = ?');
        $i = 0;
        foreach ($ids as $id) { $upd->execute([$i++, (int)$id, $col]); }
        echo json_encode(['ok' => true]);
        exit;
    }

    /* ── โหมด + ชื่อคอลัมน์ ── */
    if (isset($_POST['save_mode'])) {
        setting_set('footer_custom', !empty($_POST['footer_custom']) ? '1' : '0');
        setting_set('footer_col1_title', mb_substr(trim((string)($_POST['col1_title'] ?? '')), 0, 60));
        setting_set('footer_col2_title', mb_substr(trim((string)($_POST['col2_title'] ?? '')), 0, 60));
        setting_set('footer_col3_title', mb_substr(trim((string)($_POST['col3_title'] ?? '')), 0, 60));
        flash_set('success', 'บันทึกการตั้งค่าแล้ว');
        redirect('admin/footer-menu.php');
    }

    /* ── นำเข้ารายการปัจจุบันจากระบบ ── */
    if (isset($_POST['seed_menu'])) {
        $n = (int)db()->query('SELECT COUNT(*) FROM footer_links')->fetchColumn();
        if ($n === 0) {
            $seed = [];
            if (section_live('activity'))    $seed[] = [1, 'กิจกรรม', 'news.php?type=activity'];
            if (section_live('pr'))          $seed[] = [1, 'ประชาสัมพันธ์', 'news.php?type=pr'];
            if (section_live('announce'))    $seed[] = [1, 'ประกาศ', 'news.php?type=announce'];
            if (section_live('procurement')) $seed[] = [1, 'จัดซื้อจัดจ้าง', 'procurement.php'];
            if (section_live('ita'))         $seed[] = [2, 'ITA', 'ita.php'];
            if (section_live('documents'))   $seed[] = [2, 'เอกสารเผยแพร่', 'documents.php'];
            $seed[] = [2, 'บริการออนไลน์', 'services.php'];
            if (section_live('faq'))         $seed[] = [2, 'คำถามที่พบบ่อย', 'faq.php'];
            $seed[] = [2, 'เกี่ยวกับหน่วยงาน', 'about.php'];
            $seed[] = [2, 'ติดต่อ', 'contact.php'];
            $seed[] = [2, 'แผนผังเว็บไซต์', 'sitemap-page.php'];
            $seed[] = [3, 'ร้องเรียน-ร้องทุกข์', 'complaint.php'];
            $seed[] = [3, 'ช่องทางติดต่อ', 'contact.php'];
            if (page_by_slug('privacy')) $seed[] = [3, 'นโยบายความเป็นส่วนตัว', 'page.php?slug=privacy'];

            $counts = [1 => 0, 2 => 0, 3 => 0];
            $st = db()->prepare('INSERT INTO footer_links (col, label, url, sort_order, enabled) VALUES (?,?,?,?,1)');
            foreach ($seed as [$col, $label, $u]) { $st->execute([$col, $label, $u, $counts[$col]++]); }
            flash_set('success', 'นำเข้ารายการจาก footer ปัจจุบันแล้ว ' . count($seed) . ' รายการ — แก้ไข/ลากจัดเรียงได้เลย');
        } else {
            flash_set('danger', 'มีรายการอยู่แล้ว ไม่นำเข้าซ้ำ');
        }
        redirect('admin/footer-menu.php');
    }

    if (isset($_POST['delete_id'])) {
        $id = (int)$_POST['delete_id'];
        if (trash_delete('footer_links', $id, $ADMIN['username'] ?? '')) {
            log_action('ลบลิงก์ท้ายเว็บ (ลงถังขยะ)', '#' . $id);
            flash_set('success', 'ย้ายลิงก์ลงถังขยะแล้ว — กู้คืนได้ที่เมนู "ถังขยะ"');
        }
        redirect('admin/footer-menu.php');
    }
    if (isset($_POST['toggle_id'])) {
        db()->prepare('UPDATE footer_links SET enabled = 1 - enabled WHERE id = ?')->execute([(int)$_POST['toggle_id']]);
        redirect('admin/footer-menu.php');
    }

    /* ── เพิ่ม/แก้ไขลิงก์ ── */
    if (isset($_POST['label'])) {
        $id      = (int)($_POST['id'] ?? 0);
        $label   = mb_substr(trim((string)($_POST['label'] ?? '')), 0, 120);
        $u       = safe_link_url((string)($_POST['url'] ?? ''));
        $col     = max(1, min(3, (int)($_POST['col'] ?? 1)));
        $new_tab = !empty($_POST['new_tab']) ? 1 : 0;
        $enabled = !empty($_POST['enabled']) ? 1 : 0;

        if ($label === '') $errors[] = 'กรุณากรอกชื่อลิงก์';
        if ($u === '')     $errors[] = 'กรุณาเลือกหรือกรอกปลายทาง';
        if ($u !== '' && !preg_match('#^(https?://|/|[a-z0-9_\-]+\.php)#i', $u)) $errors[] = 'ปลายทางไม่ถูกต้อง';

        if (!$errors) {
            if ($id > 0) {
                db()->prepare('UPDATE footer_links SET col=?, label=?, url=?, new_tab=?, enabled=? WHERE id=?')
                    ->execute([$col, $label, $u, $new_tab, $enabled, $id]);
            } else {
                $mxSt = db()->prepare('SELECT COALESCE(MAX(sort_order),0) FROM footer_links WHERE col = ?');
                $mxSt->execute([$col]);
                $mx = (int)$mxSt->fetchColumn();
                db()->prepare('INSERT INTO footer_links (col, label, url, sort_order, new_tab, enabled) VALUES (?,?,?,?,?,?)')
                    ->execute([$col, $label, $u, $mx + 1, $new_tab, $enabled]);
            }
            log_action('จัดการลิงก์ท้ายเว็บ', $label);
            flash_set('success', 'บันทึกลิงก์แล้ว');
            redirect('admin/footer-menu.php');
        }
    }
}

if (isset($_GET['edit'])) {
    $st = db()->prepare('SELECT * FROM footer_links WHERE id = ?');
    $st->execute([(int)$_GET['edit']]);
    $edit = $st->fetch();
}

$all = db()->query('SELECT * FROM footer_links ORDER BY col ASC, sort_order ASC, id ASC')->fetchAll();
$byCol = [1 => [], 2 => [], 3 => []];
foreach ($all as $r) $byCol[(int)$r['col']][] = $r;
$pages = db()->query("SELECT slug, title FROM pages WHERE status='published' ORDER BY sort_order ASC")->fetchAll();
$footer_custom = footer_custom_on();

$label_target = function ($u) use ($sys_targets) {
    if (preg_match('#^https?://#', $u)) return 'ลิงก์ภายนอก';
    if (preg_match('#page\.php\?slug=#', $u)) return 'หน้าเพจ';
    return $sys_targets[$u] ?? $u;
};
$col_names = ['ตำแหน่งที่ 1 (ซ้าย)', 'ตำแหน่งที่ 2 (กลาง)', 'ตำแหน่งที่ 3 (ขวา)'];

$admin_title = 'เมนูท้ายเว็บ';
require __DIR__ . '/_top.php';
?>
<!-- โหมด + ชื่อคอลัมน์ -->
<div class="card mb-2">
  <div class="section-head" style="margin-bottom:8px;">
    <div><span class="tag"><span class="material-symbols-rounded icon-sm">tune</span> MODE</span><h3>โหมดเมนูท้ายเว็บ</h3>
    <p>ปิดอยู่ = ใช้รายการอัตโนมัติเดิม (ลิงก์จะโผล่/หายเองตามการเปิด-ปิดแต่ละหมวดในระบบ) — เปิดโหมดคุมเองเมื่อต้องการเพิ่ม/แก้ไข/จัดเรียงลิงก์ท้ายเว็บเอง</p></div>
  </div>
  <form method="post" action="">
    <?= csrf_field() ?>
    <input type="hidden" name="save_mode" value="1">
    <label class="inline-check mb-2"><input type="checkbox" name="footer_custom" value="1" <?= $footer_custom ? 'checked' : '' ?>><b>คุมเมนูท้ายเว็บเองทั้งหมด</b></label>
    <div class="form-row">
      <div><label>หัวข้อคอลัมน์ 1</label><input type="text" name="col1_title" value="<?= e(setting('footer_col1_title') ?: footer_col_title(1)) ?>" placeholder="ข่าวสาร" class="mb-2"></div>
      <div><label>หัวข้อคอลัมน์ 2</label><input type="text" name="col2_title" value="<?= e(setting('footer_col2_title') ?: footer_col_title(2)) ?>" placeholder="หน่วยงาน" class="mb-2"></div>
      <div><label>หัวข้อคอลัมน์ 3</label><input type="text" name="col3_title" value="<?= e(setting('footer_col3_title') ?: footer_col_title(3)) ?>" placeholder="นโยบาย" class="mb-2"></div>
    </div>
    <div class="flex gap-2 items-center" style="flex-wrap:wrap;">
      <button class="btn small primary" type="submit"><span class="material-symbols-rounded icon-sm">save</span>บันทึก</button>
      <?php if (!$all): ?>
      <button class="btn small" type="submit" name="seed_menu" value="1"><span class="material-symbols-rounded icon-sm">download</span>นำเข้ารายการปัจจุบันจากระบบ</button>
      <?php endif; ?>
    </div>
  </form>
  <?php if ($footer_custom && !$all): ?>
  <div class="alert info mt-2" style="font-size:13px;"><span class="material-symbols-rounded">info</span><div>เปิดโหมดคุมเองแล้วแต่ยังไม่มีลิงก์ — กด "นำเข้ารายการปัจจุบัน" เพื่อเริ่มต้น ไม่งั้นเว็บจะกลับไปใช้ footer อัตโนมัติชั่วคราว</div></div>
  <?php endif; ?>
</div>

<!-- ฟอร์มเพิ่ม/แก้ไขลิงก์ -->
<div class="card mb-2">
  <div class="section-head" style="margin-bottom:8px;">
    <div><span class="tag">LINK</span><h3><?= $edit ? 'แก้ไขลิงก์' : 'เพิ่มลิงก์' ?></h3></div>
    <?php if ($edit): ?><a class="btn small" href="<?= e(url('admin/footer-menu.php')) ?>">ยกเลิก</a><?php endif; ?>
  </div>
  <?php if ($errors): ?>
  <div class="alert danger"><span class="material-symbols-rounded">error</span><div><?php foreach ($errors as $er): ?><div><?= e($er) ?></div><?php endforeach; ?></div></div>
  <?php endif; ?>
  <form method="post" action="">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
    <div class="form-row">
      <div><label>ชื่อลิงก์ <span style="color:var(--danger);">*</span></label>
        <input type="text" name="label" value="<?= e(old('label', $edit['label'] ?? '')) ?>" required class="mb-2" placeholder="เช่น เอกสารเผยแพร่"></div>
      <div><label>คอลัมน์</label>
        <select name="col" class="mb-2">
          <?php for ($c = 1; $c <= 3; $c++): ?>
          <option value="<?= $c ?>" <?= (int)($edit['col'] ?? 1) === $c ? 'selected' : '' ?>><?= e($col_names[$c - 1]) ?></option>
          <?php endfor; ?>
        </select></div>
    </div>
    <?php dest_picker_field(old('url', $edit['url'] ?? ''), $sys_targets, $pages); ?>
    <div class="flex gap-2 items-center" style="flex-wrap:wrap;">
      <label class="inline-check" style="margin:0;"><input type="checkbox" name="new_tab" value="1" <?= !empty($edit['new_tab']) ? 'checked' : '' ?>>เปิดแท็บใหม่</label>
      <label class="inline-check" style="margin:0;"><input type="checkbox" name="enabled" value="1" <?= !isset($edit) || !empty($edit['enabled']) ? 'checked' : '' ?>>แสดงผล</label>
      <button class="btn primary" type="submit" name="label_submit" value="1"><span class="material-symbols-rounded icon-sm">save</span><?= $edit ? 'บันทึก' : 'เพิ่มลิงก์' ?></button>
    </div>
  </form>
</div>

<!-- รายการ 3 คอลัมน์ + ลากเรียง -->
<div class="card">
  <div class="section-head" style="margin-bottom:8px;">
    <div><span class="tag">ALL LINKS</span><h3>ลิงก์ทั้งหมด (<?= count($all) ?>)</h3>
    <p>ลากเพื่อจัดลำดับภายในคอลัมน์เดียวกัน — ย้ายข้ามคอลัมน์ทำได้ที่ปุ่ม "แก้ไข"</p></div>
  </div>
  <?php if ($all): ?>
  <div class="grid grid-3" style="align-items:start;">
    <?php for ($c = 1; $c <= 3; $c++): ?>
    <div>
      <p class="text-muted mb-1" style="font-size:13px;font-weight:600;"><?= e($col_names[$c - 1]) ?> (<?= count($byCol[$c]) ?>)</p>
      <div data-sortlist data-endpoint="footer-menu.php" data-cat="<?= $c ?>" data-csrf="<?= e(csrf_token()) ?>">
        <?php if ($byCol[$c]): ?>
        <ul class="doc-sort">
          <?php foreach ($byCol[$c] as $l): ?>
          <li class="doc-li" draggable="true" data-id="<?= (int)$l['id'] ?>">
            <div class="doc-row<?= $l['enabled'] ? '' : ' is-off' ?>">
              <span class="doc-grip material-symbols-rounded">drag_indicator</span>
              <span class="doc-name"><?= e($l['label']) ?></span>
              <span class="doc-actions">
                <a class="btn small" href="<?= e(url('admin/footer-menu.php?edit=' . $l['id'])) ?>">แก้ไข</a>
                <form method="post" action="" style="display:inline;"><?= csrf_field() ?><input type="hidden" name="toggle_id" value="<?= (int)$l['id'] ?>"><button class="btn small" type="submit"><?= $l['enabled'] ? 'ซ่อน' : 'แสดง' ?></button></form>
                <form method="post" action="" style="display:inline;"><?= csrf_field() ?><input type="hidden" name="delete_id" value="<?= (int)$l['id'] ?>"><button class="btn small danger" type="submit" data-confirm="ลบลิงก์ «<?= e($l['label']) ?>»?">ลบ</button></form>
              </span>
            </div>
          </li>
          <?php endforeach; ?>
        </ul>
        <?php else: ?>
        <p class="text-muted text-center" style="padding:16px;font-size:13px;">ยังไม่มีลิงก์ในคอลัมน์นี้</p>
        <?php endif; ?>
      </div>
    </div>
    <?php endfor; ?>
  </div>
  <?php else: ?>
  <p class="text-muted text-center" style="padding:24px;">ยังไม่มีลิงก์ — เพิ่มด้านบน หรือกด "นำเข้ารายการปัจจุบัน"</p>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/_bottom.php'; ?>
