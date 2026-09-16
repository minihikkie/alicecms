<?php
/**
 * trash.php — ถังขยะ: ลบแล้วกู้คืนได้
 *
 * แนวคิด: ไม่ใส่ธง "ถูกลบ" ในตารางเนื้อหา (ซึ่งจะต้องไล่แก้ query ทุกหน้าและเสี่ยงหลุด)
 * แต่ใช้วิธี "ถ่ายสำเนา" — ตอนลบจะเก็บข้อมูลทั้งแถว (+ แถวลูก + ไฟล์แนบ) ไว้ในตาราง trash
 * แล้วค่อยลบของจริง ดังนั้นหน้าเว็บและหน้าแอดมินทำงานเหมือนเดิมทุกประการ
 * เวลากู้คืนก็นำข้อมูลกลับเข้าตารางเดิมพร้อมไฟล์
 *
 * ไฟล์แนบจะถูก "ย้าย" ไปเก็บที่ storage/trash/<uid>/ ไม่ได้ลบทิ้ง จึงกู้กลับได้
 * (storage/ ถูกปิดไม่ให้เข้าถึงจากเว็บด้วย .htaccess — ดู includes/hardening.php)
 */
if (!defined('APP_ROOT')) exit('Forbidden');

/** เก็บของในถังขยะกี่วันก่อนลบถาวรอัตโนมัติ */
const TRASH_KEEP_DAYS = 30;

/**
 * นิยามของที่กู้คืนได้ — key = ชื่อตาราง
 *   kind      = ชื่อที่คนอ่านเข้าใจ
 *   label     = คอลัมน์ที่ใช้เป็นชื่อรายการในถังขยะ
 *   files     = คอลัมน์ที่เก็บ path ไฟล์ (จะถูกย้ายเข้าถังขยะ ไม่ถูกลบ)
 *   children  = ตารางลูกที่ต้องเก็บ/กู้คืนไปด้วย [table, fk, files]
 */
function trash_types(): array {
    return [
        'posts'         => ['kind' => 'ข่าว/ประกาศ',    'label' => 'title', 'files' => ['image', 'attachment'],
                            'children' => [['table' => 'post_attachments', 'fk' => 'post_id', 'files' => ['file']]]],
        'documents'     => ['kind' => 'เอกสารเผยแพร่',   'label' => 'title', 'files' => ['file']],
        'doc_categories'=> ['kind' => 'หมวดเอกสาร',      'label' => 'name',  'files' => []],
        'procurements'  => ['kind' => 'จัดซื้อจัดจ้าง',  'label' => 'title', 'files' => ['file']],
        'ita_items'     => ['kind' => 'รายการ ITA',      'label' => 'title', 'files' => ['file']],
        'personnel'     => ['kind' => 'บุคลากร',         'label' => 'name',  'files' => ['photo']],
        'links'         => ['kind' => 'ลิงก์หน่วยงาน',   'label' => 'title', 'files' => ['image']],
        'videos'        => ['kind' => 'วิดีโอ',          'label' => 'title', 'files' => ['cover']],
        'posters'       => ['kind' => 'โปสเตอร์',        'label' => 'title', 'files' => ['image']],
        'custom_sections' => ['kind' => 'กล่องอิสระหน้าแรก', 'label' => 'title', 'files' => []],
        'footer_links'  => ['kind' => 'ลิงก์ท้ายเว็บ',    'label' => 'label', 'files' => []],
        'slides'        => ['kind' => 'สไลด์แบนเนอร์',   'label' => 'title', 'files' => ['image']],
        'faqs'          => ['kind' => 'คำถามที่พบบ่อย',  'label' => 'question', 'files' => []],
        'pages'         => ['kind' => 'หน้าเพจ',         'label' => 'title', 'files' => []],
        'content_items' => ['kind' => 'รายการเนื้อหา',   'label' => 'title', 'files' => ['image']],
        'content_types' => ['kind' => 'ประเภทเนื้อหา',   'label' => 'name',  'files' => [],
                            'children' => [['table' => 'content_items', 'fk' => 'type_id', 'files' => ['image']]]],
        'forms'         => ['kind' => 'แบบฟอร์ม/บริการ', 'label' => 'title', 'files' => [],
                            'children' => [['table' => 'form_responses', 'fk' => 'form_id', 'files' => []]]],
        'complaints'    => ['kind' => 'เรื่องร้องเรียน', 'label' => 'subject', 'files' => ['file']],
        'menu_items'    => ['kind' => 'เมนูนำทาง',       'label' => 'label', 'files' => []],
        'org_chart_nodes' => ['kind' => 'ผังโครงสร้างหน่วยงาน', 'label' => 'label', 'files' => []],
        'media'         => ['kind' => 'ไฟล์ในคลังสื่อ',  'label' => 'orig',  'files' => ['path']],
    ];
}

