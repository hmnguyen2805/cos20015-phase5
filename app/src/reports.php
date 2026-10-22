<?php
declare(strict_types=1);

/*
 * The three Phase-05 reports, each defined ONCE here. The report page, the
 * CSV and PDF downloads and the email all call report_request() and
 * report_rows() with the same validated filters, so an export always holds
 * exactly what the screen showed.
 *
 * A report definition is an array:
 *   title, blurb, sources  page text and source badges
 *   filters                name => [type, label, options/default]
 *   columns                key  => [label, fmt, num, admin, show, sort, w]
 *                          admin: Admin-only (identifiable); w: PDF width in mm
 *   sql(f, identified, total)  returns [SQL, params]; the SQL ends with
 *                          "SELECT * FROM rpt ..." and report_rows() adds ORDER BY
 *   summary(rows, f)       one line of counts shown above the table
 *   notes                  definitions shown under the table and in the PDF
 *
 * Every report reads the pseudonymised views only, so it runs unchanged as
 * app_reader (User) or app_admin (Admin). Admins also get a name column.
 */

const AMENDMENT_DATE = '2026-05-11';   // protocol amendment, consent v2.0

function report_defs(): array
{
    static $defs = null;
    if ($defs !== null) {
        return $defs;
    }
    $sites = fn() => distinct_options('SELECT site_name FROM v_site_coded ORDER BY site_name');
    $drugs = fn() => distinct_options('SELECT DISTINCT drug_name FROM v_dose_coded ORDER BY drug_name');

    $defs = [
    /* ------------------------------------------------------------------ */
    'safety' => [
        'title'   => 'Safety review',
        'blurb'   => 'Subjects who need a safety review: the Phase-04 set operation A UNION B, where '
                   . 'A = given StudyDrugX at 200 mg or more and B = had a Moderate or Severe adverse event. '
                   . 'Each row adds the peak ALT (lab results), the highest vitals from the JSON eCRFs and '
                   . 'the most serious adverse event with its free-text narrative.',
        'sources' => ['structured', 'semi', 'unstructured'],
        'filters' => [
            'site'     => ['type' => 'select', 'label' => 'Site', 'options' => $sites],
            'group'    => ['type' => 'select', 'label' => 'Review group', 'options' => [
                              'Both' => 'Both (A INTERSECT B)', 'A only' => 'A only (200 mg, no mod/severe AE)',
                              'B only' => 'B only (mod/severe AE, not 200 mg)']],
            'severity' => ['type' => 'select', 'label' => 'Worst AE severity', 'options' => [
                              'Mild' => 'Mild', 'Moderate' => 'Moderate', 'Severe' => 'Severe']],
            'alt_high' => ['type' => 'check',  'label' => 'ALT above upper limit only'],
            'keyword'  => ['type' => 'text',   'label' => 'Any AE narrative contains', 'placeholder' => 'e.g. vomiting'],
        ],
        'default_sort' => 'subject_code',
        'columns' => [
            'subject_code'   => ['label' => 'Subject',        'w' => 14],
            'subject_name'   => ['label' => 'Name',           'w' => 26, 'admin' => true],
            'site_name'      => ['label' => 'Site',           'w' => 40],
            'review_group'   => ['label' => 'Group',          'w' => 14],
            'max_dose'       => ['label' => 'Max dose mg',    'w' => 14, 'fmt' => 'int', 'num' => true],
            'doses'          => ['label' => 'Doses',          'w' => 10, 'fmt' => 'int', 'num' => true],
            'peak_alt'       => ['label' => 'Peak ALT U/L',   'w' => 14, 'fmt' => 'int', 'num' => true],
            'alt_xuln'       => ['label' => 'ALT x ULN',      'w' => 12, 'fmt' => 'dec1', 'num' => true],
            'alt_high'       => ['label' => 'High ALTs',      'w' => 11, 'fmt' => 'int', 'num' => true],
            'max_sys'        => ['label' => 'Max SBP',        'w' => 11, 'fmt' => 'int', 'num' => true],
            'max_hr'         => ['label' => 'Max HR',         'w' => 10, 'fmt' => 'int', 'num' => true],
            'ae_count'       => ['label' => 'AEs',            'w' => 8,  'fmt' => 'int', 'num' => true],
            'worst_severity' => ['label' => 'Worst AE',       'w' => 15],
            'key_onset'      => ['label' => 'Onset',          'w' => 17, 'fmt' => 'date'],
            'key_narrative'  => ['label' => 'Most serious AE (narrative)', 'w' => 70, 'sort' => false],
        ],
        'sql' => 'report_safety_sql',
        'summary' => function (array $rows): string {
            $g = array_count_values(array_column($rows, 'review_group'));
            return sprintf('%d subject(s): %d in both sets, %d in A only, %d in B only.',
                count($rows), $g['Both'] ?? 0, $g['A only'] ?? 0, $g['B only'] ?? 0);
        },
        'notes' => [
            'Set A: subjects given StudyDrugX at a dose of 200 mg or more (Phase-03 Q1 cohort).',
            'Set B: subjects with at least one Moderate or Severe adverse event (Phase-03 Q2).',
            'The row set is A UNION B, so a subject in both sets appears once (group "Both").',
            'ALT x ULN = highest ALT divided by the upper limit of its reference range (40 U/L).',
            'Max SBP and Max HR come from the JSON eCRF documents (generated columns).',
            'Most serious AE = highest severity, then latest onset; the narrative is the free-text field.',
        ],
    ],
    /* ------------------------------------------------------------------ */
    'sites' => [
        'title'   => 'Site activity and exposure',
        'blurb'   => 'Per site (optionally per month): enrolment, visit attendance, drug exposure, adverse '
                   . 'events and eCRF completeness for the chosen period. The totals row is calculated '
                   . 'by the database, so distinct subject counts are not double-counted.',
        'sources' => ['structured', 'semi'],
        'filters' => [
            'from'      => ['type' => 'date',   'label' => 'From', 'default' => '2026-01-01'],
            'to'        => ['type' => 'date',   'label' => 'To',   'default' => '2026-12-31'],
            'site'      => ['type' => 'select', 'label' => 'Site', 'options' => $sites],
            'drug'      => ['type' => 'select', 'label' => 'Exposure drug', 'options' => $drugs,
                            'default' => 'StudyDrugX', 'all' => 'All drugs'],
            'breakdown' => ['type' => 'select', 'label' => 'Rows', 'all' => false, 'default' => 'site',
                            'options' => ['site' => 'One per site', 'month' => 'Per site and month']],
        ],
        'default_sort' => 'site_name',
        'tiebreak'     => '`site_name`, `period`',
        'columns' => [
            'site_name'    => ['label' => 'Site',            'w' => 46],
            'period'       => ['label' => 'Month',           'w' => 14, 'show' => fn($f) => $f['breakdown'] === 'month'],
            'enrolled'     => ['label' => 'Enrolled',        'w' => 14, 'fmt' => 'int', 'num' => true],
            'completed'    => ['label' => 'Visits done',     'w' => 15, 'fmt' => 'int', 'num' => true],
            'missed'       => ['label' => 'Missed',          'w' => 12, 'fmt' => 'int', 'num' => true],
            'booked'       => ['label' => 'Booked',          'w' => 12, 'fmt' => 'int', 'num' => true],
            'attend_pct'   => ['label' => 'Attendance %',    'w' => 17, 'fmt' => 'dec1', 'num' => true],
            'doses'        => ['label' => 'Doses',           'w' => 11, 'fmt' => 'int', 'num' => true],
            'dosed'        => ['label' => 'Subjects dosed',  'w' => 17, 'fmt' => 'int', 'num' => true],
            'total_mg'     => ['label' => 'Total mg',        'w' => 15, 'fmt' => 'int', 'num' => true],
            'mean_mg'      => ['label' => 'Mean mg',         'w' => 13, 'fmt' => 'dec1', 'num' => true],
            'aes'          => ['label' => 'AEs',             'w' => 9,  'fmt' => 'int', 'num' => true],
            'aes_modsev'   => ['label' => 'Mod/severe AEs',  'w' => 18, 'fmt' => 'int', 'num' => true],
            'ae_per_dosed' => ['label' => 'AEs per dosed subject', 'w' => 18, 'fmt' => 'dec2', 'num' => true],
            'ecrfs'        => ['label' => 'eCRFs',           'w' => 11, 'fmt' => 'int', 'num' => true],
            'ecrf_pct'     => ['label' => 'eCRF coverage %', 'w' => 17, 'fmt' => 'dec1', 'num' => true],
        ],
        'sql' => 'report_sites_sql',
        'has_totals' => true,
        'summary' => fn(array $rows, array $f): string => sprintf('%d row(s) for %s to %s.', count($rows), $f['from'], $f['to']),
        'notes' => [
            'Each figure is counted on its own date: visits and doses by visit date, AEs by onset date, enrolment by enrolment date.',
            'Attendance % = completed / (completed + missed). Booked visits are still in the future or not yet recorded.',
            'Doses, Subjects dosed, Total mg and Mean mg use the exposure drug filter. AEs are counted for every drug.',
            'eCRF coverage % = JSON eCRF documents / completed visits (100% means every completed visit has an eCRF).',
            'Sites with no subjects (e.g. Bayside Trials Centre) are listed with zeros in the one-per-site view.',
        ],
    ],
    /* ------------------------------------------------------------------ */
    'consent' => [
        'title'   => 'Consent compliance',
        'blurb'   => 'One row per subject. After the protocol amendment (v2.0, 11 May 2026) every attended visit '
                   . 'needs a v2.0 consent signed on or before that day. The consent PDFs are checked as well: '
                   . 'the version, participant code and signed date written in each PDF must match the record.',
        'sources' => ['structured', 'unstructured'],
        'filters' => [
            'site'    => ['type' => 'select', 'label' => 'Site', 'options' => $sites],
            'status'  => ['type' => 'select', 'label' => 'Status', 'options' => [
                             'gap'        => 'Gap: visit without v2.0',
                             'risk'       => 'At risk: booked visit without v2.0',
                             'early'      => 'Gap: visit before first consent',
                             'doc'        => 'Document issue',
                             'compliant'  => 'Compliant',
                             'not_needed' => 'Not required (no visits after amendment)']],
            'latest'  => ['type' => 'select', 'label' => 'Latest version held', 'options' => ['v1.0' => 'v1.0', 'v2.0' => 'v2.0']],
            'amend'   => ['type' => 'date',   'label' => 'Amendment effective', 'default' => AMENDMENT_DATE],
        ],
        'default_sort' => 'status_label',
        'columns' => [
            'subject_code'    => ['label' => 'Subject',          'w' => 14],
            'subject_name'    => ['label' => 'Name',             'w' => 28, 'admin' => true],
            'site_name'       => ['label' => 'Site',             'w' => 44],
            'enrolment_date'  => ['label' => 'Enrolled',         'w' => 18, 'fmt' => 'date'],
            'v1_signed'       => ['label' => 'v1.0 signed',      'w' => 18, 'fmt' => 'date'],
            'v2_signed'       => ['label' => 'v2.0 signed',      'w' => 18, 'fmt' => 'date'],
            'latest_version'  => ['label' => 'Latest',           'w' => 11],
            'last_visit'      => ['label' => 'Last visit',       'w' => 18, 'fmt' => 'date'],
            'post_visits'     => ['label' => 'Visits after amendment', 'w' => 20, 'fmt' => 'int', 'num' => true],
            'gap_visits'      => ['label' => 'Visits without v2.0', 'w' => 18, 'fmt' => 'int', 'num' => true],
            'pdf_check'       => ['label' => 'PDF check',        'w' => 22],
            'status_label'    => ['label' => 'Status',           'w' => 48, 'sort' => '`status_rank`, `subject_code`'],
        ],
        'sql' => 'report_consent_sql',
        'summary' => function (array $rows): string {
            $c = array_count_values(array_column($rows, 'status_code'));
            $issues = ($c['gap'] ?? 0) + ($c['risk'] ?? 0) + ($c['early'] ?? 0) + ($c['doc'] ?? 0);
            return sprintf('%d subject(s): %d with an issue, %d compliant, %d not required to re-consent.',
                count($rows), $issues, $c['compliant'] ?? 0, $c['not_needed'] ?? 0);
        },
        'notes' => [
            'Only Completed visits count as attended. A visit on the same day as the v2.0 signature is compliant.',
            'At risk: a visit still booked on or after the amendment date with no v2.0 consent signed by then.',
            'PDF check: version, participant code and signed date are extracted from the PDF text with REGEXP_SUBSTR '
              . 'and compared with InformedConsent. "No PDF" means a consent has no document on file.',
            'Change "Amendment effective" to test the rule: an earlier date shows which subjects would have needed re-consent.',
        ],
    ],
    /* ------------------------------------------------------------------ */
    // Not one of the three reports (hidden from the Reports page). It lets the
    // Automation page, its CSV/PDF export and the scheduled digest email reuse
    // the same report plumbing for the findings of the automated checks.
    'findings' => [
        'hidden'  => true,
        'title'   => 'Automated check findings',
        'blurb'   => 'Problems found by the database checks (procedure sp_run_checks, run every night by a MySQL event). '
                   . 'A finding is resolved automatically when the next run no longer sees the problem.',
        'sources' => ['structured', 'semi', 'unstructured'],
        'filters' => [
            'status'   => ['type' => 'select', 'label' => 'Status', 'default' => 'active', 'all' => 'Any status', 'options' => [
                               'active' => 'Open or acknowledged', 'Open' => 'Open', 'Acknowledged' => 'Acknowledged',
                               'Resolved' => 'Resolved']],
            'severity' => ['type' => 'select', 'label' => 'Severity', 'options' => ['High' => 'High', 'Medium' => 'Medium', 'Low' => 'Low']],
            'check'    => ['type' => 'select', 'label' => 'Check', 'options' =>
                               fn() => array_column(q('SELECT check_code, title FROM CheckRule ORDER BY title'), 'title', 'check_code')],
            'site'     => ['type' => 'select', 'label' => 'Site', 'options' => $sites],
        ],
        'default_sort' => 'severity',
        'columns' => [
            'severity'     => ['label' => 'Severity',   'w' => 14, 'sort' => '`sev_rank`, `check_title`, `subject_code`'],
            'status'       => ['label' => 'Status',     'w' => 20],
            'check_title'  => ['label' => 'Check',      'w' => 44],
            'subject_code' => ['label' => 'Subject',    'w' => 14],
            'site_name'    => ['label' => 'Site',       'w' => 38],
            'message'      => ['label' => 'Finding',    'w' => 110, 'sort' => false],
            'first_seen'   => ['label' => 'First seen', 'w' => 26],
            'ack_note'     => ['label' => 'Note',       'w' => 40, 'sort' => false],
        ],
        'sql' => 'report_findings_sql',
        'summary' => function (array $rows): string {
            $s = array_count_values(array_column($rows, 'severity'));
            return sprintf('%d finding(s): %d High, %d Medium, %d Low.', count($rows), $s['High'] ?? 0, $s['Medium'] ?? 0, $s['Low'] ?? 0);
        },
        'notes' => [
            'Each rule is one SELECT in the view v_check_candidates; sp_run_checks saves what they find in CheckFinding.',
            'Open = new; Acknowledged = an Admin has noted what is being done; Resolved = the data no longer has the problem.',
            'A resolved problem that comes back is re-opened. Findings name subjects by code only.',
        ],
    ],
    ];
    return $defs;
}

