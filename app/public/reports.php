<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/reports.php';
$u = require_login();

page_header('Reports');
?>
<p class="muted">Each report can be filtered and sorted, then exported as CSV or PDF or emailed.
Every run and export is written to the audit log.</p>
<div class="grid">
  <?php foreach (report_defs() as $key => $d): if (!empty($d['hidden'])) continue; ?>
  <div class="card">
    <h2><a href="report.php?r=<?= h($key) ?>"><?= h($d['title']) ?></a></h2>
    <p><?php foreach ($d['sources'] as $s) echo source_tag($s); ?></p>
    <p class="muted"><?= h($d['blurb']) ?></p>
    <a class="btn" href="report.php?r=<?= h($key) ?>">Open report</a>
  </div>
  <?php endforeach; ?>
</div>
<?php page_footer();
