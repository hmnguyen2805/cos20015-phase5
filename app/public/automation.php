<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/automation.php';

/*
 * Automation: everything that happens without anyone clicking.
 *   - Nightly MySQL event -> sp_run_checks -> findings (both roles can see them, by code)
 *   - Windows Task Scheduler -> tools/run_automation.php -> scheduled emails, AI extraction
 * Admins can also run the checks now, acknowledge findings and manage schedules.
 */
$u = require_login();
$isAdmin = is_admin();

/* ---------------------------------------------------------------- actions (Admin, POST) */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin();
    csrf_check();
    $pdo = db('Admin');
    $do = is_string($_POST['do'] ?? null) ? $_POST['do'] : '';
    $anchor = '';
    try {
        switch ($do) {
            case 'run_checks':
                $run = checks_run($pdo, 'MANUAL', $u['id']);
                flash('ok', "Check run #$run[run_id] finished: $run[found] finding(s), $run[new_open] new, $run[resolved] resolved.");
                break;
            case 'ack':
                $anchor = '#findings';
                $pdo->prepare('CALL sp_ack_finding(?, ?, ?)')->execute([(int)($_POST['finding_id'] ?? 0), $u['id'],
                              is_string($_POST['note'] ?? null) ? mb_substr($_POST['note'], 0, 255) : '']);
                flash('ok', 'Finding acknowledged. It will be resolved automatically once the data is fixed.');
                break;
            case 'create_schedule':
                $anchor = '#schedules';
                $id = schedule_create($pdo, $u, $_POST);
                $next = q1('SELECT next_run_at FROM ReportSchedule WHERE schedule_id = ?', [$id], $pdo);
                flash('ok', "Schedule #$id saved. First email: $next (use Send now to try it straight away).");
                break;
            case 'toggle_schedule':
            case 'send_now':
                $anchor = '#schedules';
                $id = (int)($_POST['schedule_id'] ?? 0);
                $s = q('SELECT * FROM ReportSchedule WHERE schedule_id = ?', [$id], $pdo)[0]
                     ?? throw new InvalidArgumentException('No such schedule.');
                if ($do === 'send_now') {
                    [$status, $result, $rows] = schedule_send($pdo, $s, 'manual', $u);
                    flash($status === 'Failed' ? 'error' : 'ok', "\"$s[title]\": $status ($rows rows), $result.");
                } else {
                    // Resuming recalculates the next time, so a paused schedule never fires for a missed slot.
                    // (MySQL applies SET assignments left to right, so the IF sees the NEW is_active.)
                    $pdo->prepare('UPDATE ReportSchedule SET is_active = NOT is_active,
                                          next_run_at = IF(is_active, fn_next_run(frequency, weekday, send_time, NOW()), next_run_at)
                                    WHERE schedule_id = ?')->execute([$id]);
                    audit('UPDATE', 'ReportSchedule', (string)$id, ['is_active' => ['old' => (int)$s['is_active'],
                          'new' => (int)!$s['is_active']]], null, $pdo);
                    flash('ok', "\"$s[title]\" " . ($s['is_active'] ? 'paused.' : 'resumed.'));
                }
                break;
            default:
                flash('error', 'Unknown action.');
        }
    } catch (InvalidArgumentException $e) {
        flash('error', $e->getMessage());
    } catch (PDOException $e) {
        // SIGNAL / CHECK messages from MySQL are written for people, so show them.
        flash('error', 'MySQL said: ' . ($e->errorInfo[2] ?? $e->getMessage()));
    }
    redirect('automation.php' . $anchor);
}