/* ======================================================================
 * Hidden report: findings of the automated checks
 * ==================================================================== */
function report_findings_sql(array $f, bool $identified, bool $total = false): array
{
    $where = [];
    $p = [];
    if ($f['status'] === 'active')  { $where[] = "status <> 'Resolved'"; }
    elseif ($f['status'] !== '')    { $where[] = 'status = ?';     $p[] = $f['status']; }
    if ($f['severity'] !== '')      { $where[] = 'severity = ?';   $p[] = $f['severity']; }
    if ($f['check'] !== '')         { $where[] = 'check_code = ?'; $p[] = $f['check']; }
    if ($f['site'] !== '')          { $where[] = 'site_name = ?';  $p[] = $f['site']; }
    $sql = "SELECT * FROM (
        SELECT finding_id, check_code, check_title, subject_code, site_name, severity, status, message,
               first_seen, last_seen, resolved_at, ack_note, FIELD(severity, 'High', 'Medium', 'Low') AS sev_rank
        FROM v_finding_coded) rpt" . ($where ? "\n    WHERE " . implode(' AND ', $where) : '');
    return [$sql, $p];
}

function report_def(string $key): ?array
{
    $d = report_defs()[$key] ?? null;
    return $d === null ? null : $d + ['key' => $key];
}

