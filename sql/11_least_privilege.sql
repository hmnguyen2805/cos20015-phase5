-- =========================================================================
-- COS20015 Project Phase-05 -- Least privilege for the Admin database role
-- Run after 10_automation.sql (and again after any re-run of 06, which grants
-- the old database-wide privileges). Safe to re-run.
--
-- Before: ctt_admin had SELECT, INSERT, UPDATE, DELETE, EXECUTE on the whole
-- database, so the app_admin login could delete or edit audit rows.
-- After:  ctt_admin can READ every table and view, but WRITE only what the app
-- actually writes, and only the columns it writes:
--   AppUser   : INSERT, and UPDATE of display_name, password_hash, app_role, is_active
--   VisitECRF : UPDATE of ecrf_doc (the JSON Schema CHECK and trigger still decide)
--   procedures: sp_audit, sp_run_checks, sp_ack_finding
-- No DELETE anywhere. AuditLog, CheckRun and CheckFinding are written only
-- through SQL SECURITY DEFINER procedures, so the audit trail is append-only
-- for every app account, Admin included.
-- (Scripts 12 and 13 grant their own new tables in the same style.)
-- =========================================================================
USE clinical_trial_tracking;

-- 1. Remove the database-wide grant ("IF EXISTS" keeps re-runs quiet).
REVOKE IF EXISTS SELECT, INSERT, UPDATE, DELETE, EXECUTE ON clinical_trial_tracking.* FROM 'ctt_admin';

-- 2. Read access, table by table.
--    Phase-02 tables
GRANT SELECT ON clinical_trial_tracking.Site               TO 'ctt_admin';
GRANT SELECT ON clinical_trial_tracking.Investigator       TO 'ctt_admin';
GRANT SELECT ON clinical_trial_tracking.Subject            TO 'ctt_admin';
GRANT SELECT ON clinical_trial_tracking.Screening          TO 'ctt_admin';
GRANT SELECT ON clinical_trial_tracking.Visit              TO 'ctt_admin';
GRANT SELECT ON clinical_trial_tracking.Drug               TO 'ctt_admin';
GRANT SELECT ON clinical_trial_tracking.DrugAdministration TO 'ctt_admin';
GRANT SELECT ON clinical_trial_tracking.LabResult          TO 'ctt_admin';
GRANT SELECT ON clinical_trial_tracking.AdverseEvent       TO 'ctt_admin';
GRANT SELECT ON clinical_trial_tracking.InformedConsent    TO 'ctt_admin';
--    Phase-05 tables
GRANT SELECT ON clinical_trial_tracking.VisitECRF          TO 'ctt_admin';
GRANT SELECT ON clinical_trial_tracking.ConsentDocument    TO 'ctt_admin';
GRANT SELECT ON clinical_trial_tracking.AppUser            TO 'ctt_admin';
GRANT SELECT ON clinical_trial_tracking.AuditLog           TO 'ctt_admin';   -- read only
GRANT SELECT ON clinical_trial_tracking.CheckRule          TO 'ctt_admin';
GRANT SELECT ON clinical_trial_tracking.CheckRun           TO 'ctt_admin';   -- read only
GRANT SELECT ON clinical_trial_tracking.CheckFinding       TO 'ctt_admin';   -- read only
--    Views
GRANT SELECT ON clinical_trial_tracking.v_subject_coded     TO 'ctt_admin';
GRANT SELECT ON clinical_trial_tracking.v_visit_coded       TO 'ctt_admin';
GRANT SELECT ON clinical_trial_tracking.v_lab_coded         TO 'ctt_admin';
GRANT SELECT ON clinical_trial_tracking.v_ae_coded          TO 'ctt_admin';
GRANT SELECT ON clinical_trial_tracking.v_ecrf_coded        TO 'ctt_admin';
GRANT SELECT ON clinical_trial_tracking.v_consent_coded     TO 'ctt_admin';
GRANT SELECT ON clinical_trial_tracking.v_dose_coded        TO 'ctt_admin';
GRANT SELECT ON clinical_trial_tracking.v_site_coded        TO 'ctt_admin';
GRANT SELECT ON clinical_trial_tracking.v_consent_doc_check TO 'ctt_admin';
GRANT SELECT ON clinical_trial_tracking.v_check_candidates  TO 'ctt_admin';
GRANT SELECT ON clinical_trial_tracking.v_finding_coded     TO 'ctt_admin';
GRANT SELECT ON clinical_trial_tracking.v_check_run         TO 'ctt_admin';

-- 3. Write access: only what the app does, column by column.
GRANT INSERT ON clinical_trial_tracking.AppUser TO 'ctt_admin';
GRANT UPDATE (display_name, password_hash, app_role, is_active) ON clinical_trial_tracking.AppUser TO 'ctt_admin';
GRANT UPDATE (ecrf_doc) ON clinical_trial_tracking.VisitECRF TO 'ctt_admin';

-- 4. Procedures (each runs with its definer's rights and does one checked thing).
GRANT EXECUTE ON PROCEDURE clinical_trial_tracking.sp_audit       TO 'ctt_admin';
GRANT EXECUTE ON PROCEDURE clinical_trial_tracking.sp_run_checks  TO 'ctt_admin';
GRANT EXECUTE ON PROCEDURE clinical_trial_tracking.sp_ack_finding TO 'ctt_admin';

-- Check: no database-wide privilege is left, and no DELETE on any table.
SELECT 'database-wide grants left' AS check_name, COUNT(*) AS n
FROM information_schema.SCHEMA_PRIVILEGES
WHERE GRANTEE = "'ctt_admin'@'%'" AND TABLE_SCHEMA = 'clinical_trial_tracking'
UNION ALL
SELECT 'DELETE grants on tables', COUNT(*)
FROM information_schema.TABLE_PRIVILEGES
WHERE GRANTEE = "'ctt_admin'@'%'" AND TABLE_SCHEMA = 'clinical_trial_tracking' AND PRIVILEGE_TYPE = 'DELETE';
SHOW GRANTS FOR 'ctt_admin';
