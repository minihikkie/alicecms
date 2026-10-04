<?php
/** admin/highlights.php — ข่าวด่วน · ประชาสัมพันธ์จากโซเชียล: เพิ่ม/แก้ไข/ลบ/ตั้งเวลา */
require dirname(__DIR__) . '/includes/init.php';
require __DIR__ . '/_auth.php';
require_once APP_ROOT . '/includes/highlights.php';

$errors = [];
$edit = null;

/** datetime-local (2026-10-04T09:30) → DATETIME ของ MySQL หรือ null ถ้าว่าง/ผิดรูป */
function hl_parse_dt(string $v): ?string {
    $v = trim($v);
    if ($v === '' || !preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}$/', $v)) return null;
    return str_replace('T', ' ', $v) . ':00';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['delete_id'])) {
        $id = (int)$_POST['delete_id'];
        if (trash_delete('highlights', $id, $ADMIN['username'] ?? '')) {
            log_action('ลบข่าวด่วน (ลงถังขยะ)', '#' . $id);
            flash_set('success', 'ย้ายลงถังขยะแล้ว — กู้คืนได้ที่เมนู "ถังขยะ"');
        }
        redirect('admin/highlights.php');
    }
    /* ปุ่มลัด "หยุดแสดงตอนนี้" — เรื่องด่วนที่จบแล้วไม่ต้องรอเวลาสิ้นสุด ย้ายเข้าคลังย้อนหลังทันที */
    if (isset($_POST['end_id'])) {
        db()->prepare('UPDATE highlights SET ends_at = ? WHERE id = ?')->execute([hl_now(), (int)$_POST['end_id']]);
        log_action('หยุดแสดงข่าวด่วน', '#' . (int)$_POST['end_id']);
        flash_set('success', 'หยุดแสดงบนหน้าแรกแล้ว — เรื่องนี้ยังดูได้ในหน้าคลังย้อนหลัง');
        redirect('admin/highlights.php');
    }

    $id      = (int)($_POST['id'] ?? 0);
    $title   = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 200);
    $summary = mb_substr(trim((string)($_POST['summary'] ?? '')), 0, 500);
    $link    = mb_substr(trim((string)($_POST['url'] ?? '')), 0, 600);
    $urgency = isset(hl_urgencies()[$_POST['urgency'] ?? '']) ? $_POST['urgency'] : 'normal';
    $pinned  = !empty($_POST['pinned']) ? 1 : 0;
    $status  = ($_POST['status'] ?? 'published') === 'draft' ? 'draft' : 'published';
    $starts  = hl_parse_dt((string)($_POST['starts_at'] ?? '')) ?? hl_now();
    $ends    = hl_parse_dt((string)($_POST['ends_at'] ?? ''));

    if ($title === '') $errors[] = 'กรุณากรอกหัวข้อ';
    if ($link !== '' && !preg_match('~^https?://[^\s]+$~i', $link)) $errors[] = 'ลิงก์ต้องขึ้นต้นด้วย https:// — คัดลอกจากช่องที่อยู่ของเบราว์เซอร์มาวางให้ครบ';
    if ($ends !== null && $ends <= $starts) $errors[] = 'เวลาสิ้นสุดต้องหลังเวลาเริ่ม';
    $platform = hl_detect($link)['platform'];

    $cover = null;
    if (!$errors) {
        try { $cover = handle_upload('cover', 'highlights', upload_image_exts(), 8); }
        catch (RuntimeException $ex) { $errors[] = $ex->getMessage(); }
    }

    if (!$errors) {
        if ($id > 0) {
            $st = db()->prepare('SELECT cover FROM highlights WHERE id = ?');
            $st->execute([$id]);
            $old = $st->fetch();
            if ($cover && $old) delete_upload($old['cover']);
            if (!empty($_POST['remove_cover']) && $old) delete_upload($old['cover']);
            $cov = $cover['path'] ?? (!empty($_POST['remove_cover']) ? null : ($old['cover'] ?? null));
            db()->prepare('UPDATE highlights SET title=?, summary=?, url=?, platform=?, cover=?, urgency=?, pinned=?, starts_at=?, ends_at=?, status=? WHERE id=?')
                ->execute([$title, $summary, $link, $platform, $cov, $urgency, $pinned, $starts, $ends, $status, $id]);
            log_action('แก้ไขข่าวด่วน', $title);
        } else {
            db()->prepare('INSERT INTO highlights (title, summary, url, platform, cover, urgency, pinned, starts_at, ends_at, status) VALUES (?,?,?,?,?,?,?,?,?,?)')
                ->execute([$title, $summary, $link, $platform, $cover['path'] ?? null, $urgency, $pinned, $starts, $ends, $status]);
            log_action('เพิ่มข่าวด่วน', $title);
        }
        flash_set('success', 'บันทึกเรียบร้อยแล้ว');
        redirect('admin/highlights.php');
    }
}

