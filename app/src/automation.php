<?php
declare(strict_types=1);

/*
 * Automation helpers shared by the Automation page (web) and the scheduler
 * (tools/run_automation.php, started by Windows Task Scheduler).
 *
 *   checks_run()      calls the stored procedure sp_run_checks (the same one the
 *                     nightly MySQL event calls) and returns its CheckRun row
 *   schedule_create() validates and saves a ReportSchedule row
 *   schedule_send()   builds the email with report_email_build() (the same code
 *                     as the Email button), delivers it, records the result
 *                     and, for the scheduler, moves next_run_at on
 */
require_once __DIR__ . '/report_mail.php';

const SCHEDULE_FREQS = ['Daily' => 'Every day', 'Weekdays' => 'Weekdays (Mon to Fri)', 'Weekly' => 'Once a week'];
const WEEKDAY_NAMES  = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday',
                        5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
const MAX_ACTIVE_SCHEDULES = 20;

/* ---------------------------------------------------------------- checks */

/** Run every rule now. $source: MANUAL (button) or SCHEDULER (catch-up run). */
function checks_run(PDO $admin, string $source, ?int $userId): array
{
    $st = $admin->prepare('CALL sp_run_checks(?, ?, @run_id)');
    $st->execute([$source, $userId]);
    $st->closeCursor();
    return q('SELECT * FROM CheckRun WHERE run_id = @run_id', [], $admin)[0];
}

/** Most recent successful run that was not a test, or null. */
function checks_last_ok(PDO $pdo): ?array
{
    return q("SELECT * FROM v_check_run WHERE status = 'OK' AND source <> 'TEST'
              ORDER BY run_id DESC LIMIT 1", [], $pdo)[0] ?? null;
}

/* ---------------------------------------------------------------- schedules */

/** Reports that can be scheduled: the three reports plus the findings digest. */
function schedule_reports(): array
{
    $out = [];
    foreach (report_defs() as $key => $d) {
        $out[$key] = $key === 'findings' ? 'Automated check findings (digest)' : $d['title'];
    }
    return $out;
}

function schedule_describe(array $s): string
{
    $t = substr((string)$s['send_time'], 0, 5);
    return match ($s['frequency']) {
        'Weekly'   => 'Every ' . (WEEKDAY_NAMES[(int)$s['weekday']] ?? '?') . " at $t",
        'Weekdays' => "Weekdays at $t",
        default    => "Every day at $t",
    };
}

/** The filters a schedule will use, as "label: value" text. */
function schedule_filters_text(array $s): string
{
    $def = report_def($s['report_key']);
    if ($def === null) {
        return '';
    }
    $req = report_request($def, json_decode($s['params'], true) ?: [], false);
    $parts = [];
    foreach (report_active_filters($req) as $label => $v) {
        $parts[] = "$label: $v";
    }
    return implode('; ', $parts);
}

/**
 * Validate the form and save a schedule. $src holds the report key ('r'), its
 * filters and sort (as on the report page) plus title, to, frequency, weekday,
 * send_time and skip_empty. The filters go through report_request(), so only
 * whitelisted values are ever stored. Returns the new schedule_id.
 */
function schedule_create(PDO $admin, array $user, array $src): int
{
    $key = is_string($src['r'] ?? null) ? $src['r'] : '';
    $def = report_def($key) ?? throw new InvalidArgumentException('Choose a report.');
    $str = fn(string $k) => is_string($src[$k] ?? null) ? trim($src[$k]) : '';

    $to = $str('to');
    if (!mail_valid_address($to)) {
        throw new InvalidArgumentException('Enter one valid email address.');
    }
    $freq = $str('frequency');
    if (!isset(SCHEDULE_FREQS[$freq])) {
        throw new InvalidArgumentException('Choose how often to send it.');
    }
    $weekday = $freq === 'Weekly' ? (int)$str('weekday') : null;
    if ($freq === 'Weekly' && !isset(WEEKDAY_NAMES[$weekday])) {
        throw new InvalidArgumentException('Choose the day of the week.');
    }
    $time = $str('send_time');
    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
        throw new InvalidArgumentException('Send time must look like 07:00.');
    }
    $active = (int)q1('SELECT COUNT(*) FROM ReportSchedule WHERE is_active', [], $admin);
    if ($active >= MAX_ACTIVE_SCHEDULES) {
        throw new InvalidArgumentException('There are already ' . MAX_ACTIVE_SCHEDULES . ' active schedules. Pause one first.');
    }

    $req = report_request($def, $src, false);
    $params = report_query($req);
    unset($params['r']);
    $title = mb_substr(preg_replace('/[\x00-\x1F\x7F]/u', '', $str('title')) ?? '', 0, 100);
    if ($title === '') {
        $title = $def['title'];
    }
    $skip = ($src['skip_empty'] ?? '') === '1';

    $admin->prepare('INSERT INTO ReportSchedule (title, report_key, params, recipient, frequency, weekday, send_time,
                                                 skip_empty, next_run_at, created_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, fn_next_run(?, ?, ?, NOW()), ?)')
          ->execute([$title, $key, json_encode($params, JSON_UNESCAPED_SLASHES), $to, $freq, $weekday, $time,
                     (int)$skip, $freq, $weekday, $time, $user['id']]);
    $id = (int)$admin->lastInsertId();
    audit('INSERT', 'ReportSchedule', (string)$id, ['title' => $title, 'report' => $key, 'filters' => $req['f'],
          'to' => $to, 'frequency' => $freq, 'weekday' => $weekday, 'send_time' => $time, 'skip_empty' => $skip],
          $user['id'], $admin);
    return $id;
}

