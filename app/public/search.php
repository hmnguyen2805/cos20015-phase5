<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
$u = require_login();
$admin = is_admin();

$term = get_str('q');
$scope = get_str('scope', 'ae');
if (!$admin) $scope = 'ae';   // consent text is identifiable: Admin only

/** Short excerpt around the first matching word, with matches highlighted. */
function excerpt(string $text, string $term, int $width = 220): string
{
    $words = array_filter(preg_split('/[^\p{L}\p{N}]+/u', $term), fn($w) => mb_strlen($w) >= 3);
    $pos = 0;
    foreach ($words as $w) {
        $p = mb_stripos($text, $w);
        if ($p !== false) { $pos = max(0, $p - 60); break; }
    }
    $cut = mb_substr($text, $pos, $width);
    $out = h(($pos > 0 ? '... ' : '') . $cut . (mb_strlen($text) > $pos + $width ? ' ...' : ''));
    foreach ($words as $w) {
        $out = preg_replace('/(' . preg_quote(h($w), '/') . ')/iu', '<mark>$1</mark>', $out);
    }
    return $out;
}

$aeRows = $consentRows = [];
if ($term !== '') {
    if ($scope === 'ae' || $scope === 'all') {
        // FULLTEXT index ft_ae_narrative, reached through the coded view.
        $aeRows = q('SELECT ae_id, subject_code, site_name, onset_date, severity, event_term, narrative,
                            MATCH(narrative) AGAINST (? IN BOOLEAN MODE) AS score
                     FROM v_ae_coded
                     WHERE MATCH(narrative) AGAINST (? IN BOOLEAN MODE)
                     ORDER BY score DESC, onset_date', [$term, $term]);
    }
    if ($admin && ($scope === 'consent' || $scope === 'all')) {
        // FULLTEXT index ft_consent_text over text extracted from the PDFs.
        $consentRows = q('SELECT d.consent_id, ic.subject_id, ic.version, ic.signed_date, d.extracted_text,
                                 MATCH(d.extracted_text) AGAINST (? IN BOOLEAN MODE) AS score
                          FROM ConsentDocument d JOIN InformedConsent ic ON ic.consent_id = d.consent_id
                          WHERE MATCH(d.extracted_text) AGAINST (? IN BOOLEAN MODE)
                          ORDER BY score DESC, ic.subject_id', [$term, $term]);
    }
}

page_header('Full-text search');
?>
<p class="muted">Searches free text with MySQL FULLTEXT indexes (boolean mode: <code>+liver -nausea</code>,
<code>"blood test"</code>, <code>hepat*</code>). Words shorter than 3 letters are ignored by the index. <?= source_tag('unstructured') ?></p>
<form class="filters" method="get">
  <label>Search for <input name="q" value="<?= h($term) ?>" size="30" placeholder="ALT, headache, liver ..." autofocus></label>
  <?php if ($admin): ?>
  <label>In <?= filter_select('scope', ['ae' => 'Adverse event narratives', 'consent' => 'Consent PDF text', 'all' => 'Both'], $scope, 'Adverse event narratives') ?></label>
  <?php endif; ?>
  <button class="btn">Search</button>
</form>

<?php if ($term !== ''): ?>
<?php if ($scope === 'ae' || $scope === 'all'): ?>
<h2>Adverse event narratives (<?= count($aeRows) ?>)</h2>
<div class="table-wrap"><table>
  <tr><th>Subject</th><th>Onset</th><th>Severity</th><th>Event</th><th>Narrative</th><th class="num">Relevance</th></tr>
  <?php foreach ($aeRows as $r): ?>
  <tr><td><a href="subject.php?code=<?= h($r['subject_code']) ?>"><?= h($r['subject_code']) ?></a></td>
      <td><?= h($r['onset_date']) ?></td><td><?= h($r['severity']) ?></td><td><?= h($r['event_term']) ?></td>
      <td><?= excerpt($r['narrative'], $term) ?></td><td class="num"><?= number_format((float)$r['score'], 2) ?></td></tr>
  <?php endforeach; ?>
</table></div>
<?php endif; ?>

<?php if ($admin && ($scope === 'consent' || $scope === 'all')): ?>
<h2>Consent documents (<?= count($consentRows) ?>)</h2>
<div class="table-wrap"><table>
  <tr><th>Subject</th><th>Version</th><th>Signed</th><th>Matching text</th><th class="num">Relevance</th><th></th></tr>
  <?php foreach ($consentRows as $r): $code = subject_code((int)$r['subject_id']); ?>
  <tr><td><a href="subject.php?code=<?= h($code) ?>"><?= h($code) ?></a></td><td><?= h($r['version']) ?></td>
      <td><?= h($r['signed_date']) ?></td><td><?= excerpt($r['extracted_text'], $term) ?></td>
      <td class="num"><?= number_format((float)$r['score'], 2) ?></td>
      <td><a href="consent_pdf.php?id=<?= (int)$r['consent_id'] ?>" target="_blank">PDF</a></td></tr>
  <?php endforeach; ?>
</table></div>
<?php endif; ?>
<?php endif;
page_footer();