if (isset($_GET['edit'])) {
    $st = db()->prepare('SELECT * FROM highlights WHERE id = ?');
    $st->execute([(int)$_GET['edit']]);
    $edit = $st->fetch() ?: null;
}

$all = db()->query('SELECT * FROM highlights ORDER BY COALESCE(starts_at, created_at) DESC, id DESC')->fetchAll();
$byState = ['live' => 0, 'scheduled' => 0, 'ended' => 0, 'draft' => 0];
foreach ($all as $h) $byState[hl_state($h)]++;
$view = isset($byState[$_GET['v'] ?? '']) ? $_GET['v'] : '';
$rows = $view === '' ? $all : array_values(array_filter($all, fn($h) => hl_state($h) === $view));
$sec_on = section_on('highlight');

/* ค่าในฟอร์ม (ถ้าบันทึกไม่ผ่าน ใช้ค่าที่กรอกไว้ ไม่ให้ต้องพิมพ์ใหม่) */
$fmt = fn(?string $dt) => $dt ? substr(str_replace(' ', 'T', $dt), 0, 16) : '';
$f = [
    'title'     => old('title', $edit['title'] ?? ''),
    'summary'   => old('summary', $edit['summary'] ?? ''),
    'url'       => old('url', $edit['url'] ?? ''),
    'urgency'   => old('urgency', $edit['urgency'] ?? 'urgent'),
    'pinned'    => isset($_POST['title']) ? !empty($_POST['pinned']) : !empty($edit['pinned']),
    'status'    => old('status', $edit['status'] ?? 'published'),
    'starts_at' => old('starts_at', $fmt($edit['starts_at'] ?? null) ?: substr(str_replace(' ', 'T', hl_now()), 0, 16)),
    'ends_at'   => old('ends_at', $fmt($edit['ends_at'] ?? null)),
];
/* แถวจำลองสำหรับวาดตัวอย่างการ์ด — ใช้ hl_card() ตัวเดียวกับหน้าเว็บจริง */
$preview = ['id' => (int)($edit['id'] ?? 0), 'title' => $f['title'] !== '' ? $f['title'] : 'หัวข้อเรื่องด่วน',
            'summary' => $f['summary'], 'url' => $f['url'], 'cover' => $edit['cover'] ?? null,
            'urgency' => $f['urgency'], 'pinned' => $f['pinned'] ? 1 : 0, 'status' => 'published',
            'starts_at' => hl_now(), 'ends_at' => null, 'created_at' => hl_now()];

$stateLabel = ['live' => ['กำลังแสดง', 'success'], 'scheduled' => ['ตั้งเวลาไว้', 'warning'],
               'ended' => ['หมดเวลาแล้ว', ''], 'draft' => ['ซ่อนไว้', '']];

$admin_title = 'ข่าวด่วน · ประชาสัมพันธ์';
require __DIR__ . '/_top.php';
?>
<?php if ($errors): ?>
<div class="alert danger"><span class="material-symbols-rounded">error</span>
  <div><?php foreach ($errors as $er): ?><div><?= e($er) ?></div><?php endforeach; ?></div>
</div>
<?php endif; ?>

<?php if (!$sec_on): ?>
<div class="alert warning"><span class="material-symbols-rounded">visibility_off</span>
  <div>ตอนนี้กล่องข่าวด่วนถูกปิดอยู่ จึงยังไม่แสดงบนหน้าแรก — เปิดได้ที่ <a href="<?= e(url('admin/homepage.php')) ?>">การแสดงผลหน้าแรก</a></div>
</div>
<?php endif; ?>

