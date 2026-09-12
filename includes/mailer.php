<?php
/**
 * mailer.php — ส่งอีเมลผ่าน SMTP (ไม่พึ่ง library ภายนอก)
 * รองรับ STARTTLS (พอร์ต 587) และ SSL/TLS ตรง (พอร์ต 465)
 * ตั้งค่าผ่าน settings: smtp_host, smtp_port, smtp_user, smtp_pass, smtp_secure, smtp_from, smtp_from_name
 */
if (!defined('APP_ROOT')) exit('Forbidden');

/**
 * ส่งอีเมล
 * @param string $to      ผู้รับ
 * @param string $subject หัวข้อ
 * @param string $body    เนื้อหา (ข้อความธรรมดา)
 * @param string &$error  ข้อความ error (ถ้าส่งไม่สำเร็จ)
 * @return bool
 */
function send_mail(string $to, string $subject, string $body, string &$error = ''): bool {
    $host   = setting('smtp_host');
    $port   = (int)setting('smtp_port', '587');
    $user   = setting('smtp_user');
    $pass   = setting('smtp_pass');
    $secure = setting('smtp_secure', 'tls');                  // none | tls | ssl
    $from   = setting('smtp_from', $user);
    $fname  = setting('smtp_from_name', setting('site_name', 'ระบบเว็บไซต์'));

    if ($host === '' || $from === '') { $error = 'ยังไม่ได้ตั้งค่า SMTP (host/ผู้ส่ง)'; return false; }

    $transport = ($secure === 'ssl') ? 'ssl://' . $host : $host;
    $ctx = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
    $fp = @stream_socket_client($transport . ':' . $port, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) { $error = "เชื่อมต่อ SMTP ไม่ได้: $errstr ($errno)"; return false; }
    stream_set_timeout($fp, 15);

    $read = function () use ($fp) {
        $data = '';
        while ($line = fgets($fp, 515)) {
            $data .= $line;
            if (isset($line[3]) && $line[3] === ' ') break;   // บรรทัดสุดท้ายของ response
        }
        return $data;
    };
    $cmd = function (string $c) use ($fp, $read) { fwrite($fp, $c . "\r\n"); return $read(); };
    $code = fn($r) => (int)substr((string)$r, 0, 3);

    $ok = true; $error = '';
    $expect = function ($resp, array $codes) use (&$ok, &$error, $code) {
        if (!in_array($code($resp), $codes, true)) { $ok = false; if ($error === '') $error = 'SMTP: ' . trim((string)$resp); }
        return $ok;
    };

    $expect($read(), [220]);
    $ehlo = 'EHLO ' . ($_SERVER['HTTP_HOST'] ?? 'localhost');

    if ($ok && $secure === 'tls') {
        $cmd($ehlo);
        $expect($cmd('STARTTLS'), [220]);
        if ($ok && !@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT)) {
            $ok = false; $error = 'เปิด TLS ไม่สำเร็จ';
        }
    }
    if ($ok) $expect($cmd($ehlo), [250]);

    /* AUTH LOGIN (ถ้ามี user/pass) */
    if ($ok && $user !== '') {
        $expect($cmd('AUTH LOGIN'), [334]);
        if ($ok) $expect($cmd(base64_encode($user)), [334]);
        if ($ok) $expect($cmd(base64_encode($pass)), [235]);
        if (!$ok && $error === '') $error = 'ยืนยันตัวตน SMTP ไม่ผ่าน (user/pass)';
    }

    if ($ok) $expect($cmd('MAIL FROM:<' . $from . '>'), [250]);
    if ($ok) $expect($cmd('RCPT TO:<' . $to . '>'), [250, 251]);
    if ($ok) $expect($cmd('DATA'), [354]);

    if ($ok) {
        $headers  = 'From: =?UTF-8?B?' . base64_encode($fname) . "?= <$from>\r\n";
        $headers .= 'To: <' . $to . ">\r\n";
        $headers .= 'Subject: =?UTF-8?B?' . base64_encode($subject) . "?=\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $headers .= "Content-Transfer-Encoding: base64\r\n";
        $headers .= 'Date: ' . date('r') . "\r\n";
        $data = $headers . "\r\n" . chunk_split(base64_encode($body));
        $data = preg_replace('/^\./m', '..', $data);          // escape บรรทัดที่ขึ้นต้นด้วยจุด
        $expect($cmd($data . "\r\n."), [250]);
    }

    $cmd('QUIT');
    fclose($fp);
    return $ok;
}

/** ส่งแจ้งเตือนเรื่องร้องเรียนใหม่ (เรียกหลังบันทึก complaint) — เงียบถ้าปิด/ตั้งค่าไม่ครบ */
function notify_new_complaint(string $ref_code, string $subject): void {
    if (setting('notify_enabled', '0') !== '1') return;
    $to = setting('notify_email');
    if ($to === '') return;
    require_once APP_ROOT . '/includes/mailer.php';
    $site = setting('site_name', 'เว็บไซต์หน่วยงาน');
    $body = "มีเรื่องร้องเรียนใหม่เข้ามาที่เว็บไซต์ $site\n\n"
          . "เลขรับเรื่อง: $ref_code\n"
          . "เรื่อง: $subject\n"
          . "เวลา: " . date('d/m/Y H:i') . " น.\n\n"
          . "เข้าระบบจัดการเพื่อดูรายละเอียดและตอบกลับ";
    $err = '';
    @send_mail($to, "[$site] เรื่องร้องเรียนใหม่ $ref_code", $body, $err);
}
