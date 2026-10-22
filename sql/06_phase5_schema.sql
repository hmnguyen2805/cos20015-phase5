-- =========================================================================
-- COS20015 Project Phase-05 -- Schema extension (additive only)
-- Domain: Clinical Trial Subject-Tracking (continued from Phases 1 to 4)
-- Student: Ha Minh Nguyen (105304697)
-- Target DBMS: MySQL 8.0 (tested on 8.0.46)
--
-- Run order on a clean database: 01_schema.sql, 02_data.sql, 05_index.sql,
-- then this file. The ten Phase-02 tables are NOT altered, except for one
-- FULLTEXT index on AdverseEvent.narrative (an index, like Phase-03's).
--
-- What this adds:
--   Semi-structured : VisitECRF (JSON eCRF, validated against a Draft 4 schema)
--   Unstructured    : ConsentDocument (metadata + extracted text of consent PDFs),
--                     FULLTEXT search on consent text and AE narratives
--   Access control  : AppUser, AuditLog, pseudonymised views, MySQL roles
-- =========================================================================
USE clinical_trial_tracking;

-- -------------------------------------------------------------------------
-- 1. SEMI-STRUCTURED: electronic Case Report Form (eCRF) per completed visit
--
-- The JSON Schema is version 2 of the Phase-01 Section 3.2.1 schema, rewritten
-- for Draft 4 because MySQL validates against Draft 4 only. Changes from v1:
--   * "$schema" points to Draft 4 (v1 used Draft 2020-12)
--   * "format": "date" replaced with a regex "pattern" for scheduledDate
--   * vitals gains temperatureC and weightKg; bloodPressure gets a pattern
--   * new optional formVersion, concomitantMeds (array) and investigatorComment
-- Required fields and additionalProperties:false are kept from v1.
-- -------------------------------------------------------------------------
DROP TABLE IF EXISTS VisitECRF;
CREATE TABLE VisitECRF (
 ecrf_id INT AUTO_INCREMENT PRIMARY KEY,
 visit_id INT NOT NULL UNIQUE,                 -- at most one eCRF per visit
 ecrf_doc JSON NOT NULL,
 received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

 -- Generated columns expose a few JSON paths as typed, indexable columns.
 -- They are VIRTUAL: computed on read, nothing is stored twice.
 form_version VARCHAR(10) AS (ecrf_doc->>'$.formVersion') VIRTUAL,
 heart_rate SMALLINT AS (JSON_VALUE(ecrf_doc, '$.vitals.heartRate' RETURNING UNSIGNED)) VIRTUAL,
 -- bloodPressure is text like "118/76" (semi-structured). Only split it when it
 -- matches the pattern, so a bad value is reported by the JSON Schema CHECK
 -- below instead of failing earlier as a type-conversion error.
 systolic_bp SMALLINT AS (IF(ecrf_doc->>'$.vitals.bloodPressure' REGEXP '^[0-9]{2,3}/[0-9]{2,3}$',
     CAST(SUBSTRING_INDEX(ecrf_doc->>'$.vitals.bloodPressure', '/', 1) AS UNSIGNED), NULL)) VIRTUAL,
 diastolic_bp SMALLINT AS (IF(ecrf_doc->>'$.vitals.bloodPressure' REGEXP '^[0-9]{2,3}/[0-9]{2,3}$',
     CAST(SUBSTRING_INDEX(ecrf_doc->>'$.vitals.bloodPressure', '/', -1) AS UNSIGNED), NULL)) VIRTUAL,
 temperature_c DECIMAL(4,1) AS (JSON_VALUE(ecrf_doc, '$.vitals.temperatureC' RETURNING DECIMAL(4,1))) VIRTUAL,
 med_count SMALLINT AS (COALESCE(JSON_LENGTH(ecrf_doc, '$.concomitantMeds'), 0)) VIRTUAL,

 FOREIGN KEY (visit_id) REFERENCES Visit(visit_id),

 -- Multi-valued index: one index entry per medication name in the JSON array,
 -- so "which visits recorded paracetamol?" can use MEMBER OF with an index.
 INDEX idx_ecrf_meds ((CAST(ecrf_doc->'$.concomitantMeds[*].name' AS CHAR(60) ARRAY))),
 INDEX idx_ecrf_systolic (systolic_bp),

 CONSTRAINT chk_ecrf_schema CHECK (JSON_SCHEMA_VALID('{
   "$schema": "http://json-schema.org/draft-04/schema#",
   "title": "AppointmentRecord",
   "description": "eCRF for one completed study visit, v2 of the Phase-01 schema",
   "type": "object",
   "required": ["subjectId", "visitType", "scheduledDate"],
   "properties": {
     "formVersion":   { "type": "string" },
     "subjectId":     { "type": "integer", "minimum": 1 },
     "visitType":     { "type": "string",
                        "enum": ["Screening Visit", "Dosing Visit 1", "Dosing Visit 2", "Follow-up Visit"] },
     "scheduledDate": { "type": "string", "pattern": "^[0-9]{4}-[0-9]{2}-[0-9]{2}$" },
     "vitals": {
       "type": "object",
       "properties": {
         "heartRate":     { "type": "integer", "minimum": 20, "maximum": 250 },
         "bloodPressure": { "type": "string", "pattern": "^[0-9]{2,3}/[0-9]{2,3}$" },
         "temperatureC":  { "type": "number", "minimum": 30, "maximum": 45 },
         "weightKg":      { "type": "number", "minimum": 20, "maximum": 300 }
       },
       "additionalProperties": false
     },
     "concomitantMeds": {
       "type": "array",
       "items": {
         "type": "object",
         "required": ["name"],
         "properties": {
           "name":       { "type": "string" },
           "dose":       { "type": "string" },
           "indication": { "type": "string" }
         },
         "additionalProperties": false
       }
     },
     "investigatorComment": { "type": "string", "maxLength": 1000 }
   },
   "additionalProperties": false
 }', ecrf_doc))
);

-- Cross-table rules a CHECK constraint cannot express (Phase-01 Section 3.1.4
-- said these need a trigger): the eCRF must belong to an existing, COMPLETED
-- visit, and its subjectId / visitType / scheduledDate must match that visit.
DROP PROCEDURE IF EXISTS sp_check_ecrf;
DROP TRIGGER IF EXISTS trg_ecrf_before_insert;
DROP TRIGGER IF EXISTS trg_ecrf_before_update;

DELIMITER //
CREATE PROCEDURE sp_check_ecrf(IN p_visit_id INT, IN p_doc JSON)
BEGIN
  DECLARE v_subject INT;
  DECLARE v_type VARCHAR(30);
  DECLARE v_date DATE;
  DECLARE v_status VARCHAR(20);

  SELECT subject_id, visit_type, scheduled_date, status
    INTO v_subject, v_type, v_date, v_status
  FROM Visit WHERE visit_id = p_visit_id;

  IF v_status IS NULL OR v_status <> 'Completed' THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'eCRF rejected: visit does not exist or is not Completed';
  END IF;

  -- Deliberate use of ternary logic (Phase-04 Section 2.II): if a field is
  -- missing, the comparison is UNKNOWN, the IF does not fire, and the
  -- JSON Schema CHECK constraint reports the missing required field instead.
  IF JSON_VALUE(p_doc, '$.subjectId' RETURNING UNSIGNED) <> v_subject
     OR p_doc->>'$.visitType' <> v_type
     OR p_doc->>'$.scheduledDate' <> DATE_FORMAT(v_date, '%Y-%m-%d') THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'eCRF rejected: subjectId, visitType or scheduledDate does not match the Visit row';
  END IF;
END//

CREATE TRIGGER trg_ecrf_before_insert BEFORE INSERT ON VisitECRF
FOR EACH ROW
BEGIN
  CALL sp_check_ecrf(NEW.visit_id, NEW.ecrf_doc);
END//

CREATE TRIGGER trg_ecrf_before_update BEFORE UPDATE ON VisitECRF
FOR EACH ROW
BEGIN
  CALL sp_check_ecrf(NEW.visit_id, NEW.ecrf_doc);
END//
DELIMITER ;

-- -------------------------------------------------------------------------
-- 2. UNSTRUCTURED: consent PDFs and adverse-event narratives
--
-- The PDF file itself stays on disk (path already in InformedConsent.document_ref).
-- ConsentDocument is a 1:1 extension of InformedConsent holding facts about the
-- file plus its extracted text, which gets a FULLTEXT index for searching.
-- -------------------------------------------------------------------------
DROP TABLE IF EXISTS ConsentDocument;
CREATE TABLE ConsentDocument (
 consent_id INT PRIMARY KEY,                   -- 1:1 with InformedConsent
 file_path VARCHAR(255) NOT NULL UNIQUE,
 mime_type VARCHAR(50) NOT NULL DEFAULT 'application/pdf',
 file_size_bytes INT UNSIGNED,
 sha256 CHAR(64),                              -- detects a changed or swapped file
 page_count SMALLINT UNSIGNED,
 extracted_text MEDIUMTEXT,
 loaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (consent_id) REFERENCES InformedConsent(consent_id),
 CONSTRAINT chk_consent_sha256 CHECK (sha256 IS NULL OR sha256 REGEXP '^[0-9a-f]{64}$'),
 FULLTEXT INDEX ft_consent_text (extracted_text)
);

-- Free-text search over AE narratives (the Phase-01 "full-text index" idea).
-- MySQL has no "ADD INDEX IF NOT EXISTS", so check first; this keeps the
-- whole script safe to re-run.
SET @has_ft := (SELECT COUNT(*) FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND LOWER(TABLE_NAME) = 'adverseevent'
                  AND INDEX_NAME = 'ft_ae_narrative');
SET @ddl := IF(@has_ft = 0,
               'ALTER TABLE AdverseEvent ADD FULLTEXT INDEX ft_ae_narrative (narrative)',
               'DO 0');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- -------------------------------------------------------------------------
-- 3. APPLICATION USERS AND AUDIT TRAIL (role-based access control)
-- -------------------------------------------------------------------------
DROP TABLE IF EXISTS AuditLog;
DROP TABLE IF EXISTS AppUser;

CREATE TABLE AppUser (
 user_id INT AUTO_INCREMENT PRIMARY KEY,
 username VARCHAR(50) NOT NULL UNIQUE,
 display_name VARCHAR(100) NOT NULL,
 password_hash VARCHAR(255) NOT NULL,          -- PHP password_hash(), never plain text
 app_role VARCHAR(10) NOT NULL,
 is_active BOOLEAN NOT NULL DEFAULT TRUE,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 last_login_at DATETIME NULL,
 CONSTRAINT chk_app_role CHECK (app_role IN ('Admin', 'User'))
);

CREATE TABLE AuditLog (
 audit_id BIGINT AUTO_INCREMENT PRIMARY KEY,
 user_id INT NULL,                             -- NULL for a failed login by an unknown username
 action VARCHAR(20) NOT NULL,
 table_name VARCHAR(64),
 record_id VARCHAR(64),
 detail JSON,                                  -- old/new values, report filters, etc.
 logged_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 FOREIGN KEY (user_id) REFERENCES AppUser(user_id),
 INDEX idx_audit_time (logged_at),
 CONSTRAINT chk_audit_action CHECK (action IN
   ('LOGIN', 'LOGIN_FAILED', 'LOGOUT', 'INSERT', 'UPDATE', 'DELETE',
    'EXPORT', 'REPORT_RUN', 'APPROVE', 'REJECT', 'VIEW_DOC'))
);

-- -------------------------------------------------------------------------
-- 4. PSEUDONYMISED VIEWS for the User role
-- Phase-03 said a real report would show subjects by code, not by name.
-- These views never expose first_name, last_name or date_of_birth.
-- -------------------------------------------------------------------------
CREATE OR REPLACE VIEW v_subject_coded AS
SELECT CONCAT('S', LPAD(s.subject_id, 3, '0'))               AS subject_code,
       st.site_name,
       s.sex,
       TIMESTAMPDIFF(YEAR, s.date_of_birth, s.enrolment_date)  AS age_at_enrolment,
       s.enrolment_date
FROM Subject s
JOIN Site st ON st.site_id = s.site_id;

CREATE OR REPLACE VIEW v_visit_coded AS
SELECT v.visit_id,
       CONCAT('S', LPAD(v.subject_id, 3, '0')) AS subject_code,
       st.site_name,
       v.visit_type,
       v.scheduled_date,
       v.status
FROM Visit v
JOIN Subject s ON s.subject_id = v.subject_id
JOIN Site st   ON st.site_id   = s.site_id;

CREATE OR REPLACE VIEW v_lab_coded AS
SELECT lr.result_id,
       CONCAT('S', LPAD(v.subject_id, 3, '0')) AS subject_code,
       v.visit_type,
       v.scheduled_date,
       lr.test_name,
       lr.value,
       lr.unit,
       lr.reference_range,
       CAST(SUBSTRING_INDEX(lr.reference_range, '-', 1)  AS DECIMAL(8,2)) AS low_limit,
       CAST(SUBSTRING_INDEX(lr.reference_range, '-', -1) AS DECIMAL(8,2)) AS high_limit,
       CASE
         WHEN lr.value < CAST(SUBSTRING_INDEX(lr.reference_range, '-', 1)  AS DECIMAL(8,2)) THEN 'L'
         WHEN lr.value > CAST(SUBSTRING_INDEX(lr.reference_range, '-', -1) AS DECIMAL(8,2)) THEN 'H'
         ELSE 'N'
       END AS flag
FROM LabResult lr
JOIN Visit v ON v.visit_id = lr.visit_id;

CREATE OR REPLACE VIEW v_ae_coded AS
SELECT a.ae_id,
       CONCAT('S', LPAD(a.subject_id, 3, '0')) AS subject_code,
       st.site_name,
       a.onset_date,
       a.severity,
       SUBSTRING_INDEX(a.narrative, ':', 1)    AS event_term,
       a.narrative
FROM AdverseEvent a
JOIN Subject s ON s.subject_id = a.subject_id
JOIN Site st   ON st.site_id   = s.site_id;

CREATE OR REPLACE VIEW v_ecrf_coded AS
SELECT e.ecrf_id,
       e.visit_id,
       CONCAT('S', LPAD(v.subject_id, 3, '0')) AS subject_code,
       v.visit_type,
       v.scheduled_date,
       e.form_version,
       e.heart_rate,
       e.systolic_bp,
       e.diastolic_bp,
       e.temperature_c,
       e.med_count,
       -- JSON array of medication names only (no doses, no free-text comment)
       e.ecrf_doc->'$.concomitantMeds[*].name' AS med_names
FROM VisitECRF e
JOIN Visit v ON v.visit_id = e.visit_id;

CREATE OR REPLACE VIEW v_consent_coded AS
SELECT ic.consent_id,
       CONCAT('S', LPAD(ic.subject_id, 3, '0')) AS subject_code,
       st.site_name,
       ic.version,
       ic.signed_date,
       (cd.consent_id IS NOT NULL)              AS has_document,
       cd.page_count
FROM InformedConsent ic
JOIN Subject s ON s.subject_id = ic.subject_id
JOIN Site st   ON st.site_id   = s.site_id
LEFT JOIN ConsentDocument cd ON cd.consent_id = ic.consent_id;

-- -------------------------------------------------------------------------
-- 5. DATABASE-LEVEL ROLES (defence in depth behind the app's own roles)
--   ctt_admin  : read and write every table in this database
--   ctt_reader : SELECT on the pseudonymised views only, nothing else
-- Login accounts for the PHP app are created in a later script.
-- -------------------------------------------------------------------------
CREATE ROLE IF NOT EXISTS 'ctt_admin', 'ctt_reader';

GRANT SELECT, INSERT, UPDATE, DELETE, EXECUTE ON clinical_trial_tracking.* TO 'ctt_admin';

GRANT SELECT ON clinical_trial_tracking.v_subject_coded TO 'ctt_reader';
GRANT SELECT ON clinical_trial_tracking.v_visit_coded   TO 'ctt_reader';
GRANT SELECT ON clinical_trial_tracking.v_lab_coded     TO 'ctt_reader';
GRANT SELECT ON clinical_trial_tracking.v_ae_coded      TO 'ctt_reader';
GRANT SELECT ON clinical_trial_tracking.v_ecrf_coded    TO 'ctt_reader';
GRANT SELECT ON clinical_trial_tracking.v_consent_coded TO 'ctt_reader';
