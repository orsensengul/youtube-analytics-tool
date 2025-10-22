-- Separate Query Limits Migration
-- Created: 2025-10-23
-- Description: Separate data query limits from analysis query limits

-- Rename existing columns for data queries
ALTER TABLE users
CHANGE COLUMN query_limit_daily data_query_limit_daily INT DEFAULT 100 COMMENT 'Daily data query limit (search/channel)',
CHANGE COLUMN query_count_today data_query_count_today INT DEFAULT 0 COMMENT 'Current day data query count',
CHANGE COLUMN query_limit_monthly data_query_limit_monthly INT DEFAULT 1000 COMMENT 'Monthly data query limit',
CHANGE COLUMN query_count_month data_query_count_month INT DEFAULT 0 COMMENT 'Current month data query count';

-- Add new columns for analysis queries
ALTER TABLE users
ADD COLUMN analysis_query_limit_daily INT DEFAULT 50 COMMENT 'Daily analysis query limit',
ADD COLUMN analysis_query_count_today INT DEFAULT 0 COMMENT 'Current day analysis query count',
ADD COLUMN analysis_query_limit_monthly INT DEFAULT 500 COMMENT 'Monthly analysis query limit',
ADD COLUMN analysis_query_count_month INT DEFAULT 0 COMMENT 'Current month analysis query count',
ADD COLUMN analysis_query_reset_date DATE COMMENT 'Last reset date for daily analysis counter',
ADD COLUMN analysis_query_month_reset DATE COMMENT 'Last reset date for monthly analysis counter';

-- Set admin users to unlimited for both types
UPDATE users SET
    data_query_limit_daily = -1,
    data_query_limit_monthly = -1,
    analysis_query_limit_daily = -1,
    analysis_query_limit_monthly = -1
WHERE role = 'admin';

-- Initialize reset dates for existing users
UPDATE users SET
    analysis_query_reset_date = CURDATE(),
    analysis_query_month_reset = DATE_FORMAT(NOW(), '%Y-%m-01')
WHERE analysis_query_reset_date IS NULL;
