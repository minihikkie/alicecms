<?php
/**
 * build-release.php — สร้างแพ็กเกจอัปเดต (.zip) + manifest (update.json)
 * ใช้ตอนออกเวอร์ชันใหม่:
 *   1) แก้ APP_VERSION ใน version.php ให้เป็นเวอร์ชันใหม่
 *   2) php tools/build-release.php "หมายเหตุข้อ1" "หมายเหตุข้อ2" ...
 *   3) gh release create v<ver> dist/*.zip dist/update.json
 *
 * แพ็กเกจจะรวมไฟล์ระบบทั้งหมด ยกเว้น config.php, uploads, storage, backups, .git, tools, dist
 *
 * ⚠ เซ็นแพ็กเกจในเครื่องตัวเองเสมอ อย่าเอา private.pem ขึ้น CI/GitHub Actions
 *   กุญแจนี้ควบคุมโค้ดที่จะถูกติดตั้งบนทุกเว็บที่ใช้ระบบนี้ ต้องไม่ออกจากเครื่องผู้พัฒนา
 */
if (PHP_SAPI !== 'cli') { exit('CLI only'); }

/* repo ปลายทางบน GitHub — ใช้ประกอบ URL ดาวน์โหลดที่ฝังใน update.json
   ย้าย repo หรือ fork ไปทำของตัวเอง แก้บรรทัดนี้บรรทัดเดียวพอ
   (ลายเซ็นดิจิทัลครอบแค่ version+sha256+size ไม่ได้ครอบ URL การเปลี่ยนที่เก็บไฟล์จึงไม่กระทบความปลอดภัย
    ไฟล์ที่โหลดมายังต้องมีแฮชตรงกับที่เซ็นไว้อยู่ดี) */
$GH_REPO = 'minihikkie/alicecms';
$PKG     = 'alicecms';                 /* ชื่อนำหน้าไฟล์แพ็กเกจ */

$root = dirname(__DIR__);
require $root . '/version.php';
$ver  = APP_VERSION;
$notes = array_slice($argv, 1);

$dist = $root . '/dist';
if (!is_dir($dist)) mkdir($dist, 0775, true);
$zipPath = $dist . '/' . $PKG . '-' . $ver . '.zip';
@unlink($zipPath);

/* path ที่ไม่รวมในแพ็กเกจ (prefix เทียบจาก root) */
$skip_prefix = ['uploads/', 'storage/', 'backups/', 'dist/', 'tools/', '.git/', '.claude/', 'node_modules/'];
/* เครื่องมือวินิจฉัยที่ส่งให้ผู้ดูแลเป็นครั้งคราว (standalone, ไม่พึ่งระบบหลัก)
   ห้ามติดไปกับแพ็กเกจเสมอ — เปิดเผยข้อมูลเซิร์ฟเวอร์/ทดสอบเขียนไฟล์ ถ้าถูกทิ้งไว้บนเว็บจริงจะเป็นความเสี่ยง */
$skip_exact  = ['config.php', 'install.lock', 'check-server.php', 'upload-test.php', 'session-check.php'];
/* ข้ามไฟล์ซ่อน (ขึ้นต้นด้วยจุด) — ยกเว้น .htaccess ที่เป็นชั้นป้องกันของ web server ต้องติดไปกับแพ็กเกจเสมอ */
$skip_match  = ['/^_tmp/', '/^\.(?!htaccess$)/', '/\.zip$/', '/\.sql$/', '/Thumbs\.db$/', '/\.DS_Store$/'];

function should_skip(string $rel, array $sp, array $se, array $sm): bool {
    if (in_array($rel, $se, true)) return true;
    foreach ($sp as $p) if (strpos($rel, $p) === 0) return true;
    $bn = basename($rel);
    foreach ($sm as $re) if (preg_match($re, $bn)) return true;
    return false;
}

$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) exit("เปิด zip ไม่ได้\n");

$base = realpath($root);
$n = 0;
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if (!$f->isFile()) continue;
    $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($base) + 1));
    if (should_skip($rel, $skip_prefix, $skip_exact, $skip_match)) continue;
    $zip->addFile($f->getPathname(), $rel);
    $n++;
}
$zip->close();

