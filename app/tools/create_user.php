<?php
declare(strict_types=1);

/*
 * Create (or reset the password of) an application user from the command line.
 * Needed once, to make the first Admin before anyone can log in.
 * Run through tools\create_user.ps1, which asks for the password without showing it
 * and passes it in the CTT_NEW_PASSWORD environment variable.
 *
 *   php create_user.php <username> <Admin|User> "<Display name>"
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$cfg = require __DIR__ . '/../config/config.php';
[$_, $username, $role, $display] = array_pad($argv, 4, null);
$password = getenv('CTT_NEW_PASSWORD') ?: '';

if (!$username || !preg_match('/^[a-z0-9._-]{3,50}$/', $username) || !in_array($role, ['Admin', 'User'], true) || !$display) {
    fwrite(STDERR, "Usage: php create_user.php <username> <Admin|User> \"<Display name>\"\n");
    exit(1);
}
if (mb_strlen($password) < 10) {
    fwrite(STDERR, "Password must be at least 10 characters (set CTT_NEW_PASSWORD).\n");
    exit(1);
}

$acc = $cfg['accounts']['Admin'];
$pdo = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $cfg['db']['host'], $cfg['db']['port'], $cfg['db']['name']),
               $acc['user'], $acc['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$hash = password_hash($password, PASSWORD_DEFAULT);
$st = $pdo->prepare('INSERT INTO AppUser (username, display_name, password_hash, app_role) VALUES (?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE display_name = VALUES(display_name), password_hash = VALUES(password_hash),
                                             app_role = VALUES(app_role), is_active = TRUE');
$st->execute([$username, $display, $hash, $role]);
$id = (int)$pdo->query('SELECT user_id FROM AppUser WHERE username = ' . $pdo->quote($username))->fetchColumn();

$pdo->prepare('CALL sp_audit(NULL, ?, ?, ?, ?)')->execute([
    $st->rowCount() === 1 ? 'INSERT' : 'UPDATE', 'AppUser', (string)$id,
    json_encode(['username' => $username, 'role' => $role, 'via' => 'command line'])]);

echo "OK: $username ($role) is ready to sign in.\n";
