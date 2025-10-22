-- Monthly Limit System Migration
-- Created: 2025-10-23
-- Description: Add monthly query limits to user management system

-- Add monthly limit columns to users table
ALTER TABLE users
ADD COLUMN query_limit_monthly INT DEFAULT 1000 COMMENT 'Monthly query limit (-1 for unlimited)',
ADD COLUMN query_count_month INT DEFAULT 0 COMMENT 'Current month query count',
ADD COLUMN query_month_reset DATE COMMENT 'Last reset date for monthly counter';

-- Set admin users to unlimited monthly queries
UPDATE users SET query_limit_monthly = -1 WHERE role = 'admin';

-- Initialize monthly reset date for existing users
UPDATE users SET query_month_reset = DATE_FORMAT(NOW(), '%Y-%m-01') WHERE query_month_reset IS NULL;
