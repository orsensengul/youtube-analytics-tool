<?php

require_once __DIR__ . '/Database.php';

/**
 * User Management Class
 * Handles user CRUD, separate query limits (data/analysis), license management, and activity logging
 */
class UserManager
{
    /**
     * Get all users with optional filters
     */
    public static function getAllUsers(array $filters = []): array
    {
        $sql = "SELECT id, username, email, full_name, role, is_active,
                       data_query_limit_daily, data_query_count_today,
                       analysis_query_limit_daily, analysis_query_count_today,
                       license_expires_at, created_at, last_login
                FROM users WHERE 1=1";
        $params = [];

        if (!empty($filters['role'])) {
            $sql .= " AND role = ?";
            $params[] = $filters['role'];
        }

        if (isset($filters['is_active'])) {
            $sql .= " AND is_active = ?";
            $params[] = (int)$filters['is_active'];
        }

        if (!empty($filters['license_status'])) {
            if ($filters['license_status'] === 'expired') {
                $sql .= " AND license_expires_at IS NOT NULL AND license_expires_at < NOW()";
            } elseif ($filters['license_status'] === 'expiring_soon') {
                $sql .= " AND license_expires_at IS NOT NULL AND license_expires_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)";
            } elseif ($filters['license_status'] === 'active') {
                $sql .= " AND (license_expires_at IS NULL OR license_expires_at > NOW())";
            }
        }

        if (!empty($filters['search'])) {
            $sql .= " AND (username LIKE ? OR email LIKE ?)";
            $searchTerm = '%' . $filters['search'] . '%';
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }

        $sql .= " ORDER BY created_at DESC";

        if (!empty($filters['limit'])) {
            $sql .= " LIMIT ?";
            $params[] = (int)$filters['limit'];
            if (!empty($filters['offset'])) {
                $sql .= " OFFSET ?";
                $params[] = (int)$filters['offset'];
            }
        }

        return Database::select($sql, $params);
    }

    public static function getUserById($userId): ?array
    {
        $userId = (int)$userId; // Ensure integer
        $user = Database::selectOne("SELECT * FROM users WHERE id = ?", [$userId]);
        return $user ?: null;
    }

    public static function createUser(array $data): array
    {
        if (empty($data['username']) || empty($data['email']) || empty($data['password'])) {
            return ['success' => false, 'error' => 'Kullanıcı adı, e-posta ve şifre gereklidir.'];
        }

        $existing = Database::selectOne("SELECT id FROM users WHERE username = ?", [$data['username']]);
        if ($existing) return ['success' => false, 'error' => 'Bu kullanıcı adı zaten kullanılıyor.'];

        $existing = Database::selectOne("SELECT id FROM users WHERE email = ?", [$data['email']]);
        if ($existing) return ['success' => false, 'error' => 'Bu e-posta adresi zaten kullanılıyor.'];

        $userData = [
            'username' => $data['username'],
            'email' => $data['email'],
            'password_hash' => password_hash($data['password'], PASSWORD_DEFAULT),
            'full_name' => $data['full_name'] ?? '',
            'role' => $data['role'] ?? 'user',
            'is_active' => $data['is_active'] ?? 1,
            'data_query_limit_daily' => $data['data_query_limit_daily'] ?? 100,
            'data_query_limit_monthly' => $data['data_query_limit_monthly'] ?? 1000,
            'analysis_query_limit_daily' => $data['analysis_query_limit_daily'] ?? 50,
            'analysis_query_limit_monthly' => $data['analysis_query_limit_monthly'] ?? 500,
            'query_reset_date' => date('Y-m-d'),
            'query_month_reset' => date('Y-m-01'),
            'analysis_query_reset_date' => date('Y-m-d'),
            'analysis_query_month_reset' => date('Y-m-01'),
            'license_expires_at' => $data['license_expires_at'] ?? null,
            'notes' => $data['notes'] ?? null,
        ];

        if ($userData['role'] === 'admin') {
            $userData['data_query_limit_daily'] = -1;
            $userData['data_query_limit_monthly'] = -1;
            $userData['analysis_query_limit_daily'] = -1;
            $userData['analysis_query_limit_monthly'] = -1;
        }

        try {
            $userId = Database::insert('users', $userData);
            self::logActivity($userId, 'user_created', ['created_by' => $data['created_by'] ?? null]);
            return ['success' => true, 'user_id' => $userId];
        } catch (Exception $e) {
            return ['success' => false, 'error' => 'Kullanıcı oluşturulamadı: ' . $e->getMessage()];
        }
    }