/** Escape % and _ so user text is matched literally inside LIKE '%...%'. */
function like_escape(string $s): string
{
    return addcslashes($s, '%_\\');
}

/* ======================================================================
 * Report 1: Safety review (Phase-04 A UNION B, enriched)
 * ==================================================================== */
function report_safety_sql(array $f, bool $identified, bool $total = false): array
{
    $where = [];
    $p = [];
    if ($f['site'] !== '')     { $where[] = 'site_name = ?';      $p[] = $f['site']; }
    if ($f['group'] !== '')    { $where[] = 'review_group = ?';   $p[] = $f['group']; }
    if ($f['severity'] !== '') { $where[] = 'worst_severity = ?'; $p[] = $f['severity']; }
    if ($f['alt_high'] === '1') { $where[] = 'alt_high > 0'; }
    if ($f['keyword'] !== '') {
        $where[] = 'EXISTS (SELECT 1 FROM v_ae_coded x WHERE x.subject_code = rpt.subject_code AND x.narrative LIKE ?)';
        $p[] = '%' . like_escape($f['keyword']) . '%';
    }
    // Admin only: the name comes from the base Subject table, which app_reader cannot read.
    $nameCol  = $identified ? ", CONCAT(p.first_name, ' ', p.last_name) AS subject_name" : '';
    $nameJoin = $identified ? 'JOIN Subject p ON p.subject_id = CAST(SUBSTRING(r.subject_code, 2) AS UNSIGNED)' : '';

    $sql = "WITH
    set_a AS (   -- A: given StudyDrugX at 200 mg or more
        SELECT subject_code FROM v_dose_coded WHERE drug_name = 'StudyDrugX' AND dose_mg >= 200),
    set_b AS (   -- B: at least one Moderate or Severe adverse event
        SELECT subject_code FROM v_ae_coded WHERE severity IN ('Moderate', 'Severe')),
    review AS (  -- the Phase-04 set operation; UNION removes duplicates
        SELECT subject_code FROM set_a UNION SELECT subject_code FROM set_b),
    dose AS (
        SELECT subject_code, MAX(dose_mg) AS max_dose, COUNT(*) AS doses
        FROM v_dose_coded WHERE drug_name = 'StudyDrugX' GROUP BY subject_code),
    alt AS (
        SELECT subject_code, MAX(value) AS peak_alt, ROUND(MAX(value / high_limit), 1) AS alt_xuln,
               SUM(flag = 'H') AS alt_high
        FROM v_lab_coded WHERE test_name = 'ALT' GROUP BY subject_code),
    vit AS (     -- generated columns over the JSON eCRF documents
        SELECT subject_code, MAX(systolic_bp) AS max_sys, MAX(heart_rate) AS max_hr
        FROM v_ecrf_coded GROUP BY subject_code),
    ae AS (
        SELECT subject_code, COUNT(*) AS ae_count,
               MAX(FIELD(severity, 'Mild', 'Moderate', 'Severe')) AS worst_rank
        FROM v_ae_coded GROUP BY subject_code),
    key_ae AS (  -- most serious AE per subject: highest severity, then latest
        SELECT subject_code, onset_date, narrative,
               ROW_NUMBER() OVER (PARTITION BY subject_code
                                  ORDER BY FIELD(severity, 'Mild', 'Moderate', 'Severe') DESC,
                                           onset_date DESC, ae_id DESC) AS rn
        FROM v_ae_coded),
    rpt AS (
        SELECT r.subject_code $nameCol, s.site_name,
               CASE WHEN a.subject_code IS NOT NULL AND b.subject_code IS NOT NULL THEN 'Both'
                    WHEN a.subject_code IS NOT NULL THEN 'A only'
                    ELSE 'B only' END                        AS review_group,
               dose.max_dose, dose.doses, alt.peak_alt, alt.alt_xuln, alt.alt_high,
               vit.max_sys, vit.max_hr,
               COALESCE(ae.ae_count, 0)                      AS ae_count,
               ELT(ae.worst_rank, 'Mild', 'Moderate', 'Severe') AS worst_severity,
               k.onset_date                                  AS key_onset,
               k.narrative                                   AS key_narrative
        FROM review r
        JOIN v_subject_coded s ON s.subject_code = r.subject_code
        $nameJoin
        LEFT JOIN (SELECT DISTINCT subject_code FROM set_a) a ON a.subject_code = r.subject_code
        LEFT JOIN (SELECT DISTINCT subject_code FROM set_b) b ON b.subject_code = r.subject_code
        LEFT JOIN dose ON dose.subject_code = r.subject_code
        LEFT JOIN alt  ON alt.subject_code  = r.subject_code
        LEFT JOIN vit  ON vit.subject_code  = r.subject_code
        LEFT JOIN ae   ON ae.subject_code   = r.subject_code
        LEFT JOIN key_ae k ON k.subject_code = r.subject_code AND k.rn = 1)
    SELECT * FROM rpt" . ($where ? "\n    WHERE " . implode(' AND ', $where) : '');
    return [$sql, $p];
}

