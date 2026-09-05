-- InstaPilot V15 Mission Control
CREATE TABLE IF NOT EXISTS mission_runs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  action VARCHAR(60) NOT NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'queued',
  payload JSON NULL,
  result JSON NULL,
  error_message TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at DATETIME NULL,
  INDEX idx_mission_user (user_id,created_at),
  CONSTRAINT fk_mission_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
