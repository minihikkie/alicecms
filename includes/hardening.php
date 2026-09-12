<?php
/**
 * hardening.php — สร้าง/ซ่อมไฟล์ .htaccess ที่เป็นชั้นป้องกันของ web server
 *
 * ทำไมต้องมี: โฟลเดอร์ uploads/ และ storage/ ไม่ถูกรวมในแพ็กเกจอัปเดต (เป็นข้อมูลของแต่ละเว็บ)
 * ไฟล์ .htaccess ในนั้นจึงต้องถูกสร้างขึ้นเองที่ปลายทาง — ไฟล์นี้ทำหน้าที่นั้น
 *
 * เรียกจาก: install.php (ติดตั้งใหม่) และ includes/updater.php (ซ่อมอัตโนมัติทุกครั้งที่อัปเดต)
 * ปลอดภัยที่จะเรียกซ้ำ — จะเขียนเฉพาะไฟล์ที่ยังไม่มีเท่านั้น ไม่ทับของเดิมที่ผู้ดูแลแก้เอง
 *
 * ไฟล์นี้ต้องไม่พึ่ง config.php หรือฐานข้อมูล (install.php เรียกก่อนมี config)
 */

/** เนื้อหามาตรฐานของไฟล์ป้องกันแต่ละจุด — key = path เทียบจากรากเว็บ */
function hardening_files(): array {
    return [
        'uploads/.htaccess' => <<<'HTA'
# ════════════════════════════════════════════
# uploads/.htaccess — โฟลเดอร์ไฟล์อัปโหลด (รูป/เอกสาร/ไฟล์แนบที่เปิดดูได้สาธารณะ)
# หลักการ: ไฟล์ในนี้ต้องเปิดดูได้ปกติ (เป็นจุดประสงค์ของโฟลเดอร์นี้) —
# ความปลอดภัยจึงเน้นที่ "ห้ามรันสคริปต์" ไม่ใช่ "ห้ามเข้าถึง"
#
# หมายเหตุ: เดิมเคยใช้ Require all denied + FilesMatch อนุญาตเฉพาะนามสกุล
# แต่พบว่าเซิร์ฟเวอร์บางแบบ (เช่น Apache ที่ AllowOverride ไม่ครอบคลุม AuthConfig)
# จะขึ้น 500 Internal Server Error กับคำสั่งชุดนี้ — จึงตัดออกเพื่อความเข้ากันได้กว้างสุด
# (การกันไฟล์ประเภทอันตราย ทำที่ชั้นอัปโหลด — store_upload() ในระบบ — ตรวจนามสกุล+เนื้อไฟล์จริงอยู่แล้ว
#  ไฟล์ .php จึงไม่มีทางถูกบันทึกลงโฟลเดอร์นี้ตั้งแต่แรก)
# ════════════════════════════════════════════

# ปิด PHP engine (กรณี mod_php) — กันไว้อีกชั้นแม้ไฟล์ .php จะหลุดเข้ามาได้
<IfModule mod_php.c>
  php_flag engine off
</IfModule>
<IfModule mod_php7.c>
  php_flag engine off
</IfModule>

# ตัด handler ของสคริปต์ทุกชนิด (กรณี handler-based) — ทำให้ .php ถูกส่งเป็นไฟล์ข้อความ ไม่ถูกรัน
RemoveHandler .php .phtml .php3 .php4 .php5 .php7 .phps .cgi .pl .py .asp .aspx .jsp .sh
RemoveType .php .phtml .php3 .php4 .php5 .php7 .phps

Options -Indexes -ExecCGI
HTA,

        /* ไฟล์แนบเรื่องร้องเรียน = ข้อมูลส่วนบุคคล (PDPA) ห้ามเปิดจาก URL ตรง
           ต้องผ่าน admin/complaint-file.php ที่ตรวจสิทธิ์ก่อนเสมอ */
        'uploads/complaints/.htaccess' => <<<'HTA'
# ไฟล์แนบเรื่องร้องเรียนเป็นข้อมูลส่วนบุคคล — ห้ามเข้าถึงจากเว็บโดยตรงทุกกรณี
# เจ้าหน้าที่ดูได้ผ่าน admin/complaint-file.php ซึ่งตรวจสอบสิทธิ์ก่อน
Require all denied
<IfModule !mod_authz_core.c>
  Order allow,deny
  Deny from all
</IfModule>
Options -Indexes
HTA,

        'storage/.htaccess' => <<<'HTA'
# กันการเข้าถึงไฟล์ภายในระบบ (สำรอง/ชั่วคราว) จากเว็บโดยตรง
Require all denied
<IfModule !mod_authz_core.c>
  Order allow,deny
  Deny from all
</IfModule>
Options -Indexes
HTA,

        'storage/backups/.htaccess' => <<<'HTA'
# ชุดสำรองฐานข้อมูลอัตโนมัติ (มีข้อมูลผู้ใช้ทั้งหมด) — ห้ามเข้าถึงจากเว็บโดยตรง
Require all denied
<IfModule !mod_authz_core.c>
  Order allow,deny
  Deny from all
</IfModule>
Options -Indexes
HTA,

        'storage/trash/.htaccess' => <<<'HTA'
# ไฟล์ที่ถูกลบลงถังขยะ — ห้ามเข้าถึงจากเว็บโดยตรง
Require all denied
<IfModule !mod_authz_core.c>
  Order allow,deny
  Deny from all
</IfModule>
Options -Indexes
HTA,

        'storage/updates/.htaccess' => <<<'HTA'
# กันการเข้าถึงไฟล์สำรอง/ชั่วคราวของระบบอัปเดตจากเว็บโดยตรง
Require all denied
<IfModule !mod_authz_core.c>
  Order allow,deny
  Deny from all
</IfModule>
Options -Indexes
HTA,

        'storage/sessions/.htaccess' => <<<'HTA'
# ไฟล์เซสชันผู้ใช้ (มีสถานะล็อกอิน) — ห้ามเข้าถึงจากเว็บเด็ดขาด
Require all denied
<IfModule !mod_authz_core.c>
  Order allow,deny
  Deny from all
</IfModule>
Options -Indexes
HTA,

        'backups/.htaccess' => <<<'HTA'
# กันการเข้าถึงไฟล์สำรองจากเว็บโดยตรง
Require all denied
<IfModule !mod_authz_core.c>
  Order allow,deny
  Deny from all
</IfModule>
Options -Indexes
HTA,
    ];
}

