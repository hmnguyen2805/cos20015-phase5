-- =========================================================================
-- COS20015 Project Phase-05 -- Tests for 06_phase5_schema.sql
-- Run with:  mysql --force -v -t < 06b_test_phase5_schema.sql
-- (--force keeps going after the statements that are SUPPOSED to fail.)
-- Everything runs inside one transaction and is rolled back at the end,
-- so the database is left exactly as it was.
-- =========================================================================
USE clinical_trial_tracking;
START TRANSACTION;

-- T1. Valid eCRF for visit 1 (subject 1, Screening Visit, 2026-03-01). EXPECT: OK
INSERT INTO VisitECRF (visit_id, ecrf_doc) VALUES (1, '{
  "formVersion": "2.0", "subjectId": 1, "visitType": "Screening Visit",
  "scheduledDate": "2026-03-01",
  "vitals": {"heartRate": 72, "bloodPressure": "118/76", "temperatureC": 36.6, "weightKg": 71.5},
  "concomitantMeds": [{"name": "Paracetamol", "dose": "500 mg", "indication": "Headache"}]
}');
SELECT ecrf_id, visit_id, form_version, heart_rate, systolic_bp, diastolic_bp, temperature_c, med_count
FROM VisitECRF WHERE visit_id = 1;

-- T2. Optional sections left out (vitals, meds). EXPECT: OK, generated columns NULL / 0
INSERT INTO VisitECRF (visit_id, ecrf_doc) VALUES (2,
  '{"subjectId": 1, "visitType": "Dosing Visit 1", "scheduledDate": "2026-03-15"}');
SELECT visit_id, heart_rate, systolic_bp, med_count FROM VisitECRF WHERE visit_id = 2;

-- T3. heartRate 400 breaks "maximum". EXPECT: ERROR 3819 chk_ecrf_schema
INSERT INTO VisitECRF (visit_id, ecrf_doc) VALUES (3,
  '{"subjectId": 1, "visitType": "Dosing Visit 2", "scheduledDate": "2026-03-29", "vitals": {"heartRate": 400}}');
SHOW WARNINGS;

-- T4. Unknown property "smoker" breaks additionalProperties:false. EXPECT: ERROR 3819
INSERT INTO VisitECRF (visit_id, ecrf_doc) VALUES (3,
  '{"subjectId": 1, "visitType": "Dosing Visit 2", "scheduledDate": "2026-03-29", "smoker": true}');

-- T5. Blood pressure "high" breaks the regex pattern. EXPECT: ERROR 3819
INSERT INTO VisitECRF (visit_id, ecrf_doc) VALUES (3,
  '{"subjectId": 1, "visitType": "Dosing Visit 2", "scheduledDate": "2026-03-29", "vitals": {"bloodPressure": "high"}}');

-- T6. Missing required subjectId. EXPECT: ERROR 3819 (trigger lets it through via ternary logic)
INSERT INTO VisitECRF (visit_id, ecrf_doc) VALUES (3,
  '{"visitType": "Dosing Visit 2", "scheduledDate": "2026-03-29"}');

-- T7. subjectId 2 does not own visit 3. EXPECT: ERROR 1644 (trigger, 45000)
INSERT INTO VisitECRF (visit_id, ecrf_doc) VALUES (3,
  '{"subjectId": 2, "visitType": "Dosing Visit 2", "scheduledDate": "2026-03-29"}');

-- T8. Visit 101 is only Scheduled. EXPECT: ERROR 1644 (trigger, 45000)
INSERT INTO VisitECRF (visit_id, ecrf_doc) VALUES (101,
  '{"subjectId": 27, "visitType": "Dosing Visit 2", "scheduledDate": "2026-09-22"}');

-- T9. Second eCRF for visit 1. EXPECT: ERROR 1062 duplicate (UNIQUE visit_id)
INSERT INTO VisitECRF (visit_id, ecrf_doc) VALUES (1,
  '{"subjectId": 1, "visitType": "Screening Visit", "scheduledDate": "2026-03-01"}');

-- T10. Does MySQL enforce "format": "date"? (Phase-01 schema used it.)
SELECT JSON_SCHEMA_VALID('{"type": "string", "format": "date"}', '"not-a-date"') AS format_date_ignored_if_1,
       JSON_SCHEMA_VALID('{"type": "string", "pattern": "^[0-9]{4}-[0-9]{2}-[0-9]{2}$"}', '"not-a-date"') AS pattern_rejects_if_0;

-- T11. Readable reason for a failure (what the PHP app will show the user)
SELECT JSON_PRETTY(JSON_SCHEMA_VALIDATION_REPORT(
  '{"type":"object","properties":{"vitals":{"type":"object","properties":{"heartRate":{"type":"integer","maximum":250}}}}}',
  '{"vitals":{"heartRate":400}}')) AS validation_report;

-- T12. Multi-valued index on medication names. EXPECT: key = idx_ecrf_meds
EXPLAIN SELECT visit_id FROM VisitECRF
WHERE 'Paracetamol' MEMBER OF (ecrf_doc->'$.concomitantMeds[*].name');

-- T13. FULLTEXT search over AE narratives. EXPECT: the ALT events
SELECT ae_id, severity, event_term,
       ROUND(MATCH(narrative) AGAINST ('aminotransferase monitoring'), 3) AS relevance
FROM (SELECT a.*, SUBSTRING_INDEX(a.narrative, ':', 1) AS event_term FROM AdverseEvent a) x
WHERE MATCH(narrative) AGAINST ('aminotransferase monitoring')
ORDER BY relevance DESC LIMIT 5;

-- T14. ConsentDocument sha256 must be 64 hex chars. EXPECT: ERROR 3819 chk_consent_sha256
INSERT INTO ConsentDocument (consent_id, file_path, sha256) VALUES (1, 'consent/ST04/S026_v1.0.pdf', 'not-a-hash');

-- T15. AppUser role must be Admin or User. EXPECT: ERROR 3819 chk_app_role
INSERT INTO AppUser (username, display_name, password_hash, app_role) VALUES ('test', 'Test', 'x', 'Superuser');

-- T16. Pseudonymised views expose codes, not names
SELECT * FROM v_subject_coded LIMIT 3;
SELECT * FROM v_lab_coded WHERE flag <> 'N' ORDER BY result_id LIMIT 5;
SELECT * FROM v_ecrf_coded;

ROLLBACK;

-- After rollback: EXPECT 0 eCRFs, 0 users
SELECT (SELECT COUNT(*) FROM VisitECRF) AS ecrf_rows, (SELECT COUNT(*) FROM AppUser) AS user_rows;
