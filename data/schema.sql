-- Admin users
CREATE TABLE IF NOT EXISTS admin_users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  username VARCHAR(50) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_admin_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Lưu lịch sử phân tích
CREATE TABLE IF NOT EXISTS analysis_logs (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  ip VARCHAR(64),
  input_text LONGTEXT NOT NULL,
  output_json LONGTEXT NOT NULL,
  risk_score INT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rate limit theo IP+phút
CREATE TABLE IF NOT EXISTS rate_limits (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  ip VARCHAR(64) NOT NULL,
  minute_window VARCHAR(20) NOT NULL,
  count INT DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_ip_minute (ip, minute_window)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cache post/comment từ Graph API
CREATE TABLE IF NOT EXISTS fb_cache (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  cache_key VARCHAR(200) NOT NULL,
  payload LONGTEXT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_cache_key (cache_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS auto_actions (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  object_id VARCHAR(64) NOT NULL,
  object_type ENUM('comment','post') NOT NULL,
  action VARCHAR(32) NOT NULL,               -- replied | hidden | skipped
  risk INT,
  reason VARCHAR(255),
  response_text TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_obj_action (object_id, action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE auto_actions
  ADD INDEX idx_created (created_at),
  ADD INDEX idx_risk (risk);

  -- bảng chuẩn cho MySQL/MariaDB
CREATE TABLE IF NOT EXISTS auto_actions (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  object_id    VARCHAR(64) NOT NULL,
  object_type  ENUM('post','comment') NOT NULL,
  action       VARCHAR(32) NOT NULL,
  risk         INT DEFAULT 0,
  reason       VARCHAR(255),
  response_text TEXT,
  created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_object_action (object_id, action),
  KEY idx_created_at (created_at),
  KEY idx_object_type (object_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;