/**
 * สร้างไฟล์ป้องกันที่ยังขาด (ไม่ทับของเดิม)
 * คืน ['created' => [...path ที่สร้างใหม่], 'missing' => [...path ที่เขียนไม่ได้]]
 */
function ensure_hardening_files(string $root): array {
    $created = [];
    $failed  = [];
    foreach (hardening_files() as $rel => $content) {
        $path = rtrim($root, '/\\') . '/' . $rel;
        $dir  = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) { $failed[] = $rel; continue; }
        if (is_file($path)) continue;                       /* มีอยู่แล้ว — ไม่แตะของเดิม */
        if (@file_put_contents($path, $content) === false) { $failed[] = $rel; continue; }
        $created[] = $rel;
    }
    return ['created' => $created, 'failed' => $failed];
}

/** ตรวจว่าไฟล์ป้องกันครบไหม — คืนรายการ path ที่ยังขาด (ว่าง = ครบ) */
function missing_hardening_files(string $root): array {
    $missing = [];
    foreach (array_keys(hardening_files()) as $rel) {
        if (!is_file(rtrim($root, '/\\') . '/' . $rel)) $missing[] = $rel;
    }
    return $missing;
}

/* ───────── URL สวย (ซ่อน .php) ───────── */

/** จุดอ้างอิงบล็อก URL สวยใน .htaccess — ใช้ค้นหาเพื่อถอดออกเมื่อเซิร์ฟเวอร์ไม่รองรับ */
const PRETTY_URL_BEGIN = '# BEGIN PRETTY-URLS';
const PRETTY_URL_END   = '# END PRETTY-URLS';

/** ถอดบล็อก URL สวยออกจาก .htaccess (ใช้เมื่อทดสอบไม่ผ่าน) — คืน true ถ้าถอดสำเร็จ */
function remove_pretty_urls_block(string $root): bool {
    $file = rtrim($root, '/\\') . '/.htaccess';
    if (!is_file($file)) return false;
    $cur = (string)@file_get_contents($file);
    $b = strpos($cur, PRETTY_URL_BEGIN);
    $e = strpos($cur, PRETTY_URL_END);
    if ($b === false || $e === false || $e < $b) return false;
    $new = substr($cur, 0, $b) . substr($cur, $e + strlen(PRETTY_URL_END));
    return @file_put_contents($file, $new) !== false;
}