<div class="card mb-2">
  <div class="section-head" style="margin-bottom:8px;">
    <div><span class="tag"><span class="material-symbols-rounded icon-sm">bolt</span> URGENT</span>
      <h3><?= $edit ? 'แก้ไขเรื่อง' : 'เพิ่มเรื่องด่วน / ประชาสัมพันธ์' ?></h3>
      <p>วางลิงก์จาก Facebook, YouTube, TikTok หรือเว็บอื่น — วิดีโอจะเล่นบนเว็บเราได้เลย ส่วนโพสต์จะเปิดต้นฉบับในแท็บใหม่</p></div>
    <?php if ($edit): ?><a class="btn small" href="<?= e(url('admin/highlights.php')) ?>">ยกเลิก</a><?php endif; ?>
  </div>

  <form method="post" action="" enctype="multipart/form-data" data-hl-form>
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
    <div class="hl-admin">
      <div>
        <label>ลิงก์ต้นฉบับ <span class="text-muted">(ไม่บังคับ — ถ้าไม่ใส่ กดที่การ์ดจะเปิดภาพปกแบบเต็ม)</span></label>
        <input type="text" name="url" value="<?= e($f['url']) ?>" data-hl-url autocomplete="off" inputmode="url"
               placeholder="https://www.facebook.com/... หรือ https://youtu.be/..." class="mb-1">
        <div class="hl-detect mb-2" data-hl-detect aria-live="polite"></div>

        <label>หัวข้อ <span style="color:var(--danger);">*</span></label>
        <input type="text" name="title" value="<?= e($f['title']) ?>" required maxlength="200" data-hl-title class="mb-2"
               placeholder="เช่น แจ้งเตือนมิจฉาชีพแอบอ้างเจ้าหน้าที่ตำรวจ">

        <label>คำอธิบายสั้น <span class="text-muted">(แสดงในใบใหญ่และหน้าคลัง)</span></label>
        <textarea name="summary" rows="2" maxlength="500" data-hl-summary class="mb-2" placeholder="สรุป 1-2 บรรทัดว่าเรื่องนี้คืออะไร ใครควรรู้"><?= e($f['summary']) ?></textarea>

        <label>ระดับความด่วน</label>
        <div class="hl-urg mb-2">
          <?php foreach (hl_urgencies() as $k => [$lb, $cls]): ?>
          <label class="hl-urg-opt <?= e($cls) ?>"><input type="radio" name="urgency" value="<?= e($k) ?>" data-hl-urg <?= $f['urgency'] === $k ? 'checked' : '' ?>>
            <span class="hl-badge <?= e($cls) ?>"><?= e($lb) ?></span></label>
          <?php endforeach; ?>
        </div>

        <label>ภาพปก <span class="text-muted">(แนะนำ — บันทึกภาพจากโพสต์ หรือแคปหน้าจอมาใส่ · ภาพแนวนอน 16:9 สวยที่สุด)</span></label>
        <?php if (!empty($edit['cover'])): ?>
        <div class="current-file"><img src="<?= e(url($edit['cover'])) ?>" alt="">ภาพปกปัจจุบัน
          <label class="inline-check" style="margin:0;"><input type="checkbox" name="remove_cover" value="1">ลบภาพปก</label></div>
        <?php endif; ?>
        <div class="dropzone mb-2" style="padding:16px;">
          <input type="file" name="cover" accept=".jpg,.jpeg,.png,.webp" data-hl-cover>
          <span class="material-symbols-rounded icon-lg" style="color:var(--blue)">add_photo_alternate</span>
          <p style="margin:4px 0 0;font-size:13px;">คลิกเลือกหรือลากภาพมาวาง</p>
          <div class="dz-filename"></div>
        </div>

        <div class="form-row">
          <div><label>เริ่มแสดง</label>
            <input type="datetime-local" name="starts_at" value="<?= e($f['starts_at']) ?>" data-hl-start class="mb-1"></div>
          <div><label>หยุดแสดงบนหน้าแรก <span class="text-muted">(เว้นว่าง = ไม่กำหนด)</span></label>
            <input type="datetime-local" name="ends_at" value="<?= e($f['ends_at']) ?>" data-hl-end class="mb-1"></div>
        </div>
        <div class="hl-quick mb-2">
          <span class="text-muted">แสดงนาน</span>
          <?php foreach ([1 => '1 วัน', 3 => '3 วัน', 7 => '7 วัน', 14 => '14 วัน', 30 => '30 วัน'] as $dd => $lb): ?>
          <button type="button" class="btn small" data-hl-days="<?= $dd ?>"><?= e($lb) ?></button>
          <?php endforeach; ?>
          <button type="button" class="btn small" data-hl-days="0">ไม่กำหนด</button>
        </div>
        <p class="text-muted" style="font-size:12px;margin:-4px 0 12px;">พอถึงเวลาหยุดแสดง เรื่องจะหายจากหน้าแรกเอง แต่ยังดูได้ในหน้าคลังย้อนหลัง</p>

        <div class="flex gap-2 items-center" style="flex-wrap:wrap;">
          <label class="inline-check" style="margin:0;"><input type="checkbox" name="pinned" value="1" data-hl-pin <?= $f['pinned'] ? 'checked' : '' ?>>ปักหมุดเป็นใบใหญ่</label>
          <div><select name="status" style="width:160px;">
            <option value="published" <?= $f['status'] === 'published' ? 'selected' : '' ?>>เผยแพร่</option>
            <option value="draft" <?= $f['status'] === 'draft' ? 'selected' : '' ?>>ซ่อนไว้ก่อน</option>
          </select></div>
          <button class="btn primary" type="submit"><span class="material-symbols-rounded icon-sm">save</span><?= $edit ? 'บันทึก' : 'เพิ่มเรื่อง' ?></button>
        </div>
      </div>

      <div class="hl-admin-preview">
        <div class="text-muted" style="font-size:12px;margin-bottom:6px;">ตัวอย่างบนหน้าแรก</div>
        <div data-hl-preview><?= hl_card($preview, 'feat') ?></div>
      </div>
    </div>
  </form>
