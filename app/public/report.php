<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/reports.php';

/*
 * One page renders all three reports: report.php?r=safety|sites|consent.
 * Filters and sort come from the URL (GET), so any view can be bookmarked,
 * and the export buttons pass the same values on.
 */
$u = require_login();
$def = report_def(get_str('r'));
if ($def === null) {
    http_response_code(404);
    page_header('Report not found');
    echo '<div class="card">No such report. <a href="reports.php">Back to reports</a></div>';
    page_footer();
    exit;
}

$req    = report_request($def, $_GET, is_admin());
$rows   = report_rows($req);
$totals = $rows ? report_totals($req) : null;
audit('REPORT_RUN', 'report', $def['key'], report_audit_detail($req, count($rows)));

/** Screen version of a cell: the plain value plus badges and links. */
function cell_html(string $key, array $col, array $r, bool $admin): string
{
    $v = $r[$key] ?? null;
    $t = h(report_cell($col, $v));
    $sev = ['Mild' => 'ok', 'Moderate' => 'warn', 'Severe' => 'bad'];
    return match ($key) {
        'subject_code'   => '<a href="subject.php?code=' . $t . '">' . $t . '</a>',
        'review_group'   => badge((string)$v, $v === 'Both' ? 'bad' : 'warn'),
        'alt_xuln'       => $v === null ? '' : badge($t, (float)$v > 1 ? 'bad' : 'ok'),
        'worst_severity' => $v === null ? '<span class="muted">none</span>' : badge((string)$v, $sev[$v] ?? ''),
        'key_narrative'  => $v === null ? '' : '<span class="narr">' . $t . '</span>',
        'attend_pct', 'ecrf_pct', 'mean_mg', 'ae_per_dosed'
                         => $v === null ? '<span class="muted">n/a</span>' : $t,
        'status_label'   => badge((string)$v, match ($r['status_code'] ?? '') {
                                'gap', 'early' => 'bad', 'risk', 'doc' => 'warn', 'compliant' => 'ok', default => '' }),
        'pdf_check'      => badge($t, str_starts_with((string)$v, 'OK') ? 'ok' : 'bad'),
        'v1_signed', 'v2_signed' => ($admin && $v !== null && ($id = $r[$key === 'v1_signed' ? 'v1_id' : 'v2_id'] ?? null))
                         ? $t . ' <a href="consent_pdf.php?id=' . (int)$id . '" target="_blank" title="Open the signed PDF">PDF</a>'
                         : $t,
        default          => $t,
    };
}

/** A <select> that may or may not offer "All". */
function report_select(string $name, array $flt, string $cur): string
{
    $all = $flt['all'] ?? 'All';
    $html = '<select name="' . h($name) . '">';
    if ($all !== false) {
        $html .= '<option value="">' . h($all) . '</option>';
    }
    foreach (report_filter_options($flt) as $value => $label) {
        $html .= '<option value="' . h($value) . '"' . ((string)$value === $cur ? ' selected' : '') . '>' . h($label) . '</option>';
    }
    return $html . '</select>';
}

$f = $req['f'];
$spec = $req['spec'];
$q = report_query($req);

page_header($def['title']);
?>
<p class="muted"><a href="reports.php">Reports</a> / <?= h($def['title']) ?>
  <?php foreach ($def['sources'] as $s) echo source_tag($s); ?></p>
<p><?= h($def['blurb']) ?></p>

<form class="filters" method="get">
  <input type="hidden" name="r" value="<?= h($def['key']) ?>">
  <?php foreach ($def['filters'] as $name => $flt): ?>
    <?php if ($flt['type'] === 'check'): ?>
      <label class="check"><span>&nbsp;</span><span><input type="checkbox" name="<?= h($name) ?>" value="1"<?= $f[$name] === '1' ? ' checked' : '' ?>> <?= h($flt['label']) ?></span></label>
    <?php else: ?>
      <label><?= h($flt['label']) ?>
        <?php if ($flt['type'] === 'select'): ?><?= report_select($name, $flt, $f[$name]) ?>
        <?php elseif ($flt['type'] === 'date'): ?><input type="date" name="<?= h($name) ?>" value="<?= h($f[$name]) ?>">
        <?php else: ?><input name="<?= h($name) ?>" value="<?= h($f[$name]) ?>" maxlength="60" placeholder="<?= h($flt['placeholder'] ?? '') ?>"><?php endif; ?>
      </label>
    <?php endif; ?>
  <?php endforeach; ?>
  <input type="hidden" name="sort" value="<?= h($spec[0]) ?>"><input type="hidden" name="dir" value="<?= h($spec[1]) ?>">
  <button class="btn">Run report</button> <a class="btn secondary" href="report.php?r=<?= h($def['key']) ?>">Reset</a>
</form>

<div class="export-bar">
  <span class="muted">Export this view:</span>
  <a class="btn secondary" href="export.php?<?= h(http_build_query($q + ['format' => 'csv'])) ?>">CSV</a>
  <a class="btn secondary" href="export.php?<?= h(http_build_query($q + ['format' => 'pdf'])) ?>" target="_blank">PDF</a>
  <form method="post" action="email_report.php" class="email-form">
    <?= csrf_field() ?>
    <?php foreach ($q as $k => $v): ?><input type="hidden" name="<?= h($k) ?>" value="<?= h($v) ?>"><?php endforeach; ?>
    <input type="email" name="to" required maxlength="254" placeholder="name@example.com" aria-label="Email address">
    <button class="btn secondary">Email CSV + PDF</button>
  </form>
  <span class="muted small">Emails always contain subject codes only, never names.</span>
</div>

<p><strong><?= h(($def['summary'])($rows, $f)) ?></strong></p>
<div class="table-wrap"><table class="report">
  <tr>
    <?php foreach ($req['cols'] as $k => $c): ?>
      <th class="<?= !empty($c['num']) ? 'num' : '' ?>"><?= ($c['sort'] ?? true) === false ? h($c['label']) : sort_link($c['label'], $k, $spec) ?></th>
    <?php endforeach; ?>
  </tr>
  <?php if (!$rows): ?>
    <tr><td colspan="<?= count($req['cols']) ?>" class="muted">No rows match these filters.</td></tr>
  <?php endif; ?>
  <?php foreach ($rows as $r): ?>
  <tr>
    <?php foreach ($req['cols'] as $k => $c): ?>
      <td class="<?= !empty($c['num']) ? 'num' : '' ?>"><?= cell_html($k, $c, $r, $req['identified']) ?></td>
    <?php endforeach; ?>
  </tr>
  <?php endforeach; ?>
  <?php if ($totals && $rows): ?>
  <tr class="totals">
    <?php foreach ($req['cols'] as $k => $c): ?>
      <td class="<?= !empty($c['num']) ? 'num' : '' ?>"><?= cell_html($k, $c, $totals, false) ?></td>
    <?php endforeach; ?>
  </tr>
  <?php endif; ?>
</table></div>

<div class="card notes">
  <h2>How this report is calculated</h2>
  <ul><?php foreach ($def['notes'] as $n) echo '<li>' . h($n) . '</li>'; ?></ul>
</div>
<?php page_footer();
