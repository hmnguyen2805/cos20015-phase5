<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/reports.php';
require __DIR__ . '/../src/export.php';

/*
 * Download a report as CSV or PDF: export.php?r=safety&format=csv&<filters>.
 * The filters and sort are validated by report_request() exactly as on the
 * report page, and the export follows the screen's privacy level: an Admin
 * export includes names (and says so on the PDF), a User export never does.
 */
$u = require_login();
$def = report_def(get_str('r'));
$format = get_str('format');
if ($def === null || !in_array($format, ['csv', 'pdf'], true)) {
    http_response_code(404);
    exit('Unknown report or format.');
}

$req = report_request($def, $_GET, is_admin());
$rows = report_rows($req);
$totals = $rows ? report_totals($req) : null;

if ($format === 'csv') {
    $body = export_csv($req, $rows, $totals);
    $type = 'text/csv; charset=utf-8';
    $disposition = 'attachment';
} else {
    $body = export_pdf($req, $rows, $totals, ($def['summary'])($rows, $req['f']), $u);
    $type = 'application/pdf';
    $disposition = 'inline';
}

audit('EXPORT', 'report', $def['key'],
      report_audit_detail($req, count($rows), ['format' => $format, 'bytes' => strlen($body)]));

$name = export_filename($req, $format);
header('Content-Type: ' . $type);
header('Content-Length: ' . strlen($body));
header('Content-Disposition: ' . $disposition . '; filename="' . $name . '"');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
echo $body;
