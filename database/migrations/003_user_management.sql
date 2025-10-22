-- User Management System Migration
-- Created: 2025-10-23
-- Description: Add user management features including query limits, license management, and activity logging

-- Add new columns to users table
ALTER TABLE users
ADD COLUMN query_limit_daily INT DEFAULT 100 COMMENT 'Daily search/channel query limit (-1 for unlimited)',
ADD COLUMN query_count_today INT DEFAULT 0 COMMENT 'Current day query count',
ADD COLUMN query_reset_date DATE COMMENT 'Last reset date for query counter',
ADD COLUMN license_expires_at TIMESTAMP NULL COMMENT 'License expiration date (NULL for unlimited)',
ADD COLUMN notes TEXT NULL COMMENT 'Admin notes about user';

-- Create user_activity_log table
CREATE TABLE IF NOT EXISTS user_activity_log (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    action_type VARCHAR(50) NOT NULL COMMENT 'login, logout, query_search, query_channel, password_change, etc.',
    details TEXT NULL COMMENT 'Additional details in JSON format',
    ip_address VARCHAR(45) NULL COMMENT 'IPv4 or IPv6 address',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id),
    INDEX idx_action_type (action_type),
    INDEX idx_created_at (created_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Set admin users to unlimited queries
UPDATE users SET query_limit_daily = -1 WHERE role = 'admin';

-- Initialize query_reset_date for existing users
UPDATE users SET query_reset_date = CURDATE() WHERE query_reset_date IS NULL;
