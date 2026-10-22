-- =========================================================================
-- COS20015 Project Phase-05 -- Tests for 13_ai_extraction.sql (no API call:
-- the "AI answers" are written by hand). Run with:
--   mysql --force -t < 13b_test_ai_extraction.sql
-- sp_review_extraction commits, so the test rows are removed at the end.
-- =========================================================================
USE clinical_trial_tracking;
SET @start_audit := (SELECT COALESCE(MAX(audit_id), 0) FROM AuditLog);   -- count only this run's audit rows
SET @sha11 := (SELECT SHA2(narrative, 256) FROM AdverseEvent WHERE ae_id = 11);
SET @good := '{"event_term": "Alanine aminotransferase increased", "symptoms": [], "visit_type": "Dosing Visit 2",
  "treatments": [], "action_with_study_drug": "continued with monitoring", "outcome": "recovering",
  "suggested_severity": "Moderate", "serious": false, "withdrawn": false,
  "lab": {"test": "ALT", "value": 96, "unit": "U/L", "visit_type": "Dosing Visit 2", "upper_limit": 40, "repeat_value": 61},
  "evidence": "ALT 96 U/L at Dosing Visit 2 (upper limit of normal 40 U/L)", "confidence": 0.95}';

-- T1. A well-formed answer is accepted and starts as Pending.
INSERT INTO AEExtraction (ae_id, narrative_sha256, model, prompt_version, extracted) VALUES (11, @sha11, 'test', 'test', @good);
SET @x1 := LAST_INSERT_ID();
SELECT 'T1 valid answer stored as Pending' AS test, status, event_term, lab_value, lab_repeat,
       IF(status = 'Pending' AND lab_value = 96, 'PASS', 'FAIL') AS result FROM AEExtraction WHERE extraction_id = @x1;

-- T2. Cross-check against structured data. EXPECT: severity agrees, ALT 96 at Dosing Visit 2 and 61 at follow-up exist
SELECT 'T2 cross-check' AS test, subject_code, severity_agrees, lab_found, repeat_found, is_current,
       IF(severity_agrees AND lab_found AND repeat_found AND is_current, 'PASS', 'FAIL') AS result
FROM v_ae_extraction_review WHERE extraction_id = @x1;

-- T3. A wrong lab value from the AI is caught by the cross-check. EXPECT: lab_found 0
INSERT INTO AEExtraction (ae_id, narrative_sha256, model, prompt_version, extracted)
VALUES (11, @sha11, 'test', 'test', JSON_SET(@good, '$.lab.value', 69, '$.suggested_severity', 'Severe'));
SET @x2 := LAST_INSERT_ID();
SELECT 'T3 wrong value flagged' AS test, severity_agrees, lab_found,
       IF(severity_agrees = 0 AND lab_found = 0, 'PASS', 'FAIL') AS result FROM v_ae_extraction_review WHERE extraction_id = @x2;

-- T4. The database refuses AI output of the wrong shape. EXPECT: ERROR 3819 chk_extract_schema (all three)
INSERT INTO AEExtraction (ae_id, narrative_sha256, model, prompt_version, extracted)
VALUES (11, @sha11, 'test', 'test', JSON_SET(@good, '$.suggested_severity', 'Life-threatening'));
INSERT INTO AEExtraction (ae_id, narrative_sha256, model, prompt_version, extracted)
VALUES (11, @sha11, 'test', 'test', JSON_SET(@good, '$.patient_name', 'Invented'));
INSERT INTO AEExtraction (ae_id, narrative_sha256, model, prompt_version, extracted)
VALUES (11, @sha11, 'test', 'test', JSON_REMOVE(@good, '$.outcome'));

-- T5. Rejecting needs a reason. EXPECT: ERROR 1644
CALL sp_review_extraction(@x2, NULL, 'REJECT', '');
-- T6. Reject the wrong one, approve the right one.
CALL sp_review_extraction(@x2, NULL, 'REJECT', 'Test: ALT value does not match the lab result');
CALL sp_review_extraction(@x1, NULL, 'APPROVE', NULL);
SELECT 'T6 reviewed' AS test, GROUP_CONCAT(status ORDER BY extraction_id) AS statuses,
       IF(GROUP_CONCAT(status ORDER BY extraction_id) = 'Approved,Rejected', 'PASS', 'FAIL') AS result
FROM AEExtraction WHERE extraction_id IN (@x1, @x2);
-- T7. A decision is final. EXPECT: ERROR 1644 "Only a Pending extraction can be reviewed"
CALL sp_review_extraction(@x1, NULL, 'REJECT', 'changed my mind');
-- T8. A newer approved answer supersedes the old one.
INSERT INTO AEExtraction (ae_id, narrative_sha256, model, prompt_version, extracted) VALUES (11, @sha11, 'test', 'test', @good);
SET @x3 := LAST_INSERT_ID();
CALL sp_review_extraction(@x3, NULL, 'APPROVE', NULL);
SELECT 'T8 one approved answer per AE' AS test, GROUP_CONCAT(status ORDER BY extraction_id) AS statuses,
       IF(GROUP_CONCAT(status ORDER BY extraction_id) = 'Superseded,Rejected,Approved', 'PASS', 'FAIL') AS result
FROM AEExtraction WHERE extraction_id IN (@x1, @x2, @x3);
-- T9. An answer for an older version of the narrative cannot be approved. EXPECT: ERROR 1644 "narrative changed"
INSERT INTO AEExtraction (ae_id, narrative_sha256, model, prompt_version, extracted) VALUES (11, REPEAT('0', 64), 'test', 'test', @good);
SET @x4 := LAST_INSERT_ID();
CALL sp_review_extraction(@x4, NULL, 'APPROVE', NULL);
-- T10. Decisions are audited. EXPECT: 3 rows (1 REJECT, 2 APPROVE)
SELECT 'T10 audited' AS test, COUNT(*) AS audit_rows, IF(COUNT(*) = 3, 'PASS', 'FAIL') AS result
FROM AuditLog WHERE table_name = 'AEExtraction' AND record_id IN (@x1, @x2, @x3) AND action IN ('APPROVE', 'REJECT')
  AND audit_id > @start_audit;

-- Cleanup: remove the test answers (their audit rows stay).
DELETE FROM AEExtraction WHERE extraction_id IN (@x1, @x2, @x3, @x4);
SELECT 'Cleanup' AS test, COUNT(*) AS test_rows_left FROM AEExtraction WHERE model = 'test';
