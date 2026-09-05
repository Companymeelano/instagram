-- InstaPilot V14 AI Decision Engine
CREATE TABLE IF NOT EXISTS decision_recommendations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  metric_key VARCHAR(80) NOT NULL DEFAULT 'engagement_rate',
  title VARCHAR(255) NOT NULL,
  summary TEXT NULL,
  reason TEXT NULL,
  confidence DECIMAL(5,2) NOT NULL DEFAULT 0,
  sample_count INT UNSIGNED NOT NULL DEFAULT 0,
  baseline DECIMAL(12,4) NOT NULL DEFAULT 0,
  factors JSON NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_decision_user (user_id,created_at),
  CONSTRAINT fk_decision_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
