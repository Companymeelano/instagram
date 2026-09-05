-- V33 idempotent support objects. Existing application tables are not replaced.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS api_health_checks (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NULL,
  service_key VARCHAR(80) NOT NULL,
  status ENUM('ok','warning','error') NOT NULL DEFAULT 'warning',
  latency_ms INT NULL,
  message VARCHAR(500) NULL,
  meta JSON NULL,
  checked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(id),
  KEY idx_health_user_service(user_id,service_key,checked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ai_usage_ledger (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  provider VARCHAR(40) NOT NULL,
  operation VARCHAR(80) NOT NULL,
  request_units DECIMAL(14,4) NOT NULL DEFAULT 1,
  estimated_cost DECIMAL(14,6) NOT NULL DEFAULT 0,
  status ENUM('queued','success','failed') NOT NULL DEFAULT 'success',
  metadata JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(id),
  KEY idx_usage_user_date(user_id,created_at),
  KEY idx_usage_provider(provider,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ai_provider_routes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  provider VARCHAR(40) NOT NULL,
  task_type VARCHAR(60) NOT NULL DEFAULT 'general',
  mode ENUM('auto','manual') NOT NULL DEFAULT 'auto',
  priority INT NOT NULL DEFAULT 100,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  daily_limit INT NULL,
  monthly_limit INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY(id),
  UNIQUE KEY uq_provider_route(user_id,provider,task_type),
  KEY idx_route_user_task(user_id,task_type,enabled,priority)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