/** โฟลเดอร์เก็บไฟล์ของถังขยะ */
function trash_dir(): string {
    $d = APP_ROOT . '/storage/trash';
    if (!is_dir($d)) @mkdir($d, 0775, true);
    return $d;
}

/** ย้ายไฟล์เข้าถังขยะ — คืน path ปลายทาง (เทียบจาก storage/trash) หรือ null ถ้าไม่มีไฟล์ */
function trash_stash_file(string $uid, ?string $rel): ?string {
    if (!$rel) return null;
    $real = realpath(APP_ROOT . '/' . $rel);
    $base = realpath(APP_ROOT . '/uploads');
    if (!$real || !$base || strpos($real, $base) !== 0 || !is_file($real)) return null;
    $dest = trash_dir() . '/' . $uid . '/' . $rel;
    $dir  = dirname($dest);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) return null;
    return @rename($real, $dest) ? $rel : null;
}

/** ย้ายไฟล์กลับจากถังขยะไปที่เดิม */
function trash_unstash_file(string $uid, string $rel): bool {
    $src = trash_dir() . '/' . $uid . '/' . $rel;
    if (!is_file($src)) return false;
    $dest = APP_ROOT . '/' . $rel;
    $dir  = dirname($dest);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) return false;
    if (is_file($dest)) return true;                 /* มีไฟล์ชื่อเดียวกันอยู่แล้ว ถือว่าสำเร็จ */
    return @rename($src, $dest);
}

/** ลบโฟลเดอร์ไฟล์ของรายการในถังขยะ (ใช้ตอนลบถาวร) */
function trash_rmdir(string $uid): void {
    $dir = trash_dir() . '/' . $uid;
    if (!is_dir($dir)) return;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
    @rmdir($dir);
}

/**
 * ลบรายการลงถังขยะ (แทนการลบถาวร)
 * คืน true เมื่อเก็บลงถังขยะสำเร็จและลบของจริงแล้ว
 * ถ้าเก็บไม่สำเร็จจะไม่ลบของจริง (กันข้อมูลหายโดยไม่มีที่กู้)
 */
function trash_delete(string $table, int $id, ?string $by = null): bool {
    $types = trash_types();
    if (!isset($types[$table]) || $id <= 0) return false;
    $cfg = $types[$table];

    $st = db()->prepare("SELECT * FROM `$table` WHERE id = ?");
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) return false;

    $uid   = date('Ymd-His') . '-' . bin2hex(random_bytes(4));
    $files = [];

    /* เก็บแถวลูกก่อน (ต้องอ่านให้ครบก่อนลบ) */
    $children = [];
    foreach (($cfg['children'] ?? []) as $ch) {
        $cs = db()->prepare("SELECT * FROM `{$ch['table']}` WHERE `{$ch['fk']}` = ?");
        $cs->execute([$id]);
        $rows = $cs->fetchAll();
        $children[] = ['table' => $ch['table'], 'fk' => $ch['fk'], 'rows' => $rows];
        foreach ($rows as $cr) {
            foreach (($ch['files'] ?? []) as $fc) {
                if (!empty($cr[$fc]) && trash_stash_file($uid, $cr[$fc])) $files[] = $cr[$fc];
            }
        }
    }

    /* ย้ายไฟล์ของแถวหลัก */
    foreach (($cfg['files'] ?? []) as $fc) {
        if (!empty($row[$fc]) && trash_stash_file($uid, $row[$fc])) $files[] = $row[$fc];
    }

    $label = mb_substr(trim((string)($row[$cfg['label']] ?? '')), 0, 200);
    if ($label === '') $label = '#' . $id;

    try {
        db()->prepare('INSERT INTO trash (uid, table_name, row_id, kind, label, payload, children, files, deleted_by)
                       VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute([
                $uid, $table, $id, $cfg['kind'], $label,
                json_encode($row, JSON_UNESCAPED_UNICODE),
                $children ? json_encode($children, JSON_UNESCAPED_UNICODE) : null,
                $files ? json_encode($files, JSON_UNESCAPED_UNICODE) : null,
                mb_substr((string)($by ?? ''), 0, 100),
            ]);
    } catch (Throwable $e) {
        /* เก็บไม่สำเร็จ → คืนไฟล์กลับที่เดิม แล้วไม่ลบของจริง */
        foreach ($files as $f) trash_unstash_file($uid, $f);
        trash_rmdir($uid);
        return false;
    }

    /* ลบของจริง (ลูกก่อน แล้วค่อยแม่) */
    foreach (($cfg['children'] ?? []) as $ch) {
        db()->prepare("DELETE FROM `{$ch['table']}` WHERE `{$ch['fk']}` = ?")->execute([$id]);
    }
    db()->prepare("DELETE FROM `$table` WHERE id = ?")->execute([$id]);
    return true;
}

