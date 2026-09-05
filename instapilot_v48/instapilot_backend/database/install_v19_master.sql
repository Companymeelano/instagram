-- ================================================================
-- InstaPilot V19 MASTER DATABASE INSTALLER / RECONCILER
-- Target: cPanel + PHP 8.4 + MySQL 8.x / MariaDB compatible subset
-- Run this file AFTER selecting the cPanel database created by MySQL Wizard.
-- It is intentionally idempotent: existing correct tables are skipped.
-- Missing tables are created. Missing columns/indexes are repaired where safe.
-- Existing business data is NEVER deleted by this installer.
-- ================================================================
SET NAMES utf8mb4;
SET @IP19_REPAIR_MODE := 'SAFE'; -- SAFE = add missing structure only; FORCE = also normalize known column types.

CREATE TABLE IF NOT EXISTS ip19_schema_log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  object_type VARCHAR(30) NOT NULL,
  object_name VARCHAR(190) NOT NULL,
  action VARCHAR(40) NOT NULL,
  status VARCHAR(20) NOT NULL,
  detail TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ip19_schema_log_created (created_at)
) ENGINE=InnoDB;

DELIMITER $$
DROP PROCEDURE IF EXISTS ip19_exec_safe$$
CREATE PROCEDURE ip19_exec_safe(IN p_sql LONGTEXT, IN p_object VARCHAR(190), IN p_action VARCHAR(40))
BEGIN
  DECLARE CONTINUE HANDLER FOR SQLEXCEPTION
  BEGIN
    INSERT INTO ip19_schema_log(object_type,object_name,action,status,detail)
    VALUES('sql',p_object,p_action,'warning','Operation could not be applied; installer continued safely.');
  END;
  SET @ip19_sql := p_sql;
  PREPARE ip19_stmt FROM @ip19_sql;
  EXECUTE ip19_stmt;
  DEALLOCATE PREPARE ip19_stmt;
  INSERT INTO ip19_schema_log(object_type,object_name,action,status,detail)
  VALUES('sql',p_object,p_action,'ok',NULL);
END$$

DROP PROCEDURE IF EXISTS ip19_ensure_column$$
CREATE PROCEDURE ip19_ensure_column(IN p_table VARCHAR(64), IN p_column VARCHAR(64), IN p_definition TEXT)
BEGIN
  DECLARE n INT DEFAULT 0;
  SELECT COUNT(*) INTO n FROM information_schema.columns
   WHERE table_schema=DATABASE() AND table_name=p_table AND column_name=p_column;
  IF n=0 THEN
    CALL ip19_exec_safe(CONCAT('ALTER TABLE `',p_table,'` ADD COLUMN `',p_column,'` ',p_definition), CONCAT(p_table,'.',p_column), 'ADD_COLUMN');
  ELSE
    INSERT INTO ip19_schema_log(object_type,object_name,action,status,detail)
    VALUES('column',CONCAT(p_table,'.',p_column),'CHECK','ok','Existing column preserved.');
  END IF;
END$$

DROP PROCEDURE IF EXISTS ip19_ensure_index$$
CREATE PROCEDURE ip19_ensure_index(IN p_table VARCHAR(64), IN p_index VARCHAR(64), IN p_sql TEXT)
BEGIN
  DECLARE n INT DEFAULT 0;
  SELECT COUNT(*) INTO n FROM information_schema.statistics
   WHERE table_schema=DATABASE() AND table_name=p_table AND index_name=p_index;
  IF n=0 THEN
    CALL ip19_exec_safe(CONCAT('ALTER TABLE `',p_table,'` ADD ',p_sql), CONCAT(p_table,'.',p_index), 'ADD_INDEX');
  ELSE
    INSERT INTO ip19_schema_log(object_type,object_name,action,status,detail)
    VALUES('index',CONCAT(p_table,'.',p_index),'CHECK','ok','Existing index preserved.');
  END IF;
END$$
DELIMITER ;

-- ----------------------------------------------------------------
-- CORE + V7..V17 complete table set
-- ----------------------------------------------------------------


-- ===== schema.sql =====
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

-- ===== migration_v12.sql =====
-- InstaPilot V12 migration for existing V11 installations
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

-- ===== migration_v13.sql =====
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

-- ===== migration_v14.sql =====
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

-- ===== migration_v15.sql =====
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

-- ===== migration_v16.sql =====
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

-- ===== migration_v17.sql =====
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

-- ===== migration_v19.sql =====
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


