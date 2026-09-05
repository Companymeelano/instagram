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
