-- =========================================================================
-- COS20015 Project Phase-05 -- Tests for 10_automation.sql
-- Run with:  mysql --force -t < 10b_test_automation.sql
-- (--force keeps going after the statements that are SUPPOSED to fail.)
--
-- sp_run_checks commits its own transaction, so these tests cannot simply be
-- rolled back. Instead every change is undone by the test itself, runs are
-- labelled source = 'TEST', and the one finding the tests create is removed
-- at the end. Each result row shows PASS or FAIL.
-- =========================================================================
USE clinical_trial_tracking;

-- T1. The rules on today's data (22 Oct 2026). EXPECT: 9 overdue visits, 9 high ALTs,
--     8 moderate/severe AEs, 0 consent, eCRF or PDF problems = 26 in total
SELECT 'T1 rules on current data' AS test,
       SUM(check_code = 'VISIT_OVERDUE') AS overdue, SUM(check_code = 'ALT_HIGH') AS alt_high,
       SUM(check_code = 'AE_REVIEW') AS ae_review,
       SUM(check_code IN ('CONSENT_GAP', 'CONSENT_EARLY', 'CONSENT_RISK', 'CONSENT_PDF')) AS consent,
       SUM(check_code IN ('ECRF_MISSING', 'VITALS_ALERT')) AS ecrf,
       IF(COUNT(*) = 26 AND SUM(check_code = 'VISIT_OVERDUE') = 9 AND SUM(check_code = 'ALT_HIGH') = 9
          AND SUM(check_code = 'AE_REVIEW') = 8, 'PASS', 'FAIL') AS result
FROM v_check_candidates;

-- T2. A run saves every candidate. EXPECT: status OK, found 26
CALL sp_run_checks('TEST', NULL, @r1);
SELECT 'T2 first run' AS test, run_id, status, found, new_open, resolved,
       IF(status = 'OK' AND found = 26, 'PASS', 'FAIL') AS result
FROM CheckRun WHERE run_id = @r1;

-- T3. Running again changes nothing. EXPECT: found 26, new 0, resolved 0
CALL sp_run_checks('TEST', NULL, @r2);
SELECT 'T3 second run is idempotent' AS test, found, new_open, resolved,
       IF(found = 26 AND new_open = 0 AND resolved = 0, 'PASS', 'FAIL') AS result
FROM CheckRun WHERE run_id = @r2;

-- T4. A new problem in the semi-structured data is picked up.
--     Heart rate 130 is valid for the JSON Schema (max 250) but above the alert limit 120.
SET @e := (SELECT MIN(ecrf_id) FROM VisitECRF WHERE heart_rate IS NOT NULL);
SET @orig := (SELECT ecrf_doc FROM VisitECRF WHERE ecrf_id = @e);
UPDATE VisitECRF SET ecrf_doc = JSON_SET(ecrf_doc, '$.vitals.heartRate', 130) WHERE ecrf_id = @e;
CALL sp_run_checks('TEST', NULL, @r3);
SET @f := (SELECT finding_id FROM CheckFinding WHERE check_code = 'VITALS_ALERT' AND record_ref = CONCAT('eCRF:', @e));
SELECT 'T4 new eCRF problem found' AS test, r.new_open, f.status, f.severity, f.message,
       IF(r.new_open = 1 AND f.status = 'Open' AND f.message LIKE '%heart rate 130%', 'PASS', 'FAIL') AS result
FROM CheckRun r JOIN CheckFinding f ON f.finding_id = @f WHERE r.run_id = @r3;

-- T5. Acknowledging needs a note. EXPECT: ERROR 1644 "Write a short note ..."
CALL sp_ack_finding(@f, NULL, '  ');

-- T6. Acknowledge with a note. EXPECT: status Acknowledged, an ACKNOWLEDGE audit row
CALL sp_ack_finding(@f, NULL, 'Test: site asked to re-measure');
SELECT 'T6 acknowledged' AS test, status, ack_note,
       (SELECT COUNT(*) FROM AuditLog WHERE action = 'ACKNOWLEDGE' AND record_id = CAST(@f AS CHAR)) AS audit_rows,
       IF(status = 'Acknowledged', 'PASS', 'FAIL') AS result