/* ======================================================================
 * Report 2: Site activity and exposure
 * $total = true collapses every site (and month) into one row, which gives
 * a correct totals row: COUNT(DISTINCT) can't be added up across sites.
 * ==================================================================== */
function report_sites_sql(array $f, bool $identified, bool $total = false): array
{
    $month = !$total && $f['breakdown'] === 'month';
    $p = [];
    $per  = fn(string $col): string => $month ? "DATE_FORMAT($col, '%Y-%m')" : "'All'";
    $site = fn(string $col): string => $total ? "'All sites'" : $col;
    // Date range (and site filter) pushed into each part; params are added in SQL text order.
    $range = function (string $dateCol, string $siteCol) use ($f, &$p): string {
        $w = "$dateCol BETWEEN ? AND ?";
        array_push($p, $f['from'], $f['to']);
        if ($f['site'] !== '') { $w .= " AND $siteCol = ?"; $p[] = $f['site']; }
        return $w;
    };

    $vis = "vis AS (
        SELECT {$site('site_name')} AS site_name, {$per('scheduled_date')} AS period,
               SUM(status = 'Completed') AS completed, SUM(status = 'Missed') AS missed,
               SUM(status = 'Scheduled') AS booked
        FROM v_visit_coded WHERE " . $range('scheduled_date', 'site_name') . ' GROUP BY 1, 2)';
    $enr = "enr AS (
        SELECT {$site('site_name')} AS site_name, {$per('enrolment_date')} AS period, COUNT(*) AS enrolled
        FROM v_subject_coded WHERE " . $range('enrolment_date', 'site_name') . ' GROUP BY 1, 2)';
    $doseWhere = $range('scheduled_date', 'site_name');
    if ($f['drug'] !== '') { $doseWhere .= ' AND drug_name = ?'; $p[] = $f['drug']; }
    $dose = "dose AS (
        SELECT {$site('site_name')} AS site_name, {$per('scheduled_date')} AS period,
               COUNT(*) AS doses, COUNT(DISTINCT subject_code) AS dosed, SUM(dose_mg) AS total_mg
        FROM v_dose_coded WHERE $doseWhere GROUP BY 1, 2)";
    $ae = "ae AS (
        SELECT {$site('site_name')} AS site_name, {$per('onset_date')} AS period,
               COUNT(*) AS aes, SUM(severity IN ('Moderate', 'Severe')) AS aes_modsev
        FROM v_ae_coded WHERE " . $range('onset_date', 'site_name') . ' GROUP BY 1, 2)';
    $ecrf = "ecrf AS (
        SELECT {$site('v.site_name')} AS site_name, {$per('e.scheduled_date')} AS period, COUNT(*) AS ecrfs
        FROM v_ecrf_coded e JOIN v_visit_coded v ON v.visit_id = e.visit_id
        WHERE " . $range('e.scheduled_date', 'v.site_name') . ' GROUP BY 1, 2)';

    if ($total) {
        $spine = "spine AS (SELECT 'All sites' AS site_name, 'All' AS period)";
    } elseif ($month) {   // every site-month that has any activity
        $spine = 'spine AS (SELECT site_name, period FROM vis UNION SELECT site_name, period FROM enr
                  UNION SELECT site_name, period FROM dose UNION SELECT site_name, period FROM ae
                  UNION SELECT site_name, period FROM ecrf)';
    } else {              // every site, even one with no subjects
        $spine = "spine AS (SELECT site_name, 'All' AS period FROM v_site_coded"
               . ($f['site'] !== '' ? ' WHERE site_name = ?' : '') . ')';
        if ($f['site'] !== '') { $p[] = $f['site']; }
    }

    $sql = "WITH $vis,
    $enr,
    $dose,
    $ae,
    $ecrf,
    $spine,
    rpt AS (
        SELECT sp.site_name, sp.period,
               COALESCE(enr.enrolled, 0)   AS enrolled,
               COALESCE(vis.completed, 0)  AS completed,
               COALESCE(vis.missed, 0)     AS missed,
               COALESCE(vis.booked, 0)     AS booked,
               ROUND(100 * vis.completed / NULLIF(vis.completed + vis.missed, 0), 1) AS attend_pct,
               COALESCE(dose.doses, 0)     AS doses,
               COALESCE(dose.dosed, 0)     AS dosed,
               COALESCE(dose.total_mg, 0)  AS total_mg,
               ROUND(dose.total_mg / NULLIF(dose.doses, 0), 1)       AS mean_mg,
               COALESCE(ae.aes, 0)         AS aes,
               COALESCE(ae.aes_modsev, 0)  AS aes_modsev,
               ROUND(COALESCE(ae.aes, 0) / NULLIF(dose.dosed, 0), 2) AS ae_per_dosed,
               COALESCE(ecrf.ecrfs, 0)     AS ecrfs,
               ROUND(100 * COALESCE(ecrf.ecrfs, 0) / NULLIF(vis.completed, 0), 1) AS ecrf_pct
        FROM spine sp
        LEFT JOIN vis  USING (site_name, period)
        LEFT JOIN enr  USING (site_name, period)
        LEFT JOIN dose USING (site_name, period)
        LEFT JOIN ae   USING (site_name, period)
        LEFT JOIN ecrf USING (site_name, period))
    SELECT * FROM rpt";
    return [$sql, $p];
}

