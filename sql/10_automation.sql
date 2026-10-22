-- =========================================================================
-- COS20015 Project Phase-05 -- Automated data checks (runs inside MySQL)
-- Run after 09_report_views.sql. Safe to re-run: the two history tables
-- (CheckRun, CheckFinding) are created only if missing, so re-running keeps
-- past runs and acknowledgements; views, procedures and the event are replaced.
--
-- How it works:
--   v_check_candidates  every rule written as one SELECT, joined with UNION ALL.
--                       Querying it shows the problems that exist RIGHT NOW.
--   sp_run_checks       saves the current candidates into CheckFinding in one
--                       transaction: new problems are added as Open, problems
--                       seen before are refreshed, and problems that have gone
--                       away are marked Resolved automatically.
--   ev_nightly_checks   MySQL EVENT that calls sp_run_checks at 02:00 every day
--                       (needs event_scheduler = ON, the MySQL 8.0 default).
--   sp_ack_finding      an Admin acknowledges a finding with a note (audited).
--   v_finding_coded     findings by subject code, readable by the User role.
--
-- Messages only ever contain subject codes (S023), never names, so findings
-- can be shown to Users and included in emails.
-- =========================================================================
USE clinical_trial_tracking;

-- -------------------------------------------------------------------------
-- 0. New audit actions used by the automation pieces
--    (06 creates AuditLog with the original list; this widens it in place).
-- -------------------------------------------------------------------------
SET @has_chk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
                 WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'chk_audit_action');
SET @ddl := IF(@has_chk > 0, 'ALTER TABLE AuditLog DROP CHECK chk_audit_action', 'DO 0');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

ALTER TABLE AuditLog ADD CONSTRAINT chk_audit_action CHECK (action IN
  ('LOGIN', 'LOGIN_FAILED', 'LOGOUT', 'INSERT', 'UPDATE', 'DELETE',
   'EXPORT', 'REPORT_RUN', 'APPROVE', 'REJECT', 'VIEW_DOC',
   'CHECK_RUN', 'ACKNOWLEDGE', 'SCHEDULE_RUN', 'AI_EXTRACT'));

-- -------------------------------------------------------------------------
-- 1. Rule catalogue (what each check means, shown on the Automation page)
-- -------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS CheckRule (
 check_code VARCHAR(20) PRIMARY KEY,
 title VARCHAR(80) NOT NULL,
 rule_text VARCHAR(400) NOT NULL,
 data_source VARCHAR(12) NOT NULL,
 CONSTRAINT chk_rule_source CHECK (data_source IN ('structured', 'semi', 'unstructured'))
);

REPLACE INTO CheckRule (check_code, title, rule_text, data_source) VALUES
 ('CONSENT_GAP',   'Visit without v2.0 consent',
  'A Completed visit on or after the protocol amendment (11 May 2026) where the subject had not signed consent v2.0 on or before the visit day.', 'structured'),
 ('CONSENT_EARLY', 'Visit before any consent',
  'A Completed visit dated before the subject signed any consent form.', 'structured'),
 ('CONSENT_RISK',  'Booked visit without v2.0 consent',
  'A Scheduled visit on or after the amendment with no v2.0 consent signed by its date: re-consent before the visit.', 'structured'),
 ('VISIT_OVERDUE', 'Visit date passed, not recorded',
  'A visit still marked Scheduled after its date. Record it as Completed or Missed. High after 14 days.', 'structured'),
 ('ECRF_MISSING',  'Completed visit without eCRF',
  'A Completed visit that has no JSON eCRF document.', 'semi'),
 ('VITALS_ALERT',  'Abnormal vital signs in eCRF',
  'eCRF vitals outside safe limits: systolic BP 160 or more, heart rate above 120 or below 40, or temperature 38.0 C or more.', 'semi'),
 ('ALT_HIGH',      'ALT above upper limit',
  'An ALT lab result above the upper limit of its reference range. High at 2 x ULN or more.', 'structured'),
 ('AE_REVIEW',     'Moderate or severe adverse event',
  'An adverse event graded Moderate (Medium) or Severe (High) that needs medical review.', 'unstructured'),
 ('CONSENT_PDF',   'Consent PDF does not match record',
  'The version, participant code or signed date read from the consent PDF text differs from InformedConsent, or there is no PDF.', 'unstructured');

