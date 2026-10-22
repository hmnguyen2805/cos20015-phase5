<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/reports.php';
require __DIR__ . '/../src/export.php';
require __DIR__ . '/../src/mailer.php';

/*
 * Email the current report view as CSV + PDF attachments (POST from report.php).
 *  - CSRF-checked, recipient validated as one plain address.
 *  - Whatever the sender's role, the email is built WITHOUT names and its
 *    queries run as app_reader, so MySQL itself guarantees that no identifiable
 *    data leaves the system by email.
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

$reader = db('User');                                  // app_reader: coded views only
$rows = report_rows($req, $reader);
$totals = $rows ? report_totals($req, $reader) : null;
$summary = ($def['summary'])($rows, $req['f']);

$lines = [
    $def['title'] . ' (' . APP_NAME . ')',
    '',
    $summary,
    '',
    'Filters:',
];
foreach (report_active_filters($req) as $label => $v) {
    $lines[] = "  $label: $v";
}
$lines = array_merge($lines, [
    '',
    'Sent by ' . $u['name'] . ' (' . $u['role'] . ') on ' . report_now()->format('D j M Y, H:i T') . '.',
    'Attached: the same view as CSV and PDF. Subjects are shown by code only; names are never emailed.',
    '',
    'Synthetic data for COS20015 coursework only. No real people.',
]);

$attachments = [
    [export_filename($req, 'csv'), 'text/csv; charset=UTF-8', export_csv($req, $rows, $totals)],
    [export_filename($req, 'pdf'), 'application/pdf', export_pdf($req, $rows, $totals, $summary, $u)],
];
[$msgId, $raw] = mail_build($to, APP_NAME . ': ' . $def['title'] . ' (' . count($rows) . ' rows)',
                            implode("\r\n", $lines), $attachments);

$detail = report_audit_detail($req, count($rows), ['format' => 'email', 'to' => $to, 'message_id' => $msgId,
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