/* ======================================================================
 * Report 3: Consent compliance
 * ==================================================================== */
function report_consent_sql(array $f, bool $identified, bool $total = false): array
{
    $a = $f['amend'];
    $where = [];
    $wp = [];
    if ($f['site'] !== '')   { $where[] = 'site_name = ?';      $wp[] = $f['site']; }
    if ($f['status'] !== '') { $where[] = 'status_code = ?';    $wp[] = $f['status']; }
    if ($f['latest'] !== '') { $where[] = 'latest_version = ?'; $wp[] = $f['latest']; }
    $nameCol  = $identified ? ", CONCAT(p.first_name, ' ', p.last_name) AS subject_name" : '';
    $nameJoin = $identified ? 'JOIN Subject p ON p.subject_id = CAST(SUBSTRING(s.subject_code, 2) AS UNSIGNED)' : '';

    $sql = "WITH
    c1 AS (SELECT subject_code, MIN(signed_date) AS v1_signed, MIN(consent_id) AS v1_id
           FROM v_consent_coded WHERE version = 'v1.0' GROUP BY subject_code),
    c2 AS (SELECT subject_code, MIN(signed_date) AS v2_signed, MIN(consent_id) AS v2_id
           FROM v_consent_coded WHERE version = 'v2.0' GROUP BY subject_code),
    held AS (SELECT subject_code, COUNT(*) AS consents, SUM(has_document) AS docs,
                    MIN(signed_date) AS first_signed
             FROM v_consent_coded GROUP BY subject_code),
    pdf AS (   -- unstructured check: what the PDF itself says vs the database row
        SELECT subject_code,
               SUM(doc_version <=> version AND doc_subject_code <=> subject_code
                   AND doc_signed_date <=> signed_date) AS pdf_ok,
               SUM(doc_version IS NULL) AS pdf_missing
        FROM v_consent_doc_check GROUP BY subject_code),
    att AS (
        SELECT v.subject_code,
               MAX(CASE WHEN v.status = 'Completed' THEN v.scheduled_date END) AS last_visit,
               SUM(v.status = 'Completed' AND v.scheduled_date < h.first_signed) AS early_visits,
               SUM(v.status = 'Completed' AND v.scheduled_date >= ?) AS post_visits,
               SUM(v.status = 'Completed' AND v.scheduled_date >= ?
                   AND (c2.v2_signed IS NULL OR c2.v2_signed > v.scheduled_date)) AS gap_visits,
               SUM(v.status = 'Scheduled' AND v.scheduled_date >= ?
                   AND (c2.v2_signed IS NULL OR c2.v2_signed > v.scheduled_date)) AS risk_visits
        FROM v_visit_coded v
        LEFT JOIN held h ON h.subject_code = v.subject_code
        LEFT JOIN c2     ON c2.subject_code = v.subject_code
        GROUP BY v.subject_code),
    base AS (
        SELECT s.subject_code $nameCol, s.site_name, s.enrolment_date,
               c1.v1_signed, c2.v2_signed, c1.v1_id, c2.v2_id,
               IF(c2.v2_signed IS NOT NULL, 'v2.0', 'v1.0')  AS latest_version,
               att.last_visit,
               COALESCE(att.post_visits, 0)  AS post_visits,
               COALESCE(att.gap_visits, 0)   AS gap_visits,
               COALESCE(att.risk_visits, 0)  AS risk_visits,
               COALESCE(att.early_visits, 0) AS early_visits,
               h.consents, h.docs, COALESCE(pdf.pdf_ok, 0) AS pdf_ok, COALESCE(pdf.pdf_missing, 0) AS pdf_missing
        FROM v_subject_coded s
        $nameJoin
        LEFT JOIN c1   ON c1.subject_code   = s.subject_code
        LEFT JOIN c2   ON c2.subject_code   = s.subject_code
        LEFT JOIN held h ON h.subject_code  = s.subject_code
        LEFT JOIN pdf  ON pdf.subject_code  = s.subject_code
        LEFT JOIN att  ON att.subject_code  = s.subject_code),
    rpt AS (
        SELECT b.*,
               CASE WHEN b.pdf_missing > 0 THEN CONCAT('No PDF (', b.pdf_missing, ')')
                    WHEN b.pdf_ok = b.consents THEN CONCAT('OK (', b.pdf_ok, '/', b.consents, ')')
                    ELSE CONCAT('Mismatch (', b.consents - b.pdf_ok, ')') END AS pdf_check,
               CASE WHEN b.gap_visits > 0   THEN 'gap'
                    WHEN b.early_visits > 0 THEN 'early'
                    WHEN b.risk_visits > 0  THEN 'risk'
                    WHEN b.pdf_ok < b.consents THEN 'doc'
                    WHEN b.post_visits = 0 AND b.v2_signed IS NULL THEN 'not_needed'
                    ELSE 'compliant' END AS status_code
        FROM base b),
    labelled AS (
        SELECT rpt.*,
               ELT(FIELD(status_code, 'gap', 'early', 'risk', 'doc', 'compliant', 'not_needed'),
                   'Gap: visit without v2.0', 'Gap: visit before first consent',
                   'At risk: booked visit without v2.0', 'Document issue', 'Compliant',
                   'Not required (no visits after amendment)') AS status_label,
               FIELD(status_code, 'gap', 'early', 'risk', 'doc', 'compliant', 'not_needed') AS status_rank
        FROM rpt)
    SELECT * FROM labelled rpt" . ($where ? "\n    WHERE " . implode(' AND ', $where) : '');
    return [$sql, array_merge([$a, $a, $a], $wp)];
}