/**
 * ตรวจว่า mod_rewrite ทำงานอยู่จริงและ .htaccess ถูกอ่าน — โดยไม่ต้องต่ออินเทอร์เน็ต
 *
 * ทำไมต้องมี: วิธีเดิมทดสอบด้วยการยิง HTTP จากเซิร์ฟเวอร์หาตัวเอง ซึ่งใช้ไม่ได้กับเครือข่าย
 * ที่บล็อกการเชื่อมต่อขาออก (เจอจริงกับเว็บหน่วยงานราชการ — URL สวยใช้ได้อยู่แล้ว
 * แต่ตัวทดสอบยิงออกไม่ได้ ระบบเลยเข้าใจผิดว่าไม่รองรับแล้วปิดฟีเจอร์ทิ้ง)
 *
 * วิธีนี้อาศัยตัวแปรที่ .htaccess ตั้งไว้ให้ (E=GOVCMS_REWRITE:1) จึงเชื่อถือได้ 100%
 * — ถ้าอ่าน .htaccess ไม่ได้หรือไม่มี mod_rewrite ตัวแปรนี้จะไม่มี
 * (Apache เติมคำนำหน้า REDIRECT_ ทุกครั้งที่ rewrite ภายใน จึงต้องเช็คหลายชั้น)
 */
function rewrite_module_active(): bool {
    foreach (['GOVCMS_REWRITE', 'REDIRECT_GOVCMS_REWRITE', 'REDIRECT_REDIRECT_GOVCMS_REWRITE'] as $k) {
        if (!empty($_SERVER[$k]) || !empty(getenv($k))) return true;
    }
    /* ห้ามใช้ apache_get_modules() เป็นตัวสำรอง — มันบอกแค่ว่า "โหลดโมดูลไว้" ไม่ได้บอกว่า
       ".htaccess ของเราถูกอ่านจริง" (เช่นโฮสต์ที่ AllowOverride ปิด FileInfo จะโหลดโมดูลไว้
       แต่กฎของเราไม่ถูกใช้เลย) — ทดสอบแล้วเจอจริง: ลบบล็อกออก /news กลายเป็น 404
       แต่ apache_get_modules ยังตอบ true ซึ่งจะทำให้เปิดฟีเจอร์ผิดจนลิงก์พังทั้งเว็บ */
    return false;
}

/**
 * ทดสอบว่า URL แบบซ่อน .php ใช้งานได้จริงบนเซิร์ฟเวอร์นี้ไหม
 *
 * ทำไมต้องทดสอบ: กฎ RewriteRule ต้องการสิทธิ์ AllowOverride FileInfo ซึ่งบางโฮสต์ไม่เปิดให้
 * ถ้าไม่เปิด Apache จะตอบ 500 ทั้งเว็บทันที — อันตรายมาก จึงต้องยิงทดสอบจริงแล้วถอยกลับอัตโนมัติ
 * (ใช้แนวเดียวกับ self_test_uploads_access() ที่พิสูจน์แล้วว่าได้ผลกับโฮสต์กลุ่มนี้)
 *
 * ยิง HTTP ไปที่ URL สวยของหน้าที่มีจริง (ค่าเริ่มต้น 'about') แล้วดูว่าได้ 200 ไหม
 * $baseUrl = scheme://host/path-prefix (ไม่มี / ท้าย)
 * คืน true = ใช้ได้, false = ใช้ไม่ได้ (ผู้เรียกควรถอดบล็อกออกและปิด setting)
 */
function self_test_pretty_urls(string $baseUrl, string $probePage = 'about'): bool {
    $url = rtrim($baseUrl, '/') . '/' . $probePage;
    $code = 0;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,   /* ต้องได้ 200 ตรงๆ ไม่ใช่ redirect วน */
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => false,   /* ยิงหาตัวเอง ไม่ใช่บุคคลที่สาม */
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);
        curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    } else {
        $ctx = stream_context_create(['http' => ['timeout' => 10, 'ignore_errors' => true, 'follow_location' => 0]]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body !== false && isset($http_response_header[0])
            && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) $code = (int)$m[1];
    }
    return $code === 200;
}

/**
 * ทดสอบ URL สวยแล้วเปิด/ปิดให้อัตโนมัติ — เรียกหลังติดตั้ง/อัปเดตไฟล์เสร็จ
 * คืนข้อความสรุปผลสำหรับแสดงให้ผู้ดูแล (หรือ '' ถ้าข้ามการทดสอบ)
 * ต้องมี setting_set() พร้อมใช้ (เรียกหลังเชื่อมต่อฐานข้อมูลแล้วเท่านั้น)
 */
