-- Tạo DB
CREATE DATABASE IF NOT EXISTS negative_guard
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
USE negative_guard;

-- Bảng posts: Row ID nội bộ + FB Post ID riêng
CREATE TABLE IF NOT EXISTS posts (
  fb_post_id       VARCHAR(64) PRIMARY KEY,
  from_id          VARCHAR(64) NULL,
  message          MEDIUMTEXT NULL,
  permalink_url    TEXT NULL,
  created_time     DATETIME NULL,
  last_seen        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  risk_score       FLOAT DEFAULT NULL,
  label            VARCHAR(32) DEFAULT NULL,
  action_taken     VARCHAR(32) NOT NULL DEFAULT 'none',
  INDEX idx_created (created_time),
  INDEX idx_last_seen (last_seen)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- comments: duy nhất theo fb_comment_id, biết post cha
CREATE TABLE IF NOT EXISTS comments (
  fb_comment_id    VARCHAR(64) PRIMARY KEY,
  parent_fb_post_id VARCHAR(64) NOT NULL,
  from_id          VARCHAR(64) NULL,
  message          MEDIUMTEXT NULL,
  permalink_url    TEXT NULL,
  created_time     DATETIME NULL,
  last_seen        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_parent (parent_fb_post_id),
  INDEX idx_cmt_created (created_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- thêm cột cho bảng posts (nếu chưa có)
ALTER TABLE posts
  ADD COLUMN last_analysis_time DATETIME NULL AFTER last_seen;

-- bảng audit_logs (worker đang INSERT vào bảng này)
CREATE TABLE IF NOT EXISTS audit_logs (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  post_id VARCHAR(64) NOT NULL,
  action_type VARCHAR(32) NOT NULL,
  reason VARCHAR(255) NULL,
  payload TEXT NULL,
  actor VARCHAR(64) NOT NULL DEFAULT 'admin',
  http_code INT NULL,
  error VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_post (post_id),
  INDEX idx_act (action_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE comments
  ADD COLUMN risk_score FLOAT NULL,
  ADD COLUMN label VARCHAR(32) NULL,
  ADD COLUMN last_analysis_time DATETIME NULL;

  -- cho bài viết
UPDATE posts
SET last_analysis_time = NULL
WHERE risk_score IS NULL OR label IS NULL;

-- cho bình luận
UPDATE comments
SET last_analysis_time = NULL
WHERE risk_score IS NULL OR label IS NULL;