-- InstaPilot V40 Growth OS. Idempotent migration.
CREATE TABLE IF NOT EXISTS profile_dna (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL,
 niche VARCHAR(160) NULL, audience TEXT NULL, tone VARCHAR(160) NULL,
 pillars JSON NULL, banned_terms JSON NULL, preferred_terms JSON NULL,
 visual_style VARCHAR(255) NULL, cta_style VARCHAR(255) NULL,
 best_formats JSON NULL, best_topics JSON NULL, best_hooks JSON NULL,
 confidence DECIMAL(5,2) NOT NULL DEFAULT 0,
 version INT NOT NULL DEFAULT 1,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_profile_dna_user(user_id),
 CONSTRAINT fk_profile_dna_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS growth_recommendations (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL,
 recommendation_type VARCHAR(64) NOT NULL,
 title VARCHAR(255) NOT NULL,
 rationale TEXT NULL,
 score DECIMAL(5,2) NOT NULL DEFAULT 0,
 priority ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
 payload JSON NULL,
 status ENUM('new','accepted','dismissed','completed') NOT NULL DEFAULT 'new',
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 expires_at DATETIME NULL,
 INDEX idx_growth_rec_user(user_id,status,created_at),
 CONSTRAINT fk_growth_rec_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS content_quality_reviews (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL,
 content_id BIGINT UNSIGNED NULL,
 content_type VARCHAR(32) NOT NULL,
 overall_score DECIMAL(5,2) NOT NULL DEFAULT 0,
 hook_score DECIMAL(5,2) NOT NULL DEFAULT 0,
 share_score DECIMAL(5,2) NOT NULL DEFAULT 0,
 save_score DECIMAL(5,2) NOT NULL DEFAULT 0,
 originality_score DECIMAL(5,2) NOT NULL DEFAULT 0,
 audience_fit_score DECIMAL(5,2) NOT NULL DEFAULT 0,
 recommendation_score DECIMAL(5,2) NOT NULL DEFAULT 0,
 cta_score DECIMAL(5,2) NOT NULL DEFAULT 0,
 risk_level ENUM('low','medium','high') NOT NULL DEFAULT 'low',
 findings JSON NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_quality_user(user_id,created_at),
 CONSTRAINT fk_quality_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS growth_experiments (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL,
 name VARCHAR(180) NOT NULL,
 hypothesis TEXT NULL,
 variable_key VARCHAR(80) NOT NULL,
 status ENUM('draft','running','completed','cancelled') NOT NULL DEFAULT 'draft',
 variant_a JSON NULL,
 variant_b JSON NULL,
 winner VARCHAR(20) NULL,
 confidence DECIMAL(5,2) NOT NULL DEFAULT 0,
 started_at DATETIME NULL,
 ended_at DATETIME NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_experiment_user(user_id,created_at),
 CONSTRAINT fk_experiment_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS competitor_profiles (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL,
 handle VARCHAR(120) NOT NULL,
 display_name VARCHAR(160) NULL,
 status ENUM('active','paused') NOT NULL DEFAULT 'active',
 last_snapshot_at DATETIME NULL,
 metadata JSON NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_competitor_user_handle(user_id,handle),
 CONSTRAINT fk_competitor_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS growth_daily_snapshots (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL,
 snapshot_date DATE NOT NULL,
 followers INT NULL,
 reach BIGINT NULL,
 impressions BIGINT NULL,
 engagement_rate DECIMAL(7,3) NULL,
 share_rate DECIMAL(7,3) NULL,
 save_rate DECIMAL(7,3) NULL,
 non_follower_reach DECIMAL(7,3) NULL,
 growth_score DECIMAL(5,2) NULL,
 payload JSON NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_growth_day(user_id,snapshot_date),
 CONSTRAINT fk_growth_day_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO ip19_schema_log(version,description) VALUES('40.0.0','Growth OS: Profile DNA, quality reviews, recommendations, experiments, competitors, daily growth snapshots')
ON DUPLICATE KEY UPDATE description=VALUES(description);