function apply_pretty_urls(string $root, string $baseUrl): string {
    $file = rtrim($root, '/\\') . '/.htaccess';
    if (!is_file($file) || strpos((string)@file_get_contents($file), PRETTY_URL_BEGIN) === false) {
        setting_set('pretty_urls', '0');
        return '';                                   /* ไม่มีบล็อกให้ทดสอบ (ผู้ดูแลถอดเองไปแล้ว) */
    }
    /* ตรวจแบบ local ก่อน (เชื่อถือได้และไม่ต้องต่อเน็ต) — ถ้าไม่ได้ผลค่อยลองยิง HTTP ทดสอบ
       เรียงลำดับนี้สำคัญ: เครือข่ายที่บล็อกขาออกจะผ่านด่านแรกได้ แต่ตกด่าน HTTP เสมอ */
    if (rewrite_module_active() || self_test_pretty_urls($baseUrl)) {
        setting_set('pretty_urls', '1');
        return '✓ เปิดใช้ URL แบบซ่อน .php แล้ว (เช่น /news แทน /news.php)';
    }
    setting_set('pretty_urls', '0');
    remove_pretty_urls_block($root);
    return '⚠ เซิร์ฟเวอร์นี้ไม่รองรับ URL แบบซ่อน .php — ปิดให้อัตโนมัติแล้ว เว็บใช้งานได้ตามปกติ (ลิงก์ยังเป็น .php)';
}

/**
 * ทดสอบว่าไฟล์ในโฟลเดอร์ uploads/ ยังเปิดจาก URL ได้จริงหลังสร้าง .htaccess
 *
 * ทำไมต้องมี: บางเซิร์ฟเวอร์ (เจอจริงกับโฮสต์ราชการบางแห่ง) ปฏิเสธไฟล์ .htaccess ที่มีคำสั่งบางประเภท
 * ทำให้ "ทั้งโฟลเดอร์" เปิดไม่ได้เลย (500 Internal Server Error) แม้เนื้อหาจะปลอดภัยแค่ไหนก็ตาม —
 * ผลคือรูปภาพ/โลโก้/เอกสารที่อัปโหลดไปแล้วเปิดไม่ขึ้น ทั้งที่ไฟล์ถูกบันทึกสำเร็จจริง
 *
 * ฟังก์ชันนี้วางไฟล์ทดสอบเล็กๆ แล้วยิง HTTP ไปเปิดเองทันที ถ้าเปิดไม่ได้ (ไม่ใช่ 200)
 * จะ "ลบ uploads/.htaccess ทิ้งทันที" — ยังปลอดภัยอยู่เพราะ store_upload() ตรวจนามสกุล+เนื้อไฟล์จริง
 * ก่อนบันทึกทุกครั้งอยู่แล้ว (ไฟล์ .php ไม่มีทางถูกอัปโหลดผ่านระบบได้ตั้งแต่ต้นทาง)
 *
 * $baseUrl = scheme://host/path-prefix ของเว็บ (ไม่มี / ท้าย) — ผู้เรียกต้องคำนวณเองตามบริบทที่มี
 * คืน: true = ทดสอบผ่านปกติ, false = ทดสอบไม่ผ่านและลบไฟล์ออกแล้ว, null = ข้ามการทดสอบ (ไม่มีไฟล์ให้ทดสอบ/เขียนไฟล์ทดสอบไม่ได้)
 */
function self_test_uploads_access(string $root, string $baseUrl): ?bool {
    $htaccess = rtrim($root, '/\\') . '/uploads/.htaccess';
    if (!is_file($htaccess)) return null;   /* ไม่มีไฟล์ให้ทดสอบ */

    $dir = rtrim($root, '/\\') . '/uploads';
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) return null;
    $probeName = '.selftest-' . bin2hex(random_bytes(4)) . '.txt';
    $probePath = $dir . '/' . $probeName;
    $token = bin2hex(random_bytes(6));
    if (@file_put_contents($probePath, $token) === false) return null;

    $url = rtrim($baseUrl, '/') . '/uploads/' . $probeName;
    $ok  = false;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_SSL_VERIFYPEER => false,   /* self-test ภายในเว็บตัวเอง ไม่ใช่บุคคลที่สาม */
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $ok = ($code === 200 && $body === $token);
    } else {
        $ctx  = stream_context_create(['http' => ['timeout' => 8, 'ignore_errors' => true]]);
        $body = @file_get_contents($url, false, $ctx);
        $ok   = ($body === $token);
    }

    @unlink($probePath);

    if (!$ok) {
        @unlink($htaccess);
        return false;
    }
    return true;
}
