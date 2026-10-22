<?php
declare(strict_types=1);

/* Page chrome shared by every screen: header, navigation, flashes, footer. */

function nav_items(): array
{
    // [file, label, admin only?]
    return [
        ['dashboard.php',   'Dashboard', false],
        ['subjects.php',    'Subjects',  false],
        ['ecrfs.php',       'eCRFs',     false],
        ['consents.php',    'Consents',  false],
        ['search.php',      'Search',    false],
        ['reports.php',     'Reports',   false],
        ['automation.php',  'Automation', false],
        ['ai_review.php',   'AI review', true],
        ['admin_users.php', 'Users',     true],
        ['admin_audit.php', 'Audit log', true],
    ];
}

/** Badge showing which kind of data a panel comes from (used throughout the demo). */
function source_tag(string $kind): string
{
    $labels = ['structured' => 'Structured', 'semi' => 'Semi-structured (JSON)', 'unstructured' => 'Unstructured (PDF / text)'];
    return '<span class="tag tag-' . h($kind) . '">' . h($labels[$kind] ?? $kind) . '</span>';
}

function page_header(string $title): void
{
    $u = current_user();
    $self = basename($_SERVER['SCRIPT_NAME'] ?? '');
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?> | <?= h(APP_NAME) ?></title>
<link rel="stylesheet" href="assets/app.css">
</head>
<body>
<header class="topbar">
  <a class="brand" href="dashboard.php"><?= h(APP_NAME) ?></a>
  <?php if ($u): ?>
  <nav>
    <?php foreach (nav_items() as [$file, $label, $adminOnly]):
        if ($adminOnly && $u['role'] !== 'Admin') continue; ?>
      <a href="<?= h($file) ?>" class="<?= $self === $file || ($file === 'reports.php' && $self === 'report.php') ? 'active' : '' ?>"><?= h($label) ?></a>
    <?php endforeach; ?>
  </nav>
  <div class="who">
    <span><?= h($u['name']) ?></span>
    <span class="role role-<?= h(strtolower($u['role'])) ?>"><?= h($u['role']) ?></span>
    <form method="post" action="logout.php" class="inline"><?= csrf_field() ?><button class="link">Log out</button></form>
  </div>
  <?php endif; ?>
</header>
<main>
<h1><?= h($title) ?></h1>
<?php foreach (take_flashes() as [$type, $msg]): ?>
  <div class="flash flash-<?= h($type) ?>"><?= h($msg) ?></div>
<?php endforeach;
}

function page_footer(): void
{
    ?>
</main>
<footer>Synthetic data for COS20015 coursework only. No real people. Subjects are shown as codes (S001) unless you are an Admin.</footer>
</body>
</html>
<?php
}