/* ======================================================================
 * Shared plumbing: validated request -> rows -> formatted cells
 * ==================================================================== */

/** Options of a select filter (closures run lazily, once). */
function report_filter_options(array $flt): array
{
    static $cache = [];
    $o = $flt['options'] ?? [];
    if ($o instanceof Closure) {
        $id = spl_object_id($o);
        return $cache[$id] ??= $o();
    }
    return $o;
}

/**
 * Read and validate every filter from $src ($_GET or $_POST). Anything that
 * fails validation falls back to its default, so bad input never reaches SQL.
 */
function report_filters(array $def, array $src): array
{
    $f = [];
    foreach ($def['filters'] as $name => $flt) {
        $raw = $src[$name] ?? null;
        $default = (string)($flt['default'] ?? '');
        $val = is_string($raw) ? trim($raw) : null;
        switch ($flt['type']) {
            case 'select':
                $opts = report_filter_options($flt);
                $allowsAll = ($flt['all'] ?? 'All') !== false;
                if ($val === null)                          { $val = $default; }
                elseif ($val === '' && $allowsAll)          { /* "All" chosen */ }
                elseif (!array_key_exists($val, $opts))     { $val = $default; }
                break;
            case 'date':
                $d = $val !== null ? DateTimeImmutable::createFromFormat('!Y-m-d', $val) : false;
                $val = ($d && $d->format('Y-m-d') === $val) ? $val : $default;
                break;
            case 'check':
                $val = $val === '1' ? '1' : '';
                break;
            default: // text
                $val = $val === null ? $default : mb_substr(preg_replace('/[\x00-\x1F\x7F]/u', '', $val) ?? '', 0, 60);
        }
        $f[$name] = $val;
    }
    if (isset($f['from'], $f['to']) && $f['from'] > $f['to']) {   // swapped range: fix it rather than show nothing
        [$f['from'], $f['to']] = [$f['to'], $f['from']];
    }
    return $f;
}

