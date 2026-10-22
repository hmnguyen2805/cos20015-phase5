-- =========================================================================
-- COS20015 Project Phase-05 -- Application security objects
-- Run after 06_phase5_schema.sql. Safe to re-run.
--
-- The PHP app uses THREE MySQL logins (created by scripts\setup_app_accounts.ps1,
-- which generates random passwords and writes them only to app\config\config.php):
--
--   app_auth   : login screen only. Can read AppUser, stamp last_login_at,
--                and write audit rows through sp_audit. Nothing else.
--   app_admin  : has role ctt_admin (CRUD + EXECUTE on the whole database).
--   app_reader : has role ctt_reader (SELECT on the six pseudonymised views)
--                plus EXECUTE on sp_audit.
--
-- So even if the PHP role check had a bug, a User session is connected as
-- app_reader and MySQL itself refuses to show names or raw tables.
-- =========================================================================
USE clinical_trial_tracking;

-- Every audit row goes through this procedure. It runs with the rights of its
-- definer (SQL SECURITY DEFINER), so app_reader can append to AuditLog without
-- being able to read, change or delete existing audit rows.
DROP PROCEDURE IF EXISTS sp_audit;
DELIMITER //
CREATE PROCEDURE sp_audit(
  IN p_user_id   INT,
  IN p_action    VARCHAR(20),
  IN p_table     VARCHAR(64),
  IN p_record_id VARCHAR(64),
  IN p_detail    JSON)
SQL SECURITY DEFINER
BEGIN
  INSERT INTO AuditLog (user_id, action, table_name, record_id, detail)
  VALUES (p_user_id, p_action, p_table, p_record_id, p_detail);
END//
DELIMITER ;

GRANT EXECUTE ON PROCEDURE clinical_trial_tracking.sp_audit TO 'ctt_reader';
