-- =========================================================================
-- COS20015 Project Phase-05 -- AI extraction of adverse-event narratives
-- Run after 12_report_schedule.sql. Safe to re-run (keeps existing extractions).
--
-- Unstructured -> structured, with a human in the loop:
--   1. The app sends ONE AE narrative (the free text only: no subject code,
--      no name) to the Claude API and asks for a fixed JSON shape.
--   2. The answer is stored in AEExtraction as Pending. A JSON Schema CHECK
--      validates it first: the database does not trust the AI any more than
--      it trusts a person typing an eCRF.
--   3. v_ae_extraction_review cross-checks the AI's answer against the
--      structured data (recorded severity, LabResult values).
--   4. An Admin approves or rejects it (sp_review_extraction, audited).
-- =========================================================================
USE clinical_trial_tracking;

CREATE TABLE IF NOT EXISTS AEExtraction (
 extraction_id INT AUTO_INCREMENT PRIMARY KEY,
 ae_id INT NOT NULL,
 narrative_sha256 CHAR(64) NOT NULL,           -- the exact text the AI read; a later edit makes this row stale
 model VARCHAR(60) NOT NULL,
 prompt_version VARCHAR(10) NOT NULL,
 extracted JSON NOT NULL,
 status VARCHAR(10) NOT NULL DEFAULT 'Pending',
 requested_by INT NULL,                        -- NULL = the scheduler
 requested_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
 input_tokens INT NULL,
 output_tokens INT NULL,
 latency_ms INT NULL,
 reviewed_by INT NULL,
 reviewed_at DATETIME NULL,
 review_note VARCHAR(255) NULL,

 -- Generated columns: the JSON answer as typed, comparable columns. LEFT() keeps
 -- an over-long value from failing here as "data too long", so a bad answer is
 -- always reported by the JSON Schema CHECK below with the real reason.
 event_term   VARCHAR(100) AS (LEFT(extracted->>'$.event_term', 100)) VIRTUAL,
 ai_severity  VARCHAR(20)  AS (LEFT(extracted->>'$.suggested_severity', 20)) VIRTUAL,
 outcome      VARCHAR(20)  AS (LEFT(extracted->>'$.outcome', 20)) VIRTUAL,
 drug_action  VARCHAR(30)  AS (LEFT(extracted->>'$.action_with_study_drug', 30)) VIRTUAL,
 withdrawn    BOOLEAN      AS (extracted->>'$.withdrawn' = 'true') VIRTUAL,
 lab_test     VARCHAR(20)  AS (LEFT(extracted->>'$.lab.test', 20)) VIRTUAL,
 lab_visit    VARCHAR(30)  AS (LEFT(extracted->>'$.lab.visit_type', 30)) VIRTUAL,
 lab_value    DECIMAL(8,2) AS (JSON_VALUE(extracted, '$.lab.value' RETURNING DECIMAL(8,2))) VIRTUAL,
 lab_repeat   DECIMAL(8,2) AS (JSON_VALUE(extracted, '$.lab.repeat_value' RETURNING DECIMAL(8,2))) VIRTUAL,
 confidence   DECIMAL(3,2) AS (JSON_VALUE(extracted, '$.confidence' RETURNING DECIMAL(3,2))) VIRTUAL,

 FOREIGN KEY (ae_id) REFERENCES AdverseEvent(ae_id),
 FOREIGN KEY (requested_by) REFERENCES AppUser(user_id),
 FOREIGN KEY (reviewed_by) REFERENCES AppUser(user_id),
 INDEX idx_extract_ae (ae_id, status),
 CONSTRAINT chk_extract_status CHECK (status IN ('Pending', 'Approved', 'Rejected', 'Superseded')),
 CONSTRAINT chk_extract_sha CHECK (narrative_sha256 REGEXP '^[0-9a-f]{64}$'),
 -- Same shape as the tool schema sent to the API (app/src/ai.php, AI_TOOL_SCHEMA).
 CONSTRAINT chk_extract_schema CHECK (JSON_SCHEMA_VALID('{
   "$schema": "http://json-schema.org/draft-04/schema#",
   "type": "object",
   "required": ["event_term", "symptoms", "action_with_study_drug", "outcome", "suggested_severity",
                "serious", "withdrawn", "lab", "evidence", "confidence"],
   "properties": {
     "event_term":   { "type": "string", "minLength": 2, "maxLength": 100 },
     "symptoms":     { "type": "array", "maxItems": 10, "items": { "type": "string", "maxLength": 60 } },
     "visit_type":   { "enum": ["Screening Visit", "Dosing Visit 1", "Dosing Visit 2", "Follow-up Visit", null] },
     "treatments":   { "type": "array", "maxItems": 10, "items": {
                         "type": "object", "required": ["name"],
                         "properties": { "name": { "type": "string", "maxLength": 60 },
                                         "dose": { "type": ["string", "null"], "maxLength": 30 } },
                         "additionalProperties": false } },
     "action_with_study_drug": { "enum": ["none", "continued with monitoring", "interrupted", "withdrawn", "unknown"] },
     "outcome":      { "enum": ["recovered", "recovering", "not recovered", "unknown"] },
     "suggested_severity": { "enum": ["Mild", "Moderate", "Severe"] },
     "serious":      { "type": "boolean" },
     "withdrawn":    { "type": "boolean" },
     "lab": { "oneOf": [ { "type": "null" }, {
                "type": "object", "required": ["test", "value"],
                "properties": {
                  "test":         { "enum": ["ALT", "WBC", "Haemoglobin", "Creatinine"] },
                  "value":        { "type": "number" },
                  "unit":         { "type": ["string", "null"], "maxLength": 20 },
                  "visit_type":   { "enum": ["Screening Visit", "Dosing Visit 1", "Dosing Visit 2", "Follow-up Visit", null] },
                  "upper_limit":  { "type": ["number", "null"] },
                  "repeat_value": { "type": ["number", "null"] } },
                "additionalProperties": false } ] },
     "evidence":     { "type": "string", "maxLength": 400 },
     "confidence":   { "type": "number", "minimum": 0, "maximum": 1 }
   },
   "additionalProperties": false
 }', extracted))
);

-- -------------------------------------------------------------------------
-- Review view: the AI answer next to the structured facts it can be checked against.
--   severity_agrees  AI's suggested grade = the recorded grade
--   lab_found        a LabResult row with that test and value exists for the subject
--                    at the visit the narrative names (NULL when there is no lab value)
--   repeat_found     the "repeat value at follow-up" also exists (NULL when none)
--   is_current       the narrative has not changed since the AI read it
-- -------------------------------------------------------------------------
CREATE OR REPLACE VIEW v_ae_extraction_review AS
SELECT x.extraction_id, x.ae_id, x.status, x.model, x.prompt_version, x.requested_at, x.requested_by,
       x.reviewed_by, x.reviewed_at, x.review_note, x.input_tokens, x.output_tokens, x.latency_ms,
       x.event_term, x.ai_severity, x.outcome, x.drug_action, x.withdrawn, x.lab_test, x.lab_visit,
       x.lab_value, x.lab_repeat, x.confidence, x.extracted,
       CONCAT('S', LPAD(a.subject_id, 3, '0')) AS subject_code,
       a.onset_date, a.severity AS recorded_severity, a.narrative,
       (x.ai_severity = a.severity) AS severity_agrees,
       IF(x.lab_test IS NULL, NULL,
          EXISTS (SELECT 1 FROM LabResult lr JOIN Visit v ON v.visit_id = lr.visit_id
                  WHERE v.subject_id = a.subject_id AND lr.test_name = x.lab_test AND lr.value = x.lab_value
                    AND (x.lab_visit IS NULL OR v.visit_type = x.lab_visit))) AS lab_found,
       IF(x.lab_repeat IS NULL, NULL,
          EXISTS (SELECT 1 FROM LabResult lr JOIN Visit v ON v.visit_id = lr.visit_id
                  WHERE v.subject_id = a.subject_id AND lr.test_name = x.lab_test AND lr.value = x.lab_repeat
                    AND v.visit_type = 'Follow-up Visit')) AS repeat_found,
       (x.narrative_sha256 = SHA2(a.narrative, 256)) AS is_current
FROM AEExtraction x
JOIN AdverseEvent a ON a.ae_id = x.ae_id;

-- -------------------------------------------------------------------------
-- Approve or reject a Pending extraction (one transaction, audited).
-- Approving supersedes any earlier approved answer for the same AE, so each
-- AE has at most one approved extraction.
-- -------------------------------------------------------------------------
DROP PROCEDURE IF EXISTS sp_review_extraction;
DELIMITER //
CREATE PROCEDURE sp_review_extraction(IN p_id INT, IN p_user_id INT, IN p_decision VARCHAR(10), IN p_note VARCHAR(255))
SQL SECURITY DEFINER
COMMENT 'Admin decision on an AI extraction: APPROVE or REJECT (audited)'
BEGIN
  DECLARE v_ae INT;
  DECLARE v_current BOOLEAN;
  DECLARE EXIT HANDLER FOR SQLEXCEPTION
  BEGIN
    ROLLBACK;
    RESIGNAL;
  END;

  IF p_decision IS NULL OR p_decision NOT IN ('APPROVE', 'REJECT') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Decision must be APPROVE or REJECT';
  END IF;
  IF p_decision = 'REJECT' AND (p_note IS NULL OR CHAR_LENGTH(TRIM(p_note)) < 3) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Say why you reject it (at least 3 characters)';
  END IF;

  START TRANSACTION;
  SELECT x.ae_id, (x.narrative_sha256 = SHA2(a.narrative, 256)) INTO v_ae, v_current
  FROM AEExtraction x JOIN AdverseEvent a ON a.ae_id = x.ae_id
  WHERE x.extraction_id = p_id AND x.status = 'Pending'
  FOR UPDATE;
  IF v_ae IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Only a Pending extraction can be reviewed';
  END IF;
  IF p_decision = 'APPROVE' AND NOT v_current THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The narrative changed after the AI read it: re-run the extraction first';
  END IF;

  IF p_decision = 'APPROVE' THEN
    UPDATE AEExtraction SET status = 'Superseded' WHERE ae_id = v_ae AND status = 'Approved';
  END IF;
  UPDATE AEExtraction
     SET status = IF(p_decision = 'APPROVE', 'Approved', 'Rejected'),
         reviewed_by = p_user_id, reviewed_at = NOW(), review_note = NULLIF(TRIM(p_note), '')
   WHERE extraction_id = p_id;
  CALL sp_audit(p_user_id, p_decision, 'AEExtraction', CAST(p_id AS CHAR),
                JSON_OBJECT('ae_id', v_ae, 'note', NULLIF(TRIM(p_note), '')));
  COMMIT;
END//
DELIMITER ;

-- -------------------------------------------------------------------------
-- Permissions. The app may INSERT new answers but not choose their status
-- (column list leaves status out, so every new row is Pending), and changes
-- status only through sp_review_extraction.
-- -------------------------------------------------------------------------
GRANT SELECT ON clinical_trial_tracking.AEExtraction           TO 'ctt_admin';
GRANT SELECT ON clinical_trial_tracking.v_ae_extraction_review TO 'ctt_admin';
GRANT INSERT (ae_id, narrative_sha256, model, prompt_version, extracted, requested_by,
              input_tokens, output_tokens, latency_ms)
      ON clinical_trial_tracking.AEExtraction TO 'ctt_admin';
GRANT EXECUTE ON PROCEDURE clinical_trial_tracking.sp_review_extraction TO 'ctt_admin';
