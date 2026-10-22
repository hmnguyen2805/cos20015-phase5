<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

/*
 * Login. Uses the app_auth MySQL account, which can only read AppUser,
 * stamp last_login_at and call sp_audit. Passwords are checked with
 * password_verify() against the bcrypt hash made by password_hash().
 */

const MAX_FAILS = 5;
const LOCK_SECONDS = 60;

if (current_user()) {
    redirect('dashboard.php');
}

$error = '';
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    $fails = $_SESSION['login_fails'] ?? 0;
    $lockedUntil = $_SESSION['locked_until'] ?? 0;

    if (time() < $lockedUntil) {
        $error = 'Too many failed attempts. Wait a minute and try again.';
    } else {
        $auth = db('auth');
        $st = $auth->prepare('SELECT user_id, username, display_name, password_hash, app_role, is_active
                              FROM AppUser WHERE username = ?');
        $st->execute([$username]);
        $row = $st->fetch();

        // Always run password_verify, even for an unknown username, so the response
        // time does not reveal which usernames exist.
        // (The dummy is a real bcrypt hash of a random value nobody knows.)
        $hash = $row['password_hash'] ?? '$2y$10$3QOlhTls4pS2pLhtzve8E.ewfGZE9PfhMPUPiVS1nAGTqqYyk60v.';
        $ok = password_verify($password, $hash) && $row && (int)$row['is_active'] === 1;

        if ($ok) {
            session_regenerate_id(true);           // new session id after login (prevents fixation)
            unset($_SESSION['login_fails'], $_SESSION['locked_until']);
            $_SESSION['user'] = [
                'id'   => (int)$row['user_id'],
                'name' => $row['display_name'],
                'username' => $row['username'],
                'role' => $row['app_role'],
            ];
            $auth->prepare('UPDATE AppUser SET last_login_at = NOW() WHERE user_id = ?')
                 ->execute([$row['user_id']]);
            audit('LOGIN', 'AppUser', (string)$row['user_id'], ['role' => $row['app_role']]);

            $next = (string)($_GET['next'] ?? '');
            // Only follow a local path, never another site.
            $safeNext = preg_match('#^/[^/\\\\]#', $next) ? $next : 'dashboard.php';
            redirect($safeNext);
        }

        $_SESSION['login_fails'] = ++$fails;
        if ($fails >= MAX_FAILS) {
            $_SESSION['locked_until'] = time() + LOCK_SECONDS;
            $_SESSION['login_fails'] = 0;
        }
        audit('LOGIN_FAILED', 'AppUser', $row ? (string)$row['user_id'] : null,
              ['username' => mb_substr($username, 0, 50), 'reason' => $row ? ((int)$row['is_active'] ? 'bad password' : 'inactive') : 'unknown user'],
              $row ? (int)$row['user_id'] : null, $auth);
        $error = 'Wrong username or password.';
    }
}

page_header('Sign in');
?>
<div class="card login">
  <p class="muted">Clinical trial subject tracking. Sign in with your app account.</p>
  <?php if ($error): ?><div class="flash flash-error"><?= h($error) ?></div><?php endif; ?>
  <form method="post">
    <?= csrf_field() ?>
    <label>Username <input name="username" value="<?= h($username) ?>" autocomplete="username" required autofocus></label>
    <label>Password <input name="password" type="password" autocomplete="current-password" required></label>
    <button class="btn">Sign in</button>
  </form>
</div>
<?php page_footer();