-- -------------------------------------------------------------------------
-- 2. History tables
-- -------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS CheckRun (
 run_id INT AUTO_INCREMENT PRIMARY KEY,
 source VARCHAR(10) NOT NULL,                  -- who started it (see CHECK below)
 started_by INT NULL,                          -- AppUser for MANUAL runs, NULL for the event
 started_at DATETIME(3) NOT NULL,
 finished_at DATETIME(3) NULL,
 status VARCHAR(10) NOT NULL DEFAULT 'Running',
 found INT NULL,                               -- findings seen in this run
 new_open INT NULL,                            -- of which seen for the first time
 resolved INT NULL,                            -- earlier findings that have gone away
 error_text VARCHAR(255) NULL,
 FOREIGN KEY (started_by) REFERENCES AppUser(user_id),
 INDEX idx_run_time (started_at),
 CONSTRAINT chk_run_source CHECK (source IN ('EVENT', 'MANUAL', 'SCHEDULER', 'TEST')),
 CONSTRAINT chk_run_status CHECK (status IN ('Running', 'OK', 'Failed'))
);

CREATE TABLE IF NOT EXISTS CheckFinding (
 finding_id INT AUTO_INCREMENT PRIMARY KEY,
 check_code VARCHAR(20) NOT NULL,
 record_ref VARCHAR(40) NOT NULL,              -- the row it is about, e.g. 'Visit:57', 'AE:11'
 subject_id INT NULL,
 severity VARCHAR(6) NOT NULL,
 message VARCHAR(255) NOT NULL,                -- subject codes only, never names
 status VARCHAR(12) NOT NULL DEFAULT 'Open',
 first_run INT NOT NULL,
 last_run INT NOT NULL,
 first_seen DATETIME NOT NULL,
 last_seen DATETIME NOT NULL,
 resolved_at DATETIME NULL,
 ack_by INT NULL,
 ack_at DATETIME NULL,
 ack_note VARCHAR(255) NULL,
 UNIQUE KEY uq_finding (check_code, record_ref),   -- one finding per problem, however many runs see it
 INDEX idx_finding_status (status, severity),
 FOREIGN KEY (check_code) REFERENCES CheckRule(check_code),
 FOREIGN KEY (subject_id) REFERENCES Subject(subject_id),
 FOREIGN KEY (first_run) REFERENCES CheckRun(run_id),
 FOREIGN KEY (last_run)  REFERENCES CheckRun(run_id),
 FOREIGN KEY (ack_by)    REFERENCES AppUser(user_id),
 CONSTRAINT chk_finding_severity CHECK (severity IN ('High', 'Medium', 'Low')),
 CONSTRAINT chk_finding_status CHECK (status IN ('Open', 'Acknowledged', 'Resolved'))
);

-- -------------------------------------------------------------------------
-- 3. The rules: one SELECT per check, combined with UNION ALL.
--    Every row = one problem that exists now: (check, record, subject, severity, message).
-- -------------------------------------------------------------------------
CREATE OR REPLACE VIEW v_check_candidates AS
-- CONSENT_GAP: attended after the amendment without v2.0 signed by that day
SELECT 'CONSENT_GAP' AS check_code, CONCAT('Visit:', v.visit_id) AS record_ref, v.subject_id,
       'High' AS severity,
       CONCAT('S', LPAD(v.subject_id, 3, '0'), ' attended ', v.visit_type, ' on ', v.scheduled_date,
              ' without consent v2.0 signed by that day') AS message
FROM Visit v
WHERE v.status = 'Completed' AND v.scheduled_date >= DATE '2026-05-11'
  AND NOT EXISTS (SELECT 1 FROM InformedConsent ic
                  WHERE ic.subject_id = v.subject_id AND ic.version = 'v2.0'
                    AND ic.signed_date <= v.scheduled_date)
UNION ALL
-- CONSENT_EARLY: attended before signing any consent at all
SELECT 'CONSENT_EARLY', CONCAT('Visit:', v.visit_id), v.subject_id, 'High',
       CONCAT('S', LPAD(v.subject_id, 3, '0'), ' attended ', v.visit_type, ' on ', v.scheduled_date,
              ' before signing any consent form')
FROM Visit v
WHERE v.status = 'Completed'
  AND v.scheduled_date < COALESCE((SELECT MIN(ic.signed_date) FROM InformedConsent ic
                                   WHERE ic.subject_id = v.subject_id), DATE '9999-12-31')
