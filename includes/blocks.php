<?php
/**
 * blocks.php — ระบบบล็อกสำหรับสร้างหน้าเพจ (Block Builder)
 * ใช้ทั้งฝั่งเว็บ (render_blocks) และฝั่ง admin (รายการชนิดบล็อก)
 */
if (!defined('APP_ROOT')) exit('Forbidden');

/** ชนิดบล็อกที่รองรับ: key => [ชื่อ, ไอคอน] */
function block_types(): array {
    return [
        'heading'   => ['หัวข้อ',        'title'],
        'text'      => ['ข้อความ',       'notes'],
        'image'     => ['รูปภาพ',         'image'],
        'gallery'   => ['แกลเลอรีรูป',    'collections'],
        'video'     => ['วิดีโอ YouTube', 'smart_display'],
        'button'    => ['ปุ่มกด',         'smart_button'],
        'accordion' => ['คำถาม-คำตอบ',   'expand_circle_down'],
        'quote'     => ['คำคม/อ้างอิง',   'format_quote'],
        'divider'   => ['เส้นคั่น',        'horizontal_rule'],
    ];
}

/** เรนเดอร์บล็อกเดียวเป็น HTML สำหรับหน้าเว็บ */
function render_block(array $b): string {
    $type = (string)($b['type'] ?? '');
    switch ($type) {
        case 'heading':
            $lvl = ($b['level'] ?? 'h2') === 'h3' ? 'h3' : 'h2';
            return '<' . $lvl . ' class="blk-heading">' . e((string)($b['text'] ?? '')) . '</' . $lvl . '>';

        case 'text':
            return '<div class="blk-text">' . format_rich((string)($b['text'] ?? '')) . '</div>';

        case 'image':
            $url = (string)($b['url'] ?? '');
            if ($url === '') return '';
            $al = in_array($b['align'] ?? 'center', ['left','center','right','full'], true) ? $b['align'] : 'center';
            $src = preg_match('#^https?://#', $url) ? $url : url($url);
            $cap = trim((string)($b['caption'] ?? ''));
            return '<figure class="blk-image al-' . $al . '"><img loading="lazy" src="' . e($src) . '" alt="' . e($cap) . '">'
                 . ($cap !== '' ? '<figcaption>' . e($cap) . '</figcaption>' : '') . '</figure>';

        case 'gallery':
            $urls = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', (string)($b['urls'] ?? '')))));
            if (!$urls) return '';
            $h = '<div class="blk-gallery">';
            foreach ($urls as $u) {
                $src = preg_match('#^https?://#', $u) ? $u : url($u);
                $h .= '<a href="' . e($src) . '" target="_blank" rel="noopener"><img loading="lazy" src="' . e($src) . '" alt=""></a>';
            }
            return $h . '</div>';

        case 'video':
            $vid = youtube_id((string)($b['url'] ?? ''));
            if ($vid === '') return '';
            return '<div class="blk-video"><iframe src="https://www.youtube-nocookie.com/embed/' . e($vid)
                 . '" title="วิดีโอ" loading="lazy" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe></div>';

        case 'button':
            $txt = trim((string)($b['text'] ?? ''));
            $url = trim((string)($b['url'] ?? ''));
            if ($txt === '') return '';
            $sty = in_array($b['style'] ?? 'primary', ['primary','outline','success'], true) ? $b['style'] : 'primary';
            $href = preg_match('#^(https?:)?//#', $url) ? $url : url($url ?: '#');
            $cls = $sty === 'outline' ? 'btn' : 'btn ' . $sty;
            return '<div class="blk-button"><a class="' . $cls . ' large" href="' . e($href) . '">' . e($txt) . '</a></div>';

        case 'accordion':
            $lines = array_filter(array_map('trim', preg_split('/\r?\n/', (string)($b['items'] ?? ''))));
            if (!$lines) return '';
            $h = '<div class="blk-accordion">';
            foreach ($lines as $ln) {
                $parts = explode('::', $ln, 2);
                $q = trim($parts[0]); $a = trim($parts[1] ?? '');
                if ($q === '') continue;
                $h .= '<div class="faq-item"><button type="button" class="faq-q">' . e($q)
                    . '<span class="material-symbols-rounded">expand_more</span></button>'
                    . '<div class="faq-a"><div>' . format_rich($a) . '</div></div></div>';
            }
            return $h . '</div>';

        case 'quote':
            $txt = (string)($b['text'] ?? '');
            if (trim($txt) === '') return '';
            $cite = trim((string)($b['cite'] ?? ''));
            return '<blockquote class="blk-quote">' . format_rich($txt)
                 . ($cite !== '' ? '<cite>— ' . e($cite) . '</cite>' : '') . '</blockquote>';

        case 'divider':
            return '<hr class="blk-divider">';
    }
    return '';
}

