<?php
/** content.php (includes) — Custom Content Types: ตัวช่วยประเภทเนื้อหา + เรนเดอร์ค่าฟิลด์ */
if (!defined('APP_ROOT')) exit('Forbidden');
require_once APP_ROOT . '/includes/forms.php';   // ใช้ field_types(), render_form_field()

/** ดึงประเภทเนื้อหาตาม slug */
function content_type_by_slug(string $slug, bool $only_on = true): ?array {
    $sql = "SELECT * FROM content_types WHERE slug = ?" . ($only_on ? " AND status = 'on'" : "");
    $st = db()->prepare($sql);
    $st->execute([$slug]);
    return $st->fetch() ?: null;
}

/** ดึงประเภทเนื้อหาตาม id */
function content_type_by_id(int $id): ?array {
    $st = db()->prepare('SELECT * FROM content_types WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** ฟิลด์ของประเภท (decode JSON) */
function content_type_fields(array $type): array {
    $f = json_decode((string)($type['fields'] ?? ''), true);
    return is_array($f) ? $f : [];
}

/** ประเภทที่เปิดใช้ทั้งหมด (สำหรับเมนู/หน้าเว็บ) */
function content_types_active(): array {
    try {
        return db()->query("SELECT * FROM content_types WHERE status='on' ORDER BY sort_order ASC, id ASC")->fetchAll();
    } catch (Throwable $e) { return []; }
}

/** เรนเดอร์ค่าฟิลด์เป็น HTML สำหรับแสดงผลหน้าเว็บ */
function render_field_value(array $f, string $val): string {
    if (trim($val) === '') return '<span class="text-muted">—</span>';
    switch ((string)($f['type'] ?? 'text')) {
        case 'textarea':
            return format_rich($val);
        case 'email':
            return '<a href="mailto:' . e($val) . '">' . e($val) . '</a>';
        case 'tel':
            return '<a href="tel:' . e(preg_replace('/[^0-9+]/', '', $val)) . '">' . e($val) . '</a>';
        case 'date':
            $ts = strtotime($val);
            return e($ts ? thai_date(date('Y-m-d H:i:s', $ts)) : $val);
        case 'file':
            if (preg_match('#\((https?://[^)]+)\)#', $val, $m)) {
                $nm = trim(str_replace($m[0], '', $val));
                return '<a href="' . e($m[1]) . '" target="_blank" rel="noopener"><span class="material-symbols-rounded icon-sm">attach_file</span>' . e($nm ?: 'ไฟล์แนบ') . '</a>';
            }
            return e($val);
        default:
            return e($val);
    }
}

/** ข้อความตัวอย่าง (excerpt) จากฟิลด์ข้อความแรกของรายการ */
function content_item_excerpt(array $type, array $data, int $len = 120): string {
    foreach (content_type_fields($type) as $f) {
        if (in_array($f['type'] ?? '', ['text','textarea'], true)) {
            $v = trim((string)($data[$f['label'] ?? ''] ?? ''));
            if ($v !== '') return meta_excerpt($v, $len);
        }
    }
    return '';
}