UNION ALL
-- CONSENT_RISK: booked after the amendment, v2.0 not signed by the visit date
SELECT 'CONSENT_RISK', CONCAT('Visit:', v.visit_id), v.subject_id, 'Medium',
       CONCAT('S', LPAD(v.subject_id, 3, '0'), ' is booked for ', v.visit_type, ' on ', v.scheduled_date,
              ' without consent v2.0: re-consent before the visit')
FROM Visit v
WHERE v.status = 'Scheduled' AND v.scheduled_date >= DATE '2026-05-11'
  AND NOT EXISTS (SELECT 1 FROM InformedConsent ic
                  WHERE ic.subject_id = v.subject_id AND ic.version = 'v2.0'
                    AND ic.signed_date <= v.scheduled_date)
UNION ALL
-- VISIT_OVERDUE: the date has passed but nobody recorded the outcome
SELECT 'VISIT_OVERDUE', CONCAT('Visit:', v.visit_id), v.subject_id,
       IF(DATEDIFF(CURDATE(), v.scheduled_date) > 14, 'High', 'Medium'),
       CONCAT('S', LPAD(v.subject_id, 3, '0'), ' ', v.visit_type, ' was booked for ', v.scheduled_date, ' (',
              DATEDIFF(CURDATE(), v.scheduled_date), ' days ago) and is still Scheduled: record it as Completed or Missed')
FROM Visit v
WHERE v.status = 'Scheduled' AND v.scheduled_date < CURDATE()
UNION ALL
-- ECRF_MISSING: semi-structured document missing for an attended visit
SELECT 'ECRF_MISSING', CONCAT('Visit:', v.visit_id), v.subject_id, 'Medium',
       CONCAT('S', LPAD(v.subject_id, 3, '0'), ' ', v.visit_type, ' on ', v.scheduled_date,
              ' is Completed but has no eCRF document')
FROM Visit v
LEFT JOIN VisitECRF e ON e.visit_id = v.visit_id
WHERE v.status = 'Completed' AND e.ecrf_id IS NULL
UNION ALL
-- VITALS_ALERT: read from the JSON eCRF through its generated columns
SELECT 'VITALS_ALERT', CONCAT('eCRF:', e.ecrf_id), v.subject_id, 'High',
       CONCAT('S', LPAD(v.subject_id, 3, '0'), ' ', v.visit_type, ' on ', v.scheduled_date, ': ',
              CONCAT_WS(', ',
                IF(e.systolic_bp >= 160, CONCAT('systolic BP ', e.systolic_bp), NULL),
                IF(e.heart_rate > 120 OR e.heart_rate < 40, CONCAT('heart rate ', e.heart_rate), NULL),
                IF(e.temperature_c >= 38.0, CONCAT('temperature ', e.temperature_c, ' C'), NULL)),
              ' (from eCRF JSON)')
FROM VisitECRF e
JOIN Visit v ON v.visit_id = e.visit_id
WHERE e.systolic_bp >= 160 OR e.heart_rate > 120 OR e.heart_rate < 40 OR e.temperature_c >= 38.0
UNION ALL
-- ALT_HIGH: same H flag as the lab screens (v_lab_coded)
SELECT 'ALT_HIGH', CONCAT('Lab:', l.result_id), CAST(SUBSTRING(l.subject_code, 2) AS UNSIGNED),
       IF(l.value >= 2 * l.high_limit, 'High', 'Medium'),
       CONCAT(l.subject_code, ': ALT ', ROUND(l.value), ' ', l.unit, ' at ', l.visit_type, ' on ', l.scheduled_date,
              ' (', ROUND(l.value / l.high_limit, 1), ' x upper limit ', ROUND(l.high_limit), ')')
FROM v_lab_coded l
WHERE l.test_name = 'ALT' AND l.flag = 'H'
UNION ALL
-- AE_REVIEW: event term = text before the first ':' of the free-text narrative
SELECT 'AE_REVIEW', CONCAT('AE:', a.ae_id), a.subject_id,
       IF(a.severity = 'Severe', 'High', 'Medium'),
       CONCAT('S', LPAD(a.subject_id, 3, '0'), ': ', a.severity, ' adverse event on ', a.onset_date,
              ' (', SUBSTRING_INDEX(a.narrative, ':', 1), ') needs medical review')
