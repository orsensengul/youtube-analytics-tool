<?php

require_once __DIR__ . '/Database.php';

/**
 * Authentication and User Management
 */
class Auth
{
    /**
     * Start session if not already started
     */
    public static function startSession(array $config = []): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            if (!empty($config['name'])) {
                session_name($config['name']);
            }
            if (!empty($config['lifetime'])) {
                session_set_cookie_params([
                    'lifetime' => $config['lifetime'],
                    'path' => '/',
                    'secure' => $config['cookie_secure'] ?? false,
                    'httponly' => $config['cookie_httponly'] ?? true,
                    'samesite' => 'Lax'
                ]);
            }
            session_start();
        }
    }

    /**
     * Register new user
     */
    public static function register(string $username, string $email, string $password, string $fullName = ''): array
    {
        // Validation
        if (strlen($username) < 3 || strlen($username) > 50) {
            return ['success' => false, 'error' => 'Kullanıcı adı 3-50 karakter olmalıdır.'];
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'error' => 'Geçersiz e-posta adresi.'];
        }

        if (strlen($password) < 6) {
            return ['success' => false, 'error' => 'Şifre en az 6 karakter olmalıdır.'];
        }

        // Check if username or email exists
        $existing = Database::selectOne(
            "SELECT id FROM users WHERE username = :username OR email = :email",
            [':username' => $username, ':email' => $email]
        );

        if ($existing) {
            return ['success' => false, 'error' => 'Kullanıcı adı veya e-posta zaten kullanılıyor.'];
        }

        // Hash password
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        try {
            $userId = Database::insert('users', [
                'username' => $username,
                'email' => $email,
                'password_hash' => $passwordHash,
                'full_name' => $fullName,
                'role' => 'user',
                'is_active' => 1,
            ]);

            return ['success' => true, 'user_id' => $userId];
        } catch (Exception $e) {
            return ['success' => false, 'error' => 'Kayıt sırasında hata oluştu: ' . $e->getMessage()];
        }
    }

    /**
     * Login user
     */
    public static function login(string $usernameOrEmail, string $password): array
    {
        $user = Database::selectOne(
            "SELECT * FROM users WHERE (username = :username OR email = :email) AND is_active = 1",
            [':username' => $usernameOrEmail, ':email' => $usernameOrEmail]
        );

        if (!$user) {
            return ['success' => false, 'error' => 'Kullanıcı bulunamadı veya aktif değil.'];
        }

        if (!password_verify($password, $user['password_hash'])) {
            return ['success' => false, 'error' => 'Hatalı şifre.'];
        }

        // Update last login
        Database::update('users', ['last_login' => date('Y-m-d H:i:s')], 'id = ?', [$user['id']]);

        // Set session
        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['logged_in'] = true;
        $_SESSION['login_time'] = time();

        // Create session token
        $sessionToken = bin2hex(random_bytes(32));
        $_SESSION['session_token'] = $sessionToken;

        // Store session in database
        try {
            Database::insert('user_sessions', [
                'user_id' => $user['id'],
                'session_token' => $sessionToken,
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                'expires_at' => date('Y-m-d H:i:s', time() + 86400), // 24 hours
            ]);
        } catch (Exception $e) {
            // Log but don't fail login
            error_log("Session storage error: " . $e->getMessage());
        }

        return ['success' => true, 'user' => [
            'id' => (int)$user['id'],
            'username' => $user['username'],
            'email' => $user['email'],
            'full_name' => $user['full_name'],
            'role' => $user['role'],
        ]];
    }

    /**
     * Logout user
     */
    public static function logout(): void
    {
        if (isset($_SESSION['session_token'])) {
            try {
                Database::delete('user_sessions', 'session_token = :token', [':token' => $_SESSION['session_token']]);
            } catch (Exception $e) {
                error_log("Session delete error: " . $e->getMessage());
            }
        }

        $_SESSION = [];
        session_destroy();
    }

    /**
     * Check if user is logged in
     */
    public static function check(): bool
    {
        return isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true && isset($_SESSION['user_id']);
    }

    /**
     * Get current user ID
     */
    public static function userId(): ?int
    {
        return isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
    }

    /**
     * Get current user data
     */
    public static function user(): ?array
    {
        if (!self::check()) {
            return null;
        }

        $user = Database::selectOne("SELECT * FROM users WHERE id = :id", [':id' => self::userId()]);
        return $user ?: null;
    }

    /**
     * Check if user has role
     */
    public static function hasRole(string $role): bool
    {
        return isset($_SESSION['role']) && $_SESSION['role'] === $role;
    }

    /**
     * Require authentication (redirect if not logged in)
     */
    public static function requireLogin(string $redirectTo = 'login.php'): void
    {
        if (!self::check()) {
            header("Location: $redirectTo");
            exit;
        }
    }

    /**
     * Check if current user is admin
     */
    public static function isAdmin(): bool
    {
        return self::check() && isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
    }

    /**
     * Require admin role
     */
    public static function requireAdmin(string $redirectTo = '../index.php'): void
    {
        self::requireLogin();
        if (!self::hasRole('admin')) {
            header("Location: $redirectTo");
            exit;
        }
    }

    /**
     * Update user API keys (encrypted)
     */
    public static function updateApiKeys(int $userId, ?string $rapidApiKey = null, ?string $aiApiKey = null): bool
    {
        $updates = [];

        if ($rapidApiKey !== null) {
            $updates['api_key_rapidapi'] = self::encryptApiKey($rapidApiKey);
        }

        if ($aiApiKey !== null) {
            $updates['api_key_ai'] = self::encryptApiKey($aiApiKey);
        }

        if (empty($updates)) {
            return false;
        }

        try {
            Database::update('users', $updates, 'id = :id', [':id' => $userId]);
            return true;
        } catch (Exception $e) {
            error_log("API key update error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get user API keys (decrypted)
     */
    public static function getApiKeys(int $userId): array
    {
        $user = Database::selectOne(
            "SELECT api_key_rapidapi, api_key_ai FROM users WHERE id = :id",
            [':id' => $userId]
        );

        if (!$user) {
            return ['rapidapi' => null, 'ai' => null];
        }

        return [
            'rapidapi' => $user['api_key_rapidapi'] ? self::decryptApiKey($user['api_key_rapidapi']) : null,
            'ai' => $user['api_key_ai'] ? self::decryptApiKey($user['api_key_ai']) : null,
        ];
    }

    /**
     * Simple encryption for API keys (use proper encryption in production)
     */
    private static function encryptApiKey(string $key): string
    {
        // In production, use stronger encryption like sodium_crypto_secretbox
        return base64_encode($key);
    }

    /**
     * Simple decryption for API keys
     */
    private static function decryptApiKey(string $encrypted): string
    {
        return base64_decode($encrypted);
    }

    /**
     * Clean up expired sessions
     */
    public static function cleanupExpiredSessions(): int
    {
        try {
            return Database::delete('user_sessions', 'expires_at < NOW()');
        } catch (Exception $e) {
            error_log("Session cleanup error: " . $e->getMessage());
            return 0;
        }
    }
}
