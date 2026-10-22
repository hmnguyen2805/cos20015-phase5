<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

/*
 * Serves one consent PDF to an Admin.
 * - The PDFs live in phase5\storage, outside the web root, so Apache cannot
 *   serve them directly: every download goes through this role check.
 * - The file's SHA-256 is compared with the value stored when it was loaded.
 *   If someone replaced or edited the file on disk, it is refused.
 * - Every view is written to the audit log (action VIEW_DOC).
 */
$u = require_admin();
$id = (int)get_str('id');

$doc = q('SELECT d.file_path, d.sha256, d.mime_type, ic.subject_id, ic.version
          FROM ConsentDocument d JOIN InformedConsent ic ON ic.consent_id = d.consent_id
          WHERE d.consent_id = ?', [$id])[0] ?? null;

$storage = realpath(config()['phase5_root'] . '/storage');
$path = $doc ? realpath($storage . '/' . $doc['file_path']) : false;

// realpath + prefix check stops "../" tricks reaching files outside storage\.
if (!$doc || $path === false || !str_starts_with($path, $storage . DIRECTORY_SEPARATOR)) {
    http_response_code(404);
    exit('Consent document not found.');
}

$actual = hash_file('sha256', $path);
if (!hash_equals((string)$doc['sha256'], $actual)) {
    audit('VIEW_DOC', 'ConsentDocument', (string)$id,
          ['result' => 'blocked: checksum mismatch', 'expected' => $doc['sha256'], 'actual' => $actual]);
    http_response_code(409);
    page_header('Document integrity check failed');
    echo '<div class="card"><div class="flash flash-error">The file on disk does not match the fingerprint '
       . 'recorded when it was loaded, so it was not opened. This has been logged.</div></div>';
    page_footer();
    exit;
}

audit('VIEW_DOC', 'ConsentDocument', (string)$id,
      ['subject' => subject_code((int)$doc['subject_id']), 'version' => $doc['version'], 'result' => 'served']);

// InformedConsent.version already holds the "v" (e.g. "v2.0").
$name = subject_code((int)$doc['subject_id']) . '_consent_' . $doc['version'] . '.pdf';
header('Content-Type: ' . $doc['mime_type']);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="' . $name . '"');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
readfile($path);
