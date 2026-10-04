<?php
/**
 * highlights.php — ข่าวด่วน · ประชาสัมพันธ์จากโซเชียล
 *
 * เรื่องด่วนที่หน่วยงานต้องการกระจายต่อ เช่น วิดีโอ/โพสต์จาก Facebook, YouTube, TikTok
 * แสดงเป็นกล่องบนหน้าแรก (เฉพาะเรื่องที่ยังอยู่ในช่วงเวลา) และมีหน้าคลังย้อนหลัง highlights.php
 *
 * หลักสำคัญ
 *  - ไม่โหลดอะไรจากโซเชียลตอนเปิดหน้า แสดงภาพปกของเราเอง ผู้ชมกดเล่นเมื่อไหร่ค่อยสร้าง iframe
 *    (หน้าเว็บเร็ว และ Facebook ไม่ได้เก็บข้อมูลผู้ชมแค่เพราะเปิดหน้าแรก — สำคัญกับ PDPA)
 *  - URL ที่ใส่ใน iframe ประกอบใหม่จากรหัสที่แกะได้เท่านั้น ไม่เอา URL ที่ผู้ใช้พิมพ์ใส่ iframe ตรงๆ
 *    (Facebook ใช้ URL เป็นพารามิเตอร์ href แต่โดเมนของ iframe ตรึงไว้ที่ facebook.com เสมอ)
 *  - เรื่องด่วนหายไปเองเมื่อถึงเวลาสิ้นสุด แต่ยังดูย้อนหลังได้ที่หน้าคลัง
 */
if (!defined('APP_ROOT')) exit('Forbidden');

/** แหล่งที่รองรับ: [ชื่อ, สีประจำแพลตฟอร์ม] */
function hl_platforms(): array {
    return [
        'facebook'  => ['Facebook',  '#1877F2'],
        'youtube'   => ['YouTube',   '#FF0000'],
        'tiktok'    => ['TikTok',    '#111111'],
        'x'         => ['X',         '#111111'],
        'instagram' => ['Instagram', '#D62976'],
        'line'      => ['LINE',      '#06C755'],
        'web'       => ['เว็บไซต์',  ''],
    ];
}

/** ระดับความด่วน: [ป้าย, คลาส] */
function hl_urgencies(): array {
    return [
        'critical' => ['ด่วนมาก', 'u-critical'],
        'urgent'   => ['ด่วน', 'u-urgent'],
        'normal'   => ['ประชาสัมพันธ์', 'u-normal'],
    ];
}

/** ไอคอนแพลตฟอร์มแบบ SVG ในบรรทัด (ไม่ดึงไฟล์/ฟอนต์จากภายนอก) */
function hl_platform_icon(string $p): string {
    $paths = [
        'x'         => 'M18.9 1.2h3.7l-8 9.2L24 22.8h-7.4l-5.8-7.6-6.6 7.6H.5l8.6-9.8L0 1.2h7.6l5.2 6.9 6.1-6.9Zm-1.3 19.4h2L6.5 3.3H4.3l13.3 17.3Z',
        'instagram' => 'M12 2.2c3.2 0 3.6 0 4.8.1 3.3.1 4.8 1.7 4.9 4.9.1 1.3.1 1.6.1 4.8s0 3.6-.1 4.8c-.1 3.2-1.7 4.8-4.9 4.9-1.3.1-1.6.1-4.8.1s-3.6 0-4.8-.1c-3.3-.1-4.8-1.7-4.9-4.9-.1-1.3-.1-1.6-.1-4.8s0-3.6.1-4.8c.1-3.3 1.6-4.8 4.9-4.9 1.2-.1 1.6-.1 4.8-.1Zm0 4.9a4.9 4.9 0 1 0 0 9.8 4.9 4.9 0 0 0 0-9.8Zm0 8.1a3.2 3.2 0 1 1 0-6.4 3.2 3.2 0 0 1 0 6.4Zm5.1-9.4a1.1 1.1 0 1 0 0 2.3 1.1 1.1 0 0 0 0-2.3Z',
    ];
    $d = in_array($p, ['facebook', 'youtube', 'tiktok', 'line'], true) ? social_icon_path($p) : ($paths[$p] ?? '');
    if ($d === '') return '<span class="material-symbols-rounded" aria-hidden="true">public</span>';
    return '<svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" fill="currentColor" fill-rule="evenodd"><path d="' . $d . '"/></svg>';
}