/** Visible columns for this user and these filters. */
function report_columns(array $def, array $f, bool $identified): array
{
    $cols = [];
    foreach ($def['columns'] as $k => $c) {
        if (($c['admin'] ?? false) && !$identified) continue;
        if (isset($c['show']) && !($c['show'])($f)) continue;
        $cols[$k] = $c;
    }
    return $cols;
}

/**
 * Everything one report run needs: validated filters, visible columns and the
 * whitelisted sort. Sort keys are column names, never raw SQL from the URL.
 */
function report_request(array $def, array $src, bool $identified): array
{
    $f = report_filters($def, $src);
    $cols = report_columns($def, $f, $identified);
    $tie = $def['default_sort'];
    $tieExpr = $def['tiebreak'] ?? "`$tie`";   // e.g. site, then month
    $allowed = [];
    foreach ($cols as $k => $c) {
        $s = $c['sort'] ?? true;
        if ($s === false) continue;
        $allowed[$k] = is_string($s) ? $s : ($k === $tie ? $tieExpr : "`$k`, $tieExpr");
    }
    $spec = sort_spec($allowed, $tie, 'asc', $src);
    return ['def' => $def, 'f' => $f, 'cols' => $cols, 'spec' => $spec, 'identified' => $identified];
}

/** $pdo defaults to the session's account; the email export passes app_reader explicitly. */
function report_rows(array $req, ?PDO $pdo = null): array
{
    [$sql, $params] = ($req['def']['sql'])($req['f'], $req['identified'], false);
    return q($sql . "\n    " . $req['spec'][2], $params, $pdo);
}

