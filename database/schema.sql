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
  -- Limits and counters (defaults safe for registration)
  `data_query_limit_daily` INT DEFAULT 100,
  `data_query_limit_monthly` INT DEFAULT 1000,
  `data_query_count_today` INT DEFAULT 0,
  `data_query_count_month` INT DEFAULT 0,
  `query_reset_date` DATE NULL,
  `query_month_reset` DATE NULL,
  `analysis_query_limit_daily` INT DEFAULT 50,
  `analysis_query_limit_monthly` INT DEFAULT 500,
  `analysis_query_count_today` INT DEFAULT 0,
  `analysis_query_count_month` INT DEFAULT 0,
  `analysis_query_reset_date` DATE NULL,
  `analysis_query_month_reset` DATE NULL,
  `license_expires_at` TIMESTAMP NULL,
  `notes` TEXT NULL,
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
  `search_type` ENUM('keyword', 'channel', 'video') NOT NULL,
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
  `mode` ENUM('search', 'channel', 'video') NOT NULL,
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

-- Migration for existing installations: allow 'video' mode
ALTER TABLE `analysis_results`
  MODIFY COLUMN `mode` ENUM('search', 'channel', 'video') NOT NULL;

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
  `local_assets` JSON NULL COMMENT 'Local file paths (e.g., {"thumbnail":"/path"})',
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
-- 11. USER ACTIVITY LOG TABLE (used by UserManager::logActivity)
-- ============================================
CREATE TABLE IF NOT EXISTS `user_activity_log` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL,
  `action_type` VARCHAR(50) NOT NULL,
  `details` JSON NULL,
  `ip_address` VARCHAR(45) NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_user_id (`user_id`),
  INDEX idx_action_type (`action_type`),
  INDEX idx_created_at (`created_at`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 12. VIDEO TRANSCRIPTS TABLE
-- ============================================
CREATE TABLE IF NOT EXISTS `video_transcripts` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `video_id` VARCHAR(20) NOT NULL,
  `lang` VARCHAR(10) NOT NULL,
  `provider` VARCHAR(50) NULL,
  `file_json_path` VARCHAR(500) NULL,
  `file_txt_path` VARCHAR(500) NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_video_lang (`video_id`, `lang`),
  INDEX idx_video_id (`video_id`),
  FOREIGN KEY (`video_id`) REFERENCES `video_metadata`(`video_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Migrations (MySQL 8+)
ALTER TABLE `video_metadata`
  ADD COLUMN IF NOT EXISTS `local_assets` JSON NULL;

-- ============================================
-- Safeguard migration for existing installations (MySQL 8+)
-- ============================================
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `data_query_limit_daily` INT DEFAULT 100,
  ADD COLUMN IF NOT EXISTS `data_query_limit_monthly` INT DEFAULT 1000,
  ADD COLUMN IF NOT EXISTS `data_query_count_today` INT DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `data_query_count_month` INT DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `query_reset_date` DATE NULL,
  ADD COLUMN IF NOT EXISTS `query_month_reset` DATE NULL,
  ADD COLUMN IF NOT EXISTS `analysis_query_limit_daily` INT DEFAULT 50,
  ADD COLUMN IF NOT EXISTS `analysis_query_limit_monthly` INT DEFAULT 500,
  ADD COLUMN IF NOT EXISTS `analysis_query_count_today` INT DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `analysis_query_count_month` INT DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `analysis_query_reset_date` DATE NULL,
  ADD COLUMN IF NOT EXISTS `analysis_query_month_reset` DATE NULL,
  ADD COLUMN IF NOT EXISTS `license_expires_at` TIMESTAMP NULL,
  ADD COLUMN IF NOT EXISTS `notes` TEXT NULL;

-- Allow 'video' type in search_history for existing installs
ALTER TABLE `search_history`
  MODIFY COLUMN `search_type` ENUM('keyword', 'channel', 'video') NOT NULL;

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
-- 11. ANALYSIS PROMPTS TABLE
-- ============================================
CREATE TABLE IF NOT EXISTS `analysis_prompts` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `analysis_type` VARCHAR(100) NOT NULL UNIQUE COMMENT 'descriptions, tags, titles, seo, etc.',
  `prompt_template` TEXT NOT NULL COMMENT 'The prompt template for this analysis type',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_analysis_type (`analysis_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- INITIAL DATA
-- ============================================

-- Create default admin user (password: admin123)
-- Note: Change this immediately after installation!
INSERT IGNORE INTO `users` (`username`, `email`, `password_hash`, `full_name`, `role`)
VALUES ('admin', 'admin@ymt-lokal.local', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Administrator', 'admin');

-- Insert default analysis prompt templates
INSERT IGNORE INTO `analysis_prompts` (`analysis_type`, `prompt_template`) VALUES
('descriptions', 'JSON içindeki description alanlarını incele.\n- Benzerlikler, farklılıklar, ortak temalar\n- SEO ve YouTube aranma açısından güçlü/zayıf yönler\n- Geliştirme önerileri\n- 2-3 örnek optimize açıklama şablonu\nKısa, maddeli ve somut öneriler ver.'),
('tags', 'JSON içindeki tags alanlarını analiz et.\n- En çok kullanılan etiketler, kümeler\n- Eksik/hatalı etiketler ve öneriler\n- Aranma niyeti (intent) odaklı tag önerileri\n- 10-20 yeni öneri tag listesi (TR odaklı)'),
('titles', 'JSON içindeki title alanlarını analiz et.\n- Öne çıkan kalıplar\n- CTR''ı artırma önerileri\n- 5-10 örnek yeni başlık önerisi'),
('seo', 'Kapsamlı SEO özeti çıkar: title, description, tags, izlenme metriklerine göre genel değerlendirme ve hızlı kazanım önerileri. Maddelerle yaz.'),
('auto-title-generator', 'JSON''daki başarılı başlıkları analiz et ve 20 farklı yeni başlık önerisi üret.\n- 5 clickbait tarzı\n- 5 profesyonel tarzı\n- 5 eğitsel tarzı\n- 5 merak uyandıran tarzı\nHer birini numaralandır ve kategorize et.'),
('performance-prediction', 'İzlenme verilerini analiz et ve performans tahminleri yap.\n- En iyi performans gösteren içerik özellikleri\n- Başarı olasılığı yüksek içerik tipleri\n- Risk faktörleri\n- Gelecek içerikler için öneriler'),
('content-gaps', 'İçerik boşluklarını tespit et.\n- Eksik kalan konu alanları\n- Potansiyel fırsatlar\n- Rakiplerin kullandığı ama burada olmayan konular\n- 10-15 yeni içerik fikri önerisi'),
('trending-topics', 'Yüksek izlenme alan videoların ortak temalarını tespit et.\n- Popüler konular ve trendler\n- Hangi konular daha çok ilgi görüyor\n- Trend takip önerileri\n- Güncel trendlere uyum stratejileri'),
('engagement-rate', 'Etkileşim oranlarını değerlendir.\n- Like/View oranı analizi\n- En çok etkileşim alan içerik özellikleri\n- Etkileşim artırma stratejileri\n- Topluluk oluşturma önerileri'),
('best-performers', 'En yüksek izlenmeye sahip videoları analiz et.\n- Ortak başarı faktörleri\n- Başlık, açıklama, tag paternleri\n- Tekrarlanabilir başarı formülü\n- 5-10 somut uygulama önerisi');

-- ============================================
-- CLEANUP QUERIES (for maintenance)
-- ============================================

-- Delete expired cache entries
-- DELETE FROM `api_cache` WHERE `expires_at` < NOW();

-- Delete old logs (older than 30 days)
-- DELETE FROM `system_logs` WHERE `created_at` < DATE_SUB(NOW(), INTERVAL 30 DAY);

-- Delete expired sessions
-- DELETE FROM `user_sessions` WHERE `expires_at` < NOW();
