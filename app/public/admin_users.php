<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
$u = require_admin();
$pdo = db('Admin');

const MIN_PASSWORD = 10;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = (string)($_POST['do'] ?? '');
    try {
        if ($do === 'create') {
            $username = trim((string)($_POST['username'] ?? ''));
            $display = trim((string)($_POST['display_name'] ?? ''));
            $role = (string)($_POST['app_role'] ?? '');
            $pw = (string)($_POST['password'] ?? '');
            if (!preg_match('/^[a-z0-9._-]{3,50}$/', $username)) {
                throw new InvalidArgumentException('Username: 3 to 50 lowercase letters, digits, dot, dash or underscore.');
            }
            if ($display === '' || mb_strlen($display) > 100) throw new InvalidArgumentException('Display name is required (max 100).');
            if (mb_strlen($pw) < MIN_PASSWORD) throw new InvalidArgumentException('Password must be at least ' . MIN_PASSWORD . ' characters.');
            // The role CHECK constraint in MySQL also rejects anything but Admin/User.
            $st = $pdo->prepare('INSERT INTO AppUser (username, display_name, password_hash, app_role) VALUES (?, ?, ?, ?)');
            $st->execute([$username, $display, password_hash($pw, PASSWORD_DEFAULT), $role]);
            $newId = (int)$pdo->lastInsertId();
            audit('INSERT', 'AppUser', (string)$newId, ['username' => $username, 'role' => $role]);
            flash('ok', "User $username created as $role.");
        } elseif ($do === 'toggle' || $do === 'reset') {
            $id = (int)($_POST['user_id'] ?? 0);
            $target = q('SELECT user_id, username, is_active FROM AppUser WHERE user_id = ?', [$id], $pdo)[0] ?? null;
            if (!$target) throw new InvalidArgumentException('No such user.');
            if ($do === 'toggle') {
                if ($id === $u['id']) throw new InvalidArgumentException('You cannot deactivate your own account.');
                $active = (int)$target['is_active'] ? 0 : 1;
                $pdo->prepare('UPDATE AppUser SET is_active = ? WHERE user_id = ?')->execute([$active, $id]);
                audit('UPDATE', 'AppUser', (string)$id, ['is_active' => ['old' => (int)$target['is_active'], 'new' => $active]]);
                flash('ok', $target['username'] . ($active ? ' re-activated.' : ' deactivated. They can no longer sign in.'));
            } else {
                $pw = (string)($_POST['password'] ?? '');
                if (mb_strlen($pw) < MIN_PASSWORD) throw new InvalidArgumentException('Password must be at least ' . MIN_PASSWORD . ' characters.');
                $pdo->prepare('UPDATE AppUser SET password_hash = ? WHERE user_id = ?')->execute([password_hash($pw, PASSWORD_DEFAULT), $id]);
                audit('UPDATE', 'AppUser', (string)$id, ['password' => 'reset']);   // never log the password itself
                flash('ok', 'Password reset for ' . $target['username'] . '.');
            }
        }
    } catch (InvalidArgumentException $ex) {
        flash('error', $ex->getMessage());
    } catch (PDOException $ex) {
        $msg = ($ex->errorInfo[1] ?? 0) === 1062 ? 'That username already exists.' : 'Database refused the change: ' . ($ex->errorInfo[2] ?? '');
        flash('error', $msg);
    }
    redirect('admin_users.php');
}

$users = q('SELECT user_id, username, display_name, app_role, is_active, created_at, last_login_at
            FROM AppUser ORDER BY app_role, username', [], $pdo);

page_header('Application users');
?>
<p class="muted">Passwords are stored as bcrypt hashes from PHP <code>password_hash()</code>. The app role decides which MySQL
account the session uses: Admin connects as <code>app_admin</code>, User as <code>app_reader</code> (coded views only).</p>
<div class="table-wrap"><table>
  <tr><th>Username</th><th>Name</th><th>Role</th><th>Status</th><th>Created</th><th>Last login</th><th>Actions</th></tr>
  <?php foreach ($users as $r): ?>
  <tr><td><?= h($r['username']) ?></td><td><?= h($r['display_name']) ?></td>
      <td><span class="role role-<?= h(strtolower($r['app_role'])) ?>"><?= h($r['app_role']) ?></span></td>
      <td><?= (int)$r['is_active'] ? badge('Active', 'ok') : badge('Inactive', 'bad') ?></td>
      <td><?= h(substr((string)$r['created_at'], 0, 16)) ?></td><td><?= h(substr((string)$r['last_login_at'], 0, 16)) ?></td>
      <td>
        <?php if ((int)$r['user_id'] !== $u['id']): ?>
        <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="toggle">
          <input type="hidden" name="user_id" value="<?= (int)$r['user_id'] ?>">
          <button class="btn secondary"><?= (int)$r['is_active'] ? 'Deactivate' : 'Activate' ?></button></form>
        <?php endif; ?>
        <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="reset">
          <input type="hidden" name="user_id" value="<?= (int)$r['user_id'] ?>">
          <input type="password" name="password" placeholder="new password" minlength="<?= MIN_PASSWORD ?>" required size="14">
          <button class="btn secondary">Reset</button></form>
      </td></tr>
  <?php endforeach; ?>
</table></div>

<div class="card">
  <h2>Add a user</h2>
  <form method="post" class="filters"><?= csrf_field() ?><input type="hidden" name="do" value="create">
    <label>Username <input name="username" required pattern="[a-z0-9._\-]{3,50}"></label>
    <label>Display name <input name="display_name" required maxlength="100"></label>
    <label>Role <select name="app_role"><option>User</option><option>Admin</option></select></label>
    <label>Password <input type="password" name="password" minlength="<?= MIN_PASSWORD ?>" required></label>
    <button class="btn">Create</button>
  </form>
</div>
<?php page_footer();
