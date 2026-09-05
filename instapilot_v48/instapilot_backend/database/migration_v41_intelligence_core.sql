-- InstaPilot V41 Intelligence Core. Idempotent.
CREATE TABLE IF NOT EXISTS trend_signals (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL, signal_key VARCHAR(100) NOT NULL, label VARCHAR(180) NOT NULL, score DECIMAL(5,2) NOT NULL DEFAULT 0, source VARCHAR(120) NULL, rationale TEXT NULL, payload JSON NULL, observed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_trend_user(user_id,observed_at), CONSTRAINT fk_trend_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
ALTER TABLE growth_experiments ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;
INSERT INTO ip19_schema_log(version,description) VALUES('41.0.0','Intelligence Core: forecast, trend signals, competitor lab and experiment controls') ON DUPLICATE KEY UPDATE description=VALUES(description);
