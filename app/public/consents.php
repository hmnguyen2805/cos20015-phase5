<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
$u = require_login();
$admin = is_admin();

$version = get_str('version');
$site = get_str('site');

$where = [];
$params = [];
if ($version !== '') { $where[] = 'c.version = ?'; $params[] = $version; }
if ($site !== '')    { $where[] = 'c.site_name = ?'; $params[] = $site; }

$spec = sort_spec([
    'code'   => 'c.subject_code, c.signed_date',
    'site'   => 'c.site_name, c.subject_code',
    'signed' => 'c.signed_date, c.subject_code',
    'ver'    => 'c.version, c.subject_code',
], 'code');

// Admins also get file size and the SHA-256 recorded when the PDF was loaded.
$adminCols = $admin ? ', d.file_size_bytes, LEFT(d.sha256, 12) AS sha_short' : '';
$adminJoin = $admin ? 'LEFT JOIN ConsentDocument d ON d.consent_id = c.consent_id' : '';

$rows = q("SELECT c.consent_id, c.subject_code, c.site_name, c.version, c.signed_date,
                  c.has_document, c.page_count $adminCols
           FROM v_consent_coded c $adminJoin
           " . ($where ? 'WHERE ' . implode(' AND ', $where) : '') . " {$spec[2]}", $params);

page_header('Informed consent');
?>
<p class="muted">Consent forms are signed PDFs stored on disk (unstructured). The database keeps the path,
size, a SHA-256 fingerprint and the extracted text, which has a FULLTEXT index. <?= source_tag('structured') ?><?= source_tag('unstructured') ?></p>
<form class="filters" method="get">
  <label>Version <?= filter_select('version', ['1.0' => 'v1.0', '2.0' => 'v2.0 (amendment, 11 May 2026)'], $version) ?></label>
  <label>Site <?= filter_select('site', distinct_options('SELECT DISTINCT site_name FROM v_consent_coded ORDER BY 1'), $site) ?></label>
  <input type="hidden" name="sort" value="<?= h($spec[0]) ?>"><input type="hidden" name="dir" value="<?= h($spec[1]) ?>">
  <button class="btn">Filter</button> <a class="btn secondary" href="consents.php">Clear</a>
</form>
<p class="muted"><?= count($rows) ?> consent record(s).</p>
<div class="table-wrap"><table>
  <tr><th><?= sort_link('Subject', 'code', $spec) ?></th><th><?= sort_link('Site', 'site', $spec) ?></th>
      <th><?= sort_link('Version', 'ver', $spec) ?></th><th><?= sort_link('Signed', 'signed', $spec) ?></th>
      <th class="num">Pages</th><?php if ($admin): ?><th class="num">Size</th><th>SHA-256</th><?php endif; ?><th>Document</th></tr>
  <?php foreach ($rows as $r): ?>
  <tr><td><a href="subject.php?code=<?= h($r['subject_code']) ?>"><?= h($r['subject_code']) ?></a></td>
      <td><?= h($r['site_name']) ?></td><td><?= h($r['version']) ?></td><td><?= h($r['signed_date']) ?></td>
      <td class="num"><?= h($r['page_count']) ?></td>
      <?php if ($admin): ?><td class="num"><?= $r['file_size_bytes'] ? number_format((int)$r['file_size_bytes'] / 1024, 1) . ' KB' : '' ?></td>
      <td><code><?= h($r['sha_short']) ?></code></td><?php endif; ?>
      <td><?php if (!$r['has_document']): ?><span class="muted">no file</span>
          <?php elseif ($admin): ?><a href="consent_pdf.php?id=<?= (int)$r['consent_id'] ?>" target="_blank">Open PDF</a>
          <?php else: ?><span class="muted">Admin only</span><?php endif; ?></td></tr>
  <?php endforeach; ?>
</table></div>
<?php page_footer();
