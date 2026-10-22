<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/ai.php';

/*
 * AI review queue (Admin only). One row per adverse event: the free-text
 * narrative, the AI's structured answer, and the cross-checks the database
 * makes against the structured data. Nothing the AI says is used until an
 * Admin approves it; every request and decision is in the audit log.
 */
$u = require_admin();
$pdo = db('Admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = is_string($_POST['do'] ?? null) ? $_POST['do'] : '';
    try {
        if ($do === 'extract_pending' || $do === 'extract_one') {
            set_time_limit(600);   // free tiers may make it wait for the rate limit between calls
            if ($do === 'extract_one') {
                $r = ai_extract_one($pdo, (int)($_POST['ae_id'] ?? 0), $u['id']);
                $r['ok'] ? flash('ok', 'AI answer stored for review.') : flash('error', 'AI extraction failed: ' . $r['error']);
            } else {
                $r = ai_extract_pending($pdo, $u['id'], 25);
                flash($r['failed'] ? 'error' : 'ok', "AI extraction: $r[done] stored for review, $r[failed] failed."
                      . ($r['errors'] ? ' First error: ' . $r['errors'][0] : ''));
            }
        } elseif ($do === 'review') {
            $decision = ($_POST['decision'] ?? '') === 'APPROVE' ? 'APPROVE' : 'REJECT';
            $pdo->prepare('CALL sp_review_extraction(?, ?, ?, ?)')->execute([(int)($_POST['extraction_id'] ?? 0), $u['id'], $decision,
                          is_string($_POST['note'] ?? null) ? mb_substr(trim($_POST['note']), 0, 255) : '']);
            flash('ok', $decision === 'APPROVE' ? 'Approved.' : 'Rejected.');
        }
    } catch (PDOException $e) {
        flash('error', 'MySQL said: ' . ($e->errorInfo[2] ?? $e->getMessage()));
    }
    redirect('ai_review.php' . (isset($_POST['ae_id']) ? '#ae' . (int)$_POST['ae_id'] : ''));
}

$show = get_str('show', 'all');
$rows = q("WITH latest AS (
               SELECT r.*, ROW_NUMBER() OVER (PARTITION BY r.ae_id
                                              ORDER BY r.status = 'Pending' DESC, r.extraction_id DESC) AS rn
               FROM v_ae_extraction_review r WHERE r.status <> 'Superseded')
           SELECT a.ae_id AS aid, a.subject_code AS scode, a.onset_date AS onset, a.severity AS sev, a.narrative AS narr, l.*
           FROM v_ae_coded a LEFT JOIN latest l ON l.ae_id = a.ae_id AND l.rn = 1
           ORDER BY a.ae_id", [], $pdo);
$counts = array_column(q('SELECT status, COUNT(*) AS n FROM AEExtraction GROUP BY status', [], $pdo), 'n', 'status');
$pending = ai_pending_ids($pdo, 100);
$cfg = ai_config();
$disagree = fn(array $r) => $r['extraction_id'] !== null
    && ((int)$r['severity_agrees'] === 0 || $r['lab_found'] === 0 || $r['repeat_found'] === 0 || (int)$r['is_current'] === 0);
$rows = array_values(array_filter($rows, fn($r) => match ($show) {
    'pending'  => $r['status'] === 'Pending',
    'disagree' => $disagree($r),
    'none'     => $r['extraction_id'] === null,
    default    => true,
}));

/** Yes/No/blank badge for a cross-check (null = nothing to check). */
function check_badge(mixed $v, string $yes = 'matches', string $no = 'differs'): string
{
    return $v === null ? '' : ((int)$v ? badge($yes, 'ok') : badge($no, 'bad'));
}

page_header('AI review');
?>
<p class="muted">Turns the free-text adverse-event narratives <?= source_tag('unstructured') ?> into structured fields with an AI model
(Gemini or Claude, chosen in <code>app\config\ai.php</code>).
Only the narrative text is sent (no subject code, no name). The answer must fit a fixed JSON shape, which MySQL checks again with a
JSON Schema CHECK before storing it. The database then compares it with the recorded severity and the lab results
<?= source_tag('structured') ?>. Nothing is used until an Admin approves it.</p>

<div class="grid">
  <div class="card stat"><h2>AI connection</h2>
    <div class="num small-num"><?= $cfg ? 'On' : 'Off' ?></div>
    <div class="sub"><?= $cfg ? h(ucfirst($cfg['provider'])) . ', model ' . h($cfg['model']) . ', prompt ' . h(AI_PROMPT_VERSION)
        . ($cfg['provider'] === 'gemini' ? '<br>Free tier: synthetic data only' : '')
        : 'Copy app\\config\\ai.example.php to ai.php and add an API key.' ?></div></div>
  <div class="card stat"><h2>Waiting for the AI</h2>
    <div class="num"><?= count($pending) ?></div>
    <div class="sub">narratives with no answer yet (the scheduler does up to 20 every 15 minutes)</div>
    <?php if ($cfg && $pending): ?>
    <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="extract_pending">
      <button class="btn">Extract <?= count($pending) ?> now</button></form><?php endif; ?></div>
  <div class="card stat"><h2>Review status</h2>
    <div class="sub"><?= badge('Pending ' . (int)($counts['Pending'] ?? 0), 'warn') ?> <?= badge('Approved ' . (int)($counts['Approved'] ?? 0), 'ok') ?>
      <?= badge('Rejected ' . (int)($counts['Rejected'] ?? 0), 'bad') ?> <?= badge('Superseded ' . (int)($counts['Superseded'] ?? 0)) ?></div></div>