/** Totals row (Site report only): the same SQL with every site collapsed into one. */
function report_totals(array $req, ?PDO $pdo = null): ?array
{
    if (empty($req['def']['has_totals'])) {
        return null;
    }
    [$sql, $params] = ($req['def']['sql'])($req['f'], $req['identified'], true);
    $row = q($sql, $params, $pdo)[0] ?? null;
    if ($row && isset($row['period'])) {
        $row['period'] = '';
    }
    return $row;
}

/** Plain-text cell value, used by CSV, PDF and email (the screen adds HTML on top). */
function report_cell(array $col, mixed $v): string
{
    if ($v === null || $v === '') {
        return '';
    }
    return match ($col['fmt'] ?? 'text') {
        'int'  => (string)(int)round((float)$v),
        'dec1' => number_format((float)$v, 1, '.', ''),
        'dec2' => number_format((float)$v, 2, '.', ''),
        default => (string)$v,
    };
}

/** Filters that differ from "All", as label => shown value (for audit, PDF and email). */
function report_active_filters(array $req): array
{
    $out = [];
    foreach ($req['def']['filters'] as $name => $flt) {
        $v = $req['f'][$name];
        if ($v === '') continue;
        if ($flt['type'] === 'select') {
            $v = report_filter_options($flt)[$v] ?? $v;
        } elseif ($flt['type'] === 'check') {
            $v = 'yes';
        }
        $out[$flt['label']] = (string)$v;
    }
    $sortLabel = $req['cols'][$req['spec'][0]]['label'] ?? $req['spec'][0];
    $out['Sorted by'] = $sortLabel . ($req['spec'][1] === 'desc' ? ' (descending)' : '');
    return $out;
}

/** Query-string values that reproduce this run (filters + sort), for export links and forms. */
function report_query(array $req): array
{
    return array_merge(['r' => $req['def']['key']], $req['f'],
                       ['sort' => $req['spec'][0], 'dir' => $req['spec'][1]]);
}

/** Text written to the audit log for a run or an export. */
function report_audit_detail(array $req, int $rows, array $extra = []): array
{
    return array_merge(['report' => $req['def']['key'], 'filters' => $req['f'],
                        'sort' => $req['spec'][0] . ' ' . $req['spec'][1], 'rows' => $rows,
                        'identified' => $req['identified']], $extra);
}