FROM AdverseEvent a
WHERE a.severity IN ('Moderate', 'Severe')
UNION ALL
-- CONSENT_PDF: what the PDF text says vs the structured consent record
SELECT 'CONSENT_PDF', CONCAT('Consent:', d.consent_id), CAST(SUBSTRING(d.subject_code, 2) AS UNSIGNED), 'High',
       CONCAT(d.subject_code, ' consent ', d.version, ' signed ', d.signed_date, ': ',
              IF(d.doc_version IS NULL, 'no PDF on file or its text could not be read',
                 CONCAT('PDF says ', d.doc_version, ', ', COALESCE(d.doc_subject_code, '?'), ', ',
                        COALESCE(d.doc_signed_date, '?'))))
FROM v_consent_doc_check d
WHERE NOT (d.doc_version <=> d.version AND d.doc_subject_code <=> d.subject_code
           AND d.doc_signed_date <=> d.signed_date);

-- -------------------------------------------------------------------------
-- 4. sp_run_checks: save the current candidates as findings (one transaction)
-- -------------------------------------------------------------------------
DROP PROCEDURE IF EXISTS sp_run_checks;
DROP PROCEDURE IF EXISTS sp_ack_finding;

DELIMITER //
CREATE PROCEDURE sp_run_checks(IN p_source VARCHAR(10), IN p_user_id INT, OUT p_run_id INT)
SQL SECURITY DEFINER
COMMENT 'Runs every rule in v_check_candidates and updates CheckFinding'
BEGIN
  DECLARE v_now DATETIME DEFAULT NOW();
  DECLARE v_found INT DEFAULT 0;
  DECLARE v_new INT DEFAULT 0;
  DECLARE v_resolved INT DEFAULT 0;
  DECLARE v_err VARCHAR(255);

  IF p_source IS NULL OR p_source NOT IN ('EVENT', 'MANUAL', 'SCHEDULER', 'TEST') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'sp_run_checks: source must be EVENT, MANUAL, SCHEDULER or TEST';
  END IF;

  run_block: BEGIN
    -- Any error: undo the partial run, record a Failed run (so the failure is
    -- visible on the Automation page and in the audit log), then re-raise it.
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
      GET DIAGNOSTICS CONDITION 1 v_err = MESSAGE_TEXT;
      ROLLBACK;
      DO RELEASE_LOCK('ctt_checks');
      INSERT INTO CheckRun (source, started_by, started_at, finished_at, status, error_text)
      VALUES (p_source, p_user_id, v_now, NOW(3), 'Failed', v_err);
      SET p_run_id = LAST_INSERT_ID();
      CALL sp_audit(p_user_id, 'CHECK_RUN', 'CheckRun', CAST(p_run_id AS CHAR),
                    JSON_OBJECT('source', p_source, 'status', 'Failed', 'error', v_err));
      RESIGNAL;
    END;

    -- Only one run at a time (the nightly event and a "Run now" click could overlap).
    IF GET_LOCK('ctt_checks', 5) = 0 THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Another check run is in progress, try again in a moment';
    END IF;

    START TRANSACTION;
    INSERT INTO CheckRun (source, started_by, started_at) VALUES (p_source, p_user_id, v_now);
    SET p_run_id = LAST_INSERT_ID();

    -- New problems are inserted as Open. A problem already on file is refreshed;
    -- if it had been Resolved and has come back, it is re-opened and its old
    -- acknowledgement cleared. (Assignments run left to right, so status is last.)
    INSERT INTO CheckFinding (check_code, record_ref, subject_id, severity, message,
                              first_run, last_run, first_seen, last_seen)
    SELECT * FROM (
        SELECT c.check_code, c.record_ref, c.subject_id, c.severity, c.message,
               p_run_id AS fr, p_run_id AS lr, v_now AS fs, v_now AS ls
        FROM v_check_candidates c) AS n
    ON DUPLICATE KEY UPDATE
        severity    = n.severity,
        message     = n.message,
        last_run    = n.lr,
        last_seen   = n.ls,
        ack_by      = IF(status = 'Resolved', NULL, ack_by),
        ack_at      = IF(status = 'Resolved', NULL, ack_at),
        ack_note    = IF(status = 'Resolved', NULL, ack_note),
        resolved_at = NULL,
        status      = IF(status = 'Resolved', 'Open', status);

    SELECT COUNT(*), COALESCE(SUM(first_run = p_run_id), 0) INTO v_found, v_new
    FROM CheckFinding WHERE last_run = p_run_id;

    -- Anything still open that this run did NOT see has been fixed in the data.
    UPDATE CheckFinding SET status = 'Resolved', resolved_at = v_now
    WHERE status <> 'Resolved' AND last_run <> p_run_id;
    SET v_resolved = ROW_COUNT();

    UPDATE CheckRun SET finished_at = NOW(3), status = 'OK',
           found = v_found, new_open = v_new, resolved = v_resolved
    WHERE run_id = p_run_id;

    CALL sp_audit(p_user_id, 'CHECK_RUN', 'CheckRun', CAST(p_run_id AS CHAR),
                  JSON_OBJECT('source', p_source, 'status', 'OK', 'found', v_found,
                              'new', v_new, 'resolved', v_resolved));
    COMMIT;
    DO RELEASE_LOCK('ctt_checks');
  END run_block;