</div>

<form class="filters" method="get">
  <label>Show <?= filter_select('show', ['pending' => 'Needs review (Pending)', 'disagree' => 'AI disagrees with the data',
                                          'none' => 'No AI answer yet'], $show === 'all' ? '' : $show, 'All adverse events') ?></label>
  <button class="btn">Show</button>
</form>

<div class="table-wrap"><table class="ai-table">
  <tr><th>AE</th><th>Recorded</th><th>Narrative (free text)</th><th>AI answer</th><th>Cross-checks</th><th>Decision</th></tr>
  <?php if (!$rows): ?><tr><td colspan="6" class="muted">Nothing to show.</td></tr><?php endif; ?>
  <?php foreach ($rows as $r): $x = $r['extraction_id'] !== null ? json_decode($r['extracted'], true) : null; ?>
  <tr id="ae<?= (int)$r['aid'] ?>">
    <td class="nowrap"><strong>AE <?= (int)$r['aid'] ?></strong><br><a href="subject.php?code=<?= h($r['scode']) ?>"><?= h($r['scode']) ?></a><br>
        <span class="muted small"><?= h($r['onset']) ?></span></td>
    <td><?= badge($r['sev'], ['Mild' => 'ok', 'Moderate' => 'warn', 'Severe' => 'bad'][$r['sev']] ?? '') ?></td>
    <td class="narr-cell"><?= h($r['narr']) ?></td>
    <td class="ai-cell"><?php if (!$x): ?><span class="muted">No AI answer yet.</span><?php else: ?>
      <?= badge($r['status'], match ($r['status']) { 'Approved' => 'ok', 'Rejected' => 'bad', default => 'warn' }) ?>
      <dl class="kv small">
        <dt>Term</dt><dd><?= h($x['event_term']) ?></dd>
        <dt>Severity</dt><dd><?= h($x['suggested_severity']) ?><?= $x['serious'] ? ' ' . badge('serious', 'bad') : '' ?></dd>
        <dt>Symptoms</dt><dd><?= h(implode(', ', $x['symptoms']) ?: 'none') ?></dd>
        <dt>Treatment</dt><dd><?= h(implode(', ', array_map(fn($t) => trim($t['name'] . ' ' . ($t['dose'] ?? '')), $x['treatments'] ?? [])) ?: 'none') ?></dd>
        <dt>Study drug</dt><dd><?= h($x['action_with_study_drug']) ?><?= $x['withdrawn'] ? ' ' . badge('withdrawn', 'warn') : '' ?></dd>
        <dt>Outcome</dt><dd><?= h($x['outcome']) ?></dd>
        <?php if ($x['lab']): ?><dt>Lab</dt><dd><?= h($x['lab']['test'] . ' ' . $x['lab']['value'] . ' ' . ($x['lab']['unit'] ?? '')
            . (isset($x['lab']['visit_type']) ? ' at ' . $x['lab']['visit_type'] : '')
            . (isset($x['lab']['repeat_value']) ? ', repeat ' . $x['lab']['repeat_value'] : '')) ?></dd><?php endif; ?>
        <dt>Evidence</dt><dd><q><?= h($x['evidence']) ?></q></dd>
        <dt>Confidence</dt><dd><?= h(number_format((float)$x['confidence'], 2)) ?>
          <span class="muted"><?= h($r['model']) ?>, <?= (int)$r['input_tokens'] ?>+<?= (int)$r['output_tokens'] ?> tokens, <?= round((int)$r['latency_ms'] / 1000, 1) ?> s</span></dd>
      </dl><?php endif; ?></td>
    <td class="nowrap"><?php if ($x): ?>
      Severity <?= check_badge($r['severity_agrees'], 'agrees', 'differs') ?><br>
      <?php if ($r['lab_found'] !== null): ?>Lab value <?= check_badge($r['lab_found'], 'in LabResult', 'not found') ?><br><?php endif; ?>
      <?php if ($r['repeat_found'] !== null): ?>Repeat value <?= check_badge($r['repeat_found'], 'in LabResult', 'not found') ?><br><?php endif; ?>
      <?= (int)$r['is_current'] ? '' : badge('narrative changed since', 'bad') ?>
    <?php endif; ?></td>
    <td><?php if ($x && $r['status'] === 'Pending'): ?>
      <form method="post" class="review-form"><?= csrf_field() ?><input type="hidden" name="do" value="review">
        <input type="hidden" name="extraction_id" value="<?= (int)$r['extraction_id'] ?>"><input type="hidden" name="ae_id" value="<?= (int)$r['aid'] ?>">
        <input name="note" maxlength="255" placeholder="Note (needed to reject)" aria-label="Note">
        <span><button class="btn" name="decision" value="APPROVE">Approve</button>
              <button class="btn danger" name="decision" value="REJECT">Reject</button></span></form>
    <?php elseif ($x): ?>
      <span class="small"><?= h(substr((string)$r['reviewed_at'], 0, 16)) ?></span><br><span class="muted small"><?= h($r['review_note']) ?></span>
    <?php endif; ?>
    <?php if ($cfg && (!$x || $r['status'] !== 'Pending')): ?>
      <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="extract_one">
        <input type="hidden" name="ae_id" value="<?= (int)$r['aid'] ?>"><button class="btn secondary"><?= $x ? 'Ask again' : 'Ask the AI' ?></button></form>
    <?php endif; ?></td>
  </tr>
  <?php endforeach; ?>
</table></div>
<?php page_footer();