/** เรนเดอร์บล็อกทั้งหมดจาก JSON — คืน '' ถ้าไม่มี/ผิดรูปแบบ */
function render_blocks(?string $json): string {
    if (!$json) return '';
    $arr = json_decode($json, true);
    if (!is_array($arr)) return '';
    $out = '';
    foreach ($arr as $b) {
        if (is_array($b)) $out .= render_block($b);
    }
    return $out;
}

/** ทำความสะอาดบล็อกจากฟอร์ม (เก็บเฉพาะชนิด/ฟิลด์ที่รู้จัก + จำกัดความยาว) ก่อนบันทึก */
function sanitize_blocks(?string $json): string {
    $arr = json_decode((string)$json, true);
    if (!is_array($arr)) return '';
    $types = block_types();
    $fields = ['text','level','url','caption','align','urls','style','items','cite'];
    $out = [];
    foreach ($arr as $b) {
        if (!is_array($b)) continue;
        $t = (string)($b['type'] ?? '');
        if (!isset($types[$t])) continue;
        $clean = ['type' => $t];
        foreach ($fields as $f) {
            if (isset($b[$f]) && is_string($b[$f]) && $b[$f] !== '') $clean[$f] = mb_substr($b[$f], 0, 20000);
        }
        $out[] = $clean;
    }
    return $out ? json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
}

/** มีบล็อกที่ใช้งานได้ไหม */
function has_blocks(?string $json): bool {
    if (!$json) return false;
    $arr = json_decode($json, true);
    return is_array($arr) && count($arr) > 0;
}

/** เรนเดอร์บล็อกหนึ่งอันในตัวแก้ไข (ฟิลด์ใช้ data-field ให้ JS อ่านตอนบันทึก)
 *  ใช้ร่วมกันทุกหน้าที่มีตัวแก้ไขบล็อก (admin/pages.php, admin/site-info.php ฯลฯ) */