END//

-- An Admin marks an Open finding as Acknowledged (seen, being handled).
-- It stays Acknowledged until the data is fixed; then the next run resolves it.
CREATE PROCEDURE sp_ack_finding(IN p_finding_id INT, IN p_user_id INT, IN p_note VARCHAR(255))
SQL SECURITY DEFINER
COMMENT 'Acknowledge an open finding with a note (audited)'
BEGIN
  DECLARE EXIT HANDLER FOR SQLEXCEPTION
  BEGIN
    ROLLBACK;
    RESIGNAL;
  END;

  IF p_note IS NULL OR CHAR_LENGTH(TRIM(p_note)) < 3 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Write a short note (at least 3 characters) saying what is being done';
  END IF;

  START TRANSACTION;
  UPDATE CheckFinding
     SET status = 'Acknowledged', ack_by = p_user_id, ack_at = NOW(), ack_note = TRIM(p_note)
   WHERE finding_id = p_finding_id AND status = 'Open';
  IF ROW_COUNT() = 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Only an Open finding can be acknowledged';
  END IF;
  CALL sp_audit(p_user_id, 'ACKNOWLEDGE', 'CheckFinding', CAST(p_finding_id AS CHAR),
                JSON_OBJECT('note', TRIM(p_note)));
  COMMIT;
END//
DELIMITER ;

-- -------------------------------------------------------------------------
-- 5. Nightly schedule inside MySQL
-- -------------------------------------------------------------------------
DROP EVENT IF EXISTS ev_nightly_checks;
CREATE EVENT ev_nightly_checks
  ON SCHEDULE EVERY 1 DAY STARTS TIMESTAMP(CURDATE() + INTERVAL 1 DAY, '02:00:00')
  ON COMPLETION PRESERVE
  ENABLE
  COMMENT 'COS20015: run the automated data checks every night at 02:00'
  DO CALL sp_run_checks('EVENT', NULL, @ev_run_id);

-- -------------------------------------------------------------------------
-- 6. Findings for the User role (codes only) and permissions
-- -------------------------------------------------------------------------
CREATE OR REPLACE VIEW v_finding_coded AS
SELECT f.finding_id,
       f.check_code,
       r.title           AS check_title,
       r.data_source,
       CONCAT('S', LPAD(f.subject_id, 3, '0')) AS subject_code,
       st.site_name,
       f.severity,
       f.status,
       f.message,
       f.first_seen,
       f.last_seen,
       f.resolved_at,
       f.ack_at,
       f.ack_note
FROM CheckFinding f
JOIN CheckRule r ON r.check_code = f.check_code
LEFT JOIN Subject s ON s.subject_id = f.subject_id
LEFT JOIN Site st   ON st.site_id   = s.site_id;

CREATE OR REPLACE VIEW v_check_run AS
SELECT run_id, source, started_at, finished_at, status, found, new_open, resolved, error_text
FROM CheckRun;

GRANT SELECT ON clinical_trial_tracking.v_finding_coded TO 'ctt_reader';
GRANT SELECT ON clinical_trial_tracking.v_check_run     TO 'ctt_reader';
GRANT SELECT ON clinical_trial_tracking.CheckRule       TO 'ctt_reader';
-- ctt_admin: reads everything (still has SELECT on the database here; 11 narrows
-- it table by table) and may run the checks and acknowledge findings, but only
-- through the two procedures, never by writing CheckFinding directly.
GRANT EXECUTE ON PROCEDURE clinical_trial_tracking.sp_run_checks  TO 'ctt_admin';
GRANT EXECUTE ON PROCEDURE clinical_trial_tracking.sp_ack_finding TO 'ctt_admin';

-- Quick look: what the rules find today
SELECT check_code, severity, COUNT(*) AS problems
FROM v_check_candidates GROUP BY check_code, severity ORDER BY check_code, severity;