$sha = hash_file('sha256', $zipPath);
$size = filesize($zipPath);

/* ── เซ็นลายเซ็นดิจิทัล ──
   ป้องกันการถูกสวมรอยระหว่างทาง (MITM) แม้ดาวน์โหลดผ่าน http และแม้เซิร์ฟเวอร์อัปเดตถูกยึด
   เพราะผู้โจมตีไม่มีกุญแจลับ จึงสร้างลายเซ็นที่ผ่านการตรวจไม่ได้
   กุญแจลับอยู่ที่ tools/release-key/private.pem เท่านั้น (ไม่เคยอยู่ในแพ็กเกจ — tools/ ถูกข้าม) */
$keyFile = $root . '/tools/release-key/private.pem';
if (!is_file($keyFile)) {
    @unlink($zipPath);
    exit("ไม่พบกุญแจสำหรับเซ็นแพ็กเกจ: tools/release-key/private.pem\n"
       . "หากทำหาย ต้องสร้างคู่ใหม่แล้วอัปเดต UPDATE_PUBLIC_KEY ใน includes/updater.php\n");
}
$privKey = openssl_pkey_get_private((string)file_get_contents($keyFile));
if (!$privKey) { @unlink($zipPath); exit("อ่านกุญแจลับไม่ได้ (ไฟล์เสียหาย?)\n"); }

/* ข้อมูลที่เซ็น = เวอร์ชัน + sha256 + ขนาด — ผูกทั้งสามอย่างเข้าด้วยกัน สลับไฟล์/ลดเวอร์ชันไม่ได้ */
$signedPayload = $ver . "\n" . $sha . "\n" . $size;
$sig = '';
if (!openssl_sign($signedPayload, $sig, $privKey, OPENSSL_ALGO_SHA256)) {
    @unlink($zipPath);
    exit("เซ็นแพ็กเกจไม่สำเร็จ: " . openssl_error_string() . "\n");
}

/* manifest */
$manifest = [
    'version'     => $ver,
    'released'    => date('Y-m-d'),
    'min_php'     => '8.0.0',
    /* ดาวน์โหลดจาก GitHub Releases — HTTPS บนโดเมนที่แทบไม่มีเครือข่ายไหนบล็อก
       ดีกว่าเซิร์ฟเวอร์ส่วนตัวเดิม (HTTP ล้วนบน IP เปล่า + พอร์ตไม่มาตรฐาน) ซึ่งเคยทำให้
       "ตรวจเจอเวอร์ชันใหม่ แต่ดาวน์โหลดไม่สำเร็จ" บนเครือข่ายหน่วยงานราชการ
       และไม่ผูกอายุระบบอัปเดตของทุกเว็บไว้กับ VPS เครื่องเดียว */
    'package_url' => "https://github.com/$GH_REPO/releases/download/v$ver/$PKG-$ver.zip",
    'sha256'      => $sha,
    'size'        => $size,
    'signature'   => base64_encode($sig),
    'notes'       => $notes ?: ['ปรับปรุงระบบและแก้ไขข้อบกพร่อง'],
];
file_put_contents($dist . '/update.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

echo "สร้างแพ็กเกจ v$ver สำเร็จ\n";
echo "  ไฟล์   : dist/$PKG-$ver.zip ($n ไฟล์, " . round($size / 1024) . " KB)\n";
echo "  SHA256 : $sha\n";
echo "  ลายเซ็น: เซ็นแล้ว ✓ (RSA-SHA256)\n";
echo "  manifest: dist/update.json\n";
echo "\nออกรุ่นบน GitHub (tag + สร้าง release + แนบไฟล์ ในคำสั่งเดียว):\n";
echo "  gh release create v$ver dist/$PKG-$ver.zip dist/update.json \\\n";
echo "     --repo $GH_REPO --title \"v$ver\" --notes " . escapeshellarg(implode("\n", $manifest['notes'])) . "\n";
echo "\nเว็บที่ติดตั้งแล้วจะเห็นรุ่นใหม่เองจาก URL นี้ (ตั้งครั้งเดียว ไม่ต้องแก้อีก):\n";
echo "  https://github.com/$GH_REPO/releases/latest/download/update.json\n";