function block_editor_item(string $type, array $d = []): string {
    $types = block_types();
    if (!isset($types[$type])) return '';
    [$label, $icon] = $types[$type];
    ob_start(); ?>
    <div class="block-item" data-type="<?= e($type) ?>">
      <div class="block-head">
        <span class="block-label"><span class="material-symbols-rounded icon-sm"><?= e($icon) ?></span><?= e($label) ?></span>
        <span class="block-tools">
          <button type="button" class="btn small" data-bmove="up" title="เลื่อนขึ้น"><span class="material-symbols-rounded icon-sm">keyboard_arrow_up</span></button>
          <button type="button" class="btn small" data-bmove="down" title="เลื่อนลง"><span class="material-symbols-rounded icon-sm">keyboard_arrow_down</span></button>
          <button type="button" class="btn small danger" data-bdel title="ลบบล็อก"><span class="material-symbols-rounded icon-sm">close</span></button>
        </span>
      </div>
      <div class="block-body">
        <?php switch ($type):
          case 'heading': ?>
            <input type="text" data-field="text" value="<?= e($d['text'] ?? '') ?>" placeholder="ข้อความหัวข้อ" class="mb-1">
            <select data-field="level" style="width:170px;">
              <option value="h2" <?= ($d['level'] ?? 'h2') === 'h2' ? 'selected' : '' ?>>หัวข้อใหญ่</option>
              <option value="h3" <?= ($d['level'] ?? '') === 'h3' ? 'selected' : '' ?>>หัวข้อรอง</option>
            </select>
          <?php break; case 'text': ?>
            <textarea data-field="text" rows="4" placeholder="พิมพ์ข้อความ... ใช้ **ตัวหนา** *ตัวเอียง* และวางลิงก์ได้"><?= e($d['text'] ?? '') ?></textarea>
          <?php break; case 'image': ?>
            <div class="flex gap-1 mb-1"><input type="text" data-field="url" value="<?= e($d['url'] ?? '') ?>" placeholder="URL รูป หรือเลือกจากคลังสื่อ" style="flex:1;">
              <button type="button" class="btn small" data-mediapick="url"><span class="material-symbols-rounded icon-sm">perm_media</span>คลัง</button></div>
            <input type="text" data-field="caption" value="<?= e($d['caption'] ?? '') ?>" placeholder="คำบรรยายใต้ภาพ (ไม่บังคับ)" class="mb-1">
            <select data-field="align" style="width:170px;">
              <?php foreach (['center' => 'กึ่งกลาง','left' => 'ชิดซ้าย','right' => 'ชิดขวา','full' => 'เต็มกว้าง'] as $av => $al): ?>
              <option value="<?= $av ?>" <?= ($d['align'] ?? 'center') === $av ? 'selected' : '' ?>><?= $al ?></option><?php endforeach; ?>
            </select>
          <?php break; case 'gallery': ?>
            <div class="flex gap-1 mb-1 items-center"><span class="text-muted" style="font-size:13px;">URL รูป 1 บรรทัด/รูป</span>
              <button type="button" class="btn small" data-mediapick="urls" style="margin-left:auto;"><span class="material-symbols-rounded icon-sm">perm_media</span>เลือกจากคลัง</button></div>
            <textarea data-field="urls" rows="3" placeholder="uploads/media/a.jpg&#10;uploads/media/b.jpg"><?= e($d['urls'] ?? '') ?></textarea>
          <?php break; case 'video': ?>
            <input type="text" data-field="url" value="<?= e($d['url'] ?? '') ?>" placeholder="ลิงก์ YouTube เช่น https://www.youtube.com/watch?v=...">
          <?php break; case 'button': ?>
            <div class="form-row"><div><input type="text" data-field="text" value="<?= e($d['text'] ?? '') ?>" placeholder="ข้อความบนปุ่ม" class="mb-1"></div>
              <div><input type="text" data-field="url" value="<?= e($d['url'] ?? '') ?>" placeholder="ลิงก์ปลายทาง" class="mb-1"></div></div>
            <select data-field="style" style="width:170px;">
              <?php foreach (['primary' => 'ปุ่มหลัก','outline' => 'ปุ่มขอบ','success' => 'ปุ่มเขียว'] as $sv => $sl): ?>
              <option value="<?= $sv ?>" <?= ($d['style'] ?? 'primary') === $sv ? 'selected' : '' ?>><?= $sl ?></option><?php endforeach; ?>
            </select>
          <?php break; case 'accordion': ?>
            <span class="text-muted" style="font-size:13px;">1 บรรทัด = <b>คำถาม :: คำตอบ</b></span>
            <textarea data-field="items" rows="3" placeholder="เปิดทำการกี่โมง :: จันทร์-ศุกร์ 8.30-16.30 น.&#10;ติดต่อที่ไหน :: ชั้น 2 อาคาร A"><?= e($d['items'] ?? '') ?></textarea>
          <?php break; case 'quote': ?>
            <textarea data-field="text" rows="2" placeholder="ข้อความคำคม/คำกล่าว" class="mb-1"><?= e($d['text'] ?? '') ?></textarea>
            <input type="text" data-field="cite" value="<?= e($d['cite'] ?? '') ?>" placeholder="ผู้กล่าว/แหล่งอ้างอิง (ไม่บังคับ)">
          <?php break; case 'divider': ?>
            <span class="text-muted" style="font-size:13px;">— เส้นคั่นแนวนอน —</span>
        <?php endswitch; ?>
      </div>
    </div>
    <?php return ob_get_clean();
}

/** เรนเดอร์การ์ด "เนื้อหา (บล็อก)" ทั้งชุด — ตัวแก้ไข + ปุ่มเพิ่มบล็อก + template
 *  $name = ชื่อ input hidden ที่จะส่งค่า JSON (เช่น 'blocks' หรือ 'about_blocks')
 *  $initJson = ค่าบล็อกเดิม (JSON string จาก DB/settings) ใช้ seed ตัวแก้ไข */
function block_editor_card(string $name, ?string $initJson): void {
    $init_blocks = [];
    if ($initJson && ($dec = json_decode($initJson, true)) && is_array($dec)) $init_blocks = $dec;
    ?>
    <div id="blockEditor">
      <div class="blocks-list" id="blocksList">
        <?php foreach ($init_blocks as $b) if (is_array($b)) echo block_editor_item((string)($b['type'] ?? ''), $b); ?>
      </div>
      <div class="blocks-empty"><span class="material-symbols-rounded">post_add</span>ยังไม่มีเนื้อหา — เลือกเพิ่มบล็อกด้านล่าง</div>
      <div class="block-add">
        <span class="block-add-title"><span class="material-symbols-rounded icon-sm">add_circle</span>เพิ่มบล็อกเนื้อหา</span>
        <div class="block-add-btns">
        <?php foreach (block_types() as $t => [$lbl, $ic]): ?>
          <button type="button" class="btn small" data-addblock="<?= e($t) ?>"><span class="material-symbols-rounded icon-sm"><?= e($ic) ?></span><?= e($lbl) ?></button>
        <?php endforeach; ?>
        </div>
      </div>
      <input type="hidden" name="<?= e($name) ?>" id="blocksJson">
      <template id="blockTpls"><?php foreach (block_types() as $t => $_) echo '<div data-tpl="' . e($t) . '">' . block_editor_item($t, []) . '</div>'; ?></template>
    </div>
    <?php
}
