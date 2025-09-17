-- Tạo DB
CREATE DATABASE IF NOT EXISTS negative_guard
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
USE negative_guard;

-- Bảng posts: Row ID nội bộ + FB Post ID riêng
CREATE TABLE posts (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,         -- Row ID nội bộ (PRIMARY)
  fb_post_id        VARCHAR(64)  NOT NULL,                           -- Facebook Post ID: pageid_postid
  message           TEXT NULL,
  created_time      DATETIME NULL,                                   -- thời điểm post trên FB
  permalink_url     VARCHAR(255) NULL,
  from_id           VARCHAR(64)  NULL,                               -- người đăng (page/user id)
  risk_score        DECIMAL(5,4) NOT NULL DEFAULT 0.0000,            -- 0..1
  label             VARCHAR(32)  NOT NULL DEFAULT '',                -- negative/neutral/positive
  action_taken      VARCHAR(32)  NOT NULL DEFAULT 'none',            -- none/pending/commented/posted
  last_analysis_time DATETIME NULL,
  last_seen         DATETIME NULL,                                   -- lần cuối collector thấy post
  meta              JSON NULL,                                       -- dữ liệu phụ (kind, parent_id, ...)
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_fb_post_id (fb_post_id),
  KEY idx_created_time      (created_time),
  KEY idx_last_seen         (last_seen),
  KEY idx_risk_score        (risk_score),
  KEY idx_action_taken      (action_taken),
  KEY idx_label             (label),
  KEY idx_from_id           (from_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bảng logs cho manual/auto actions, kèm http_code & error để debug Graph API
CREATE TABLE audit_logs (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  post_id     VARCHAR(64) NULL,          -- có thể là fb_post_id hoặc row id tuỳ bạn log
  action_type VARCHAR(50) NOT NULL,      -- manual_comment/manual_post/auto_comment/...
  reason      TEXT NULL,
  payload     JSON NULL,                 -- raw response từ Graph
  http_code   SMALLINT NULL,             -- HTTP status của Graph
  error       TEXT NULL,                 -- cURL error hoặc message
  actor       VARCHAR(100) NULL,         -- admin/bot
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_post_id (post_id),
  KEY idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Khoá dành cho cron
CREATE TABLE cron_lock (
  name         VARCHAR(50) PRIMARY KEY,
  locked_until DATETIME
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
