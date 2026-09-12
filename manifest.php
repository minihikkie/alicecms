<?php
/** manifest.php — Web App Manifest (PWA) สร้างจากค่าตั้งค่าเว็บไซต์ */
require __DIR__ . '/includes/init.php';

header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=3600');

$name  = setting('site_name', 'เว็บไซต์หน่วยงาน');
$short = mb_substr($name, 0, 18);
$desc  = setting('site_description', $name);
$theme = valid_hex(setting('theme_color', '')) ? setting('theme_color') : '#1A73E8';

$manifest = [
    'name'             => $name,
    'short_name'       => $short,
    'description'      => $desc,
    'lang'             => 'th',
    'dir'              => 'ltr',
    'start_url'        => url('index.php') . '?source=pwa',
    'scope'            => url(''),
    'display'          => 'standalone',
    'orientation'      => 'portrait-primary',
    'background_color' => '#ffffff',
    'theme_color'      => $theme,
    'icons'            => [
        ['src' => url('assets/img/pwa/icon-192.png'), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => url('assets/img/pwa/icon-512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => url('assets/img/pwa/icon-maskable-512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
    ],
    'shortcuts'        => [
        ['name' => 'ข่าวสาร',    'url' => url('news.php'),      'icons' => [['src' => url('assets/img/pwa/icon-192.png'), 'sizes' => '192x192']]],
        ['name' => 'ร้องเรียน',  'url' => url('complaint.php'), 'icons' => [['src' => url('assets/img/pwa/icon-192.png'), 'sizes' => '192x192']]],
        ['name' => 'ติดต่อเรา',  'url' => url('contact.php'),   'icons' => [['src' => url('assets/img/pwa/icon-192.png'), 'sizes' => '192x192']]],
    ],
];

echo json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