    public static function updateUser(int $userId, array $data): bool
    {
        $updateData = [];
        $allowedFields = ['username', 'email', 'full_name', 'role', 'is_active',
                          'data_query_limit_daily', 'data_query_limit_monthly',
                          'analysis_query_limit_daily', 'analysis_query_limit_monthly',
                          'license_expires_at', 'notes'];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $updateData[$field] = $data[$field];
            }
        }

        if (!empty($data['password'])) {
            $updateData['password_hash'] = password_hash($data['password'], PASSWORD_DEFAULT);
        }

        if (isset($updateData['role']) && $updateData['role'] === 'admin') {
            $updateData['data_query_limit_daily'] = -1;
            $updateData['data_query_limit_monthly'] = -1;
            $updateData['analysis_query_limit_daily'] = -1;
            $updateData['analysis_query_limit_monthly'] = -1;
        }

        if (empty($updateData)) return false;

        try {
            Database::update('users', $updateData, 'id = ?', [$userId]);
            self::logActivity($userId, 'user_updated', ['fields' => array_keys($updateData)]);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    public static function deleteUser($userId): bool
    {
        $userId = (int)$userId; // Ensure integer
        try {
            Database::delete('users', 'id = ?', [$userId]);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    // ==================== DATA QUERY LIMITS ====================

    public static function checkDataQueryLimit($userId): bool
    {
        $userId = (int)$userId; // Ensure integer
        $user = self::getUserById($userId);
        if (!$user) return false;

        if ($user['role'] === 'admin' || $user['data_query_limit_daily'] === -1) {
            return true;
        }

        self::resetDataQueryCountIfNeeded($userId);
        $user = self::getUserById($userId);

        // Check daily limit
        if ($user['data_query_count_today'] >= $user['data_query_limit_daily']) {
            return false;
        }

        // Check monthly limit
        if ($user['data_query_count_month'] >= $user['data_query_limit_monthly']) {
            return false;
        }

        return true;
    }

    public static function incrementDataQueryCount($userId): void
    {
        $userId = (int)$userId; // Ensure integer
        $user = self::getUserById($userId);
        if (!$user) return;

        if ($user['role'] === 'admin' || $user['data_query_limit_daily'] === -1) {
            return;
        }

        Database::query(
            "UPDATE users SET data_query_count_today = data_query_count_today + 1,
                              data_query_count_month = data_query_count_month + 1
             WHERE id = ?",
            [$userId]
        );
    }

    public static function resetDataQueryCountIfNeeded($userId): void
    {
        $userId = (int)$userId; // Ensure integer
        $user = self::getUserById($userId);
        if (!$user) return;

        $today = date('Y-m-d');
        $thisMonth = date('Y-m-01');
        $updates = [];

        // Reset daily if date changed
        if ($user['query_reset_date'] !== $today) {
            $updates['data_query_count_today'] = 0;
            $updates['query_reset_date'] = $today;
        }

        // Reset monthly if month changed
        if ($user['query_month_reset'] !== $thisMonth) {
            $updates['data_query_count_month'] = 0;
            $updates['query_month_reset'] = $thisMonth;
        }

        if (!empty($updates)) {
            Database::update('users', $updates, 'id = ?', [$userId]);
        }
    }

    public static function getRemainingDataQueries($userId): array
    {
        $userId = (int)$userId; // Ensure integer
        $user = self::getUserById($userId);
        if (!$user) return ['daily' => 0, 'monthly' => 0];

        if ($user['role'] === 'admin' || $user['data_query_limit_daily'] === -1) {
            return ['daily' => -1, 'monthly' => -1];
        }

        self::resetDataQueryCountIfNeeded($userId);
        $user = self::getUserById($userId);

        return [
            'daily' => max(0, $user['data_query_limit_daily'] - $user['data_query_count_today']),
            'monthly' => max(0, $user['data_query_limit_monthly'] - $user['data_query_count_month'])
        ];
    }

    // ==================== ANALYSIS QUERY LIMITS ====================

    public static function checkAnalysisQueryLimit($userId): bool
    {
        $userId = (int)$userId; // Ensure integer
        $user = self::getUserById($userId);
        if (!$user) return false;

        if ($user['role'] === 'admin' || $user['analysis_query_limit_daily'] === -1) {
            return true;
        }

        self::resetAnalysisQueryCountIfNeeded($userId);
        $user = self::getUserById($userId);

        // Check daily limit
        if ($user['analysis_query_count_today'] >= $user['analysis_query_limit_daily']) {
            return false;
        }

        // Check monthly limit
        if ($user['analysis_query_count_month'] >= $user['analysis_query_limit_monthly']) {
            return false;
        }

        return true;
    }

    public static function incrementAnalysisQueryCount($userId): void
    {
        $userId = (int)$userId; // Ensure integer
        $user = self::getUserById($userId);
        if (!$user) return;

        if ($user['role'] === 'admin' || $user['analysis_query_limit_daily'] === -1) {
            return;
        }

        Database::query(
            "UPDATE users SET analysis_query_count_today = analysis_query_count_today + 1,
                              analysis_query_count_month = analysis_query_count_month + 1
             WHERE id = ?",
            [$userId]
        );
    }

    public static function resetAnalysisQueryCountIfNeeded($userId): void
    {
        $userId = (int)$userId; // Ensure integer
        $user = self::getUserById($userId);
        if (!$user) return;

        $today = date('Y-m-d');
        $thisMonth = date('Y-m-01');
        $updates = [];

        // Reset daily if date changed
        if ($user['analysis_query_reset_date'] !== $today) {
            $updates['analysis_query_count_today'] = 0;
            $updates['analysis_query_reset_date'] = $today;
        }

        // Reset monthly if month changed
        if ($user['analysis_query_month_reset'] !== $thisMonth) {
            $updates['analysis_query_count_month'] = 0;
            $updates['analysis_query_month_reset'] = $thisMonth;
        }

        if (!empty($updates)) {
            Database::update('users', $updates, 'id = ?', [$userId]);
        }
    }

    public static function getRemainingAnalysisQueries($userId): array
    {
        $userId = (int)$userId; // Ensure integer
        $user = self::getUserById($userId);
        if (!$user) return ['daily' => 0, 'monthly' => 0];

        if ($user['role'] === 'admin' || $user['analysis_query_limit_daily'] === -1) {
            return ['daily' => -1, 'monthly' => -1];
        }

        self::resetAnalysisQueryCountIfNeeded($userId);
        $user = self::getUserById($userId);

        return [
            'daily' => max(0, $user['analysis_query_limit_daily'] - $user['analysis_query_count_today']),
            'monthly' => max(0, $user['analysis_query_limit_monthly'] - $user['analysis_query_count_month'])
        ];
    }

    // ==================== LICENSE & ACTIVITY ====================

    public static function isLicenseValid($userId): bool
    {
        $userId = (int)$userId; // Ensure integer
        $user = self::getUserById($userId);
        if (!$user) return false;

        if ($user['role'] === 'admin' || $user['license_expires_at'] === null) {
            return true;
        }

        return strtotime($user['license_expires_at']) > time();
    }

    public static function getLicenseInfo($userId): array
    {
        $userId = (int)$userId; // Ensure integer
        $user = self::getUserById($userId);
        if (!$user) {
            return ['status' => 'unknown', 'days_remaining' => 0];
        }

        if ($user['role'] === 'admin' || $user['license_expires_at'] === null) {
            return ['status' => 'unlimited', 'days_remaining' => -1, 'expires_at' => null];
        }

        $expiresAt = strtotime($user['license_expires_at']);
        $now = time();
        $daysRemaining = (int)ceil(($expiresAt - $now) / 86400);

        if ($daysRemaining < 0) {
            $status = 'expired';
        } elseif ($daysRemaining <= 7) {
            $status = 'expiring_soon';
        } else {
            $status = 'active';
        }

        return [
            'status' => $status,
            'days_remaining' => max(0, $daysRemaining),
            'expires_at' => $user['license_expires_at']
        ];
    }

    public static function extendLicense(int $userId, string $expiresAt): bool
    {
        try {
            Database::update('users', ['license_expires_at' => $expiresAt], 'id = ?', [$userId]);
            self::logActivity($userId, 'license_extended', ['expires_at' => $expiresAt]);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    public static function logActivity(int $userId, string $actionType, ?array $details = null): void
    {
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
        Database::insert('user_activity_log', [
            'user_id' => $userId,
            'action_type' => $actionType,
            'details' => $details ? json_encode($details) : null,
            'ip_address' => $ipAddress
        ]);
    }

    public static function getUserActivity(int $userId, array $filters = []): array
    {
        $sql = "SELECT * FROM user_activity_log WHERE user_id = ?";
        $params = [$userId];

        if (!empty($filters['action_type'])) {
            $sql .= " AND action_type = ?";
            $params[] = $filters['action_type'];
        }

        if (!empty($filters['date_from'])) {
            $sql .= " AND created_at >= ?";
            $params[] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $sql .= " AND created_at <= ?";
            $params[] = $filters['date_to'];
        }

        $sql .= " ORDER BY created_at DESC";

        if (!empty($filters['limit'])) {
            $sql .= " LIMIT ?";
            $params[] = (int)$filters['limit'];
        }

        return Database::select($sql, $params);
    }

    public static function getUserStats($userId): array
    {
        $userId = (int)$userId; // Ensure integer
        $user = self::getUserById($userId);
        if (!$user) return [];

        // Count data queries
        $totalDataQueries = Database::selectOne(
            "SELECT COUNT(*) as count FROM user_activity_log
             WHERE user_id = ? AND action_type IN ('query_search', 'query_channel')",
            [$userId]
        );

        // Count analysis queries
        $totalAnalysisQueries = Database::selectOne(
            "SELECT COUNT(*) as count FROM user_activity_log
             WHERE user_id = ? AND action_type = 'analysis_query'",
            [$userId]
        );

        $dataRemaining = self::getRemainingDataQueries($userId);
        $analysisRemaining = self::getRemainingAnalysisQueries($userId);

        return [
            'data_queries_today' => $user['data_query_count_today'],
            'data_queries_month' => $user['data_query_count_month'],
            'data_queries_total' => $totalDataQueries['count'] ?? 0,
            'data_query_limit_daily' => $user['data_query_limit_daily'],
            'data_query_limit_monthly' => $user['data_query_limit_monthly'],
            'data_remaining_daily' => $dataRemaining['daily'],
            'data_remaining_monthly' => $dataRemaining['monthly'],

            'analysis_queries_today' => $user['analysis_query_count_today'],
            'analysis_queries_month' => $user['analysis_query_count_month'],
            'analysis_queries_total' => $totalAnalysisQueries['count'] ?? 0,
            'analysis_query_limit_daily' => $user['analysis_query_limit_daily'],
            'analysis_query_limit_monthly' => $user['analysis_query_limit_monthly'],
            'analysis_remaining_daily' => $analysisRemaining['daily'],
            'analysis_remaining_monthly' => $analysisRemaining['monthly'],

            'license_info' => self::getLicenseInfo($userId),
            'last_login' => $user['last_login'],
            'account_age_days' => (int)ceil((time() - strtotime($user['created_at'])) / 86400)
        ];
    }

    public static function getSystemStats(): array
    {
        $totalUsers = Database::selectOne("SELECT COUNT(*) as count FROM users");
        $activeUsers = Database::selectOne("SELECT COUNT(*) as count FROM users WHERE is_active = 1");
        $adminUsers = Database::selectOne("SELECT COUNT(*) as count FROM users WHERE role = 'admin'");

        $expiredLicenses = Database::selectOne(
            "SELECT COUNT(*) as count FROM users
             WHERE license_expires_at IS NOT NULL AND license_expires_at < NOW()"
        );

        $expiringSoon = Database::selectOne(
            "SELECT COUNT(*) as count FROM users
             WHERE license_expires_at IS NOT NULL
             AND license_expires_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)"
        );

        return [
            'total_users' => $totalUsers['count'] ?? 0,
            'active_users' => $activeUsers['count'] ?? 0,
            'admin_users' => $adminUsers['count'] ?? 0,
            'expired_licenses' => $expiredLicenses['count'] ?? 0,
            'expiring_soon' => $expiringSoon['count'] ?? 0
        ];
    }
}