/**
 * แกะลิงก์ → แพลตฟอร์ม ชนิด และ URL สำหรับเล่นในเว็บ
 * @return array{platform:string, kind:string, embed:string, vertical:bool}
 *   kind: video = เล่นใน popup ได้ · post = โพสต์ (เปิดแท็บใหม่) · link = ลิงก์ทั่วไป · none = ไม่มีลิงก์
 */
function hl_detect(string $url): array {
    $url = trim($url);
    $out = ['platform' => 'web', 'kind' => $url === '' ? 'none' : 'link', 'embed' => '', 'vertical' => false];
    if ($url === '' || !preg_match('~^https?://~i', $url)) return $out;
    $host = strtolower((string)parse_url($url, PHP_URL_HOST));
    $host = preg_replace('/^(www|m|mobile|web)\./', '', $host);
    $path = (string)parse_url($url, PHP_URL_PATH);

    /* YouTube — ใช้ youtube-nocookie แบบเดียวกับวิดีโอความรู้ */
    if (in_array($host, ['youtube.com', 'youtu.be', 'music.youtube.com'], true)) {
        $out['platform'] = 'youtube';
        $id = youtube_id($url);
        if ($id !== '') {
            $out['kind'] = 'video';
            $out['embed'] = 'https://www.youtube-nocookie.com/embed/' . $id . '?autoplay=1&rel=0';
            $out['vertical'] = str_contains($path, '/shorts/');
        }
        return $out;
    }

    /* Facebook — วิดีโอ/รีลเล่นในเว็บได้ผ่าน video plugin ส่วนโพสต์ทั่วไปเปิดแท็บใหม่
       (ฝังโพสต์ทั่วไปได้ก็จริง แต่ความสูงเดาไม่ได้ และหน้าตาเป็นกรอบ Facebook ล้วน) */
    if (in_array($host, ['facebook.com', 'fb.com', 'fb.watch'], true)) {
        $out['platform'] = 'facebook';
        $isReel  = (bool)preg_match('~/(reel|reels)/|/share/r/~i', $path);
        $isVideo = $host === 'fb.watch' || $isReel
                || (bool)preg_match('~/videos?/|/watch/?$|/share/v/~i', $path)
                || (str_contains($path, '/watch') && str_contains($url, 'v='));
        $out['kind'] = $isVideo ? 'video' : 'post';
        if ($isVideo) {
            $out['embed'] = 'https://www.facebook.com/plugins/video.php?href=' . rawurlencode($url)
                          . '&show_text=false&autoplay=true';
            $out['vertical'] = $isReel;
        }
        return $out;
    }

    /* TikTok — เล่นได้เฉพาะลิงก์เต็มที่มีเลขวิดีโอ ลิงก์ย่อ vm.tiktok.com ต้องเปิดแท็บใหม่ */
    if (in_array($host, ['tiktok.com', 'vm.tiktok.com', 'vt.tiktok.com'], true)) {
        $out['platform'] = 'tiktok';
        if (preg_match('~/video/(\d{6,25})~', $path, $m)) {
            $out['kind'] = 'video';
            $out['embed'] = 'https://www.tiktok.com/embed/v2/' . $m[1];
            $out['vertical'] = true;
        }
        return $out;
    }

    if (in_array($host, ['x.com', 'twitter.com'], true))           $out['platform'] = 'x';
    elseif ($host === 'instagram.com')                               $out['platform'] = 'instagram';
    elseif (in_array($host, ['line.me', 'lin.ee', 'liff.line.me'], true)) $out['platform'] = 'line';
    if ($out['platform'] !== 'web') $out['kind'] = 'post';
    return $out;
}

/** เวลาปัจจุบันฝั่ง PHP (เขตเวลาไทยตาม init.php) — ไม่ใช้ NOW() ของ MySQL ซึ่งอาจตั้งเป็น UTC
 *  เวลาที่ผู้ดูแลกรอกเป็นเวลาไทย ถ้าเทียบกับ NOW() แบบ UTC เรื่องด่วนจะขึ้น/หายช้าไป 7 ชั่วโมง */
function hl_now(): string { return date('Y-m-d H:i:s'); }

/** เงื่อนไข "กำลังแสดง" (ต้องผูกค่า :now) */
function hl_live_where(): string {
    return "status = 'published' AND (starts_at IS NULL OR starts_at <= :now) AND (ends_at IS NULL OR ends_at > :now2)";
}

