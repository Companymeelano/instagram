-- InstaPilot V17: Mission Planner + Approval Inbox + Smart Scheduler
CREATE TABLE IF NOT EXISTS mission_plans (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  mission_id BIGINT UNSIGNED NOT NULL,
  plan JSON NOT NULL,
  status ENUM('planned','in_review','approved','scheduled','active','completed','paused','failed') NOT NULL DEFAULT 'planned',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_mission_plan_user (user_id,created_at),
  INDEX idx_mission_plan_mission (mission_id),
  CONSTRAINT fk_mission_plan_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_mission_plan_mission FOREIGN KEY (mission_id) REFERENCES mission_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS approval_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  mission_id BIGINT UNSIGNED NULL,
  content_id BIGINT UNSIGNED NULL,
  item_type ENUM('content','schedule','decision') NOT NULL DEFAULT 'content',
  title VARCHAR(255) NOT NULL,
  payload JSON NULL,
  status ENUM('pending','approved','rejected','expired') NOT NULL DEFAULT 'pending',
  reviewed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_approval_user (user_id,status,created_at),
  CONSTRAINT fk_approval_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_approval_mission FOREIGN KEY (mission_id) REFERENCES mission_sessions(id) ON DELETE SET NULL,
  CONSTRAINT fk_approval_content FOREIGN KEY (content_id) REFERENCES content_items(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS scheduler_recommendations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  mission_id BIGINT UNSIGNED NULL,
  recommended_at DATETIME NOT NULL,
  score DECIMAL(6,2) NOT NULL DEFAULT 0,
  reason JSON NULL,
  selected TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_scheduler_user (user_id,recommended_at),
  CONSTRAINT fk_scheduler_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_scheduler_mission FOREIGN KEY (mission_id) REFERENCES mission_sessions(id) ON DELETE SET NULL
) ENGINE=InnoDB;
