<?php
/**
 * totp.php — ยืนยันตัวตนสองชั้น (2FA) แบบ TOTP ตามมาตรฐาน RFC 6238
 *   ใช้กับแอป Google Authenticator / Microsoft Authenticator / Authy
 *
 * เก็บ secret เป็น Base32 (RFC 4648) ในคอลัมน์ users.totp_secret
 * รหัส backup เก็บเป็น hash (SHA-256) ใน users.backup_codes (JSON)
 *
 * ไม่พึ่งไลบรารีภายนอก — ใช้ hash_hmac('sha1', ...) ของ PHP
 */
if (!defined('APP_ROOT')) exit('Forbidden');

const TOTP_PERIOD = 30;   /* วินาทีต่อรอบ */
const TOTP_DIGITS = 6;    /* จำนวนหลัก */
const TOTP_WINDOW = 1;    /* ยอมรับ ±1 ช่วง (กันนาฬิกาคลาด) */

/* ───────── Base32 (RFC 4648, ไม่มี padding ในการใช้งาน) ───────── */

function totp_base32_encode(string $bytes): string {
    if ($bytes === '') return '';
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($bytes) as $c) {
        $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 5) as $chunk) {
        $out .= $alphabet[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
    }
    return $out;
}

function totp_base32_decode(string $b32): string {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $b32 = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $b32));
    if ($b32 === '') return '';
    $bits = '';
    $len = strlen($b32);
    for ($i = 0; $i < $len; $i++) {
        $v = strpos($alphabet, $b32[$i]);
        if ($v === false) continue;
        $bits .= str_pad(decbin($v), 5, '0', STR_PAD_LEFT);
    }
    $bytes = '';
    foreach (str_split($bits, 8) as $chunk) {
        if (strlen($chunk) === 8) $bytes .= chr(bindec($chunk));
    }
    return $bytes;
}

/** สร้าง secret ใหม่แบบสุ่ม (160 บิต = 32 อักขระ Base32) */
function totp_random_secret(): string {
    return totp_base32_encode(random_bytes(20));
}

/* ───────── สร้าง/ตรวจรหัส ───────── */

/** รหัส TOTP สำหรับ counter ที่กำหนด (HOTP) — คืนสตริงเลข TOTP_DIGITS หลัก */
function totp_code_at(string $b32secret, int $counter, int $digits = TOTP_DIGITS): string {
    $key = totp_base32_decode($b32secret);
    if ($key === '') return str_repeat('0', $digits);
    /* counter → 8 ไบต์ big-endian */
    $bin = pack('N*', 0, $counter);           /* 8 ไบต์: 4 สูง (0) + 4 ต่ำ */
    $hash = hash_hmac('sha1', $bin, $key, true);
    $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
    $part = (ord($hash[$offset]) & 0x7F) << 24
          | (ord($hash[$offset + 1]) & 0xFF) << 16
          | (ord($hash[$offset + 2]) & 0xFF) << 8
          | (ord($hash[$offset + 3]) & 0xFF);
    $code = $part % (10 ** $digits);
    return str_pad((string)$code, $digits, '0', STR_PAD_LEFT);
}

/** รหัส TOTP ปัจจุบัน (ตามเวลา) */
function totp_now(string $b32secret, ?int $ts = null): string {
    $ts = $ts ?? time();
    return totp_code_at($b32secret, intdiv($ts, TOTP_PERIOD));
}

/** ตรวจรหัสที่ผู้ใช้กรอก — ยอมรับ ±window ช่วงเวลา (กันนาฬิกาคลาด) */
function totp_verify(string $b32secret, string $code, int $window = TOTP_WINDOW, ?int $ts = null): bool {
    $code = preg_replace('/\D/', '', $code);
    if (strlen($code) !== TOTP_DIGITS) return false;
    $ts = $ts ?? time();
    $cur = intdiv($ts, TOTP_PERIOD);
    for ($i = -$window; $i <= $window; $i++) {
        if (hash_equals(totp_code_at($b32secret, $cur + $i), $code)) return true;
    }
    return false;
}

/** URI สำหรับสร้าง QR (otpauth://) ให้แอป Authenticator สแกน */
function totp_uri(string $b32secret, string $account, string $issuer): string {
    return 'otpauth://totp/' . rawurlencode($issuer . ':' . $account)
         . '?secret=' . $b32secret
         . '&issuer=' . rawurlencode($issuer)
         . '&algorithm=SHA1&digits=' . TOTP_DIGITS . '&period=' . TOTP_PERIOD;
}

/** จัดรูป secret เป็นกลุ่มละ 4 ตัว อ่าน/พิมพ์ง่าย */
function totp_format_secret(string $b32secret): string {
    return trim(chunk_split($b32secret, 4, ' '));
}

/* ───────── รหัสสำรอง (Backup codes) ───────── */

/** สร้างรหัสสำรอง n ชุด — คืน ['plain'=>[...โชว์ครั้งเดียว], 'hashes'=>[...เก็บลง DB]] */
function totp_generate_backup_codes(int $n = 10): array {
    $plain = [];
    $hashes = [];
    for ($i = 0; $i < $n; $i++) {
        $c = strtoupper(bin2hex(random_bytes(5)));          /* 10 อักขระ hex */
        $fmt = substr($c, 0, 5) . '-' . substr($c, 5, 5);   /* เช่น A1B2C-3D4E5 */
        $plain[] = $fmt;
        $hashes[] = hash('sha256', $c);                     /* เก็บเฉพาะ hash */
    }
    return ['plain' => $plain, 'hashes' => $hashes];
}

/** ตรวจรหัสสำรอง — ถ้าถูก คืน index ที่ใช้ (เพื่อลบทิ้ง) ไม่ถูกคืน -1 */
function totp_check_backup_code(string $input, array $hashes): int {
    $c = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', $input));
    if (strlen($c) !== 10) return -1;
    $h = hash('sha256', $c);
    foreach ($hashes as $i => $stored) {
        if (hash_equals((string)$stored, $h)) return $i;
    }
    return -1;
}
