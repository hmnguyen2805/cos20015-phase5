<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
$u = require_login();
$admin = is_admin();

$site = get_str('site');
$sex  = get_str('sex');
$find = get_str('q');

$where = [];
$params = [];
if ($site !== '') { $where[] = 's.site_name = ?'; $params[] = $site; }
if ($sex !== '')  { $where[] = 's.sex = ?';       $params[] = $sex; }
if ($find !== '') {
    // Users search by code only. Admins can also search by name (base table).
    if ($admin) {
        $where[] = "(s.subject_code LIKE ? OR CONCAT(p.first_name, ' ', p.last_name) LIKE ?)";
        $params[] = "%$find%"; $params[] = "%$find%";
    } else {
        $where[] = 's.subject_code LIKE ?';
        $params[] = "%$find%";
    }
}

$sortable = [
    'code' => 's.subject_code',
    'site' => 's.site_name, s.subject_code',
    'age'  => 's.age_at_enrolment, s.subject_code',
    'enrol'=> 's.enrolment_date, s.subject_code',
    'visits' => 'visits, s.subject_code',
    'aes'  => 'aes, s.subject_code',
    'high' => 'high_labs, s.subject_code',
];
$spec = sort_spec($sortable, 'code');

// Admin joins the base Subject table to show names; User never touches it.
$nameCols = $admin ? ", CONCAT(p.first_name, ' ', p.last_name) AS full_name, p.date_of_birth" : '';
$nameJoin = $admin ? 'JOIN Subject p ON p.subject_id = CAST(SUBSTRING(s.subject_code, 2) AS UNSIGNED)' : '';

$sql = "SELECT s.subject_code, s.site_name, s.sex, s.age_at_enrolment, s.enrolment_date $nameCols,
               COALESCE(v.visits, 0) AS visits, COALESCE(v.done, 0) AS done,
               COALESCE(a.aes, 0) AS aes, COALESCE(l.high_labs, 0) AS high_labs
        FROM v_subject_coded s
        $nameJoin
        LEFT JOIN (SELECT subject_code, COUNT(*) AS visits, SUM(status = 'Completed') AS done
                   FROM v_visit_coded GROUP BY subject_code) v ON v.subject_code = s.subject_code
        LEFT JOIN (SELECT subject_code, COUNT(*) AS aes FROM v_ae_coded GROUP BY subject_code) a
               ON a.subject_code = s.subject_code
        LEFT JOIN (SELECT subject_code, COUNT(*) AS high_labs FROM v_lab_coded WHERE flag = 'H'
                   GROUP BY subject_code) l ON l.subject_code = s.subject_code
        " . ($where ? 'WHERE ' . implode(' AND ', $where) : '') . "
        {$spec[2]}";
$rows = q($sql, $params);

page_header('Subjects');
?>
<form class="filters" method="get">
  <label>Site <?= filter_select('site', distinct_options('SELECT DISTINCT site_name FROM v_subject_coded ORDER BY 1'), $site) ?></label>
  <label>Sex <?= filter_select('sex', ['F' => 'Female', 'M' => 'Male'], $sex) ?></label>
  <label><?= $admin ? 'Code or name' : 'Subject code' ?> <input name="q" value="<?= h($find) ?>" placeholder="<?= $admin ? 'S007 or surname' : 'S007' ?>"></label>
  <input type="hidden" name="sort" value="<?= h($spec[0]) ?>"><input type="hidden" name="dir" value="<?= h($spec[1]) ?>">
  <button class="btn">Filter</button> <a class="btn secondary" href="subjects.php">Clear</a>
</form>
<p class="muted"><?= count($rows) ?> subject(s). <?= source_tag('structured') ?></p>
<div class="table-wrap"><table>
  <tr>
    <th><?= sort_link('Code', 'code', $spec) ?></th>
    <?php if ($admin): ?><th>Name</th><th>Date of birth</th><?php endif; ?>
    <th><?= sort_link('Site', 'site', $spec) ?></th><th>Sex</th>
    <th class="num"><?= sort_link('Age', 'age', $spec) ?></th>
    <th><?= sort_link('Enrolled', 'enrol', $spec) ?></th>
    <th class="num"><?= sort_link('Visits (done)', 'visits', $spec) ?></th>
    <th class="num"><?= sort_link('AEs', 'aes', $spec) ?></th>
    <th class="num"><?= sort_link('High labs', 'high', $spec) ?></th>
  </tr>
  <?php foreach ($rows as $r): ?>
  <tr>
    <td><a href="subject.php?code=<?= h($r['subject_code']) ?>"><?= h($r['subject_code']) ?></a></td>
    <?php if ($admin): ?><td><?= h($r['full_name']) ?></td><td><?= h($r['date_of_birth']) ?></td><?php endif; ?>
    <td><?= h($r['site_name']) ?></td><td><?= h($r['sex']) ?></td>
    <td class="num"><?= h($r['age_at_enrolment']) ?></td><td><?= h($r['enrolment_date']) ?></td>
    <td class="num"><?= (int)$r['visits'] ?> (<?= (int)$r['done'] ?>)</td>
    <td class="num"><?= (int)$r['aes'] ?></td>
    <td class="num"><?= (int)$r['high_labs'] ? badge((string)$r['high_labs'], 'bad') : '0' ?></td>
  </tr>
  <?php endforeach; ?>
</table></div>
<?php page_footer();
