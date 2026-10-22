-- =========================================================================
-- COS20015 Project Phase-05 -- Tests for 12_report_schedule.sql
-- Run with:  mysql --force -t < 12b_test_report_schedule.sql
-- The INSERT tests run in a transaction that is rolled back.
-- =========================================================================
USE clinical_trial_tracking;

-- T1 to T7: fn_next_run. 2026-10-22 is a Thursday, 2026-10-24 a Saturday.
SELECT t.test, t.got, t.expected, IF(t.got = t.expected, 'PASS', 'FAIL') AS result FROM (
  SELECT 'T1 daily, later today'            AS test, fn_next_run('Daily', NULL, '18:00', '2026-10-22 14:00') AS got, TIMESTAMP('2026-10-22 18:00') AS expected
  UNION ALL SELECT 'T2 daily, slot passed',      fn_next_run('Daily', NULL, '07:00', '2026-10-22 14:00'),    '2026-10-23 07:00'
  UNION ALL SELECT 'T3 daily, exactly at slot',  fn_next_run('Daily', NULL, '07:00', '2026-10-23 07:00'),    '2026-10-24 07:00'
  UNION ALL SELECT 'T4 weekdays, Fri -> Mon',    fn_next_run('Weekdays', NULL, '07:00', '2026-10-23 08:00'), '2026-10-26 07:00'
  UNION ALL SELECT 'T5 weekdays, Sat -> Mon',    fn_next_run('Weekdays', NULL, '07:00', '2026-10-24 06:00'), '2026-10-26 07:00'
  UNION ALL SELECT 'T6 weekly Monday from Thu',  fn_next_run('Weekly', 1, '07:00', '2026-10-22 14:00'),      '2026-10-26 07:00'
  UNION ALL SELECT 'T7 weekly Thu, slot passed', fn_next_run('Weekly', 4, '07:00', '2026-10-22 14:00'),      '2026-10-29 07:00'
  UNION ALL SELECT 'T8 month/year rollover',     fn_next_run('Daily', NULL, '07:00', '2026-12-31 23:30'),    '2027-01-01 07:00'
) t;

-- T9. Unknown frequency. EXPECT: ERROR 1644
SELECT fn_next_run('Hourly', NULL, '07:00', NOW());

START TRANSACTION;
SET @u := (SELECT MIN(user_id) FROM AppUser);

-- T10. A valid weekly schedule. EXPECT: OK
INSERT INTO ReportSchedule (title, report_key, params, recipient, frequency, weekday, send_time, next_run_at, created_by)
VALUES ('Test weekly', 'consent', '{"status": "gap"}', 'test@example.com', 'Weekly', 1, '07:00',
        fn_next_run('Weekly', 1, '07:00', NOW()), @u);
SELECT 'T10 valid schedule' AS test, IF(ROW_COUNT() = 1, 'PASS', 'FAIL') AS result;

-- T11. Weekly without a weekday. EXPECT: ERROR 3819 chk_sched_weekday
--      (needs the COALESCE in the CHECK: without it NULL makes the CHECK UNKNOWN, which passes)
INSERT INTO ReportSchedule (title, report_key, params, recipient, frequency, next_run_at, created_by)
VALUES ('x', 'safety', '{}', 'test@example.com', 'Weekly', NOW(), @u);

-- T12. Two recipients / header injection. EXPECT: ERROR 3819 chk_sched_recipient (both)
INSERT INTO ReportSchedule (title, report_key, params, recipient, frequency, next_run_at, created_by)
VALUES ('x', 'safety', '{}', 'a@example.com,b@example.com', 'Daily', NOW(), @u);
INSERT INTO ReportSchedule (title, report_key, params, recipient, frequency, next_run_at, created_by)
VALUES ('x', 'safety', '{}', CONCAT('a@example.com', CHAR(13, 10), 'Bcc: c@example.com'), 'Daily', NOW(), @u);

-- T13. Unknown report and a params value that is not a JSON object. EXPECT: ERROR 3819 (both)
INSERT INTO ReportSchedule (title, report_key, params, recipient, frequency, next_run_at, created_by)
VALUES ('x', 'payroll', '{}', 'a@example.com', 'Daily', NOW(), @u);
INSERT INTO ReportSchedule (title, report_key, params, recipient, frequency, next_run_at, created_by)
VALUES ('x', 'safety', '[1, 2]', 'a@example.com', 'Daily', NOW(), @u);

ROLLBACK;
SELECT 'After rollback' AS test, COUNT(*) AS test_rows_left FROM ReportSchedule WHERE title IN ('Test weekly', 'x');
