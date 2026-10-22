<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
$u = require_login();

$form = get_str('form');
$site = get_str('site');
$med  = get_str('med');
$minSys = get_str('min_sys');

$where = [];
$params = [];
if ($form !== '') { $where[] = "COALESCE(e.form_version, '1.0') = ?"; $params[] = $form; }
if ($site !== '') { $where[] = 's.site_name = ?'; $params[] = $site; }
if ($med !== '')  {
    // MEMBER OF searches inside the JSON array; it can use the multi-valued index idx_ecrf_meds.
    $where[] = '? MEMBER OF (e.med_names)'; $params[] = $med;
}
if ($minSys !== '' && ctype_digit($minSys)) { $where[] = 'e.systolic_bp >= ?'; $params[] = (int)$minSys; }

$spec = sort_spec([
    'date' => 'e.scheduled_date, e.subject_code',
    'code' => 'e.subject_code, e.scheduled_date',
    'hr'   => 'e.heart_rate, e.ecrf_id',
    'sys'  => 'e.systolic_bp, e.ecrf_id',
    'temp' => 'e.temperature_c, e.ecrf_id',
    'meds' => 'e.med_count, e.ecrf_id',
], 'date');

$rows = q("SELECT e.ecrf_id, e.subject_code, s.site_name, e.visit_type, e.scheduled_date,
                  COALESCE(e.form_version, '1.0') AS form_version, e.heart_rate, e.systolic_bp,
                  e.diastolic_bp, e.temperature_c, e.med_count, e.med_names
           FROM v_ecrf_coded e
           JOIN v_subject_coded s ON s.subject_code = e.subject_code
           " . ($where ? 'WHERE ' . implode(' AND ', $where) : '') . " {$spec[2]}", $params);

// Distinct medicine names, unpacked from every JSON array with JSON_TABLE.
$medOptions = distinct_options("SELECT DISTINCT jt.name
    FROM v_ecrf_coded e,
         JSON_TABLE(COALESCE(e.med_names, JSON_ARRAY()), '$[*]' COLUMNS (name VARCHAR(60) PATH '$')) AS jt
    ORDER BY jt.name");

page_header('eCRF documents');
?>
<p class="muted">Each completed visit has one electronic case report form stored as a JSON document,
validated by MySQL against a JSON Schema. The columns below are generated columns read out of the JSON. <?= source_tag('semi') ?></p>
<form class="filters" method="get">
  <label>Form version <?= filter_select('form', ['1.0' => 'v1.0 (before 11 May)', '2.0' => 'v2.0'], $form) ?></label>
  <label>Site <?= filter_select('site', distinct_options('SELECT DISTINCT site_name FROM v_subject_coded ORDER BY 1'), $site) ?></label>
  <label>Medicine recorded <?= filter_select('med', $medOptions, $med, 'Any') ?></label>
  <label>Systolic BP at least <input name="min_sys" value="<?= h($minSys) ?>" size="5" inputmode="numeric"></label>
  <input type="hidden" name="sort" value="<?= h($spec[0]) ?>"><input type="hidden" name="dir" value="<?= h($spec[1]) ?>">
  <button class="btn">Filter</button> <a class="btn secondary" href="ecrfs.php">Clear</a>
</form>
<p class="muted"><?= count($rows) ?> eCRF(s).</p>
<div class="table-wrap"><table>
  <tr><th><?= sort_link('Date', 'date', $spec) ?></th><th><?= sort_link('Subject', 'code', $spec) ?></th><th>Site</th><th>Visit</th><th>Form</th>
      <th class="num"><?= sort_link('HR', 'hr', $spec) ?></th><th><?= sort_link('BP', 'sys', $spec) ?></th>
      <th class="num"><?= sort_link('Temp', 'temp', $spec) ?></th><th><?= sort_link('Medicines', 'meds', $spec) ?></th><th></th></tr>
  <?php foreach ($rows as $r): $meds = $r['med_names'] ? json_decode($r['med_names'], true) : []; ?>
  <tr><td><?= h($r['scheduled_date']) ?></td>
      <td><a href="subject.php?code=<?= h($r['subject_code']) ?>"><?= h($r['subject_code']) ?></a></td>
      <td><?= h($r['site_name']) ?></td><td><?= h($r['visit_type']) ?></td><td>v<?= h($r['form_version']) ?></td>
      <td class="num"><?= h($r['heart_rate']) ?></td>
      <td><?= $r['systolic_bp'] ? h($r['systolic_bp'] . '/' . $r['diastolic_bp']) : '' ?></td>
      <td class="num"><?= h($r['temperature_c']) ?></td><td><?= h(implode(', ', $meds)) ?></td>
      <td><a href="ecrf.php?id=<?= (int)$r['ecrf_id'] ?>">Open</a></td></tr>
  <?php endforeach; ?>
</table></div>
<?php page_footer();
