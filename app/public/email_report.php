<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/report_mail.php';   // also loads reports, export and mailer

/*
 * Email the current report view as CSV + PDF attachments (POST from report.php).
 *  - CSRF-checked, recipient validated as one plain address.
 *  - Built by report_email_build() (shared with the scheduled emails): whatever
 *    the sender's role, the email has NO names and its queries run as app_reader,
 *    so MySQL itself guarantees that no identifiable data leaves by email.
 *  - Limited to 5 emails per 10 minutes per session.
 *  - Every attempt (sent, saved or failed) is written to the audit log.
 */
$u = require_login();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('reports.php');
}
csrf_check();

$def = report_def(is_string($_POST['r'] ?? null) ? $_POST['r'] : '');
if ($def === null) {
    http_response_code(404);
    exit('Unknown report.');
}
$req = report_request($def, $_POST, false);           // never identified
$back = 'report.php?' . http_build_query(report_query($req));
$to = trim(is_string($_POST['to'] ?? null) ? $_POST['to'] : '');

if (!mail_valid_address($to)) {
    flash('error', 'Enter one valid email address.');
    redirect($back);
}
$recent = array_filter($_SESSION['emails_sent'] ?? [], fn($t) => $t > time() - 600);
if (count($recent) >= 5) {
    flash('error', 'Email limit reached (5 per 10 minutes). Please wait and try again.');
    redirect($back);
}

$mail = report_email_build($req, $u, $to);
$raw = $mail['raw'];
$detail = report_audit_detail($req, $mail['rows'], ['format' => 'email', 'to' => $to, 'message_id' => $mail['msg_id'],
                                                    'transport' => mail_config()['transport'], 'bytes' => strlen($raw)]);
try {
    $result = mail_deliver($to, $raw);
    $recent[] = time();
    $_SESSION['emails_sent'] = $recent;
    audit('EXPORT', 'report', $def['key'], $detail + ['result' => $result]);
    flash('ok', "Report emailed to $to: $result.");
} catch (Throwable $e) {
    audit('EXPORT', 'report', $def['key'], $detail + ['result' => 'failed: ' . $e->getMessage()]);
    flash('error', 'The email could not be sent: ' . $e->getMessage());
}
redirect($back);
