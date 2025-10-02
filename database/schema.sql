-- YouTube Marketing Tool - Database Schema
-- Created: 2025-10-02

-- ============================================
-- 1. USERS TABLE
-- ============================================
CREATE TABLE IF NOT EXISTS `users` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(50) NOT NULL UNIQUE,
  `email` VARCHAR(100) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `full_name` VARCHAR(100) NULL,
  `role` ENUM('admin', 'user') DEFAULT 'user',
  `api_key_rapidapi` VARCHAR(255) NULL COMMENT 'Encrypted RapidAPI key',
  `api_key_ai` VARCHAR(255) NULL COMMENT 'Encrypted AI API key',
  `is_active` BOOLEAN DEFAULT TRUE,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `last_login` TIMESTAMP NULL,
  INDEX idx_username (`username`),
  INDEX idx_email (`email`),
  INDEX idx_is_active (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 2. API CACHE TABLE
-- ============================================
CREATE TABLE IF NOT EXISTS `api_cache` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NULL COMMENT 'NULL for shared cache',
  `cache_key` VARCHAR(255) NOT NULL,
  `cache_type` ENUM('search', 'video_info', 'channel_info', 'channel_videos') NOT NULL,
  `provider` VARCHAR(50) DEFAULT 'yt-api' COMMENT 'API provider (yt-api, youtube-v31)',
  `request_params` JSON NOT NULL COMMENT 'Original request parameters',
  `response_data` LONGTEXT NOT NULL COMMENT 'Cached response (JSON)',
  `ttl_seconds` INT UNSIGNED DEFAULT 21600 COMMENT 'Time to live in seconds',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `expires_at` TIMESTAMP GENERATED ALWAYS AS (created_at + INTERVAL ttl_seconds SECOND) STORED,
  `hit_count` INT UNSIGNED DEFAULT 0 COMMENT 'Cache hit counter',
  `last_accessed` TIMESTAMP NULL,
  UNIQUE KEY unique_cache (`cache_key`),
  INDEX idx_user_id (`user_id`),
  INDEX idx_cache_type (`cache_type`),
  INDEX idx_expires_at (`expires_at`),
  INDEX idx_provider (`provider`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 3. SEARCH HISTORY TABLE
-- ============================================
CREATE TABLE IF NOT EXISTS `search_history` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NULL COMMENT 'NULL for anonymous',
  `search_type` ENUM('keyword', 'channel') NOT NULL,
  `query` VARCHAR(500) NOT NULL,
  `metadata` JSON NULL COMMENT 'Additional search parameters',
  `result_count` INT UNSIGNED DEFAULT 0,
  `video_ids` TEXT NULL COMMENT 'Comma-separated video IDs',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_user_id (`user_id`),
  INDEX idx_search_type (`search_type`),
  INDEX idx_created_at (`created_at`),
  INDEX idx_query (`query`(100)),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 4. ANALYSIS RESULTS TABLE
-- ============================================
CREATE TABLE IF NOT EXISTS `analysis_results` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL,
  `analysis_type` VARCHAR(50) NOT NULL COMMENT 'descriptions, tags, titles, seo, etc.',
  `mode` ENUM('search', 'channel') NOT NULL,
  `query` VARCHAR(500) NULL COMMENT 'Search query or channel ID',
  `input_data` MEDIUMTEXT NOT NULL COMMENT 'Input JSON (limited 30 items)',
  `prompt` TEXT NOT NULL COMMENT 'AI prompt used',
  `ai_provider` VARCHAR(50) DEFAULT 'codefast',
  `ai_model` VARCHAR(100) DEFAULT 'gpt-5-chat',
  `result` LONGTEXT NOT NULL COMMENT 'AI analysis result',
  `tokens_used` INT UNSIGNED NULL COMMENT 'Token consumption',
  `processing_time_ms` INT UNSIGNED NULL COMMENT 'Processing time in milliseconds',
  `is_saved` BOOLEAN DEFAULT FALSE COMMENT 'User explicitly saved?',
  `file_path` VARCHAR(500) NULL COMMENT 'Saved file path',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_user_id (`user_id`),
  INDEX idx_analysis_type (`analysis_type`),
  INDEX idx_mode (`mode`),
  INDEX idx_created_at (`created_at`),
  INDEX idx_is_saved (`is_saved`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 5. VIDEO METADATA TABLE
-- ============================================
CREATE TABLE IF NOT EXISTS `video_metadata` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `video_id` VARCHAR(20) NOT NULL UNIQUE,
  `title` VARCHAR(500) NULL,
  `description` TEXT NULL,
  `channel_id` VARCHAR(50) NULL,
  `channel_title` VARCHAR(200) NULL,
  `published_at` TIMESTAMP NULL,
  `duration` INT UNSIGNED NULL COMMENT 'Video duration in seconds',
  `view_count` BIGINT UNSIGNED DEFAULT 0,
  `like_count` INT UNSIGNED DEFAULT 0,
  `comment_count` INT UNSIGNED DEFAULT 0,
  `tags` JSON NULL COMMENT 'Array of tags',
  `thumbnails` JSON NULL COMMENT 'Thumbnail URLs',
  `category_id` INT UNSIGNED NULL,
  `is_live` BOOLEAN DEFAULT FALSE,
  `is_upcoming` BOOLEAN DEFAULT FALSE,
  `raw_data` JSON NULL COMMENT 'Full API response',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_video_id (`video_id`),
  INDEX idx_channel_id (`channel_id`),
  INDEX idx_published_at (`published_at`),
  INDEX idx_view_count (`view_count`),
  INDEX idx_like_count (`like_count`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 6. CHANNEL METADATA TABLE
-- ============================================
CREATE TABLE IF NOT EXISTS `channel_metadata` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `channel_id` VARCHAR(50) NOT NULL UNIQUE,
  `channel_title` VARCHAR(200) NULL,
  `description` TEXT NULL,
  `custom_url` VARCHAR(200) NULL,
  `published_at` TIMESTAMP NULL,
  `subscriber_count` BIGINT UNSIGNED DEFAULT 0,
  `video_count` INT UNSIGNED DEFAULT 0,
  `view_count` BIGINT UNSIGNED DEFAULT 0,
  `thumbnails` JSON NULL,
  `country` VARCHAR(10) NULL,
  `raw_data` JSON NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_channel_id (`channel_id`),
  INDEX idx_subscriber_count (`subscriber_count`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 7. USER SESSIONS TABLE
-- ============================================
CREATE TABLE IF NOT EXISTS `user_sessions` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL,
  `session_token` VARCHAR(255) NOT NULL UNIQUE,
  `ip_address` VARCHAR(45) NULL,
  `user_agent` VARCHAR(500) NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `expires_at` TIMESTAMP NOT NULL,
  `last_activity` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_user_id (`user_id`),
  INDEX idx_session_token (`session_token`),
  INDEX idx_expires_at (`expires_at`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 8. USER FAVORITES TABLE
-- ============================================
CREATE TABLE IF NOT EXISTS `user_favorites` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL,
  `item_type` ENUM('video', 'channel', 'search', 'analysis') NOT NULL,
  `item_id` VARCHAR(100) NOT NULL COMMENT 'video_id, channel_id, search_id, analysis_id',
  `note` TEXT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY unique_favorite (`user_id`, `item_type`, `item_id`),
  INDEX idx_user_id (`user_id`),
  INDEX idx_item_type (`item_type`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 9. SYSTEM LOGS TABLE
-- ============================================
CREATE TABLE IF NOT EXISTS `system_logs` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NULL,
  `log_level` ENUM('debug', 'info', 'warning', 'error', 'critical') DEFAULT 'info',
  `log_type` VARCHAR(50) NOT NULL COMMENT 'api_error, auth_fail, cache_hit, etc.',
  `message` TEXT NOT NULL,
  `context` JSON NULL COMMENT 'Additional context data',
  `ip_address` VARCHAR(45) NULL,
  `user_agent` VARCHAR(500) NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_user_id (`user_id`),
  INDEX idx_log_level (`log_level`),
  INDEX idx_log_type (`log_type`),
  INDEX idx_created_at (`created_at`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 10. API USAGE STATS TABLE
-- ============================================
CREATE TABLE IF NOT EXISTS `api_usage_stats` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NULL,
  `api_provider` VARCHAR(50) NOT NULL COMMENT 'rapidapi, codefast, etc.',
  `endpoint` VARCHAR(200) NOT NULL,
  `request_count` INT UNSIGNED DEFAULT 1,
  `total_tokens` BIGINT UNSIGNED DEFAULT 0,
  `total_cost` DECIMAL(10, 4) DEFAULT 0.0000 COMMENT 'Estimated cost in USD',
  `date` DATE NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY unique_usage (`user_id`, `api_provider`, `endpoint`, `date`),
  INDEX idx_user_id (`user_id`),
  INDEX idx_api_provider (`api_provider`),
  INDEX idx_date (`date`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- INITIAL DATA
-- ============================================

-- Create default admin user (password: admin123)
-- Note: Change this immediately after installation!
INSERT IGNORE INTO `users` (`username`, `email`, `password_hash`, `full_name`, `role`)
VALUES ('admin', 'admin@ymt-lokal.local', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Administrator', 'admin');

-- ============================================
-- CLEANUP QUERIES (for maintenance)
-- ============================================

-- Delete expired cache entries
-- DELETE FROM `api_cache` WHERE `expires_at` < NOW();

-- Delete old logs (older than 30 days)
-- DELETE FROM `system_logs` WHERE `created_at` < DATE_SUB(NOW(), INTERVAL 30 DAY);

-- Delete expired sessions
-- DELETE FROM `user_sessions` WHERE `expires_at` < NOW();
