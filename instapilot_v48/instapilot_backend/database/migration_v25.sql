-- InstaPilot V25 database hardening / AI control center
-- Idempotent: safe to run on an existing installation.

CREATE TABLE IF NOT EXISTS ai_providers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  provider_key VARCHAR(64) NOT NULL,
  display_name VARCHAR(120) NOT NULL,
  category VARCHAR(40) NOT NULL DEFAULT 'text',
  base_url VARCHAR(255) NULL,
  model VARCHAR(120) NULL,
  capabilities JSON NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 0,
  priority INT NOT NULL DEFAULT 100,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_ai_provider_key (provider_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ai_provider_settings (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  provider_key VARCHAR(64) NOT NULL,
  api_key_enc TEXT NULL,
  account_id_enc TEXT NULL,
  base_url VARCHAR(255) NULL,
  model VARCHAR(120) NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 0,
  last_test_status ENUM('never','ok','error') NOT NULL DEFAULT 'never',
  last_test_message VARCHAR(500) NULL,
  last_test_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_user_provider (user_id,provider_key),
  INDEX idx_ai_provider_user (user_id),
  CONSTRAINT fk_ai_provider_settings_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ai_task_routes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  task_key VARCHAR(64) NOT NULL,
  mode ENUM('auto','manual') NOT NULL DEFAULT 'auto',
  provider_key VARCHAR(64) NOT NULL DEFAULT 'builtin',
  fallback_provider_key VARCHAR(64) NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_user_task_route (user_id,task_key),
  INDEX idx_task_route_user (user_id),
  CONSTRAINT fk_ai_task_routes_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ai_provider_test_runs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  provider_key VARCHAR(64) NOT NULL,
  test_type VARCHAR(40) NOT NULL DEFAULT 'health',
  status ENUM('success','failed') NOT NULL,
  http_status SMALLINT NULL,
  latency_ms INT NULL,
  message VARCHAR(500) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ai_test_user (user_id,created_at),
  CONSTRAINT fk_ai_provider_test_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO ai_providers (provider_key,display_name,category,capabilities,enabled,priority) VALUES
('builtin','InstaPilot Internal','text','["text","score","demo"]',1,999),
('openai','OpenAI','multi','["text","vision","embeddings"]',0,10),
('gemini','Google Gemini','multi','["text","vision"]',0,20),
('groq','Groq / Llama','text','["text","fast"]',0,30),
('gapgpt','GapGPT','text','["text"]',0,40),
('stability','Stability AI','image','["image"]',0,50),
('deepai','DeepAI','multi','["text","image"]',0,60),
('fal','Fal.ai','media','["image","video"]',0,70),
('kling','Kling AI','video','["video"]',0,80),
('byteplus','BytePlus Ark','multi','["text","multi"]',0,90),
('piapi','PiAPI','media','["image","video"]',0,100),
('maxrouter','MaxRouter','routing','["routing","multi"]',0,110),
('cloudflare','Cloudflare AI','edge','["edge_ai","text"]',0,120)
ON DUPLICATE KEY UPDATE display_name=VALUES(display_name),capabilities=VALUES(capabilities);

INSERT INTO ip19_schema_log (version,description) VALUES ('25.0.0','AI Provider Control Center, task routing, provider test history')
ON DUPLICATE KEY UPDATE description=VALUES(description);
