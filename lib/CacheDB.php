<?php

require_once __DIR__ . '/Database.php';

/**
 * MySQL-based Cache Manager
 * Replaces file-based cache with database storage
 */
class CacheDB
{
    private ?int $userId;
    private string $provider;

    public function __construct(?int $userId = null, string $provider = 'yt-api')
    {
        $this->userId = $userId;
        $this->provider = $provider;
    }

    /**
     * Generate cache key from type and parameters
     */
    private function generateCacheKey(string $type, array $params): string
    {
        ksort($params); // Ensure consistent key generation
        $paramsStr = json_encode($params, JSON_UNESCAPED_UNICODE);
        return md5($type . ':' . $this->provider . ':' . $paramsStr);
    }

    /**
     * Get cached data
     * @param string $type Cache type (search, video_info, channel_info, channel_videos)
     * @param array $params Request parameters
     * @param int $ttl Time to live in seconds
     * @return array|null Cached data or null if not found/expired
     */
    public function get(string $type, array $params, int $ttl = 21600): ?array
    {
        $cacheKey = $this->generateCacheKey($type, $params);

        $sql = "
            SELECT response_data, hit_count
            FROM api_cache
            WHERE cache_key = :cache_key
            AND cache_type = :cache_type
            AND expires_at > NOW()
            LIMIT 1
        ";

        $result = Database::selectOne($sql, [
            ':cache_key' => $cacheKey,
            ':cache_type' => $type,
        ]);

        if ($result) {
            // Update hit count and last accessed time
            Database::query(
                "UPDATE api_cache SET hit_count = hit_count + 1, last_accessed = NOW() WHERE cache_key = :cache_key",
                [':cache_key' => $cacheKey]
            );

            $data = json_decode($result['response_data'], true);
            return is_array($data) ? $data : null;
        }

        return null;
    }

    /**
     * Set cache data
     * @param string $type Cache type
     * @param array $params Request parameters
     * @param array $data Data to cache
     * @param int $ttl Time to live in seconds
     * @return bool Success
     */
    public function set(string $type, array $params, array $data, int $ttl = 21600): bool
    {
        $cacheKey = $this->generateCacheKey($type, $params);

        try {
            // Check if cache key exists
            $existing = Database::selectOne(
                "SELECT id FROM api_cache WHERE cache_key = :cache_key",
                [':cache_key' => $cacheKey]
            );

            if ($existing) {
                // Update existing cache
                Database::update(
                    'api_cache',
                    [
                        'response_data' => json_encode($data, JSON_UNESCAPED_UNICODE),
                        'ttl_seconds' => $ttl,
                        'created_at' => date('Y-m-d H:i:s'),
                        'last_accessed' => date('Y-m-d H:i:s'),
                        'hit_count' => 0,
                    ],
                    'cache_key = :cache_key',
                    [':cache_key' => $cacheKey]
                );
            } else {
                // Insert new cache
                Database::insert('api_cache', [
                    'user_id' => $this->userId,
                    'cache_key' => $cacheKey,
                    'cache_type' => $type,
                    'provider' => $this->provider,
                    'request_params' => json_encode($params, JSON_UNESCAPED_UNICODE),
                    'response_data' => json_encode($data, JSON_UNESCAPED_UNICODE),
                    'ttl_seconds' => $ttl,
                    'hit_count' => 0,
                ]);
            }

            return true;
        } catch (Exception $e) {
            error_log("Cache set error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete cache entry
     */
    public function delete(string $type, array $params): bool
    {
        $cacheKey = $this->generateCacheKey($type, $params);

        try {
            Database::delete('api_cache', 'cache_key = :cache_key', [':cache_key' => $cacheKey]);
            return true;
        } catch (Exception $e) {
            error_log("Cache delete error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Clear all expired cache entries
     */
    public static function cleanupExpired(): int
    {
        try {
            return Database::delete('api_cache', 'expires_at < NOW()');
        } catch (Exception $e) {
            error_log("Cache cleanup error: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Clear all cache for a specific user
     */
    public static function clearUserCache(int $userId): int
    {
        try {
            return Database::delete('api_cache', 'user_id = :user_id', [':user_id' => $userId]);
        } catch (Exception $e) {
            error_log("User cache clear error: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Clear all cache entries
     */
    public static function clearAll(): int
    {
        try {
            return Database::delete('api_cache', '1=1');
        } catch (Exception $e) {
            error_log("Cache clear all error: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Get cache statistics
     */
    public static function getStats(): array
    {
        try {
            $total = Database::selectOne("SELECT COUNT(*) as count FROM api_cache");
            $expired = Database::selectOne("SELECT COUNT(*) as count FROM api_cache WHERE expires_at < NOW()");
            $byType = Database::select("
                SELECT cache_type, COUNT(*) as count, SUM(hit_count) as total_hits
                FROM api_cache
                GROUP BY cache_type
            ");
            $topHits = Database::select("
                SELECT cache_key, cache_type, hit_count, created_at
                FROM api_cache
                ORDER BY hit_count DESC
                LIMIT 10
            ");

            return [
                'total_entries' => (int)($total['count'] ?? 0),
                'expired_entries' => (int)($expired['count'] ?? 0),
                'by_type' => $byType,
                'top_hits' => $topHits,
            ];
        } catch (Exception $e) {
            error_log("Cache stats error: " . $e->getMessage());
            return [];
        }
    }
}
