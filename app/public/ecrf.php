<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
$u = require_login();
$admin = is_admin();

$id = (int)get_str('id');
$e = q("SELECT e.*, COALESCE(e.form_version, '1.0') AS form_label, s.site_name
        FROM v_ecrf_coded e JOIN v_subject_coded s ON s.subject_code = e.subject_code
        WHERE e.ecrf_id = ?", [$id])[0] ?? null;
if (!$e) {
    http_response_code(404);
    page_header('eCRF not found');
    echo '<div class="card">No eCRF with that id. <a href="ecrfs.php">Back to eCRFs</a></div>';
    page_footer();
    exit;
}

$editText = null;     // what to show in the editor after a failed save
$dbError = null;      // MySQL's own error message
$report = null;       // JSON_SCHEMA_VALIDATION_REPORT explaining a schema failure

/* ---------- Admin: save an edited eCRF ----------
 * The app does not validate the document itself. It sends it to MySQL, where
 * the BEFORE UPDATE trigger (sp_check_ecrf) and the JSON Schema CHECK
 * constraint decide. If MySQL refuses, the error is shown to the Admin. */
if ($admin && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $editText = (string)($_POST['doc'] ?? '');
    $pdo = db('Admin');
    json_decode($editText);
    if (json_last_error() !== JSON_ERROR_NONE) {
        $dbError = 'Not valid JSON: ' . json_last_error_msg();
    } else {
        try {
            $pdo->beginTransaction();
            $old = q1('SELECT ecrf_doc FROM VisitECRF WHERE ecrf_id = ? FOR UPDATE', [$id], $pdo);
            $st = $pdo->prepare('UPDATE VisitECRF SET ecrf_doc = ? WHERE ecrf_id = ?');
            $st->execute([$editText, $id]);
            if ($st->rowCount() === 0) {
                $pdo->rollBack();
                flash('info', 'No change: the document is the same as before.');
            } else {
                audit('UPDATE', 'VisitECRF', (string)$id,
                      ['old' => json_decode($old, true), 'new' => json_decode($editText, true)], null, $pdo);
                $pdo->commit();
                flash('ok', 'eCRF saved. MySQL accepted it against the JSON Schema and the visit check.');
            }
            redirect('ecrf.php?id=' . $id);
        } catch (PDOException $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $dbError = 'MySQL rejected the document: ' . ($ex->errorInfo[2] ?? $ex->getMessage());
            // For a schema failure, ask MySQL which rule was broken.
            if (($ex->errorInfo[1] ?? 0) === 3819) {
                $schema = file_get_contents(config()['phase5_root'] . '/data/ecrf_schema_v2.json');
                // CAST is needed: with server-side prepares MySQL cannot infer that a ? is JSON.
                $report = json_decode((string)q1('SELECT JSON_SCHEMA_VALIDATION_REPORT(CAST(? AS JSON), CAST(? AS JSON))',
                                                 [$schema, $editText], $pdo), true);
            }
        }
    }
}

$doc = $admin ? q('SELECT ecrf_doc, received_at FROM VisitECRF WHERE ecrf_id = ?', [$id])[0] : null;
$meds = $e['med_names'] ? json_decode($e['med_names'], true) : [];

page_header('eCRF ' . $e['subject_code'] . ' ' . $e['visit_type']);
?>
<div class="card">
  <h2>Values read from the JSON <?= source_tag('semi') ?></h2>
  <dl class="kv">
    <dt>Subject</dt><dd><a href="subject.php?code=<?= h($e['subject_code']) ?>"><?= h($e['subject_code']) ?></a> (<?= h($e['site_name']) ?>)</dd>
    <dt>Visit</dt><dd><?= h($e['visit_type']) ?> on <?= h($e['scheduled_date']) ?></dd>
    <dt>Form version</dt><dd>v<?= h($e['form_label']) ?><?= $e['form_version'] === null ? ' <span class="muted">(no formVersion field: original v1.0 shape)</span>' : '' ?></dd>
    <dt>Heart rate</dt><dd><?= h($e['heart_rate']) ?> bpm</dd>
    <dt>Blood pressure</dt><dd><?= $e['systolic_bp'] ? h($e['systolic_bp'] . '/' . $e['diastolic_bp']) . ' mmHg' : '' ?></dd>
    <dt>Temperature</dt><dd><?= h($e['temperature_c']) ?><?= $e['temperature_c'] !== null ? ' &deg;C' : '' ?></dd>
    <dt>Medicines</dt><dd><?= $meds ? h(implode(', ', $meds)) : '<span class="muted">none listed</span>' ?></dd>
  </dl>
</div>

<?php if (!$admin): ?>
  <p class="muted">The raw JSON document (doses, investigator comments) is available to Admins only.</p>
<?php else: ?>
<div class="card">
  <h2>Raw JSON document <?= badge('Admin only', 'warn') ?></h2>
  <p class="muted">Received <?= h($doc['received_at']) ?>.</p>
  <pre class="json"><?= h(json_encode(json_decode($doc['ecrf_doc']), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
</div>

<div class="card">
  <h2>Edit eCRF</h2>
  <p class="muted">MySQL validates the change: the JSON Schema CHECK constraint and the trigger that
  matches subjectId, visitType and scheduledDate to the visit row. Try a heart rate of 400, or a different subjectId.</p>
  <?php if ($dbError): ?>
    <div class="flash flash-error"><?= h($dbError) ?>
    <?php if ($report && !$report['valid']): ?>
      <br>Rule broken: <strong><?= h($report['reason'] ?? '') ?></strong>
      (keyword <code><?= h($report['schema-failed-keyword'] ?? '') ?></code>
      at <code><?= h($report['document-location'] ?? '') ?></code>)
    <?php endif; ?></div>
  <?php endif; ?>
  <form method="post">
    <?= csrf_field() ?>
    <textarea name="doc" rows="18"><?= h($editText ?? json_encode(json_decode($doc['ecrf_doc']), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></textarea>
    <p><button class="btn">Save to database</button> <a class="btn secondary" href="ecrf.php?id=<?= $id ?>">Cancel</a></p>
  </form>
</div>
<?php endif;
page_footer();
