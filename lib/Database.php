<?php

/**
 * Database Connection Manager
 * PDO-based MySQL connection with singleton pattern
 */
class Database
{
    private static ?PDO $instance = null;
    private static array $config = [];

    /**
     * Private constructor to prevent direct instantiation
     */
    private function __construct() {}

    /**
     * Initialize database configuration
     */
    public static function init(array $config): void
    {
        self::$config = $config;
    }

    /**
     * Get singleton PDO instance
     */
    public static function getInstance(): PDO
    {
        if (self::$instance === null) {
            if (empty(self::$config)) {
                throw new RuntimeException('Database configuration not initialized. Call Database::init() first.');
            }

            $host = self::$config['host'] ?? 'localhost';
            $port = self::$config['port'] ?? 3306;
            $dbname = self::$config['database'] ?? '';
            $charset = self::$config['charset'] ?? 'utf8mb4';
            $username = self::$config['username'] ?? '';
            $password = self::$config['password'] ?? '';

            if (empty($dbname) || empty($username)) {
                throw new RuntimeException('Database name and username are required.');
            }

            $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset={$charset}";

            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_PERSISTENT => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$charset} COLLATE {$charset}_unicode_ci"
            ];

            try {
                self::$instance = new PDO($dsn, $username, $password, $options);
            } catch (PDOException $e) {
                throw new RuntimeException('Database connection failed: ' . $e->getMessage());
            }
        }

        return self::$instance;
    }

    /**
     * Execute a SELECT query
     */
    public static function select(string $sql, array $params = []): array
    {
        $pdo = self::getInstance();
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Execute a SELECT query and return single row
     */
    public static function selectOne(string $sql, array $params = []): ?array
    {
        $pdo = self::getInstance();
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Execute an INSERT query
     */
    public static function insert(string $table, array $data): int
    {
        $pdo = self::getInstance();
        $columns = array_keys($data);
        $placeholders = array_map(fn($col) => ":$col", $columns);

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', $columns),
            implode(', ', $placeholders)
        );

        $stmt = $pdo->prepare($sql);

        foreach ($data as $key => $value) {
            $stmt->bindValue(":$key", $value);
        }

        $stmt->execute();
        return (int)$pdo->lastInsertId();
    }

    /**
     * Execute an UPDATE query
     */
    public static function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $pdo = self::getInstance();
        $setParts = [];

        foreach (array_keys($data) as $col) {
            $setParts[] = "$col = :set_$col";
        }

        $sql = sprintf(
            'UPDATE %s SET %s WHERE %s',
            $table,
            implode(', ', $setParts),
            $where
        );

        $stmt = $pdo->prepare($sql);

        foreach ($data as $key => $value) {
            $stmt->bindValue(":set_$key", $value);
        }

        foreach ($whereParams as $key => $value) {
            $stmt->bindValue($key, $value);
        }

        $stmt->execute();
        return $stmt->rowCount();
    }

    /**
     * Execute a DELETE query
     */
    public static function delete(string $table, string $where, array $params = []): int
    {
        $pdo = self::getInstance();
        $sql = "DELETE FROM $table WHERE $where";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /**
     * Execute raw query
     */
    public static function query(string $sql, array $params = []): bool
    {
        $pdo = self::getInstance();
        $stmt = $pdo->prepare($sql);
        return $stmt->execute($params);
    }

    /**
     * Begin transaction
     */
    public static function beginTransaction(): void
    {
        self::getInstance()->beginTransaction();
    }

    /**
     * Commit transaction
     */
    public static function commit(): void
    {
        self::getInstance()->commit();
    }

    /**
     * Rollback transaction
     */
    public static function rollback(): void
    {
        self::getInstance()->rollBack();
    }

    /**
     * Check if currently in transaction
     */
    public static function inTransaction(): bool
    {
        return self::getInstance()->inTransaction();
    }

    /**
     * Close connection (rarely needed due to singleton pattern)
     */
    public static function disconnect(): void
    {
        self::$instance = null;
    }
}
