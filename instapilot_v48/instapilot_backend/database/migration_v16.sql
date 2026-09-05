-- InstaPilot V16 AI Mission Workflow
CREATE TABLE IF NOT EXISTS mission_sessions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  goal VARCHAR(255) NOT NULL,
  horizon_days TINYINT UNSIGNED NOT NULL DEFAULT 30,
  status ENUM('planning','drafting','awaiting_approval','scheduled','published','learning','completed','failed') NOT NULL DEFAULT 'planning',
  current_step TINYINT UNSIGNED NOT NULL DEFAULT 1,
  decision JSON NULL,
  blueprint JSON NULL,
  content_id BIGINT UNSIGNED NULL,
  scheduled_at DATETIME NULL,
  published_media_id VARCHAR(190) NULL,
  learning_summary JSON NULL,
  approval_required TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  completed_at DATETIME NULL,
  INDEX idx_mission_session_user (user_id, created_at),
  INDEX idx_mission_session_status (user_id, status),
  CONSTRAINT fk_mission_session_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
