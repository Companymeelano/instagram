CREATE DATABASE IF NOT EXISTS `instapilot` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `instapilot`;

CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  display_name VARCHAR(190) NOT NULL,
  role ENUM('user','admin') NOT NULL DEFAULT 'user',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS brand_profiles (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL UNIQUE,
  brand_name VARCHAR(190) NULL,
  category VARCHAR(190) NULL,
  audience VARCHAR(500) NULL,
  bio TEXT NULL,
  tone VARCHAR(190) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_brand_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS instagram_accounts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  ig_user_id VARCHAR(190) NOT NULL,
  username VARCHAR(190) NULL,
  account_type VARCHAR(50) NULL,
  access_token TEXT NOT NULL,
  token_expires_at DATETIME NULL,
  status ENUM('connected','expired','error','disconnected') NOT NULL DEFAULT 'connected',
  last_sync_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_user_ig (user_id, ig_user_id),
  CONSTRAINT fk_ig_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS content_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  type ENUM('reel','post','story') NOT NULL,
  title VARCHAR(255) NULL,
  caption TEXT NULL,
  hashtags TEXT NULL,
  status ENUM('draft','queued','publishing','published','failed') NOT NULL DEFAULT 'draft',
  scheduled_at DATETIME NULL,
  published_at DATETIME NULL,
  external_id VARCHAR(190) NULL,
  error_message TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_content_queue (status, scheduled_at),
  CONSTRAINT fk_content_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS analytics_daily (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  metric_date DATE NOT NULL,
  followers INT UNSIGNED NOT NULL DEFAULT 0,
  reach INT UNSIGNED NOT NULL DEFAULT 0,
  impressions INT UNSIGNED NOT NULL DEFAULT 0,
  engagement DECIMAL(8,3) NOT NULL DEFAULT 0,
  saves INT UNSIGNED NOT NULL DEFAULT 0,
  shares INT UNSIGNED NOT NULL DEFAULT 0,
  profile_visits INT UNSIGNED NOT NULL DEFAULT 0,
  UNIQUE KEY uq_analytics_day (user_id, metric_date),
  CONSTRAINT fk_analytics_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS notifications (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  type VARCHAR(50) NOT NULL,
  title VARCHAR(255) NOT NULL,
  body TEXT NULL,
  read_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_notifications_user (user_id, created_at),
  CONSTRAINT fk_notification_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS jobs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  job_type VARCHAR(80) NOT NULL,
  payload JSON NOT NULL,
  status ENUM('queued','processing','done','failed') NOT NULL DEFAULT 'queued',
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  locked_at DATETIME NULL,
  error_message TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_jobs_queue (status, available_at),
  CONSTRAINT fk_job_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS audit_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NULL,
  action VARCHAR(120) NOT NULL,
  ip_address VARCHAR(45) NULL,
  metadata JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_audit_user (user_id, created_at)
) ENGINE=InnoDB;

-- V7 Instagram Engine additions
CREATE TABLE IF NOT EXISTS instagram_webhook_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NULL,
  instagram_account_id BIGINT UNSIGNED NULL,
  event_type VARCHAR(100) NOT NULL,
  payload JSON NOT NULL,
  processed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ig_events_created (created_at),
  CONSTRAINT fk_ig_event_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_ig_event_account FOREIGN KEY (instagram_account_id) REFERENCES instagram_accounts(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- V8 AI Agent Engine
CREATE TABLE IF NOT EXISTS ai_runs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  agent VARCHAR(50) NOT NULL,
  goal VARCHAR(255) NULL,
  input JSON NULL,
  output JSON NULL,
  provider VARCHAR(50) NULL,
  status ENUM('running','completed','failed') NOT NULL DEFAULT 'running',
  error_message TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at DATETIME NULL,
  INDEX idx_ai_runs_user (user_id, created_at),
  CONSTRAINT fk_ai_run_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ai_memories (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  memory_type VARCHAR(60) NOT NULL,
  memory_key VARCHAR(190) NOT NULL,
  memory_value TEXT NOT NULL,
  confidence DECIMAL(5,2) NOT NULL DEFAULT 0,
  source VARCHAR(80) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_ai_memory (user_id, memory_type, memory_key),
  CONSTRAINT fk_ai_memory_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS growth_plans (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  goal VARCHAR(255) NOT NULL,
  horizon_days TINYINT UNSIGNED NOT NULL DEFAULT 30,
  plan JSON NOT NULL,
  status ENUM('draft','active','completed','archived') NOT NULL DEFAULT 'draft',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_growth_plan_user (user_id, status, created_at),
  CONSTRAINT fk_growth_plan_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- V9 AI Growth Autopilot + learning loop
CREATE TABLE IF NOT EXISTS autopilot_runs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  growth_plan_id BIGINT UNSIGNED NULL,
  goal VARCHAR(255) NOT NULL,
  horizon_days TINYINT UNSIGNED NOT NULL DEFAULT 30,
  context JSON NULL,
  output JSON NULL,
  provider VARCHAR(50) NULL,
  status ENUM('running','completed','failed') NOT NULL DEFAULT 'running',
  error_message TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at DATETIME NULL,
  INDEX idx_autopilot_user (user_id,created_at),
  CONSTRAINT fk_autopilot_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_autopilot_plan FOREIGN KEY (growth_plan_id) REFERENCES growth_plans(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS analytics_sync_log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  instagram_account_id BIGINT UNSIGNED NULL,
  period_days TINYINT UNSIGNED NOT NULL DEFAULT 30,
  metrics JSON NULL,
  status ENUM('running','completed','failed') NOT NULL DEFAULT 'running',
  error_message TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at DATETIME NULL,
  INDEX idx_analytics_sync_user (user_id,created_at),
  CONSTRAINT fk_analytics_sync_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_analytics_sync_account FOREIGN KEY (instagram_account_id) REFERENCES instagram_accounts(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- V10 Autonomous Content Engine + Explainability
CREATE TABLE IF NOT EXISTS content_ai_reviews (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  content_id BIGINT UNSIGNED NOT NULL,
  score DECIMAL(5,2) NOT NULL DEFAULT 0,
  brand_match DECIMAL(5,2) NOT NULL DEFAULT 0,
  hook_score DECIMAL(5,2) NOT NULL DEFAULT 0,
  cta_score DECIMAL(5,2) NOT NULL DEFAULT 0,
  engagement_score DECIMAL(5,2) NOT NULL DEFAULT 0,
  explanation JSON NULL,
  recommendation TEXT NULL,
  provider VARCHAR(50) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_content_review_user (user_id,created_at),
  CONSTRAINT fk_content_review_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_content_review_content FOREIGN KEY (content_id) REFERENCES content_items(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS content_ai_runs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  content_id BIGINT UNSIGNED NULL,
  goal VARCHAR(255) NOT NULL,
  context JSON NULL,
  output JSON NULL,
  provider VARCHAR(50) NULL,
  status ENUM('running','completed','failed') NOT NULL DEFAULT 'running',
  error_message TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at DATETIME NULL,
  INDEX idx_content_ai_user (user_id,created_at),
  CONSTRAINT fk_content_ai_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_content_ai_content FOREIGN KEY (content_id) REFERENCES content_items(id) ON DELETE SET NULL
) ENGINE=InnoDB;


-- V12 Real Media Performance Adapter
CREATE TABLE IF NOT EXISTS content_performance_snapshots (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  content_id BIGINT UNSIGNED NOT NULL,
  external_media_id VARCHAR(190) NOT NULL,
  captured_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  views BIGINT UNSIGNED NOT NULL DEFAULT 0,
  reach BIGINT UNSIGNED NOT NULL DEFAULT 0,
  likes BIGINT UNSIGNED NOT NULL DEFAULT 0,
  comments BIGINT UNSIGNED NOT NULL DEFAULT 0,
  saves BIGINT UNSIGNED NOT NULL DEFAULT 0,
  shares BIGINT UNSIGNED NOT NULL DEFAULT 0,
  total_interactions BIGINT UNSIGNED NOT NULL DEFAULT 0,
  raw_payload JSON NULL,
  INDEX idx_perf_snapshot_content (content_id,captured_at),
  INDEX idx_perf_snapshot_user (user_id,captured_at),
  CONSTRAINT fk_perf_snapshot_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_perf_snapshot_content FOREIGN KEY (content_id) REFERENCES content_items(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS media_sync_runs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  instagram_account_id BIGINT UNSIGNED NULL,
  scanned INT UNSIGNED NOT NULL DEFAULT 0,
  synced INT UNSIGNED NOT NULL DEFAULT 0,
  skipped INT UNSIGNED NOT NULL DEFAULT 0,
  failed INT UNSIGNED NOT NULL DEFAULT 0,
  status ENUM('running','completed','partial','failed') NOT NULL DEFAULT 'running',
  error_message TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at DATETIME NULL,
  INDEX idx_media_sync_user (user_id,created_at),
  CONSTRAINT fk_media_sync_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_media_sync_account FOREIGN KEY (instagram_account_id) REFERENCES instagram_accounts(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- V11 Closed-Loop Learning Engine
CREATE TABLE IF NOT EXISTS content_predictions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  content_id BIGINT UNSIGNED NOT NULL,
  metric_key VARCHAR(80) NOT NULL,
  predicted_value DECIMAL(12,4) NULL,
  confidence DECIMAL(5,2) NOT NULL DEFAULT 0,
  basis JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_prediction_content (content_id,metric_key,created_at),
  CONSTRAINT fk_prediction_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_prediction_content FOREIGN KEY (content_id) REFERENCES content_items(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS content_performance (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  content_id BIGINT UNSIGNED NOT NULL,
  external_media_id VARCHAR(190) NULL,
  metric_date DATE NOT NULL,
  reach INT UNSIGNED NOT NULL DEFAULT 0,
  impressions INT UNSIGNED NOT NULL DEFAULT 0,
  likes INT UNSIGNED NOT NULL DEFAULT 0,
  comments INT UNSIGNED NOT NULL DEFAULT 0,
  saves INT UNSIGNED NOT NULL DEFAULT 0,
  shares INT UNSIGNED NOT NULL DEFAULT 0,
  source ENUM('meta','manual') NOT NULL DEFAULT 'meta',
  raw_payload JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_content_perf_day (content_id,metric_date),
  INDEX idx_content_perf_user (user_id,metric_date),
  CONSTRAINT fk_content_perf_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_content_perf_content FOREIGN KEY (content_id) REFERENCES content_items(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS learning_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  content_id BIGINT UNSIGNED NOT NULL,
  event_type VARCHAR(80) NOT NULL,
  metric_key VARCHAR(80) NOT NULL,
  predicted_value DECIMAL(12,4) NULL,
  actual_value DECIMAL(12,4) NULL,
  error_value DECIMAL(12,4) NULL,
  confidence DECIMAL(5,2) NOT NULL DEFAULT 0,
  evidence JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_learning_user (user_id,created_at),
  CONSTRAINT fk_learning_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_learning_content FOREIGN KEY (content_id) REFERENCES content_items(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS learning_profiles (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  metric_key VARCHAR(80) NOT NULL,
  sample_count INT UNSIGNED NOT NULL DEFAULT 0,
  mean_absolute_error DECIMAL(12,4) NOT NULL DEFAULT 0,
  winner_count INT UNSIGNED NOT NULL DEFAULT 0,
  loser_count INT UNSIGNED NOT NULL DEFAULT 0,
  confidence DECIMAL(5,2) NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_learning_profile (user_id,metric_key),
  CONSTRAINT fk_learning_profile_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ab_experiments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(190) NOT NULL,
  test_dimension ENUM('hook','cta','format','time') NOT NULL,
  metric_key VARCHAR(80) NOT NULL DEFAULT 'engagement_rate',
  status ENUM('draft','running','completed','cancelled') NOT NULL DEFAULT 'draft',
  winner_variant_id BIGINT UNSIGNED NULL,
  confidence DECIMAL(5,2) NOT NULL DEFAULT 0,
  started_at DATETIME NULL,
  ended_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_ab_user (user_id,status,created_at),
  CONSTRAINT fk_ab_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ab_variants (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  experiment_id BIGINT UNSIGNED NOT NULL,
  content_id BIGINT UNSIGNED NULL,
  variant_key VARCHAR(20) NOT NULL,
  label VARCHAR(190) NOT NULL,
  exposure_count INT UNSIGNED NOT NULL DEFAULT 0,
  reach INT UNSIGNED NOT NULL DEFAULT 0,
  interactions INT UNSIGNED NOT NULL DEFAULT 0,
  engagement_rate DECIMAL(12,4) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_ab_variant (experiment_id,variant_key),
  CONSTRAINT fk_ab_variant_experiment FOREIGN KEY (experiment_id) REFERENCES ab_experiments(id) ON DELETE CASCADE,
  CONSTRAINT fk_ab_variant_content FOREIGN KEY (content_id) REFERENCES content_items(id) ON DELETE SET NULL
) ENGINE=InnoDB;


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
-- InstaPilot V42 Data & Recommendation Engine. Idempotent.
CREATE TABLE IF NOT EXISTS instagram_sync_logs (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL,
 instagram_account_id BIGINT UNSIGNED NULL,
 sync_type VARCHAR(60) NOT NULL,
 status ENUM('running','completed','partial','failed') NOT NULL DEFAULT 'running',
 items_scanned INT UNSIGNED NOT NULL DEFAULT 0,
 items_synced INT UNSIGNED NOT NULL DEFAULT 0,
 error_message TEXT NULL,
 started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 completed_at DATETIME NULL,
 INDEX idx_ig_sync_user(user_id,started_at),
 CONSTRAINT fk_v42_sync_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
 CONSTRAINT fk_v42_sync_account FOREIGN KEY(instagram_account_id) REFERENCES instagram_accounts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS recommendation_readiness (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL,
 content_id BIGINT UNSIGNED NULL,
 readiness_score DECIMAL(5,2) NOT NULL DEFAULT 0,
 status ENUM('ready','review','blocked') NOT NULL DEFAULT 'review',
 originality_score DECIMAL(5,2) NOT NULL DEFAULT 0,
 quality_score DECIMAL(5,2) NOT NULL DEFAULT 0,
 safety_score DECIMAL(5,2) NOT NULL DEFAULT 0,
 reasons JSON NULL,
 checked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_readiness_user(user_id,checked_at),
 INDEX idx_readiness_content(content_id,checked_at),
 CONSTRAINT fk_v42_readiness_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
 CONSTRAINT fk_v42_readiness_content FOREIGN KEY(content_id) REFERENCES content_items(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS smart_publish_slots (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL,
 slot_start DATETIME NOT NULL,
 slot_end DATETIME NOT NULL,
 score DECIMAL(5,2) NOT NULL DEFAULT 0,
 confidence DECIMAL(5,2) NOT NULL DEFAULT 0,
 reason JSON NULL,
 source VARCHAR(50) NOT NULL DEFAULT 'historical',
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_smart_slot_user(user_id,slot_start),
 CONSTRAINT fk_v42_slot_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS content_attribution (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL,
 content_id BIGINT UNSIGNED NOT NULL,
 outcome_metric VARCHAR(80) NOT NULL,
 outcome_value DECIMAL(14,4) NOT NULL DEFAULT 0,
 factor_key VARCHAR(80) NOT NULL,
 factor_weight DECIMAL(8,4) NOT NULL DEFAULT 0,
 evidence JSON NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_attr_user(user_id,created_at),
 INDEX idx_attr_content(content_id),
 CONSTRAINT fk_v42_attr_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
 CONSTRAINT fk_v42_attr_content FOREIGN KEY(content_id) REFERENCES content_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO ip19_schema_log(version,description) VALUES('42.0.0','Data & Recommendation Engine: Instagram sync, prediction, readiness, smart slots, attribution and learning loop') ON DUPLICATE KEY UPDATE description=VALUES(description);


-- InstaPilot V43 Autonomous Growth Brain
CREATE TABLE IF NOT EXISTS growth_missions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL,
 mission_type VARCHAR(60) NOT NULL,
 title VARCHAR(255) NOT NULL,
 status ENUM('planned','approved','running','completed','failed','cancelled') NOT NULL DEFAULT 'planned',
 priority INT NOT NULL DEFAULT 50,
 payload JSON NULL,
 decision_snapshot JSON NULL,
 result JSON NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 approved_at DATETIME NULL,
 started_at DATETIME NULL,
 completed_at DATETIME NULL,
 INDEX idx_v43_mission_user(user_id,status,priority,created_at),
 CONSTRAINT fk_v43_mission_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO ip19_schema_log(version,description) VALUES('43.0.0','Autonomous Growth Brain: decision loop, missions, guardrails and learning orchestration') ON DUPLICATE KEY UPDATE description=VALUES(description);
