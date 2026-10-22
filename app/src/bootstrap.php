<?php
declare(strict_types=1);

/*
 * COS20015 Phase-05 -- shared bootstrap, included first by every page.
 * Loads config, starts a hardened session and provides the helpers:
 * one PDO connection per MySQL account, escaping, CSRF, flash messages,
 * login/role guards and the audit trail.
 */

const APP_NAME = 'Clinical Trial Tracker';
const SESSION_IDLE_SECONDS = 1800;   // 30 minutes

function config(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $cfg = require __DIR__ . '/../config/config.php';
    }
    return $cfg;
}

/* ---------- session ---------- */
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_name('ctt_session');
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Strict']);
session_start();

if (isset($_SESSION['user'], $_SESSION['last_seen'])
    && time() - $_SESSION['last_seen'] > SESSION_IDLE_SECONDS) {
    $_SESSION = [];
    session_regenerate_id(true);
    $_SESSION['flash'][] = ['info', 'You were logged out after 30 minutes of inactivity.'];
}
$_SESSION['last_seen'] = time();

/* ---------- database: one connection per MySQL account ----------
 * 'auth'  -> app_auth   (login screen only)
 * 'Admin' -> app_admin  (role ctt_admin)
 * 'User'  -> app_reader (role ctt_reader: the six coded views only)
 * The account is chosen from the logged-in user's app role, so MySQL
 * enforces the same boundary the PHP code does (defence in depth). */
function db(string $account): PDO
{
    static $pool = [];
    if (!isset($pool[$account])) {
        $c = config();
        $acc = $c['accounts'][$account] ?? throw new RuntimeException("Unknown DB account: $account");
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $c['db']['host'], $c['db']['port'], $c['db']['name']);
        $pool[$account] = new PDO($dsn, $acc['user'], $acc['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,   // real server-side prepared statements
        ]);
    }
    return $pool[$account];
}

/** Connection for the current session: app_admin for Admins, app_reader for Users. */
function db_session(): PDO
{
    $u = current_user() ?? throw new RuntimeException('Not logged in');
    return db($u['role']);
}

/** Run a prepared query and return all rows. */
function q(string $sql, array $params = [], ?PDO $pdo = null): array
{
    $st = ($pdo ?? db_session())->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

/** Run a prepared query and return the first column of the first row. */
function q1(string $sql, array $params = [], ?PDO $pdo = null): mixed
{
    $st = ($pdo ?? db_session())->prepare($sql);
    $st->execute($params);
    $v = $st->fetchColumn();
    return $v === false ? null : $v;
}

/* ---------- small helpers ---------- */
function h(mixed $s): string
{
    return htmlspecialchars((string)($s ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function subject_code(int $id): string
{
    return sprintf('S%03d', $id);
}

/** "S007" -> 7, anything else -> null (codes are the only subject key the UI uses). */
function code_to_id(?string $code): ?int
{
    return ($code !== null && preg_match('/^S(\d{3})$/', $code, $m)) ? (int)$m[1] : null;
}

function get_str(string $name, string $default = ''): string
{
    $v = $_GET[$name] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

function flash(string $type, string $msg): void
{
    $_SESSION['flash'][] = [$type, $msg];
}

function take_flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

/* ---------- CSRF: every POST form carries a per-session token ---------- */
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
}

function csrf_check(): void
{
    $sent = $_POST['csrf'] ?? '';
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        http_response_code(400);
        exit('Invalid or expired form. Go back, reload the page and try again.');
    }
}

/* ---------- authentication and role guards ---------- */
function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function is_admin(): bool
{
    return (current_user()['role'] ?? '') === 'Admin';
}

function require_login(): array
{
    $u = current_user();
    if ($u === null) {
        redirect('login.php?next=' . urlencode($_SERVER['REQUEST_URI'] ?? ''));
    }
    return $u;
}

function require_admin(): array
{
    $u = require_login();
    if ($u['role'] !== 'Admin') {
        http_response_code(403);
        require_once __DIR__ . '/layout.php';
        page_header('Access denied');
        echo '<div class="card"><h2>Admins only</h2><p>Your role (User) can see pseudonymised data only. '
           . 'This page shows identifiable data or changes records.</p>'
           . '<p><a href="dashboard.php">Back to the dashboard</a></p></div>';
        page_footer();
        exit;
    }
    return $u;
}

/* ---------- audit trail ----------
 * Every audit row is written through the stored procedure sp_audit
 * (SQL SECURITY DEFINER), so neither app account can read or alter old rows
 * except app_admin, which reads them on the Audit log page. */
function audit(string $action, ?string $table = null, ?string $recordId = null,
               array $detail = [], ?int $userId = null, ?PDO $pdo = null): void
{
    $u = current_user();
    $pdo ??= $u ? db_session() : db('auth');
    $detail['ip'] = $_SERVER['REMOTE_ADDR'] ?? 'cli';
    $st = $pdo->prepare('CALL sp_audit(?, ?, ?, ?, ?)');
    $st->execute([$userId ?? ($u['id'] ?? null), $action, $table, $recordId,
                  json_encode($detail, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
    $st->closeCursor();
}

require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/table.php';