-- -- Repair critical columns for installations that predate the feature.
-- These calls only ADD a column when it is absent.
-- ----------------------------------------------------------------
CALL ip19_ensure_column('users','updated_at','DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');
CALL ip19_ensure_column('brand_profiles','updated_at','DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');
CALL ip19_ensure_column('instagram_accounts','last_sync_at','DATETIME NULL');
CALL ip19_ensure_column('content_items','scheduled_at','DATETIME NULL');
CALL ip19_ensure_column('content_items','published_at','DATETIME NULL');
CALL ip19_ensure_column('content_items','external_id','VARCHAR(190) NULL');
CALL ip19_ensure_column('content_items','media_url','TEXT NULL');
CALL ip19_ensure_column('content_items','media_type','VARCHAR(20) NULL');
CALL ip19_ensure_column('jobs','available_at','DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP');
CALL ip19_ensure_column('jobs','locked_at','DATETIME NULL');
CALL ip19_ensure_column('jobs','attempts','TINYINT UNSIGNED NOT NULL DEFAULT 0');
CALL ip19_ensure_column('audit_logs','metadata','JSON NULL');
CALL ip19_ensure_column('mission_sessions','approval_required','TINYINT(1) NOT NULL DEFAULT 1');
CALL ip19_ensure_column('approval_items','reviewed_at','DATETIME NULL');
CALL ip19_ensure_column('scheduler_recommendations','selected','TINYINT(1) NOT NULL DEFAULT 0');
CALL ip19_ensure_column('publish_queue','attempts','TINYINT UNSIGNED NOT NULL DEFAULT 0');
CALL ip19_ensure_column('publish_queue','max_attempts','TINYINT UNSIGNED NOT NULL DEFAULT 3');
CALL ip19_ensure_column('publish_queue','next_attempt_at','DATETIME NULL');
CALL ip19_ensure_column('publish_queue','idempotency_key','VARCHAR(190) NULL');
UPDATE publish_queue SET idempotency_key=CONCAT('legacy-',id) WHERE idempotency_key IS NULL OR idempotency_key='';
CALL ip19_exec_safe('ALTER TABLE `publish_queue` MODIFY COLUMN `idempotency_key` VARCHAR(190) NOT NULL','publish_queue.idempotency_key','NORMALIZE_COLUMN');
CALL ip19_ensure_column('publish_queue','locked_at','DATETIME NULL');
CALL ip19_ensure_column('publish_queue','published_media_id','VARCHAR(190) NULL');
CALL ip19_ensure_column('publish_queue','last_error','TEXT NULL');

-- ----------------------------------------------------------------
-- Required indexes. If they already exist, the installer skips them.
-- ----------------------------------------------------------------
CALL ip19_ensure_index('jobs','idx_jobs_queue','INDEX `idx_jobs_queue` (`status`,`available_at`)');
CALL ip19_ensure_index('notifications','idx_notifications_user','INDEX `idx_notifications_user` (`user_id`,`created_at`)');
CALL ip19_ensure_index('content_items','idx_content_queue','INDEX `idx_content_queue` (`status`,`scheduled_at`)');
CALL ip19_ensure_index('content_performance','uq_content_perf_day','UNIQUE KEY `uq_content_perf_day` (`content_id`,`metric_date`)');
CALL ip19_ensure_index('publish_queue','uq_publish_idempotency','UNIQUE KEY `uq_publish_idempotency` (`idempotency_key`)');
CALL ip19_ensure_index('publish_queue','idx_publish_queue','INDEX `idx_publish_queue` (`status`,`scheduled_at`,`next_attempt_at`)');
CALL ip19_ensure_index('scheduler_slots','idx_scheduler_slot_user_time','INDEX `idx_scheduler_slot_user_time` (`user_id`,`scheduled_at`)');

-- ----------------------------------------------------------------
-- Final verification snapshot
-- ----------------------------------------------------------------
INSERT INTO ip19_schema_log(object_type,object_name,action,status,detail)
SELECT 'table', required_name, 'VERIFY', IF(COUNT(t.table_name)>0,'ok','missing'),
       IF(COUNT(t.table_name)>0,'Present','Missing after reconciliation')
FROM (
 SELECT 'users' required_name UNION ALL SELECT 'brand_profiles' UNION ALL SELECT 'instagram_accounts'
 UNION ALL SELECT 'content_items' UNION ALL SELECT 'analytics_daily' UNION ALL SELECT 'notifications'
 UNION ALL SELECT 'jobs' UNION ALL SELECT 'audit_logs' UNION ALL SELECT 'instagram_webhook_events'
 UNION ALL SELECT 'ai_runs' UNION ALL SELECT 'ai_memories' UNION ALL SELECT 'growth_plans'
 UNION ALL SELECT 'autopilot_runs' UNION ALL SELECT 'analytics_sync_log' UNION ALL SELECT 'content_ai_reviews'
 UNION ALL SELECT 'content_ai_runs' UNION ALL SELECT 'content_performance_snapshots' UNION ALL SELECT 'media_sync_runs'
 UNION ALL SELECT 'content_predictions' UNION ALL SELECT 'content_performance' UNION ALL SELECT 'learning_events'
 UNION ALL SELECT 'learning_profiles' UNION ALL SELECT 'ab_experiments' UNION ALL SELECT 'ab_variants'
 UNION ALL SELECT 'mission_sessions' UNION ALL SELECT 'attribution_factor_weights' UNION ALL SELECT 'attribution_events'
 UNION ALL SELECT 'decision_recommendations' UNION ALL SELECT 'mission_runs' UNION ALL SELECT 'mission_plans'
 UNION ALL SELECT 'approval_items' UNION ALL SELECT 'scheduler_recommendations' UNION ALL SELECT 'scheduler_slots'
 UNION ALL SELECT 'publish_queue' UNION ALL SELECT 'publish_attempts' UNION ALL SELECT 'mission_events' UNION ALL SELECT 'system_settings'
) r LEFT JOIN information_schema.tables t ON t.table_schema=DATABASE() AND t.table_name=r.required_name
GROUP BY required_name;

SELECT 'InstaPilot V19 installer completed' AS installer_status, DATABASE() AS database_name, COUNT(*) AS schema_log_rows
FROM ip19_schema_log WHERE created_at >= NOW() - INTERVAL 5 MINUTE;

DROP PROCEDURE IF EXISTS ip19_ensure_column;
DROP PROCEDURE IF EXISTS ip19_ensure_index;
DROP PROCEDURE IF EXISTS ip19_exec_safe;
