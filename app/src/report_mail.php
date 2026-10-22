<?php
declare(strict_types=1);

/*
 * One function builds every report email: the "Email CSV + PDF" button
 * (public/email_report.php) and the scheduled emails (src/automation.php).
 * So a scheduled email is exactly what a person would have sent by hand.
 *
 * Privacy rule, enforced here for both: the request must be pseudonymised
 * and the rows are read through app_reader, so MySQL itself guarantees that
 * no name or date of birth can leave the system by email.
 */
require_once __DIR__ . '/reports.php';
require_once __DIR__ . '/export.php';
require_once __DIR__ . '/mailer.php';

/**
 * @param array    $req    from report_request(..., identified: false)
 * @param array    $sender ['name' => ..., 'role' => ...] shown in the body and PDF
 * @param string[] $intro  extra lines at the top of the body (e.g. the schedule)
 * @return array   rows, summary, subject, msg_id, raw (the MIME message)
 */
function report_email_build(array $req, array $sender, string $to, array $intro = []): array
{
    if ($req['identified']) {
        throw new LogicException('Report emails must be built pseudonymised (identified = false).');
    }
    $def = $req['def'];
    $reader = db('User');                                  // app_reader: coded views only
    $rows = report_rows($req, $reader);
    $totals = $rows ? report_totals($req, $reader) : null;
    $summary = ($def['summary'])($rows, $req['f']);

    $lines = array_merge($intro ? array_merge($intro, ['']) : [], [
        $def['title'] . ' (' . APP_NAME . ')',
        '',
        $summary,
        '',
        'Filters:',
    ]);
    foreach (report_active_filters($req) as $label => $v) {
        $lines[] = "  $label: $v";
    }
    $lines = array_merge($lines, [
        '',
        'Sent by ' . $sender['name'] . ' (' . $sender['role'] . ') on ' . report_now()->format('D j M Y, H:i T') . '.',
        'Attached: the same view as CSV and PDF. Subjects are shown by code only; names are never emailed.',
        '',
        'Synthetic data for COS20015 coursework only. No real people.',
    ]);

    $attachments = [
        [export_filename($req, 'csv'), 'text/csv; charset=UTF-8', export_csv($req, $rows, $totals)],
        [export_filename($req, 'pdf'), 'application/pdf', export_pdf($req, $rows, $totals, $summary, $sender)],
    ];
    $subject = APP_NAME . ': ' . $def['title'] . ' (' . count($rows) . ' rows)';
    [$msgId, $raw] = mail_build($to, $subject, implode("\r\n", $lines), $attachments);
    return ['rows' => count($rows), 'summary' => $summary, 'subject' => $subject, 'msg_id' => $msgId, 'raw' => $raw];
}
