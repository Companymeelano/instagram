-- InstaPilot V13 AI Attribution Engine
CREATE TABLE IF NOT EXISTS attribution_factor_weights (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  metric_key VARCHAR(80) NOT NULL,
  factor_key VARCHAR(80) NOT NULL,
  factor_value VARCHAR(190) NOT NULL,
  sample_count INT UNSIGNED NOT NULL DEFAULT 0,
  mean_performance DECIMAL(12,4) NOT NULL DEFAULT 0,
  baseline_performance DECIMAL(12,4) NOT NULL DEFAULT 0,
  lift_pct DECIMAL(12,4) NOT NULL DEFAULT 0,
  weight_pct DECIMAL(8,4) NOT NULL DEFAULT 0,
  confidence DECIMAL(5,2) NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_attr_weight (user_id,metric_key,factor_key,factor_value),
  INDEX idx_attr_user (user_id,metric_key,confidence),
  CONSTRAINT fk_attr_weight_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS attribution_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  content_id BIGINT UNSIGNED NOT NULL,
  metric_key VARCHAR(80) NOT NULL,
  actual_value DECIMAL(12,4) NOT NULL,
  baseline_value DECIMAL(12,4) NOT NULL,
  attribution JSON NOT NULL,
  confidence DECIMAL(5,2) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_attr_event_user (user_id,created_at),
  INDEX idx_attr_event_content (content_id,created_at),
  CONSTRAINT fk_attr_event_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_attr_event_content FOREIGN KEY (content_id) REFERENCES content_items(id) ON DELETE CASCADE
) ENGINE=InnoDB;
