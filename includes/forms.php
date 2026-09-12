<?php
/** forms.php — ตัวสร้างแบบฟอร์ม (e-Service) : ชนิดฟิลด์ + เรนเดอร์ + ทำความสะอาด */
if (!defined('APP_ROOT')) exit('Forbidden');

/** ชนิดฟิลด์: key => [ชื่อ, ไอคอน, มี options ไหม] */
function field_types(): array {
    return [
        'text'     => ['ข้อความสั้น',   'short_text',    false],
        'textarea' => ['ข้อความยาว',    'notes',         false],
        'email'    => ['อีเมล',          'mail',          false],
        'tel'      => ['เบอร์โทร',       'call',          false],
        'number'   => ['ตัวเลข',         'pin',           false],
        'date'     => ['วันที่',          'calendar_today', false],
        'select'   => ['เลือก (dropdown)', 'arrow_drop_down_circle', true],
        'radio'    => ['ตัวเลือกเดียว',  'radio_button_checked', true],
        'checkbox' => ['เลือกหลายข้อ',   'check_box',     true],
        'file'     => ['แนบไฟล์',         'attach_file',   false],
    ];
}

/** แตก options เป็น array (1 บรรทัด/ตัวเลือก) */
function field_options(array $f): array {
    return array_values(array_filter(array_map('trim', preg_split('/\r?\n/', (string)($f['options'] ?? '')))));
}

/** เรนเดอร์ฟิลด์ฟอร์มหนึ่งช่อง (ฝั่งประชาชน) — $i = ลำดับฟิลด์ */
function render_form_field(array $f, int $i, $old = null): string {
    $types = field_types();
    $type  = (string)($f['type'] ?? 'text');
    if (!isset($types[$type])) return '';
    $label = (string)($f['label'] ?? 'ช่องกรอก');
    $req   = !empty($f['required']);
    $ph    = (string)($f['placeholder'] ?? '');
    $name  = 'field[' . $i . ']';
    $id    = 'ff_' . $i;
    $star  = $req ? ' <span style="color:var(--danger);">*</span>' : '';
    $rattr = $req ? ' required' : '';
    $val   = is_string($old) ? $old : '';

    $h = '<div class="ff-field"><label for="' . $id . '">' . e($label) . $star . '</label>';
    switch ($type) {
        case 'textarea':
            $h .= '<textarea id="' . $id . '" name="' . $name . '" rows="4"' . $rattr . ' placeholder="' . e($ph) . '">' . e($val) . '</textarea>';
            break;
        case 'select':
            $h .= '<select id="' . $id . '" name="' . $name . '"' . $rattr . '><option value="">— เลือก —</option>';
            foreach (field_options($f) as $op) $h .= '<option value="' . e($op) . '"' . ($val === $op ? ' selected' : '') . '>' . e($op) . '</option>';
            $h .= '</select>';
            break;
        case 'radio':
            $h .= '<div class="ff-choices">';
            foreach (field_options($f) as $k => $op) $h .= '<label class="ff-choice"><input type="radio" name="' . $name . '" value="' . e($op) . '"' . ($k === 0 && $req ? $rattr : '') . ($val === $op ? ' checked' : '') . '>' . e($op) . '</label>';
            $h .= '</div>';
            break;
        case 'checkbox':
            $oldArr = is_array($old) ? $old : [];
            $h .= '<div class="ff-choices">';
            foreach (field_options($f) as $op) $h .= '<label class="ff-choice"><input type="checkbox" name="field[' . $i . '][]" value="' . e($op) . '"' . (in_array($op, $oldArr, true) ? ' checked' : '') . '>' . e($op) . '</label>';
            $h .= '</div>';
            break;
        case 'file':
            $h .= '<input type="file" id="' . $id . '" name="ffile[' . $i . ']"' . $rattr . ' accept=".jpg,.jpeg,.png,.webp,.pdf,.doc,.docx,.xls,.xlsx,.zip">';
            break;
        default: /* text/email/tel/number/date */
            $h .= '<input type="' . e($type) . '" id="' . $id . '" name="' . $name . '" value="' . e($val) . '"' . $rattr . ' placeholder="' . e($ph) . '">';
    }
    return $h . '</div>';
}

/** ทำความสะอาดนิยามฟิลด์จากตัวสร้าง ก่อนบันทึก */
function sanitize_form_fields(?string $json): string {
    $arr = json_decode((string)$json, true);
    if (!is_array($arr)) return '';
    $types = field_types();
    $out = [];
    foreach ($arr as $f) {
        if (!is_array($f)) continue;
        $t = (string)($f['type'] ?? '');
        if (!isset($types[$t])) continue;
        $clean = ['type' => $t, 'label' => mb_substr(trim((string)($f['label'] ?? 'ช่องกรอก')), 0, 150) ?: 'ช่องกรอก'];
        if (!empty($f['required'])) $clean['required'] = 1;
        if (isset($f['placeholder']) && is_string($f['placeholder']) && $f['placeholder'] !== '') $clean['placeholder'] = mb_substr($f['placeholder'], 0, 200);
        if ($types[$t][2] && isset($f['options']) && is_string($f['options'])) $clean['options'] = mb_substr($f['options'], 0, 3000);
        $out[] = $clean;
    }
    return $out ? json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
}

/** ดึงฟอร์มตาม slug (สถานะ open) */
function form_by_slug(string $slug): ?array {
    $st = db()->prepare("SELECT * FROM forms WHERE slug = ?");
    $st->execute([$slug]);
    return $st->fetch() ?: null;
}

/** ตัวสร้างฟิลด์ (admin) — ใช้ DOM เดียวกับ block editor (admin.js จัดการ add/move/del/serialize) */
function field_editor_item(string $type, array $d = []): string {
    $types = field_types();
    if (!isset($types[$type])) return '';
    [$label, $icon, $hasOpt] = $types[$type];
    ob_start(); ?>
    <div class="block-item" data-type="<?= e($type) ?>">
      <div class="block-head">
        <span class="block-label"><span class="material-symbols-rounded icon-sm"><?= e($icon) ?></span><?= e($label) ?></span>
        <span class="block-tools">
          <button type="button" class="btn small" data-bmove="up"><span class="material-symbols-rounded icon-sm">keyboard_arrow_up</span></button>
          <button type="button" class="btn small" data-bmove="down"><span class="material-symbols-rounded icon-sm">keyboard_arrow_down</span></button>
          <button type="button" class="btn small danger" data-bdel><span class="material-symbols-rounded icon-sm">close</span></button>
        </span>
      </div>
      <div class="block-body">
        <input type="text" data-field="label" value="<?= e($d['label'] ?? '') ?>" placeholder="ชื่อช่อง (ป้ายกำกับ)" class="mb-1">
        <?php if (!$hasOpt && !in_array($type, ['file','date'], true)): ?>
        <input type="text" data-field="placeholder" value="<?= e($d['placeholder'] ?? '') ?>" placeholder="ข้อความตัวอย่างในช่อง (ไม่บังคับ)" class="mb-1">
        <?php endif; ?>
        <?php if ($hasOpt): ?>
        <textarea data-field="options" rows="3" placeholder="ตัวเลือก 1 บรรทัด/ข้อ"><?= e($d['options'] ?? '') ?></textarea>
        <?php endif; ?>
        <label class="inline-check"><input type="checkbox" data-field="required" value="1" <?= !empty($d['required']) ? 'checked' : '' ?>>จำเป็นต้องกรอก</label>
      </div>
    </div>
    <?php return ob_get_clean();
}
