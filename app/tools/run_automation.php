<?php
declare(strict_types=1);

/*
 * COS20015 Phase-05 automation runner. Windows Task Scheduler starts it every
 * 15 minutes (scripts\register_automation_task.ps1). Each run:
 *   1. Catch-up checks: if the nightly MySQL event has not produced a good run
 *      in the last 24 hours (e.g. the PC was off at 02:00), run sp_run_checks now.
 *   2. AI extraction: send new adverse-event narratives to the AI model and queue the
 *      results for Admin review (only if app\config\ai.php exists).
 *   3. Scheduled emails: send every ReportSchedule whose time has come.
 * It writes a log (storage\logs\automation.log) and a heartbeat file that the
 * Automation page shows ("scheduler last ran ...").
 *
 *   C:\xampp\php\php.exe app\tools\run_automation.php            run everything that is due
 *   C:\xampp\php\php.exe app\tools\run_automation.php --checks   also force a check run now
 *   ... run_automation.php --schedule=7   only schedule 7, if it is due (used by the smoke test)
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/automation.php';
if (is_file(__DIR__ . '/../src/ai.php')) {
    require_once __DIR__ . '/../src/ai.php';
}

$opts = getopt('', ['checks', 'schedule:']);
$only = isset($opts['schedule']) ? (int)$opts['schedule'] : null;   // one schedule only: skip steps 1 and 2
$dir = automation_dir();

// Only one runner at a time (a slow SMTP server must not cause double emails).
$lock = fopen("$dir/automation.lock", 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    echo "Another run is still in progress; exiting.\n";
    exit(0);
}

$logFile = "$dir/automation.log";
$log = function (string $msg) use ($logFile): void {
    $line = report_now()->format('Y-m-d H:i:s') . "  $msg";
    echo $line . "\n";
    file_put_contents($logFile, $line . "\n", FILE_APPEND | LOCK_EX);
};

$started = report_now();
$summary = ['started_at' => $started->format('c'), 'checks' => null, 'ai' => null, 'emails' => [], 'errors' => 0];
$admin = db('Admin');

/* 1. Catch-up checks */
if ($only !== null) {
    $summary['checks'] = 'skipped (--schedule)';
} else {
    try {
        $last = checks_last_ok($admin);
        $stale = $last === null || strtotime($last['started_at']) < time() - 24 * 3600;
        if ($stale || isset($opts['checks'])) {
            $run = checks_run($admin, 'SCHEDULER', null);
            $summary['checks'] = "run $run[run_id]: $run[found] found, $run[new_open] new, $run[resolved] resolved";
            $log('Checks ' . $summary['checks'] . ($stale ? ' (no good run in the last 24 h)' : ' (forced)'));
        } else {
            $summary['checks'] = 'not needed (last good run ' . $last['started_at'] . ", $last[source])";
        }
    } catch (Throwable $e) {
        $summary['errors']++;
        $summary['checks'] = 'FAILED: ' . $e->getMessage();
        $log('Checks FAILED: ' . $e->getMessage());
    }
}

/* 2. AI extraction of new adverse-event narratives */
if ($only !== null) {
    $summary['ai'] = 'skipped (--schedule)';
} elseif (function_exists('ai_enabled') && ai_enabled()) {
    try {
        $r = ai_extract_pending($admin, null, 20);
        $summary['ai'] = "$r[done] extracted, $r[failed] failed";
        if ($r['done'] || $r['failed']) {
            $log('AI extraction: ' . $summary['ai']);
        }
        $summary['errors'] += $r['failed'];
    } catch (Throwable $e) {
        $summary['errors']++;
        $summary['ai'] = 'FAILED: ' . $e->getMessage();
        $log('AI extraction FAILED: ' . $e->getMessage());
    }
} else {
    $summary['ai'] = 'off (no app\\config\\ai.php)';
}

/* 3. Scheduled emails */
try {
    foreach (schedules_due($admin) as $s) {
        if ($only !== null && (int)$s['schedule_id'] !== $only) {
            continue;
        }
        [$status, $result, $rows] = schedule_send($admin, $s, 'scheduler');
        $summary['emails'][] = "#$s[schedule_id] $s[title]: $status ($rows rows)";
        $log("Schedule #$s[schedule_id] \"$s[title]\" to $s[recipient]: $status, $rows rows, $result");
        if ($status === 'Failed') {
            $summary['errors']++;
        }
    }
} catch (Throwable $e) {
    $summary['errors']++;
    $log('Schedules FAILED: ' . $e->getMessage());
}

$summary['finished_at'] = report_now()->format('c');
if ($only === null) {   // a one-schedule test run is not a real heartbeat
    file_put_contents("$dir/automation_last.json", json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}
echo 'Done: ' . count($summary['emails']) . " email(s), $summary[errors] error(s).\n";
flock($lock, LOCK_UN);
exit($summary['errors'] ? 1 : 0);
