CREATE DATABASE negative_guard DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE negative_guard;

CREATE TABLE posts (
  id VARCHAR(64) PRIMARY KEY,
  message TEXT,
  created_time DATETIME,
  from_id VARCHAR(64),
  risk_score FLOAT DEFAULT 0,
  label VARCHAR(50) DEFAULT '',
  action_taken VARCHAR(50) DEFAULT 'none',  -- none/pending/commented/posted
  last_analysis_time DATETIME NULL,
  meta JSON NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE audit_logs (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  post_id VARCHAR(64),
  action_type VARCHAR(50),
  reason TEXT,
  payload JSON NULL,
  actor VARCHAR(100),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE cron_lock (
  name VARCHAR(50) PRIMARY KEY,
  locked_until DATETIME
);
