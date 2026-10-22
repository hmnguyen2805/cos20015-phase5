-- =========================================================================
-- COS20015 Project Phase-05 -- Extra pseudonymised views for the reports
-- Run after 08_app_security.sql. Additive and safe to re-run (no data change).
--
-- The three reports run as the logged-in user's MySQL account. A User session
-- is app_reader, which can only read the coded views, so the reports need two
-- more of them:
--   v_dose_coded : DrugAdministration by subject code (safety + exposure)
--   v_site_coded : every site, including sites with no subjects yet, so the
--                  site activity report can show a site with zero activity
-- Neither view exposes a subject name or date of birth.
-- =========================================================================
USE clinical_trial_tracking;

CREATE OR REPLACE VIEW v_dose_coded AS
SELECT da.admin_id,
       da.visit_id,
       CONCAT('S', LPAD(v.subject_id, 3, '0')) AS subject_code,
       st.site_name,
       v.visit_type,
       v.scheduled_date,
       d.drug_name,
       da.dose_mg,
       da.administered_time
FROM DrugAdministration da
JOIN Visit v   ON v.visit_id   = da.visit_id
JOIN Subject s ON s.subject_id = v.subject_id
JOIN Site st   ON st.site_id   = s.site_id
JOIN Drug d    ON d.drug_id    = da.drug_id;

CREATE OR REPLACE VIEW v_site_coded AS
SELECT st.site_id,
       st.site_name
FROM Site st;

-- Cross-check between the unstructured consent PDF and the structured record.
-- The text extracted from each PDF (ConsentDocument.extracted_text) states the
-- document version, the participant code and the date signed. REGEXP_SUBSTR
-- pulls out just those three values, so a User can see whether the PDF agrees
-- with InformedConsent without ever seeing the text (which holds the name).
CREATE OR REPLACE VIEW v_consent_doc_check AS
SELECT ic.consent_id,
       CONCAT('S', LPAD(ic.subject_id, 3, '0'))                                   AS subject_code,
       ic.version,
       ic.signed_date,
       REGEXP_SUBSTR(cd.extracted_text, '(?<=Document version: )v[0-9]+\\.[0-9]+') AS doc_version,
       REGEXP_SUBSTR(cd.extracted_text, '(?<=Participant code: )S[0-9]{3}')        AS doc_subject_code,
       CAST(REGEXP_SUBSTR(cd.extracted_text,
            '(?<=Date signed:\\s)[0-9]{4}-[0-9]{2}-[0-9]{2}') AS DATE)             AS doc_signed_date
FROM InformedConsent ic
LEFT JOIN ConsentDocument cd ON cd.consent_id = ic.consent_id;

GRANT SELECT ON clinical_trial_tracking.v_dose_coded        TO 'ctt_reader';
GRANT SELECT ON clinical_trial_tracking.v_site_coded        TO 'ctt_reader';
GRANT SELECT ON clinical_trial_tracking.v_consent_doc_check TO 'ctt_reader';

-- Quick checks (expected: 60 administrations, 5 sites, 34 consents, 34 PDFs that agree)
SELECT (SELECT COUNT(*) FROM v_dose_coded) AS dose_rows,
       (SELECT COUNT(*) FROM v_site_coded) AS site_rows,
       COUNT(*)                            AS consent_rows,
       SUM(doc_version = version AND doc_subject_code = subject_code
           AND doc_signed_date = signed_date) AS pdfs_matching_record
FROM v_consent_doc_check;
