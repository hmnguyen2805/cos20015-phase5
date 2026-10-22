<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
$u = require_login();
$admin = is_admin();

$code = get_str('code');
$id = code_to_id($code);
$s = $id ? (q('SELECT * FROM v_subject_coded WHERE subject_code = ?', [$code])[0] ?? null) : null;
if (!$s) {
    http_response_code(404);
    page_header('Subject not found');
    echo '<div class="card">No subject with that code. <a href="subjects.php">Back to subjects</a></div>';
    page_footer();
    exit;
}

$identity = $admin ? (q('SELECT first_name, last_name, date_of_birth FROM Subject WHERE subject_id = ?', [$id])[0] ?? null) : null;

// One subject's whole record, pulled from structured, semi-structured and unstructured sources.
$visits = q("SELECT v.visit_id, v.visit_type, v.scheduled_date, v.status,
                    e.ecrf_id, COALESCE(e.form_version, '1.0') AS form_version, e.heart_rate,
                    e.systolic_bp, e.diastolic_bp, e.temperature_c, e.med_names
             FROM v_visit_coded v
             LEFT JOIN v_ecrf_coded e ON e.visit_id = v.visit_id
             WHERE v.subject_code = ? ORDER BY v.scheduled_date", [$code]);
$labs = q("SELECT visit_type, scheduled_date, test_name, value, unit, reference_range, flag
           FROM v_lab_coded WHERE subject_code = ? ORDER BY scheduled_date, test_name", [$code]);
$aes = q('SELECT ae_id, onset_date, severity, event_term, narrative FROM v_ae_coded
          WHERE subject_code = ? ORDER BY onset_date', [$code]);
$consents = q('SELECT consent_id, version, signed_date, has_document, page_count FROM v_consent_coded
               WHERE subject_code = ? ORDER BY signed_date', [$code]);
$onlyAbnormal = get_str('labs') === 'abnormal';

page_header('Subject ' . $code);
?>
<div class="card">
  <dl class="kv">
    <?php if ($identity): ?>
      <dt>Name</dt><dd><?= h($identity['first_name'] . ' ' . $identity['last_name']) ?> <?= badge('Admin only', 'warn') ?></dd>
      <dt>Date of birth</dt><dd><?= h($identity['date_of_birth']) ?></dd>
    <?php endif; ?>
    <dt>Site</dt><dd><?= h($s['site_name']) ?></dd>
    <dt>Sex / age at enrolment</dt><dd><?= h($s['sex']) ?> / <?= h($s['age_at_enrolment']) ?></dd>
    <dt>Enrolled</dt><dd><?= h($s['enrolment_date']) ?></dd>
  </dl>
</div>

<h2>Visits and eCRF vitals <?= source_tag('structured') ?><?= source_tag('semi') ?></h2>
<div class="table-wrap"><table>
  <tr><th>Visit</th><th>Date</th><th>Status</th><th>eCRF</th><th class="num">HR</th><th>BP</th><th class="num">Temp &deg;C</th><th>Medicines (from JSON)</th></tr>
  <?php foreach ($visits as $v): $meds = $v['med_names'] ? json_decode($v['med_names'], true) : []; ?>
  <tr>
    <td><?= h($v['visit_type']) ?></td><td><?= h($v['scheduled_date']) ?></td><td><?= h($v['status']) ?></td>
    <td><?= $v['ecrf_id'] ? '<a href="ecrf.php?id=' . (int)$v['ecrf_id'] . '">Form v' . h($v['form_version']) . '</a>' : '<span class="muted">none</span>' ?></td>
    <td class="num"><?= h($v['heart_rate']) ?></td>
    <td><?= $v['systolic_bp'] ? h($v['systolic_bp'] . '/' . $v['diastolic_bp']) : '' ?></td>
    <td class="num"><?= h($v['temperature_c']) ?></td>
    <td><?= h(implode(', ', $meds)) ?></td>
  </tr>
  <?php endforeach; ?>
</table></div>

<h2>Lab results <?= source_tag('structured') ?></h2>
<p><?= $onlyAbnormal
    ? '<a href="?code=' . h($code) . '">Show all results</a>'
    : '<a href="?code=' . h($code) . '&labs=abnormal">Show only out-of-range results</a>' ?></p>
<div class="table-wrap"><table>
  <tr><th>Visit</th><th>Date</th><th>Test</th><th class="num">Value</th><th>Unit</th><th>Range</th><th>Flag</th></tr>
  <?php foreach ($labs as $l): if ($onlyAbnormal && $l['flag'] === 'N') continue; ?>
  <tr><td><?= h($l['visit_type']) ?></td><td><?= h($l['scheduled_date']) ?></td><td><?= h($l['test_name']) ?></td>
      <td class="num"><?= h($l['value']) ?></td><td><?= h($l['unit']) ?></td><td><?= h($l['reference_range']) ?></td>
      <td><?= flag_badge($l['flag']) ?></td></tr>
  <?php endforeach; ?>
</table></div>

<h2>Adverse events <?= source_tag('structured') ?><?= source_tag('unstructured') ?></h2>
<?php if (!$aes): ?><p class="muted">No adverse events recorded.</p><?php else: ?>
<div class="table-wrap"><table>
  <tr><th>Onset</th><th>Severity</th><th>Event</th><th>Narrative (free text)</th></tr>
  <?php foreach ($aes as $a): ?>
  <tr><td><?= h($a['onset_date']) ?></td>
      <td><?= badge($a['severity'], $a['severity'] === 'Mild' ? 'ok' : ($a['severity'] === 'Moderate' ? 'warn' : 'bad')) ?></td>
      <td><?= h($a['event_term']) ?></td><td><?= h($a['narrative']) ?></td></tr>
  <?php endforeach; ?>
</table></div>
<?php endif; ?>

<h2>Informed consent <?= source_tag('structured') ?><?= source_tag('unstructured') ?></h2>
<div class="table-wrap"><table>
  <tr><th>Version</th><th>Signed</th><th>Document</th></tr>
  <?php foreach ($consents as $c): ?>
  <tr><td><?= h($c['version']) ?></td><td><?= h($c['signed_date']) ?></td>
      <td><?php if (!$c['has_document']): ?><span class="muted">no file</span>
          <?php elseif ($admin): ?><a href="consent_pdf.php?id=<?= (int)$c['consent_id'] ?>" target="_blank">Open PDF</a> (<?= (int)$c['page_count'] ?> pages)
          <?php else: ?>PDF on file (<?= (int)$c['page_count'] ?> pages), Admin access only<?php endif; ?></td></tr>
  <?php endforeach; ?>
</table></div>
<?php page_footer();
