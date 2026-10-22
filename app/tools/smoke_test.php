<?php
declare(strict_types=1);

/*
 * End-to-end smoke test of the web app, for both roles.
 * Creates two temporary users with random passwords, drives the pages over HTTP
 * (Apache must be running), then deletes the users and their audit rows.
 *
 *   C:\xampp\php\php.exe app\tools\smoke_test.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$BASE = 'http://localhost/ctt/';
$cfg = require __DIR__ . '/../config/config.php';
function pdo_for(array $cfg, string $acc): PDO {
    $a = $cfg['accounts'][$acc];
    return new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $cfg['db']['host'], $cfg['db']['port'], $cfg['db']['name']),
                   $a['user'], $a['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
}
$admin = pdo_for($cfg, 'Admin');

$pass = 0; $fail = 0;
function check(string $name, bool $ok, string $extra = ''): void {
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? '  PASS ' : '  FAIL ') . $name . ($ok || $extra === '' ? '' : "  [$extra]") . "\n";
}

/** Tiny HTTP client with one cookie jar per "browser". */
final class Browser {
    private string $jar;
    public function __construct(private string $base) { $this->jar = tempnam(sys_get_temp_dir(), 'ctt'); }
    public function req(string $path, ?array $post = null): array {
        $ch = curl_init($this->base . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
                                CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar]);
        if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
        $raw = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        return ['code' => $code, 'head' => substr($raw, 0, $hs), 'body' => substr($raw, $hs)];
    }
    public static function token(string $html): string {
        return preg_match('/name="csrf" value="([0-9a-f]{64})"/', $html, $m) ? $m[1] : '';
    }
    public function login(string $user, string $pw): array {
        $t = self::token($this->req('login.php')['body']);
        return $this->req('login.php', ['csrf' => $t, 'username' => $user, 'password' => $pw]);
    }
}

/* ---------- setup: temporary users ---------- */
$tmp = ['zz_test_admin' => 'Admin', 'zz_test_user' => 'User'];
$pw = [];
foreach ($tmp as $name => $role) {
    $pw[$name] = bin2hex(random_bytes(12));
    $admin->prepare('INSERT INTO AppUser (username, display_name, password_hash, app_role) VALUES (?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), is_active = TRUE')
          ->execute([$name, "Smoke test $role", password_hash($pw[$name], PASSWORD_DEFAULT), $role]);
}
$ids = $admin->query("SELECT user_id FROM AppUser WHERE username LIKE 'zz\\_test\\_%'")->fetchAll(PDO::FETCH_COLUMN);
$name1 = $admin->query("SELECT CONCAT(first_name, ' ', last_name) FROM Subject WHERE subject_id = 1")->fetchColumn();
$name23 = $admin->query("SELECT CONCAT(first_name, ' ', last_name) FROM Subject WHERE subject_id = 23")->fetchColumn();

// Report email: the test only checks delivery when no SMTP server is set up (outbox mode).
$mailFile = __DIR__ . '/../config/mail.php';
$mailTransport = is_file($mailFile) ? ((require $mailFile)['transport'] ?? 'outbox') : 'outbox';
$outbox = $cfg['phase5_root'] . '/storage/outbox';

/** Decode the attachment whose filename ends with $suffix from a saved .eml. */
function eml_attachment(string $raw, string $suffix): string {
    foreach (preg_split('/^--=_ctt_[0-9a-f]+(?:--)?\r?$/m', $raw) as $part) {
        if (preg_match('/filename="[^"]*' . preg_quote($suffix, '/') . '"\r?\n\r?\n(.*)$/s', $part, $m)) {
            return (string)base64_decode(preg_replace('/\s+/', '', $m[1]));
        }
    }
    return '';
}
$ecrfId = (int)$admin->query("SELECT ecrf_id FROM VisitECRF WHERE med_count > 0 ORDER BY ecrf_id LIMIT 1")->fetchColumn();
$origDoc = $admin->query("SELECT ecrf_doc FROM VisitECRF WHERE ecrf_id = $ecrfId")->fetchColumn();

