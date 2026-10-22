<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
$u = require_login();

// Both roles read the same pseudonymised views; only the MySQL account differs.
$subjects = (int)q1('SELECT COUNT(*) FROM v_subject_coded');
$visits   = q('SELECT status, COUNT(*) AS n FROM v_visit_coded GROUP BY status ORDER BY status');
$ecrf     = q("SELECT COALESCE(form_version, '1.0') AS v, COUNT(*) AS n,
                      SUM(med_count > 0) AS with_meds
               FROM v_ecrf_coded GROUP BY v ORDER BY v");
$consent  = q('SELECT version, COUNT(*) AS n, SUM(has_document) AS docs
               FROM v_consent_coded GROUP BY version ORDER BY version');
$ae       = q("SELECT severity, COUNT(*) AS n FROM v_ae_coded
               GROUP BY severity ORDER BY FIELD(severity, 'Mild', 'Moderate', 'Severe')");
$labs     = q("SELECT flag, COUNT(*) AS n FROM v_lab_coded GROUP BY flag");
$labFlags = array_column($labs, 'n', 'flag');
$altHigh  = (int)q1("SELECT COUNT(*) FROM v_lab_coded WHERE test_name = 'ALT' AND flag = 'H'");
// Automated checks (both roles can read the coded findings view)
$open     = q("SELECT check_title, severity, COUNT(*) AS n FROM v_finding_coded WHERE status = 'Open'
               GROUP BY check_title, severity ORDER BY FIELD(severity, 'High', 'Medium', 'Low'), n DESC");
$lastRun  = q1("SELECT MAX(started_at) FROM v_check_run WHERE status = 'OK' AND source <> 'TEST'");

page_header('Dashboard');
?>
<p class="muted">Signed in as <strong><?= h($u['name']) ?></strong> (<?= h($u['role']) ?>).
<?= $u['role'] === 'Admin'
    ? 'You can see identities, raw eCRF JSON and consent PDFs, and every action is audited.'
    : 'You see pseudonymised data only: subject codes, no names or dates of birth.' ?></p>

<?php $openTotal = array_sum(array_column($open, 'n'));
      $openHigh = array_sum(array_map(fn($r) => $r['severity'] === 'High' ? (int)$r['n'] : 0, $open)); ?>
<div class="card <?= $openHigh ? 'alert-card' : '' ?>">
  <h2>Automated checks: <?= $openTotal ?> open finding(s)<?= $openHigh ? ', ' . $openHigh . ' High' : '' ?></h2>
  <?php if ($open): ?>
  <p><?php foreach ($open as $o) echo badge($o['n'] . ' ' . $o['check_title'], match ($o['severity']) { 'High' => 'bad', 'Medium' => 'warn', default => 'ok' }) . ' '; ?></p>
  <?php else: ?><p class="muted">Nothing open: every rule passed on the last run.</p><?php endif; ?>
  <p class="muted small">Last check run: <?= h($lastRun ? substr($lastRun, 0, 16) : 'never') ?> (every night at 02:00 by a MySQL event).
  <a href="automation.php">Open the Automation page</a><?php if ($u['role'] === 'Admin'):
      $aiPending = (int)q1("SELECT COUNT(*) FROM AEExtraction WHERE status = 'Pending'"); ?>
  &middot; <a href="ai_review.php?show=pending">AI answers waiting for review: <?= $aiPending ?></a><?php endif; ?></p>
</div>

<div class="grid">
  <div class="card stat"><h2>Subjects <?= source_tag('structured') ?></h2>
    <div class="num"><?= $subjects ?></div><div class="sub">enrolled across 5 sites</div></div>

  <div class="card stat"><h2>Visits <?= source_tag('structured') ?></h2>
    <div class="num"><?= array_sum(array_column($visits, 'n')) ?></div>
    <div class="sub"><?php foreach ($visits as $v) echo h($v['status']) . ' ' . (int)$v['n'] . '<br>'; ?></div></div>

  <div class="card stat"><h2>eCRF documents <?= source_tag('semi') ?></h2>
    <div class="num"><?= array_sum(array_column($ecrf, 'n')) ?></div>
    <div class="sub"><?php foreach ($ecrf as $e) echo 'Form v' . h($e['v']) . ': ' . (int)$e['n'] . ' (' . (int)$e['with_meds'] . ' list medicines)<br>'; ?></div></div>

  <div class="card stat"><h2>Consent PDFs <?= source_tag('unstructured') ?></h2>
    <div class="num"><?= array_sum(array_column($consent, 'docs')) ?></div>
    <div class="sub"><?php foreach ($consent as $c) echo 'Version ' . h($c['version']) . ': ' . (int)$c['docs'] . ' of ' . (int)$c['n'] . ' on file<br>'; ?></div></div>

  <div class="card stat"><h2>Adverse events <?= source_tag('unstructured') ?></h2>
    <div class="num"><?= array_sum(array_column($ae, 'n')) ?></div>
    <div class="sub"><?php foreach ($ae as $a) echo h($a['severity']) . ' ' . (int)$a['n'] . '<br>'; ?>Free-text narratives, full-text indexed</div></div>

  <div class="card stat"><h2>Lab results <?= source_tag('structured') ?></h2>
    <div class="num"><?= array_sum($labFlags) ?></div>
    <div class="sub">High <?= (int)($labFlags['H'] ?? 0) ?>, Low <?= (int)($labFlags['L'] ?? 0) ?>, Normal <?= (int)($labFlags['N'] ?? 0) ?><br>
    ALT above range: <strong><?= $altHigh ?></strong></div></div>
</div>

<?php if ($u['role'] === 'Admin'):
    $recent = q('SELECT a.logged_at, COALESCE(u.username, a.detail->>\'$.username\') AS who, a.action, a.table_name, a.record_id
                 FROM AuditLog a LEFT JOIN AppUser u ON u.user_id = a.user_id
                 ORDER BY a.audit_id DESC LIMIT 8'); ?>
<div class="card">
  <h2>Recent activity</h2>
  <div class="table-wrap"><table>
    <tr><th>When</th><th>User</th><th>Action</th><th>Table</th><th>Record</th></tr>
    <?php foreach ($recent as $r): ?>
      <tr><td><?= h(substr($r['logged_at'], 0, 19)) ?></td><td><?= h($r['who']) ?></td>
          <td><?= h($r['action']) ?></td><td><?= h($r['table_name']) ?></td><td><?= h($r['record_id']) ?></td></tr>
    <?php endforeach; ?>
  </table></div>
  <a href="admin_audit.php">Full audit log</a>
</div>
<?php endif;
page_footer();
