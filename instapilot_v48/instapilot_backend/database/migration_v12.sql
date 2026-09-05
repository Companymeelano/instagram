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
