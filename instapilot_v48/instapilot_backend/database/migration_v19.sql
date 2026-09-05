-- InstaPilot V19: Operations Center / Real Scheduler / Publish Queue / Retry & Recovery
CREATE TABLE IF NOT EXISTS scheduler_slots (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  mission_id BIGINT UNSIGNED NULL,
  content_id BIGINT UNSIGNED NULL,
  scheduled_at DATETIME NOT NULL,
  timezone VARCHAR(64) NOT NULL DEFAULT 'Asia/Baku',
  score DECIMAL(6,2) NOT NULL DEFAULT 0,
  status ENUM('suggested','selected','queued','published','skipped','failed') NOT NULL DEFAULT 'suggested',
  source VARCHAR(40) NOT NULL DEFAULT 'ai',
  reason JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_scheduler_slot_user_time (user_id,scheduled_at),
  INDEX idx_scheduler_slot_status (user_id,status,scheduled_at),
  CONSTRAINT fk_scheduler_slot_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_scheduler_slot_mission FOREIGN KEY (mission_id) REFERENCES mission_sessions(id) ON DELETE SET NULL,
  CONSTRAINT fk_scheduler_slot_content FOREIGN KEY (content_id) REFERENCES content_items(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS publish_queue (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  content_id BIGINT UNSIGNED NOT NULL,
  mission_id BIGINT UNSIGNED NULL,
  instagram_account_id BIGINT UNSIGNED NULL,
  scheduled_at DATETIME NOT NULL,
  status ENUM('queued','processing','published','retrying','failed','cancelled') NOT NULL DEFAULT 'queued',
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 3,
  next_attempt_at DATETIME NULL,
  idempotency_key VARCHAR(190) NOT NULL,
  locked_at DATETIME NULL,
  published_media_id VARCHAR(190) NULL,
  last_error TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_publish_idempotency (idempotency_key),
  INDEX idx_publish_queue (status,scheduled_at,next_attempt_at),
  INDEX idx_publish_user (user_id,status,scheduled_at),
  CONSTRAINT fk_publish_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_publish_content FOREIGN KEY (content_id) REFERENCES content_items(id) ON DELETE CASCADE,
  CONSTRAINT fk_publish_mission FOREIGN KEY (mission_id) REFERENCES mission_sessions(id) ON DELETE SET NULL,
  CONSTRAINT fk_publish_ig FOREIGN KEY (instagram_account_id) REFERENCES instagram_accounts(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS publish_attempts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  publish_queue_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  attempt_no TINYINT UNSIGNED NOT NULL,
  stage VARCHAR(40) NOT NULL,
  status ENUM('started','success','failed') NOT NULL,
  http_status SMALLINT UNSIGNED NULL,
  provider_code VARCHAR(120) NULL,
  provider_message TEXT NULL,
  response JSON NULL,
  started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at DATETIME NULL,
  INDEX idx_publish_attempt_queue (publish_queue_id,attempt_no),
  INDEX idx_publish_attempt_user (user_id,started_at),
  CONSTRAINT fk_publish_attempt_queue FOREIGN KEY (publish_queue_id) REFERENCES publish_queue(id) ON DELETE CASCADE,
  CONSTRAINT fk_publish_attempt_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS mission_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  mission_id BIGINT UNSIGNED NOT NULL,
  event_type VARCHAR(60) NOT NULL,
  from_status VARCHAR(40) NULL,
  to_status VARCHAR(40) NULL,
  message VARCHAR(500) NULL,
  payload JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_mission_event_user (user_id,created_at),
  INDEX idx_mission_event_mission (mission_id,created_at),
  CONSTRAINT fk_mission_event_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_mission_event_mission FOREIGN KEY (mission_id) REFERENCES mission_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS system_settings (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NULL,
  setting_group VARCHAR(60) NOT NULL,
  setting_key VARCHAR(120) NOT NULL,
  setting_value TEXT NULL,
  is_secret TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_system_setting (user_id,setting_group,setting_key),
  INDEX idx_system_setting_group (setting_group,setting_key),
  CONSTRAINT fk_system_setting_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