/* ---------------------------------------------------------------- data for the page */
$fdef = report_def('findings');
$req = report_request($fdef, $_GET, false);
$findings = report_rows($req);
$spec = $req['spec'];
$open = array_column(q("SELECT severity, COUNT(*) AS n FROM v_finding_coded WHERE status <> 'Resolved' GROUP BY severity"), 'n', 'severity');
$runs = q('SELECT * FROM v_check_run ORDER BY run_id DESC LIMIT 8');
$lastOk = checks_last_ok(db_session());
$lastEvent = q1("SELECT MAX(started_at) FROM v_check_run WHERE source = 'EVENT' AND status = 'OK'");
$beat = automation_last();
$beatAge = $beat ? (time() - strtotime($beat['finished_at'] ?? $beat['started_at'])) : null;
$schedules = $isAdmin ? q('SELECT s.*, u.username FROM ReportSchedule s JOIN AppUser u ON u.user_id = s.created_by
                           ORDER BY s.is_active DESC, s.next_run_at') : [];

function ago(?int $sec): string
{
    if ($sec === null) return 'never';
    return match (true) {
        $sec < 90    => 'just now',
        $sec < 5400  => round($sec / 60) . ' min ago',
        $sec < 86400 * 2 => round($sec / 3600) . ' h ago',
        default      => round($sec / 86400) . ' days ago',
    };
}

page_header('Automation');
?>
<p class="muted">What the system does by itself. <strong>Every night at 02:00</strong> a MySQL event runs the stored procedure
<code>sp_run_checks</code>, which applies every rule to the structured, JSON and text data and records findings.
<strong>Every 15 minutes</strong> Windows Task Scheduler runs <code>run_automation.php</code>, which sends scheduled report emails,
sends new adverse-event narratives to the AI model for extraction, and re-runs the checks if the nightly run was missed.</p>

<div class="grid">
  <div class="card stat"><h2>Open findings</h2>
    <div class="num"><?= array_sum($open) ?></div>
    <div class="sub"><?= badge('High ' . (int)($open['High'] ?? 0), 'bad') ?> <?= badge('Medium ' . (int)($open['Medium'] ?? 0), 'warn') ?>
      <?= badge('Low ' . (int)($open['Low'] ?? 0), 'ok') ?><br>open or acknowledged, not yet fixed</div></div>
  <div class="card stat"><h2>Last check run</h2>
    <div class="num small-num"><?= $lastOk ? h(ago(time() - strtotime($lastOk['started_at']))) : 'never' ?></div>
    <div class="sub"><?= $lastOk ? 'Run #' . (int)$lastOk['run_id'] . ' (' . h($lastOk['source']) . '): ' . (int)$lastOk['found']
        . ' found, ' . (int)$lastOk['new_open'] . ' new, ' . (int)$lastOk['resolved'] . ' resolved' : 'No run yet' ?><br>
      Last nightly event run: <?= h($lastEvent ? substr($lastEvent, 0, 16) : 'not yet') ?></div>
    <?php if ($isAdmin): ?>
    <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="run_checks">
      <button class="btn secondary">Run checks now</button></form>
    <?php endif; ?></div>
  <div class="card stat"><h2>Windows scheduler</h2>
    <div class="num small-num"><?= h(ago($beatAge)) ?></div>
    <div class="sub"><?php if ($beat): ?>
      Emails: <?= $beat['emails'] ? h(implode('; ', $beat['emails'])) : 'none due' ?><br>
      AI: <?= h($beat['ai'] ?? '') ?><br>
      <?= $beat['errors'] ? badge($beat['errors'] . ' error(s), see storage\\logs\\automation.log', 'bad') : badge('no errors', 'ok') ?>
      <?php if ($beatAge !== null && $beatAge > 1800) echo '<br>' . badge('Not running? Check Task Scheduler', 'warn'); ?>
    <?php else: ?>run_automation.php has not run yet. Register it with scripts\register_automation_task.ps1.<?php endif; ?></div></div>
</div>

<div class="card" id="findings">
  <h2>Findings <?php foreach ($fdef['sources'] as $s) echo source_tag($s); ?></h2>
  <form class="filters" method="get" action="automation.php#findings">
    <?php foreach (['status', 'severity', 'check', 'site'] as $name):
        $flt = $fdef['filters'][$name]; ?>
      <label><?= h($flt['label']) ?>
        <select name="<?= h($name) ?>"><option value=""><?= h($flt['all'] ?? 'All') ?></option>
          <?php foreach (report_filter_options($flt) as $v => $l): ?>
            <option value="<?= h($v) ?>"<?= (string)$v === $req['f'][$name] ? ' selected' : '' ?>><?= h($l) ?></option>
          <?php endforeach; ?></select></label>
    <?php endforeach; ?>
    <input type="hidden" name="sort" value="<?= h($spec[0]) ?>"><input type="hidden" name="dir" value="<?= h($spec[1]) ?>">
    <button class="btn">Filter</button> <a class="btn secondary" href="automation.php#findings">Reset</a>
    <?php $fq = report_query($req); ?>
    <a class="btn secondary" href="export.php?<?= h(http_build_query($fq + ['format' => 'csv'])) ?>">CSV</a>
    <a class="btn secondary" href="export.php?<?= h(http_build_query($fq + ['format' => 'pdf'])) ?>" target="_blank">PDF</a>
  </form>
  <p><strong><?= h(($fdef['summary'])($findings, $req['f'])) ?></strong></p>
  <div class="table-wrap"><table>
    <tr><?php foreach (['severity' => 'Severity', 'status' => 'Status', 'check_title' => 'Check', 'subject_code' => 'Subject',
                        'first_seen' => 'First seen'] as $k => $l): ?><th><?= sort_link($l, $k, $spec) ?></th><?php endforeach; ?>
        <th>Finding</th><th><?= $isAdmin ? 'Note / action' : 'Note' ?></th></tr>
    <?php if (!$findings): ?><tr><td colspan="7" class="muted">No findings match these filters.</td></tr><?php endif; ?>
    <?php foreach ($findings as $f): ?>
    <tr>
      <td><?= badge($f['severity'], match ($f['severity']) { 'High' => 'bad', 'Medium' => 'warn', default => 'ok' }) ?></td>
      <td><?= badge($f['status'], match ($f['status']) { 'Open' => 'bad', 'Acknowledged' => 'warn', default => 'ok' }) ?></td>
      <td><?= h($f['check_title']) ?></td>
      <td><a href="subject.php?code=<?= h($f['subject_code']) ?>"><?= h($f['subject_code']) ?></a></td>
      <td class="nowrap"><?= h(substr((string)$f['first_seen'], 0, 16)) ?></td>
      <td><?= h($f['message']) ?></td>
      <td><?php if ($isAdmin && $f['status'] === 'Open'): ?>
        <form method="post" class="ack-form"><?= csrf_field() ?><input type="hidden" name="do" value="ack">
          <input type="hidden" name="finding_id" value="<?= (int)$f['finding_id'] ?>">
          <input name="note" required minlength="3" maxlength="255" placeholder="What is being done?" aria-label="Note">
          <button class="btn secondary">Acknowledge</button></form>
      <?php else: ?><?= h($f['ack_note']) ?><?php if ($f['status'] === 'Resolved') echo ' <span class="muted small">resolved ' . h(substr((string)$f['resolved_at'], 0, 16)) . '</span>'; ?><?php endif; ?></td>
    </tr>
    <?php endforeach; ?>
  </table></div>
  <details><summary>The rules (view <code>v_check_candidates</code>)</summary>
    <ul><?php foreach (q('SELECT * FROM CheckRule ORDER BY data_source, title') as $r): ?>
      <li><?= source_tag($r['data_source']) ?> <strong><?= h($r['title']) ?></strong>: <?= h($r['rule_text']) ?></li>
    <?php endforeach; ?></ul></details>
</div>

<div class="card">
  <h2>Recent check runs</h2>
  <div class="table-wrap"><table>
    <tr><th>#</th><th>Started</th><th>Source</th><th>Status</th><th class="num">Found</th><th class="num">New</th><th class="num">Resolved</th><th>Error</th></tr>
    <?php foreach ($runs as $r): ?>
    <tr><td><?= (int)$r['run_id'] ?></td><td><?= h(substr($r['started_at'], 0, 19)) ?></td><td><?= h($r['source']) ?></td>
        <td><?= badge($r['status'], $r['status'] === 'OK' ? 'ok' : 'bad') ?></td>
        <td class="num"><?= h($r['found']) ?></td><td class="num"><?= h($r['new_open']) ?></td><td class="num"><?= h($r['resolved']) ?></td>
        <td><?= h($r['error_text']) ?></td></tr>
    <?php endforeach; ?>
  </table></div>
  <p class="muted small">EVENT = nightly MySQL event, MANUAL = Run checks now, SCHEDULER = catch-up by run_automation.php, TEST = sql\10b test script.</p>
</div>

<?php if ($isAdmin): ?>
<div class="card" id="schedules">
  <h2>Scheduled emails</h2>
  <p class="muted">Each schedule re-runs a saved report view and emails it as CSV + PDF, pseudonymised, exactly like the Email button.
  To schedule a filtered view, open a report, set the filters and use <em>Schedule this view</em>.
  Transport now: <strong><?= h(mail_config()['transport'] === 'smtp' ? 'SMTP via ' . mail_config()['host'] : 'outbox (no mail.php: saved as .eml files)') ?></strong>.</p>
  <div class="table-wrap"><table>
    <tr><th>Name</th><th>Report and filters</th><th>To</th><th>When</th><th>Next</th><th>Last result</th><th></th></tr>
    <?php if (!$schedules): ?><tr><td colspan="7" class="muted">No schedules yet.</td></tr><?php endif; ?>
    <?php foreach ($schedules as $s): ?>
    <tr class="<?= $s['is_active'] ? '' : 'paused' ?>">
      <td><?= h($s['title']) ?><br><span class="muted small">by <?= h($s['username']) ?></span></td>
      <td><?= h(schedule_reports()[$s['report_key']] ?? $s['report_key']) ?><br><span class="muted small"><?= h(schedule_filters_text($s)) ?></span></td>
      <td><?= h($s['recipient']) ?></td>
      <td><?= h(schedule_describe($s)) ?><?= $s['skip_empty'] ? '<br><span class="muted small">only when there are rows</span>' : '' ?></td>
      <td class="nowrap"><?= $s['is_active'] ? h(substr($s['next_run_at'], 0, 16)) : badge('Paused') ?></td>
      <td><?php if ($s['last_status']): ?><?= badge($s['last_status'], match ($s['last_status']) { 'Failed' => 'bad', 'Skipped' => 'warn', default => 'ok' }) ?>
          <span class="small"><?= h(substr((string)$s['last_run_at'], 0, 16)) ?></span><br><span class="muted small"><?= h($s['last_result']) ?></span><?php else: ?><span class="muted">not sent yet</span><?php endif; ?></td>
      <td class="nowrap">
        <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="send_now"><input type="hidden" name="schedule_id" value="<?= (int)$s['schedule_id'] ?>"><button class="btn secondary">Send now</button></form>
        <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="toggle_schedule"><input type="hidden" name="schedule_id" value="<?= (int)$s['schedule_id'] ?>"><button class="btn secondary"><?= $s['is_active'] ? 'Pause' : 'Resume' ?></button></form>
      </td>
    </tr>
    <?php endforeach; ?>
  </table></div>
  <h3>New schedule (default filters)</h3>
  <?= schedule_form_html([]) ?>
</div>
<?php endif;
page_footer();
