<?php
declare(strict_types=1);

/*
 * Minimal mail sender for the report email export (no external library).
 *  - Builds a MIME message: plain-text body + attachments (base64).
 *  - transport "smtp":   connects to the server in app\config\mail.php, upgrades
 *                        to TLS (STARTTLS on 587, or implicit TLS on 465),
 *                        logs in with AUTH LOGIN and sends.
 *  - transport "outbox": no server configured, so the message is saved as an
 *                        .eml file in storage\outbox (outside the web root);
 *                        double-click it to open in Outlook or Thunderbird.
 * The password lives only in mail.php, which is git-ignored.
 */

function mail_config(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $file = __DIR__ . '/../config/mail.php';
        $cfg = is_file($file) ? require $file : [];
        $cfg += ['transport' => 'outbox', 'from' => 'ctt-reports@localhost', 'from_name' => APP_NAME];
    }
    return $cfg;
}

/** Reject anything that is not one plain address (stops header injection via CR/LF). */
function mail_valid_address(string $a): bool
{
    return strlen($a) <= 254 && !preg_match('/[\r\n,;<>]/', $a) && filter_var($a, FILTER_VALIDATE_EMAIL) !== false;
}

/** RFC 2047 encoded header text, safe for any UTF-8. */
function mail_header_text(string $s): string
{
    return preg_match('/[^\x20-\x7E]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s;
}

/**
 * Build the full message. $attachments = [[filename, mime type, bytes], ...]
 * Returns [message-id, raw message with CRLF line endings].
 */
function mail_build(string $to, string $subject, string $body, array $attachments): array
{
    $c = mail_config();
    $domain = substr(strrchr($c['from'], '@') ?: '@localhost', 1);
    $msgId = '<' . bin2hex(random_bytes(12)) . '@' . $domain . '>';
    $boundary = '=_ctt_' . bin2hex(random_bytes(8));

    $h = [
        'Date: ' . date(DATE_RFC2822),
        'From: ' . mail_header_text($c['from_name']) . ' <' . $c['from'] . '>',
        'To: <' . $to . '>',
        'Subject: ' . mail_header_text($subject),
        'Message-ID: ' . $msgId,
        'MIME-Version: 1.0',
        'X-Mailer: COS20015 Phase-05 report export',
        'Content-Type: multipart/mixed; boundary="' . $boundary . '"',
    ];
    $parts = "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
           . chunk_split(base64_encode($body));
    foreach ($attachments as [$name, $type, $bytes]) {
        $parts .= "--$boundary\r\nContent-Type: $type; name=\"$name\"\r\nContent-Transfer-Encoding: base64\r\n"
                . "Content-Disposition: attachment; filename=\"$name\"\r\n\r\n" . chunk_split(base64_encode($bytes));
    }
    $parts .= "--$boundary--\r\n";
    return [$msgId, implode("\r\n", $h) . "\r\n\r\n" . $parts];
}

/** Send (or save) a built message. Returns a short description of what happened. */
function mail_deliver(string $to, string $raw): string
{
    $c = mail_config();
    if ($c['transport'] === 'smtp') {
        smtp_send($c, $to, $raw);
        return 'sent via ' . $c['host'];
    }
    $dir = config()['phase5_root'] . '/storage/outbox';
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create storage/outbox');
    }
    $file = $dir . '/' . date('Ymd-His') . '_' . preg_replace('/[^a-z0-9]+/i', '_', $to) . '.eml';
    file_put_contents($file, $raw);
    return 'saved to storage/outbox/' . basename($file) . ' (no SMTP server configured)';
}

/* ---------------------------------------------------------------- SMTP */

function smtp_send(array $c, string $to, string $raw): void
{
    $secure = $c['secure'] ?? 'tls';                       // 'tls' = STARTTLS, 'ssl' = implicit TLS
    $port = (int)($c['port'] ?? ($secure === 'ssl' ? 465 : 587));
    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
    $remote = ($secure === 'ssl' ? 'ssl://' : 'tcp://') . $c['host'] . ':' . $port;
    $s = @stream_socket_client($remote, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$s) {
        throw new RuntimeException("Cannot connect to $remote ($errstr)");
    }
    stream_set_timeout($s, 20);
    try {
        smtp_expect($s, 220);
        $ehlo = 'EHLO ' . (gethostname() ?: 'localhost');
        smtp_cmd($s, $ehlo, 250);
        if ($secure === 'tls') {
            smtp_cmd($s, 'STARTTLS', 220);
            if (!stream_socket_enable_crypto($s, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                throw new RuntimeException('TLS negotiation failed');
            }
            smtp_cmd($s, $ehlo, 250);
        }
        if (!empty($c['user'])) {
            smtp_cmd($s, 'AUTH LOGIN', 334);
            smtp_cmd($s, base64_encode($c['user']), 334, 'username');
            smtp_cmd($s, base64_encode($c['pass'] ?? ''), 235, 'password');
        }
        smtp_cmd($s, 'MAIL FROM:<' . $c['from'] . '>', 250);
        smtp_cmd($s, 'RCPT TO:<' . $to . '>', [250, 251]);
        smtp_cmd($s, 'DATA', 354);
        // Dot-stuffing: a line that starts with "." gets a second one (RFC 5321 4.5.2).
        $data = preg_replace('/^\./m', '..', $raw);
        smtp_cmd($s, rtrim($data, "\r\n") . "\r\n.", 250, 'message body');
        @fwrite($s, "QUIT\r\n");
    } finally {
        fclose($s);
    }
}

/** Send one command and check the reply code. $label hides secrets in error text. */
function smtp_cmd($s, string $line, int|array $expect, ?string $label = null): string
{
    fwrite($s, $line . "\r\n");
    return smtp_expect($s, $expect, $label ?? $line);
}

function smtp_expect($s, int|array $expect, string $after = 'connect'): string
{
    $reply = '';
    while (($l = fgets($s, 1024)) !== false) {
        $reply .= $l;
        if (strlen($l) < 4 || $l[3] === ' ') break;      // last line of a multi-line reply
    }
    $code = (int)substr($reply, 0, 3);
    if (!in_array($code, (array)$expect, true)) {
        throw new RuntimeException('SMTP server said "' . trim($reply ?: 'no reply') . '" after ' . $after);
    }
    return $reply;
}
