<?php

require_once __DIR__ . '/Database.php';

/**
 * History Manager - Database based
 */
class History
{
    private ?int $userId;

    public function __construct(?int $userId = null)
    {
        $this->userId = $userId;
    }

    /**
     * Add search to history
     */
    public function addSearch(string $query, array $meta = []): void
    {
        $type = $meta['type'] ?? 'keyword';
        $count = $meta['count'] ?? 0;
        $videoIds = isset($meta['ids']) && is_array($meta['ids']) ? implode(',', $meta['ids']) : null;

        try {
            Database::insert('search_history', [
                'user_id' => $this->userId,
                'search_type' => $type,
                'query' => $query,
                'metadata' => json_encode($meta, JSON_UNESCAPED_UNICODE),
                'result_count' => $count,
                'video_ids' => $videoIds,
            ]);
        } catch (Exception $e) {
            error_log("History insert error: " . $e->getMessage());
        }
    }

    /**
     * Get all history (optionally filtered by type)
     */
    public function getHistory(string $type = 'all', int $limit = 100): array
    {
        $sql = "SELECT * FROM search_history WHERE 1=1";
        $params = [];

        if ($this->userId !== null) {
            $sql .= " AND (user_id = :user_id OR user_id IS NULL)";
            $params[':user_id'] = $this->userId;
        }

        if ($type !== 'all') {
            $sql .= " AND search_type = :type";
            $params[':type'] = $type;
        }

        $sql .= " ORDER BY created_at DESC LIMIT :limit";
        $params[':limit'] = $limit;

        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare($sql);

            foreach ($params as $key => $value) {
                if ($key === ':limit') {
                    $stmt->bindValue($key, $value, PDO::PARAM_INT);
                } else {
                    $stmt->bindValue($key, $value);
                }
            }

            $stmt->execute();
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log("History fetch error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Delete history entry
     */
    public function delete(int $id): bool
    {
        try {
            Database::delete('search_history', 'id = :id', [':id' => $id]);
            return true;
        } catch (Exception $e) {
            error_log("History delete error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Clear all history for user
     */
    public function clearAll(): bool
    {
        try {
            if ($this->userId !== null) {
                Database::delete('search_history', 'user_id = :user_id', [':user_id' => $this->userId]);
            } else {
                Database::delete('search_history', '1=1');
            }
            return true;
        } catch (Exception $e) {
            error_log("History clear error: " . $e->getMessage());
            return false;
        }
    }
}
