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

    $t = Browser::token($a->req('dashboard.php')['body']);
    $r = $a->req('logout.php', ['csrf' => $t]);
    check('Logout works', $r['code'] === 302 && $a->req('dashboard.php')['code'] === 302);

    echo "Database-level role check (app_reader account)\n";
    $reader = pdo_for($cfg, 'User');
    try { $reader->query('SELECT first_name FROM Subject LIMIT 1'); check('app_reader blocked from Subject table', false); }
    catch (PDOException $e) { check('app_reader blocked from Subject table (MySQL error ' . $e->errorInfo[1] . ')', $e->errorInfo[1] === 1142); }
    try { $reader->query('DELETE FROM AuditLog'); check('app_reader cannot delete audit rows', false); }
    catch (PDOException $e) { check('app_reader cannot delete audit rows', $e->errorInfo[1] === 1142); }
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
    echo "\nCleanup done: temporary users and their audit rows removed.\n";
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
