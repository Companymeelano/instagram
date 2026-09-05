-- InstaPilot V45: Auth Guard + Realtime Notifications + Session Security
CREATE TABLE IF NOT EXISTS device_sessions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL,
 session_token_hash CHAR(64) NOT NULL, device_label VARCHAR(120) NOT NULL DEFAULT 'Web Browser',
 ip_address VARCHAR(45) NULL, user_agent VARCHAR(500) NULL, fingerprint CHAR(64) NULL,
 last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 revoked_at DATETIME NULL, INDEX idx_ds_user(user_id,revoked_at,last_seen_at), UNIQUE KEY uq_ds_token(session_token_hash),
 CONSTRAINT fk_ds_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS login_activity (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NULL, event VARCHAR(40) NOT NULL,
 ip_address VARCHAR(45) NULL, user_agent VARCHAR(500) NULL, fingerprint CHAR(64) NULL, metadata TEXT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_la_user(user_id,created_at), INDEX idx_la_event(event,created_at),
 CONSTRAINT fk_la_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS rate_limits (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, rate_key CHAR(64) NOT NULL, bucket VARCHAR(60) NOT NULL, attempts INT UNSIGNED NOT NULL DEFAULT 0,
 window_started_at DATETIME NOT NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_rate_key(rate_key), INDEX idx_rate_bucket(bucket,window_started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO ip19_schema_log(version,description) VALUES('45.0.0','Auth Guard, session timeout, device sessions, login activity, realtime notifications and rate limiting') ON DUPLICATE KEY UPDATE description=VALUES(description);
