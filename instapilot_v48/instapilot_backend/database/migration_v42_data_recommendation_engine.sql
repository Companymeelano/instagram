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