/** เรื่องที่กำลังแสดงบนหน้าแรก — ปักหมุดก่อน แล้วเรียงตามความด่วน และใหม่สุดก่อน */
function hl_active(int $limit = 4): array {
    try {
        $st = db()->prepare('SELECT * FROM highlights WHERE ' . hl_live_where() . "
            ORDER BY pinned DESC, FIELD(urgency, 'critical', 'urgent', 'normal'), COALESCE(starts_at, created_at) DESC, id DESC
            LIMIT " . max(1, $limit));
        $st->execute([':now' => hl_now(), ':now2' => hl_now()]);
        return $st->fetchAll();
    } catch (Throwable $e) { return []; }
}

/** สถานะของเรื่องหนึ่งเรื่อง: live | scheduled | ended | draft */
function hl_state(array $h): string {
    if (($h['status'] ?? '') !== 'published') return 'draft';
    $now = hl_now();
    if (!empty($h['starts_at']) && $h['starts_at'] > $now) return 'scheduled';
    if (!empty($h['ends_at']) && $h['ends_at'] <= $now) return 'ended';
    return 'live';
}

/** วันที่ที่ใช้แสดง/เรียง — วันเริ่มประชาสัมพันธ์ ถ้าไม่ได้ตั้งใช้วันที่สร้าง */
function hl_date(array $h): string {
    return (string)($h['starts_at'] ?: $h['created_at']);
}

/** ลิงก์แชร์ของเรื่อง (ชี้ไปหน้าคลัง แล้วเลื่อนไปที่เรื่องนั้น) */
function hl_share_url(array $h): string {
    return abs_url('highlights.php?h=' . (int)$h['id']);
}

/** แอตทริบิวต์ของลิงก์ที่กดแล้วเปิด — วิดีโอได้ data-hl-play ให้ main.js เปิด popup
 *  ถ้า JS ไม่ทำงาน ลิงก์ยังพาไปดูต้นฉบับในแท็บใหม่ได้ตามปกติ */
function hl_open_attrs(array $h, array $d): string {
    $url = trim((string)$h['url']);
    /* ยอมเฉพาะ http(s) — หน้า admin กันไว้ตอนบันทึกแล้ว แต่ตัวสร้างการ์ดต้องกันเองด้วย
       ไม่งั้นข้อมูลที่หลุดเข้าฐานข้อมูลทางอื่น (นำเข้า/กู้คืน) จะกลายเป็น href="javascript:..." */
    if ($url !== '' && !preg_match('~^https?://~i', $url)) $url = '';
    if ($d['embed'] !== '') {
        $pl = hl_platforms()[$d['platform']][0] ?? '';
        return 'href="' . e($url) . '" target="_blank" rel="noopener noreferrer"'
             . ' data-hl-play="' . e($d['embed']) . '" data-hl-vertical="' . ($d['vertical'] ? '1' : '0') . '"'
             . ' data-hl-title="' . e($h['title']) . '" data-hl-src="' . e($url) . '" data-hl-plat="' . e($pl) . '"';
    }
    if ($url !== '') return 'href="' . e($url) . '" target="_blank" rel="noopener noreferrer"';
    /* ไม่มีลิงก์แต่มีภาพปก → เปิดภาพเต็มด้วยตัวแสดงภาพเดิมของระบบ */
    if (!empty($h['cover'])) return 'href="' . e(url($h['cover'])) . '" data-lightbox="' . e(url($h['cover'])) . '"';
    return '';
}

/** ข้อความปุ่มตามชนิดลิงก์ */
function hl_action_label(array $d): string {
    return ['video' => 'ดูวิดีโอ', 'post' => 'เปิดโพสต์', 'link' => 'เปิดลิงก์'][$d['kind']] ?? 'ดูภาพ';
}

/**
 * การ์ดหนึ่งใบ — ใช้ร่วมกันทั้งกล่องหน้าแรก หน้าคลัง และตัวอย่างในหน้า admin
 * @param string $variant feat = ใบใหญ่บนหน้าแรก · side = ใบเล็กข้างใบใหญ่ · grid = การ์ดในหน้าคลัง
 */
function hl_card(array $h, string $variant = 'grid'): string {
    $d     = hl_detect((string)$h['url']);
    $plats = hl_platforms();
    $urg   = hl_urgencies();
    $state = hl_state($h);
    $u     = isset($urg[$h['urgency']]) ? $h['urgency'] : 'normal';
    [$plName, $plColor] = $plats[$d['platform']] ?? $plats['web'];
    $attrs = hl_open_attrs($h, $d);
    $tag   = $attrs !== '' ? 'a' : 'div';
    $date  = hl_date($h);

    $cls = 'hl-card hl-' . $variant . ' ' . $urg[$u][1] . ($state === 'ended' ? ' is-ended' : '');
    $html = '<article class="' . $cls . '" id="h-' . (int)$h['id'] . '" data-hl-id="' . (int)$h['id'] . '">';

    /* ── สื่อ (ภาพปก + ปุ่มเล่น + ป้ายแพลตฟอร์ม) ── */
    $html .= '<' . $tag . ' class="hl-media' . ($d['vertical'] ? ' is-vertical' : '') . '" ' . $attrs
           . ($tag === 'a' ? ' aria-label="' . e(hl_action_label($d) . ': ' . $h['title']) . '"' : '') . '>';
    if (!empty($h['cover'])) {
        $html .= '<img src="' . e(url($h['cover'])) . '" alt="" loading="lazy" decoding="async">';
    } else {
        /* ไม่มีภาพปก — ใช้พื้นไล่สีของแพลตฟอร์ม/ธีม พร้อมไอคอนใหญ่ ไม่ปล่อยเป็นกล่องว่าง */
        /* วิดีโอไม่ต้องใส่ไอคอนกลางภาพ — ปุ่มเล่นอยู่ตรงกลางอยู่แล้ว ซ้อนกันแล้วดูรก
           ป้ายแหล่งที่มามุมล่างซ้ายบอกอยู่แล้วว่ามาจากไหน */
        $html .= '<span class="hl-fallback" style="' . ($plColor ? '--pc:' . e($plColor) . ';' : '') . '">'
               . ($d['kind'] === 'video' ? '' : hl_platform_icon($d['platform'])) . '</span>';
    }
    if ($d['kind'] === 'video') {
        $html .= '<span class="hl-play" aria-hidden="true"><span class="material-symbols-rounded">play_arrow</span></span>';
    }
    /* ป้ายแหล่งที่มาใส่ไว้เสมอ (ซ่อนเมื่อไม่มีลิงก์) — ตัวอย่างในหน้า admin จะได้สลับข้อความได้ทันทีที่วางลิงก์ */
    $html .= '<span class="hl-plat"' . ($plColor ? ' style="--pc:' . e($plColor) . ';"' : '') . ($d['kind'] === 'none' ? ' hidden' : '') . '>'
           . hl_platform_icon($d['platform']) . e($plName) . '</span>';
    $html .= '</' . $tag . '>';

    /* ── เนื้อหา ── */
    $html .= '<div class="hl-body"><div class="hl-tags">';
    if ($state === 'ended') {
        $html .= '<span class="hl-badge u-ended">สิ้นสุดแล้ว</span>';
    } else {
        $html .= '<span class="hl-badge ' . $urg[$u][1] . '">' . ($u === 'critical' ? '<i class="hl-pulse" aria-hidden="true"></i>' : '')
               . e($urg[$u][0]) . '</span>';
        if (is_new($date)) $html .= '<span class="badge gold hl-new">ใหม่</span>';
    }
    if (!empty($h['pinned']) && $state === 'live' && $variant !== 'grid') {
        $html .= '<span class="hl-pin" title="ปักหมุด"><span class="material-symbols-rounded">push_pin</span></span>';
    }
    $html .= '</div>';

    $title = e($h['title']);
    $html .= '<h3 class="hl-title">' . ($tag === 'a' && $variant !== 'feat' ? '<a ' . $attrs . '>' . $title . '</a>' : $title) . '</h3>';
    if (trim((string)$h['summary']) !== '' && $variant !== 'side') {
        $html .= '<p class="hl-summary">' . e($h['summary']) . '</p>';
    }
    $html .= '<div class="hl-meta"><span class="material-symbols-rounded">schedule</span>' . e(thai_date($date));
    if ($state === 'ended' && !empty($h['ends_at'])) $html .= ' · สิ้นสุด ' . e(thai_date($h['ends_at']));
    $html .= '</div>';

    if ($variant === 'feat') {
        $html .= '<div class="hl-actions">';
        if ($tag === 'a') {
            $icon = $d['kind'] === 'video' ? 'play_circle' : ($d['kind'] === 'none' ? 'zoom_in' : 'open_in_new');
            $html .= '<a class="btn primary" ' . $attrs . '><span class="material-symbols-rounded icon-sm">' . $icon . '</span>'
                   . e(hl_action_label($d)) . '</a>';
        }
        $html .= '<button type="button" class="btn" data-copy-link="' . e(hl_share_url($h)) . '">'
               . '<span class="material-symbols-rounded icon-sm">share</span>แชร์</button></div>';
    }
    return $html . '</div></article>';
}