</div>

<div class="card">
  <div class="section-head" style="margin-bottom:8px;">
    <div><span class="tag">ALL</span><h3>เรื่องทั้งหมด (<?= count($all) ?>)</h3>
      <p>ใบที่ปักหมุดขึ้นเป็นใบใหญ่ก่อน ถ้าไม่มี ใบใหญ่คือเรื่องที่ด่วนที่สุดและใหม่ที่สุด</p></div>
    <?php if ($all): ?><a class="btn small" href="<?= e(url('highlights.php')) ?>" target="_blank" rel="noopener"><span class="material-symbols-rounded icon-sm">open_in_new</span>หน้าคลังย้อนหลัง</a><?php endif; ?>
  </div>
  <?php if ($all): ?>
  <div class="cats mb-2">
    <a class="cat<?= $view === '' ? ' active' : '' ?>" href="<?= e(url('admin/highlights.php')) ?>">ทั้งหมด <small><?= count($all) ?></small></a>
    <?php foreach ($stateLabel as $k => [$lb]): if (!$byState[$k]) continue; ?>
    <a class="cat<?= $view === $k ? ' active' : '' ?>" href="<?= e(url('admin/highlights.php?v=' . $k)) ?>"><?= e($lb) ?> <small><?= $byState[$k] ?></small></a>
    <?php endforeach; ?>
  </div>
  <div class="hl-admin-list">
    <?php foreach ($rows as $h): $stt = hl_state($h); $d = hl_detect((string)$h['url']);
          [$plName] = hl_platforms()[$d['platform']]; $urg = hl_urgencies()[$h['urgency']] ?? hl_urgencies()['normal']; ?>
    <div class="hl-row">
      <span class="hl-row-thumb"><?php if ($h['cover']): ?><img src="<?= e(url($h['cover'])) ?>" alt="" loading="lazy"><?php else: ?><?= hl_platform_icon($d['platform']) ?><?php endif; ?></span>
      <span class="hl-row-main">
        <b><?= e(mb_strimwidth($h['title'], 0, 80, '…')) ?></b>
        <span class="hl-row-meta">
          <span class="hl-badge <?= e($urg[1]) ?>"><?= e($urg[0]) ?></span>
          <span class="badge <?= e($stateLabel[$stt][1]) ?>" style="font-size:11px;"><?= e($stateLabel[$stt][0]) ?></span>
          <?php if ($h['pinned']): ?><span class="badge" style="font-size:11px;">ปักหมุด</span><?php endif; ?>
          <span><?= e($plName) ?><?= $d['kind'] === 'video' ? ' · วิดีโอ' : '' ?></span>
          <span><?= e(thai_date(hl_date($h))) ?><?= $h['ends_at'] ? ' → ' . e(thai_date($h['ends_at'])) : '' ?></span>
        </span>
      </span>
      <span class="hl-row-actions">
        <?php if ($h['url']): ?><a class="btn small" href="<?= e($h['url']) ?>" target="_blank" rel="noopener noreferrer" title="เปิดต้นฉบับ"><span class="material-symbols-rounded icon-sm">open_in_new</span></a><?php endif; ?>
        <a class="btn small" href="<?= e(url('admin/highlights.php?edit=' . $h['id'])) ?>">แก้ไข</a>
        <?php if ($stt === 'live'): ?>
        <form method="post" action="" style="display:inline;"><?= csrf_field() ?><input type="hidden" name="end_id" value="<?= (int)$h['id'] ?>">
          <button class="btn small" type="submit" title="ย้ายเข้าคลังย้อนหลังทันที" data-confirm="หยุดแสดงเรื่องนี้บนหน้าแรกตอนนี้เลย? (ยังดูได้ในหน้าคลังย้อนหลัง)">หยุดแสดง</button></form>
        <?php endif; ?>
        <form method="post" action="" style="display:inline;"><?= csrf_field() ?><input type="hidden" name="delete_id" value="<?= (int)$h['id'] ?>">
          <button class="btn small danger" type="submit" data-confirm="ลบเรื่องนี้? (กู้คืนได้จากถังขยะ)">ลบ</button></form>
      </span>
    </div>
    <?php endforeach; ?>
  </div>
  <?php else: ?>
  <div class="text-center text-muted" style="padding:28px;">
    <span class="material-symbols-rounded icon-lg">bolt</span>
    <p style="margin:8px 0 0;">ยังไม่มีเรื่อง — เพิ่มเรื่องแรกได้จากฟอร์มด้านบน</p>
  </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/_bottom.php'; ?>
