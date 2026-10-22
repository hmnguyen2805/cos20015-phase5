<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
$u = require_admin();

$action = get_str('action');
$who = get_str('user');

$where = [];
$params = [];
if ($action !== '') { $where[] = 'a.action = ?'; $params[] = $action; }
if ($who !== '')    { $where[] = 'u.username = ?'; $params[] = $who; }

$rows = q('SELECT a.audit_id, a.logged_at, u.username, u.app_role, a.action, a.table_name, a.record_id, a.detail
           FROM AuditLog a LEFT JOIN AppUser u ON u.user_id = a.user_id
           ' . ($where ? 'WHERE ' . implode(' AND ', $where) : '') . '
           ORDER BY a.audit_id DESC LIMIT 300', $params);

$actions = ['LOGIN', 'LOGIN_FAILED', 'LOGOUT', 'INSERT', 'UPDATE', 'DELETE', 'EXPORT', 'REPORT_RUN', 'APPROVE', 'REJECT', 'VIEW_DOC'];

page_header('Audit log');
?>
<p class="muted">Written only through the stored procedure <code>sp_audit</code>. The User role's MySQL account can add
rows through it but cannot read or change them. Latest 300 shown.</p>
<form class="filters" method="get">
  <label>Action <?= filter_select('action', array_combine($actions, $actions), $action) ?></label>
  <label>User <?= filter_select('user', distinct_options('SELECT username FROM AppUser ORDER BY 1'), $who) ?></label>
  <button class="btn">Filter</button> <a class="btn secondary" href="admin_audit.php">Clear</a>
</form>
<div class="table-wrap"><table>
  <tr><th>#</th><th>When</th><th>User</th><th>Action</th><th>Table</th><th>Record</th><th>Detail (JSON)</th></tr>
  <?php foreach ($rows as $r): ?>
  <tr><td><?= (int)$r['audit_id'] ?></td><td><?= h(substr($r['logged_at'], 0, 19)) ?></td>
      <td><?= h($r['username'] ?? '(unknown)') ?> <?= $r['app_role'] ? '<span class="muted">' . h($r['app_role']) . '</span>' : '' ?></td>
      <td><?= h($r['action']) ?></td><td><?= h($r['table_name']) ?></td><td><?= h($r['record_id']) ?></td>
      <td><code><?= h(mb_strimwidth((string)$r['detail'], 0, 160, ' ...')) ?></code></td></tr>
  <?php endforeach; ?>
</table></div>
<?php page_footer();