/**
 * Send one schedule now.
 *  $source  'scheduler' (Task Scheduler, moves next_run_at on) or 'manual' (Send now button, does not)
 *  $actor   the Admin who pressed Send now; for the scheduler the audit row is
 *           attributed to the schedule's creator, with source = scheduler.
 * Returns [status, result text, rows]. Never throws for a delivery problem:
 * a failure is recorded on the schedule and in the audit log instead.
 */
function schedule_send(PDO $admin, array $s, string $source, ?array $actor = null): array
{
    $rows = 0;
    $msgId = null;
    try {
        $def = report_def($s['report_key']) ?? throw new RuntimeException('Unknown report ' . $s['report_key']);
        $req = report_request($def, json_decode($s['params'], true) ?: [], false);
        $sender = $actor
            ? ['name' => $actor['name'], 'role' => $actor['role']]
            : ['name' => 'Scheduled email', 'role' => 'Automation'];
        $intro = [
            'Scheduled email "' . $s['title'] . '": ' . schedule_describe($s) . '.',
            $source === 'manual' ? 'Sent early with "Send now" from the Automation page.' : 'Sent automatically by the report scheduler.',
        ];
        $mail = report_email_build($req, $sender, $s['recipient'], $intro);
        $rows = $mail['rows'];
        $msgId = $mail['msg_id'];
        if ($rows === 0 && $s['skip_empty']) {
            [$status, $result] = ['Skipped', 'nothing to report (0 rows), so no email was sent'];
        } else {
            $result = mail_deliver($s['recipient'], $mail['raw']);
            $status = mail_config()['transport'] === 'smtp' ? 'Sent' : 'Saved';
        }
    } catch (Throwable $e) {
        [$status, $result] = ['Failed', mb_substr($e->getMessage(), 0, 200)];
    }

    $advance = $source === 'scheduler' ? 1 : 0;
    $admin->prepare('UPDATE ReportSchedule
                        SET last_run_at = NOW(), last_status = ?, last_result = ?,
                            next_run_at = IF(?, fn_next_run(frequency, weekday, send_time, NOW()), next_run_at)
                      WHERE schedule_id = ?')
          ->execute([$status, mb_substr($result, 0, 255), $advance, $s['schedule_id']]);

    audit('SCHEDULE_RUN', 'ReportSchedule', (string)$s['schedule_id'], [
        'source' => $source, 'title' => $s['title'], 'report' => $s['report_key'], 'to' => $s['recipient'],
        'rows' => $rows, 'status' => $status, 'result' => $result, 'message_id' => $msgId,
        'transport' => mail_config()['transport'],
    ], $actor['id'] ?? (int)$s['created_by'], $admin);

    return [$status, $result, $rows];
}

/** Schedules whose time has come. */
function schedules_due(PDO $admin): array
{
    return q('SELECT * FROM ReportSchedule WHERE is_active AND next_run_at <= NOW() ORDER BY next_run_at, schedule_id', [], $admin);
}

/* ---------------------------------------------------------------- scheduler heartbeat */

function automation_dir(): string
{
    $d = config()['phase5_root'] . '/storage/logs';
    if (!is_dir($d)) {
        @mkdir($d, 0700, true);
    }
    return $d;
}

/** What the last run of run_automation.php did (written by the scheduler, read by the page). */
function automation_last(): ?array
{
    $f = automation_dir() . '/automation_last.json';
    return is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: null) : null;
}

/* ---------------------------------------------------------------- shared form */

/**
 * The "schedule by email" fields. $q = report_query() of the view being
 * scheduled (hidden fields), or [] to let the Admin pick a report.
 */
function schedule_form_html(array $q, string $defaultTitle = ''): string
{
    $h = '<form method="post" action="automation.php#schedules" class="filters schedule-form">' . csrf_field()
       . '<input type="hidden" name="do" value="create_schedule">';
    if ($q) {
        foreach ($q as $k => $v) {
            $h .= '<input type="hidden" name="' . h($k) . '" value="' . h($v) . '">';
        }
    } else {
        $h .= '<label>Report <select name="r">';
        foreach (schedule_reports() as $k => $t) {
            $h .= '<option value="' . h($k) . '"' . ($k === 'findings' ? ' selected' : '') . '>' . h($t) . '</option>';
        }
        $h .= '</select></label>';
    }
    $h .= '<label>Name <input name="title" maxlength="100" value="' . h($defaultTitle) . '" placeholder="e.g. Weekly safety review"></label>'
        . '<label>Send to <input type="email" name="to" required maxlength="254" placeholder="name@example.com"></label>'
        . '<label>How often <select name="frequency">';
    foreach (SCHEDULE_FREQS as $k => $t) {
        $h .= '<option value="' . h($k) . '">' . h($t) . '</option>';
    }
    $h .= '</select></label><label>Day (weekly) <select name="weekday">';
    foreach (WEEKDAY_NAMES as $k => $t) {
        $h .= '<option value="' . $k . '">' . h($t) . '</option>';
    }
    $h .= '</select></label>'
        . '<label>At <input type="time" name="send_time" value="07:00" required></label>'
        . '<label class="check"><span>&nbsp;</span><span><input type="checkbox" name="skip_empty" value="1"> Only when there are rows (alert)</span></label>'
        . '<button class="btn">Save schedule</button></form>';
    return $h;
}
