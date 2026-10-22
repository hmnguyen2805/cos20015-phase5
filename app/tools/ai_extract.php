<?php
declare(strict_types=1);

/*
 * Run the AI extraction from the command line (the scheduler does the same
 * every 15 minutes, the AI review page has a button for it).
 *
 *   C:\xampp\php\php.exe app\tools\ai_extract.php            every narrative with no answer yet (max 25)
 *   C:\xampp\php\php.exe app\tools\ai_extract.php --ae=11    just AE 11 (asks again even if answered)
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/ai.php';

if (!ai_enabled()) {
    fwrite(STDERR, "AI is not set up: copy app\\config\\ai.example.php to app\\config\\ai.php and add an API key.\n");
    exit(1);
}
$opts = getopt('', ['ae:', 'limit:']);
$admin = db('Admin');
echo 'Provider: ' . ai_config()['provider'] . ', model ' . ai_config()['model'] . ', prompt ' . AI_PROMPT_VERSION . "\n";

if (isset($opts['ae'])) {
    $r = ai_extract_one($admin, (int)$opts['ae'], null);
    echo $r['ok'] ? "AE $opts[ae]: stored as extraction $r[id] (Pending)\n" : "AE $opts[ae]: FAILED: $r[error]\n";
    exit($r['ok'] ? 0 : 1);
}
$r = ai_extract_pending($admin, null, (int)($opts['limit'] ?? 25));
echo "$r[done] stored for review, $r[failed] failed.\n";
foreach ($r['errors'] as $e) {
    echo "  $e\n";
}
exit($r['failed'] ? 1 : 0);
