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
        $sql = "SELECT * FROM search_history WHERE user_id = ?";
        $params = [$this->userId];

        if ($type !== 'all') {
            $sql .= " AND search_type = ?";
            $params[] = $type;
        }

        $sql .= " ORDER BY created_at DESC LIMIT ?";
        $params[] = $limit;

        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare($sql);

            // Bind all parameters as positional
            foreach ($params as $i => $value) {
                if ($i === count($params) - 1) {
                    // Last parameter is LIMIT, bind as INT
                    $stmt->bindValue($i + 1, $value, PDO::PARAM_INT);
                } else {
                    $stmt->bindValue($i + 1, $value);
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
            Database::delete('search_history', 'id = ? AND user_id = ?', [$id, $this->userId]);
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
                Database::delete('search_history', 'user_id = ?', [$this->userId]);
            }
            return true;
        } catch (Exception $e) {
            error_log("History clear error: " . $e->getMessage());
            return false;
        }
    }
}
