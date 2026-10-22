-- =========================================================================
-- COS20015 Project Phase-05 -- Scheduled report emails
-- Run after 11_least_privilege.sql. Safe to re-run (keeps existing schedules).
--
-- An Admin saves any report view (filters + sort) as a schedule: who gets it,
-- how often and at what time. Windows Task Scheduler runs
-- app\tools\run_automation.php every 15 minutes; it sends every schedule whose
-- next_run_at has passed, using the SAME report code as the screen and the
-- manual email (pseudonymised, queried as app_reader), then asks
-- fn_next_run() for the next time.
-- =========================================================================
USE clinical_trial_tracking;

CREATE TABLE IF NOT EXISTS ReportSchedule (
 schedule_id INT AUTO_INCREMENT PRIMARY KEY,
 title VARCHAR(100) NOT NULL,
 report_key VARCHAR(20) NOT NULL,              -- safety / sites / consent, or findings (automated-check digest)
 params JSON NOT NULL,                         -- filters and sort, exactly as the report page uses them
 recipient VARCHAR(254) NOT NULL,
 frequency VARCHAR(10) NOT NULL,
 weekday TINYINT NULL,                         -- 1 = Monday ... 7 = Sunday (Weekly only)
 send_time TIME NOT NULL DEFAULT '07:00:00',
 skip_empty BOOLEAN NOT NULL DEFAULT FALSE,    -- TRUE = only send when the report has rows (an alert)
 is_active BOOLEAN NOT NULL DEFAULT TRUE,
 next_run_at DATETIME NOT NULL,
 last_run_at DATETIME NULL,
 last_status VARCHAR(10) NULL,
 last_result VARCHAR(255) NULL,
 created_by INT NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (created_by) REFERENCES AppUser(user_id),
 INDEX idx_schedule_due (is_active, next_run_at),
 CONSTRAINT chk_sched_report CHECK (report_key IN ('safety', 'sites', 'consent', 'findings')),
 CONSTRAINT chk_sched_freq CHECK (frequency IN ('Daily', 'Weekdays', 'Weekly')),
 -- COALESCE matters: with weekday NULL, "weekday BETWEEN 1 AND 7" is UNKNOWN, and a
 -- CHECK only rejects FALSE, so a Weekly row with no weekday would slip through
 -- (three-valued logic, as in Phase-04). COALESCE turns NULL into a definite FALSE.
 CONSTRAINT chk_sched_weekday CHECK ((frequency = 'Weekly' AND COALESCE(weekday, 0) BETWEEN 1 AND 7)
                                     OR (frequency <> 'Weekly' AND weekday IS NULL)),
 CONSTRAINT chk_sched_params CHECK (JSON_TYPE(params) = 'OBJECT'),
 -- one plain address: no spaces, commas, semicolons or line breaks (header injection)
 CONSTRAINT chk_sched_recipient CHECK (recipient REGEXP '^[^@[:space:],;<>]+@[^@[:space:],;<>]+[.][^@[:space:],;<>]+$'),
 CONSTRAINT chk_sched_status CHECK (last_status IS NULL OR last_status IN ('Sent', 'Saved', 'Skipped', 'Failed'))
);

-- Next send time strictly after p_after. Pure function of its inputs, so it is
-- declared DETERMINISTIC and can be tested on its own (see 12b).
DROP FUNCTION IF EXISTS fn_next_run;
DELIMITER //
CREATE FUNCTION fn_next_run(p_freq VARCHAR(10), p_weekday TINYINT, p_time TIME, p_after DATETIME)
RETURNS DATETIME
DETERMINISTIC
NO SQL
COMMENT 'Next scheduled send time after p_after (Daily, Weekdays or Weekly)'
BEGIN
  DECLARE v_next DATETIME DEFAULT TIMESTAMP(DATE(p_after), p_time);
  IF v_next <= p_after THEN
    SET v_next = v_next + INTERVAL 1 DAY;          -- today's slot has gone: start tomorrow
  END IF;
  IF p_freq = 'Weekdays' THEN                       -- WEEKDAY(): 0 = Monday ... 5 = Saturday, 6 = Sunday
    IF WEEKDAY(v_next) = 5 THEN SET v_next = v_next + INTERVAL 2 DAY;
    ELSEIF WEEKDAY(v_next) = 6 THEN SET v_next = v_next + INTERVAL 1 DAY;
    END IF;
  ELSEIF p_freq = 'Weekly' THEN
    SET v_next = v_next + INTERVAL MOD(p_weekday - 1 - WEEKDAY(v_next) + 7, 7) DAY;
  ELSEIF p_freq <> 'Daily' OR p_freq IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'fn_next_run: frequency must be Daily, Weekdays or Weekly';
  END IF;
  RETURN v_next;
END//
DELIMITER ;

-- Admin: manage schedules (no DELETE: a schedule is switched off, so its
-- history in the audit log still points at a real row).
GRANT SELECT, INSERT ON clinical_trial_tracking.ReportSchedule TO 'ctt_admin';
GRANT UPDATE (title, params, recipient, frequency, weekday, send_time, skip_empty, is_active,
              next_run_at, last_run_at, last_status, last_result)
      ON clinical_trial_tracking.ReportSchedule TO 'ctt_admin';
GRANT EXECUTE ON FUNCTION clinical_trial_tracking.fn_next_run TO 'ctt_admin';

-- Quick look (Thu 22 Oct 2026 14:00 -> Daily 07:00 = Fri 23 Oct; Weekly Monday = 26 Oct)
SELECT fn_next_run('Daily', NULL, '07:00', '2026-10-22 14:00') AS daily,
       fn_next_run('Weekly', 1, '07:00', '2026-10-22 14:00')   AS weekly_monday;