FROM CheckFinding WHERE finding_id = @f;

-- T7. A finding can only be acknowledged once. EXPECT: ERROR 1644 "Only an Open finding ..."
CALL sp_ack_finding(@f, NULL, 'Second try');

-- T8. Fix the data: the next run resolves the finding by itself. EXPECT: resolved 1, status Resolved
UPDATE VisitECRF SET ecrf_doc = @orig WHERE ecrf_id = @e;
CALL sp_run_checks('TEST', NULL, @r4);
SELECT 'T8 fixed data auto-resolves' AS test, r.found, r.resolved, f.status, f.resolved_at,
       IF(r.resolved = 1 AND r.found = 26 AND f.status = 'Resolved', 'PASS', 'FAIL') AS result
FROM CheckRun r JOIN CheckFinding f ON f.finding_id = @f WHERE r.run_id = @r4;

-- T9. Unknown source is refused. EXPECT: ERROR 1644 "source must be ..."
CALL sp_run_checks('HACKER', NULL, @r5);

-- T10. Severity is limited by a CHECK. EXPECT: ERROR 3819 chk_finding_severity
INSERT INTO CheckFinding (check_code, record_ref, severity, message, first_run, last_run, first_seen, last_seen)
VALUES ('AE_REVIEW', 'AE:999', 'Urgent', 'x', @r1, @r1, NOW(), NOW());

-- T11. The nightly event exists and is enabled. EXPECT: ENABLED, every 1 DAY at 02:00
SELECT 'T11 nightly event' AS test, EVENT_NAME, STATUS, INTERVAL_VALUE, INTERVAL_FIELD, TIME(STARTS) AS at_time,
       IF(STATUS = 'ENABLED' AND INTERVAL_FIELD = 'DAY' AND TIME(STARTS) = '02:00:00', 'PASS', 'FAIL') AS result
FROM information_schema.EVENTS WHERE EVENT_SCHEMA = DATABASE() AND EVENT_NAME = 'ev_nightly_checks';

-- T12. Every run is in the audit log. EXPECT: 4 CHECK_RUN rows for the 4 test runs
SELECT 'T12 runs audited' AS test, COUNT(*) AS audit_rows,
       IF(COUNT(*) = 4, 'PASS', 'FAIL') AS result
FROM AuditLog WHERE action = 'CHECK_RUN' AND record_id IN (@r1, @r2, @r3, @r4);

-- T13. Users see findings by code only: the view has no name columns.
SELECT 'T13 coded view has no names' AS test, COUNT(*) AS name_columns,
       IF(COUNT(*) = 0, 'PASS', 'FAIL') AS result
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'v_finding_coded'
  AND COLUMN_NAME IN ('first_name', 'last_name', 'date_of_birth', 'subject_id');

-- T14. The event scheduler really fires the procedure: a one-off event 3 seconds
--      from now must create a new run by itself. EXPECT: 1 new run, status OK
SET @before := (SELECT COALESCE(MAX(run_id), 0) FROM CheckRun);
DROP EVENT IF EXISTS ev_test_once;
CREATE EVENT ev_test_once ON SCHEDULE AT NOW() + INTERVAL 3 SECOND ON COMPLETION NOT PRESERVE
  DO CALL sp_run_checks('TEST', NULL, @ev_test_run);
DO SLEEP(6);
SELECT 'T14 event scheduler fires' AS test, @@event_scheduler AS scheduler, COUNT(*) AS new_runs, MAX(status) AS status,
       IF(COUNT(*) = 1 AND MAX(status) = 'OK', 'PASS', 'FAIL') AS result
FROM CheckRun WHERE run_id > @before;

-- Cleanup: remove the one finding the tests created (runs and audit rows stay,
-- labelled TEST, because history is never deleted).
DELETE FROM CheckFinding WHERE finding_id = @f;
SELECT 'Cleanup' AS test, (SELECT COUNT(*) FROM CheckFinding WHERE status <> 'Resolved') AS open_findings,
       (SELECT heart_rate FROM VisitECRF WHERE ecrf_id = @e) AS restored_heart_rate;
