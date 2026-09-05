-- InstaPilot V23 Production Productization
-- Safe/re-runnable migration. Existing rows are preserved.

CREATE TABLE IF NOT EXISTS api_health_checks (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NULL,
  component VARCHAR(60) NOT NULL,
  status ENUM('ok','warning','error') NOT NULL,
  message VARCHAR(500) NULL,
  meta JSON NULL,
  checked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_health_component (component,checked_at),
  INDEX idx_health_user (user_id,checked_at),
  CONSTRAINT fk_health_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS api_request_log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NULL,
  route VARCHAR(190) NOT NULL,
  method VARCHAR(10) NOT NULL,
  status_code SMALLINT UNSIGNED NOT NULL DEFAULT 200,
  duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
  request_id VARCHAR(64) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_api_log_route (route,created_at),
  INDEX idx_api_log_user (user_id,created_at),
  CONSTRAINT fk_api_log_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS scheduler_runs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NULL,
  run_type VARCHAR(60) NOT NULL,
  status ENUM('running','completed','partial','failed') NOT NULL DEFAULT 'running',
  items_scanned INT UNSIGNED NOT NULL DEFAULT 0,
  items_created INT UNSIGNED NOT NULL DEFAULT 0,
  error_message TEXT NULL,
  started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at DATETIME NULL,
  INDEX idx_scheduler_runs_user (user_id,started_at),
  CONSTRAINT fk_scheduler_run_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Idempotent index helper. MySQL does not provide CREATE INDEX IF NOT EXISTS.
DROP PROCEDURE IF EXISTS ip23_ensure_index;
DELIMITER $$
CREATE PROCEDURE ip23_ensure_index(IN p_table VARCHAR(64), IN p_index VARCHAR(64), IN p_columns VARCHAR(500))
BEGIN
  DECLARE n INT DEFAULT 0;
  SELECT COUNT(*) INTO n FROM information_schema.statistics
    WHERE table_schema=DATABASE() AND table_name=p_table AND index_name=p_index;
  IF n=0 THEN
    SET @sql = CONCAT('CREATE INDEX `',REPLACE(p_index,'`','``'),'` ON `',REPLACE(p_table,'`','``'),'` (',p_columns,')');
    PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
  END IF;
END$$
DELIMITER ;

CALL ip23_ensure_index('jobs','idx_jobs_user_status_time','`user_id`,`status`,`available_at`');
CALL ip23_ensure_index('notifications','idx_notifications_unread','`user_id`,`read_at`,`created_at`');
DROP PROCEDURE IF EXISTS ip23_ensure_index;