try {
    echo "Anonymous\n";
    $b = new Browser($BASE);
    $r = $b->req('dashboard.php');
    check('dashboard redirects to login when not signed in', $r['code'] === 302 && str_contains($r['head'], 'login.php'));
    $r = $b->login('zz_test_user', 'wrong-password-123');
    check('wrong password is refused', str_contains($r['body'], 'Wrong username or password'));
    $r = $b->login('nobody_zz', 'whatever-123');
    check('unknown user gets the same message', str_contains($r['body'], 'Wrong username or password'));
    $r = $b->req('logout.php', ['csrf' => 'bad']);
    check('POST with a bad CSRF token is rejected (400)', $r['code'] === 400);

    echo "User role\n";
    $u = new Browser($BASE);
    $r = $u->login('zz_test_user', $pw['zz_test_user']);
    check('User can sign in', $r['code'] === 302);
    $r = $u->req('dashboard.php');
    check('User dashboard loads', $r['code'] === 200 && str_contains($r['body'], 'pseudonymised'));
    check('User dashboard has no audit panel', !str_contains($r['body'], 'Recent activity'));
    $r = $u->req('subjects.php');
    check('User subject list shows codes', $r['code'] === 200 && str_contains($r['body'], 'S030'));
    check('User subject list hides names', !str_contains($r['body'], $name1), $name1);
    $r = $u->req('subject.php?code=S001');
    check('User subject page loads without the name', $r['code'] === 200 && !str_contains($r['body'], $name1));
    $r = $u->req('ecrfs.php?med=Cetirizine');
    check('eCRF medicine filter (MEMBER OF) finds rows', $r['code'] === 200 && preg_match('/([1-9]\d*) eCRF\(s\)/', $r['body']) === 1);
    $r = $u->req("ecrf.php?id=$ecrfId");
    check('User eCRF page hides raw JSON', $r['code'] === 200 && !str_contains($r['body'], 'Raw JSON document'));
    $r = $u->req('consent_pdf.php?id=5');
    check('User cannot open consent PDFs (403)', $r['code'] === 403);
    $r = $u->req('admin_users.php');
    check('User cannot open user admin (403)', $r['code'] === 403);
    $r = $u->req('search.php?q=ALT&scope=consent');
    check('User search is limited to AE narratives', $r['code'] === 200 && str_contains($r['body'], 'Adverse event narratives') && !str_contains($r['body'], 'Consent documents ('));

    echo "Reports (User role)\n";
    $r = $u->req('reports.php');
    check('Reports index lists the three reports', $r['code'] === 200 && substr_count($r['body'], 'report.php?r=') >= 3);
    $r = $u->req('report.php?r=safety');
    check('Safety review = Phase-04 A UNION B (14 subjects, 2 in both)', str_contains($r['body'], '14 subject(s): 2 in both sets, 6 in A only, 6 in B only.'));
    check('Safety review hides names from Users', !str_contains($r['body'], $name23), $name23);
    $r = $u->req('report.php?r=safety&group=Both');
    check('Filter group=Both gives A INTERSECT B = S023, S025', str_contains($r['body'], '2 subject(s)') && str_contains($r['body'], 'S023') && str_contains($r['body'], 'S025'));
    $r = $u->req('report.php?r=safety&keyword=vomit');
    check('Narrative keyword filter (LIKE on free text)', str_contains($r['body'], '2 subject(s)') && str_contains($r['body'], 'S009') && str_contains($r['body'], 'S022'));
    $r = $u->req('report.php?' . http_build_query(['r' => 'safety', 'sort' => '1;DROP TABLE Visit', 'dir' => 'x', 'severity' => 'Fatal']));
    check('Report ignores unknown sort keys and filter values', $r['code'] === 200 && str_contains($r['body'], '14 subject(s)'));
    $r = $u->req('report.php?r=safety&alt_high=1&sort=peak_alt&dir=desc');
    check('Sort by peak ALT descending puts S023 (96 U/L) first',
          preg_match_all('/subject\.php\?code=(S\d{3})/', $r['body'], $m) && ($m[1][0] ?? '') === 'S023' && count($m[1]) === 6);
    $r = $u->req('report.php?r=sites');
    check('Site report totals row: 30 enrolled, 102 visits done', str_contains($r['body'], 'All sites')
          && preg_match('/class="totals">.*?<td class="num">30<\/td>\s*<td class="num">102<\/td>/s', $r['body']) === 1);
    $r = $u->req('report.php?r=sites&breakdown=month&site=' . urlencode('Northside Research Clinic'));
    check('Site report per month for one site', str_contains($r['body'], '6 row(s)') && str_contains($r['body'], '2026-03'));
    $r = $u->req('report.php?r=consent');
    check('Consent compliance: no gaps at the 11 May amendment', str_contains($r['body'], '30 subject(s): 0 with an issue, 16 compliant, 14 not required'));
    $r = $u->req('report.php?r=consent&amend=2026-04-01&status=gap');
    check('Consent what-if: amendment on 1 Apr gives 16 gaps', str_contains($r['body'], '16 subject(s): 16 with an issue'));
    check('Consent report shows PDF cross-check to Users (no PDF link)', str_contains($r['body'], 'OK (') && !str_contains($r['body'], 'consent_pdf.php'));

    $r = $u->req('export.php?r=safety&format=csv&group=Both');
    $lines = array_values(array_filter(explode("\n", $r['body'])));
    check('CSV export: text/csv, BOM, header + 2 rows', str_contains($r['head'], 'text/csv') && str_starts_with($r['body'], "\xEF\xBB\xBF")
          && count($lines) === 3 && str_starts_with($lines[1], 'S023'));
    check('CSV export by a User has no names', !str_contains($r['body'], $name23));
    $r = $u->req('export.php?r=consent&format=pdf');
    check('PDF export: application/pdf', $r['code'] === 200 && str_starts_with($r['body'], '%PDF') && str_contains($r['head'], 'application/pdf'));
    $r = $u->req('export.php?r=nope&format=csv');
    check('Unknown report export is 404', $r['code'] === 404);

    $t = Browser::token($u->req('report.php?r=safety')['body']);
    $r = $u->req('email_report.php', ['csrf' => 'bad', 'r' => 'safety', 'to' => 'zz-smoke@example.com']);
    check('Email without a valid CSRF token is rejected (400)', $r['code'] === 400);
    $r = $u->req('email_report.php', ['csrf' => $t, 'r' => 'safety', 'to' => "zz@example.com\r\nBcc: x@example.com"]);
    check('Email refuses a header-injection address', $r['code'] === 302 && str_contains($u->req('report.php?r=safety')['body'], 'Enter one valid email address'));
    if ($mailTransport === 'outbox') {
        $before = glob($outbox . '/*zz_smoke*.eml') ?: [];
        $r = $u->req('email_report.php', ['csrf' => $t, 'r' => 'safety', 'group' => 'Both', 'to' => 'zz-smoke@example.com']);
        $after = array_values(array_diff(glob($outbox . '/*zz_smoke*.eml') ?: [], $before));
        $csv = $after ? eml_attachment((string)file_get_contents($after[0]), '.csv') : '';
        check('Email (outbox) saves an .eml with the filtered CSV attached', $r['code'] === 302 && count($after) === 1
              && str_contains($csv, 'S023') && str_contains($csv, 'S025'));
    } else {
        echo "  SKIP email delivery test (mail.php uses SMTP; the test never sends real email)\n";
    }

    echo "Admin role\n";
    $a = new Browser($BASE);
    $r = $a->login('zz_test_admin', $pw['zz_test_admin']);
    check('Admin can sign in', $r['code'] === 302);
    $r = $a->req('dashboard.php');
    check('Admin dashboard shows recent activity', $r['code'] === 200 && str_contains($r['body'], 'Recent activity'));
    $r = $a->req('subjects.php?sort=aes&dir=desc');
    check('Admin subject list shows names', $r['code'] === 200 && str_contains($r['body'], $name1));
    $r = $a->req('subjects.php?' . http_build_query(['sort' => 'x;DROP TABLE Subject', 'dir' => 'up']));
    check('Unknown sort key falls back safely', $r['code'] === 200 && str_contains($r['body'], 'S001'));
    $r = $a->req('consent_pdf.php?id=5');
    check('Admin opens a consent PDF after the SHA-256 check', $r['code'] === 200 && str_starts_with($r['body'], '%PDF') && str_contains($r['head'], 'application/pdf'));
    $r = $a->req('search.php?q=liver&scope=consent');
    check('Full-text search of consent PDF text', $r['code'] === 200 && preg_match('/Consent documents \(([1-9]\d*)\)/', $r['body']) === 1);
    $r = $a->req('search.php?q=ALT&scope=all');
    check('Full-text search of AE narratives', preg_match('/Adverse event narratives \(([1-9]\d*)\)/', $r['body']) === 1);

    // eCRF edits: MySQL is the one that validates.
    $page = $a->req("ecrf.php?id=$ecrfId");
    check('Admin sees raw JSON and the editor', str_contains($page['body'], 'Raw JSON document') && str_contains($page['body'], 'Edit eCRF'));
    $t = Browser::token($page['body']);
    $doc = json_decode($origDoc, true);

    $bad = $doc; $bad['vitals']['heartRate'] = 400;
    $r = $a->req("ecrf.php?id=$ecrfId", ['csrf' => $t, 'doc' => json_encode($bad)]);
    check('Schema CHECK rejects heart rate 400, report names the rule', str_contains($r['body'], 'MySQL rejected') && str_contains($r['body'], 'maximum'));

    $bad = $doc; $bad['subjectId'] = $doc['subjectId'] + 1;
    $r = $a->req("ecrf.php?id=$ecrfId", ['csrf' => $t, 'doc' => json_encode($bad)]);
    check('Trigger rejects a subjectId that does not match the visit', str_contains($r['body'], 'does not match the Visit row'));

    $r = $a->req("ecrf.php?id=$ecrfId", ['csrf' => $t, 'doc' => '{"subjectId": 1,']);
    check('Broken JSON is caught before the database', str_contains($r['body'], 'Not valid JSON'));

    $good = $doc; $good['investigatorComment'] = 'Smoke test edit';
    $r = $a->req("ecrf.php?id=$ecrfId", ['csrf' => $t, 'doc' => json_encode($good)]);
    $after = $admin->query("SELECT ecrf_doc->>'$.investigatorComment' FROM VisitECRF WHERE ecrf_id = $ecrfId")->fetchColumn();
    check('A valid edit is saved', $r['code'] === 302 && $after === 'Smoke test edit');
    $r = $a->req("ecrf.php?id=$ecrfId", ['csrf' => $t, 'doc' => $origDoc]);
    $restored = (int)$admin->query("SELECT ecrf_doc = CAST(" . $admin->quote($origDoc) . " AS JSON) FROM VisitECRF WHERE ecrf_id = $ecrfId")->fetchColumn();
    check('Original eCRF restored', $restored === 1);

    $r = $a->req('admin_audit.php?action=VIEW_DOC');
    check('Audit log recorded the PDF view', str_contains($r['body'], 'ConsentDocument'));
    $n = (int)$admin->query("SELECT COUNT(*) FROM AuditLog WHERE action = 'UPDATE' AND table_name = 'VisitECRF' AND record_id = '$ecrfId'")->fetchColumn();
    check('Audit log has old/new rows for the two eCRF edits', $n === 2, "found $n");

    echo "Reports (Admin role)\n";
    $r = $a->req('report.php?r=safety&group=Both');
    check('Admin safety review shows names', str_contains($r['body'], $name23));
    $r = $a->req('report.php?r=consent');
    check('Admin consent report links the signed PDFs', str_contains($r['body'], 'consent_pdf.php?id='));
    $r = $a->req('export.php?r=safety&format=csv&group=Both');
    check('Admin CSV export includes names (same as the screen)', str_contains($r['body'], $name23));
    if ($mailTransport === 'outbox') {
        $t = Browser::token($a->req('report.php?r=safety')['body']);
        $before = glob($outbox . '/*zz_smoke*.eml') ?: [];
        $a->req('email_report.php', ['csrf' => $t, 'r' => 'safety', 'group' => 'Both', 'to' => 'zz-smoke@example.com']);
        $after = array_values(array_diff(glob($outbox . '/*zz_smoke*.eml') ?: [], $before));
        $csv = $after ? eml_attachment((string)file_get_contents($after[0]), '.csv') : '';
        check('Admin email still has codes only (built as app_reader)', str_contains($csv, 'S023') && !str_contains($csv, $name23));
    }
    $ids2 = implode(',', array_map('intval', $ids));
    $runs = (int)$admin->query("SELECT COUNT(*) FROM AuditLog WHERE action = 'REPORT_RUN' AND user_id IN ($ids2)")->fetchColumn();
    $exp  = $admin->query("SELECT GROUP_CONCAT(DISTINCT detail->>'$.format' ORDER BY detail->>'$.format')
                           FROM AuditLog WHERE action = 'EXPORT' AND user_id IN ($ids2)")->fetchColumn();
    check('Audit log has REPORT_RUN rows and EXPORT rows per format', $runs >= 10
          && $exp === ($mailTransport === 'outbox' ? 'csv,email,pdf' : 'csv,pdf'), "runs=$runs formats=$exp");

    $t = Browser::token($a->req('dashboard.php')['body']);
    $r = $a->req('logout.php', ['csrf' => $t]);
    check('Logout works', $r['code'] === 302 && $a->req('dashboard.php')['code'] === 302);

    echo "Database-level role check (app_reader account)\n";
    $reader = pdo_for($cfg, 'User');
    try { $reader->query('SELECT first_name FROM Subject LIMIT 1'); check('app_reader blocked from Subject table', false); }
    catch (PDOException $e) { check('app_reader blocked from Subject table (MySQL error ' . $e->errorInfo[1] . ')', $e->errorInfo[1] === 1142); }
    try { $reader->query('DELETE FROM AuditLog'); check('app_reader cannot delete audit rows', false); }
    catch (PDOException $e) { check('app_reader cannot delete audit rows', $e->errorInfo[1] === 1142); }
    $n = (int)$reader->query('SELECT COUNT(*) FROM v_dose_coded')->fetchColumn();
    check('app_reader can read the report view v_dose_coded (60 rows)', $n === 60, "found $n");
    $n = (int)$reader->query('SELECT SUM(doc_version = version AND doc_subject_code = subject_code AND doc_signed_date = signed_date)
                              FROM v_consent_doc_check')->fetchColumn();
    check('All 34 consent PDFs agree with their records (v_consent_doc_check)', $n === 34, "found $n");
    try { $reader->query('SELECT extracted_text FROM ConsentDocument LIMIT 1'); check('app_reader blocked from consent PDF text', false); }
    catch (PDOException $e) { check('app_reader blocked from consent PDF text (MySQL error ' . $e->errorInfo[1] . ')', $e->errorInfo[1] === 1142); }
} finally {
    /* ---------- cleanup ---------- */
    $admin->prepare('UPDATE VisitECRF SET ecrf_doc = ? WHERE ecrf_id = ?')->execute([$origDoc, $ecrfId]);
    if ($ids) {
        $in = implode(',', array_map('intval', $ids));
        $admin->exec("DELETE FROM AuditLog WHERE user_id IN ($in)
                      OR (action = 'LOGIN_FAILED' AND detail->>'$.username' IN ('zz_test_user', 'nobody_zz'))
                      OR (table_name = 'AppUser' AND record_id IN ($in))");
        $admin->exec("DELETE FROM AppUser WHERE user_id IN ($in)");
    }
    foreach (glob($outbox . '/*zz_smoke*.eml') ?: [] as $f) {
        unlink($f);
    }
    echo "\nCleanup done: temporary users and their audit rows removed.\n";
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