/** กู้คืนรายการจากถังขยะ — คืน [ok, ข้อความ] */
function trash_restore(int $trashId): array {
    $st = db()->prepare('SELECT * FROM trash WHERE id = ?');
    $st->execute([$trashId]);
    $t = $st->fetch();
    if (!$t) return [false, 'ไม่พบรายการในถังขยะ'];

    $table = (string)$t['table_name'];
    if (!isset(trash_types()[$table])) return [false, 'ไม่รองรับการกู้คืนรายการชนิดนี้'];

    $row = json_decode((string)$t['payload'], true);
    if (!is_array($row) || !$row) return [false, 'ข้อมูลสำรองเสียหาย กู้คืนไม่ได้'];

    /* ถ้ามีแถว id เดิมอยู่แล้ว (ถูกใช้ซ้ำ) ให้กู้เป็นรายการใหม่แทน */
    $chk = db()->prepare("SELECT COUNT(*) FROM `$table` WHERE id = ?");
    $chk->execute([(int)$t['row_id']]);
    $idTaken = (int)$chk->fetchColumn() > 0;
    if ($idTaken) unset($row['id']);

    try {
        db()->beginTransaction();

        $cols = array_keys($row);
        $ph   = implode(',', array_fill(0, count($cols), '?'));
        db()->prepare("INSERT INTO `$table` (`" . implode('`,`', $cols) . "`) VALUES ($ph)")
            ->execute(array_values($row));
        $newId = $idTaken ? (int)db()->lastInsertId() : (int)$t['row_id'];

        /* แถวลูก */
        $children = json_decode((string)($t['children'] ?? ''), true) ?: [];
        foreach ($children as $ch) {
            foreach (($ch['rows'] ?? []) as $cr) {
                unset($cr['id']);                       /* ให้ระบบออกเลขใหม่ */
                $cr[$ch['fk']] = $newId;
                $ccols = array_keys($cr);
                $cph   = implode(',', array_fill(0, count($ccols), '?'));
                db()->prepare("INSERT INTO `{$ch['table']}` (`" . implode('`,`', $ccols) . "`) VALUES ($cph)")
                    ->execute(array_values($cr));
            }
        }

        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        return [false, 'กู้คืนไม่สำเร็จ: ' . $e->getMessage()];
    }

    /* คืนไฟล์กลับที่เดิม */
    $files = json_decode((string)($t['files'] ?? ''), true) ?: [];
    foreach ($files as $f) trash_unstash_file((string)$t['uid'], (string)$f);
    trash_rmdir((string)$t['uid']);

    db()->prepare('DELETE FROM trash WHERE id = ?')->execute([$trashId]);
    return [true, 'กู้คืน "' . $t['label'] . '" เรียบร้อยแล้ว'];
}

/** ลบถาวร (ลบไฟล์ในถังขยะทิ้งด้วย) */
function trash_purge(int $trashId): bool {
    $st = db()->prepare('SELECT uid FROM trash WHERE id = ?');
    $st->execute([$trashId]);
    $uid = (string)($st->fetchColumn() ?: '');
    if ($uid === '') return false;
    trash_rmdir($uid);
    db()->prepare('DELETE FROM trash WHERE id = ?')->execute([$trashId]);
    return true;
}

/** ลบของเก่าเกินกำหนดอัตโนมัติ — คืนจำนวนที่ลบ */
function trash_autopurge(int $days = TRASH_KEEP_DAYS): int {
    $n = 0;
    try {
        $st = db()->prepare('SELECT id FROM trash WHERE deleted_at < (NOW() - INTERVAL ? DAY)');
        $st->execute([max(1, $days)]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) { if (trash_purge((int)$id)) $n++; }
    } catch (Throwable $e) {}
    return $n;
}

/** จำนวนรายการในถังขยะ (ใช้โชว์ badge) */
function trash_count(): int {
    try { return (int)db()->query('SELECT COUNT(*) FROM trash')->fetchColumn(); }
    catch (Throwable $e) { return 0; }
}
