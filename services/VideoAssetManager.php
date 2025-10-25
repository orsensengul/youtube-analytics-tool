<?php

require_once __DIR__ . '/../lib/Database.php';

class VideoAssetManager
{
    public static function ensureVideo(string $videoId, array $meta = []): void
    {
        $row = Database::selectOne("SELECT id FROM video_metadata WHERE video_id = ?", [$videoId]);
        if ($row) return;
        $data = [
            'video_id' => $videoId,
            'title' => $meta['title'] ?? null,
            'description' => $meta['description'] ?? null,
            'channel_id' => $meta['channel_id'] ?? null,
            'channel_title' => $meta['channel_title'] ?? null,
            'published_at' => self::normalizeDate($meta['published_at'] ?? null),
            'tags' => isset($meta['tags']) ? json_encode($meta['tags'], JSON_UNESCAPED_UNICODE) : null,
            'thumbnails' => isset($meta['thumbnails']) ? json_encode($meta['thumbnails'], JSON_UNESCAPED_UNICODE) : null,
            'raw_data' => isset($meta['raw']) ? json_encode($meta['raw']) : null,
        ];
        Database::insert('video_metadata', array_filter($data, fn($v) => $v !== null));
    }

    public static function saveThumbPath(string $videoId, string $path): void
    {
        self::ensureLocalAssetsColumn();
        $row = Database::selectOne("SELECT local_assets FROM video_metadata WHERE video_id = ?", [$videoId]);
        $assets = [];
        if ($row && !empty($row['local_assets'])) {
            $assets = json_decode($row['local_assets'], true);
            if (!is_array($assets)) $assets = [];
        }
        $assets['thumbnail'] = $path;
        Database::update('video_metadata', ['local_assets' => json_encode($assets, JSON_UNESCAPED_UNICODE)], 'video_id = ?', [$videoId]);
    }

    public static function saveTranscriptPaths(string $videoId, string $lang, ?string $jsonPath, ?string $txtPath, ?string $provider = null): void
    {
        $row = Database::selectOne("SELECT id FROM video_transcripts WHERE video_id = ? AND lang = ?", [$videoId, $lang]);
        if ($row) {
            Database::update('video_transcripts', [
                'provider' => $provider,
                'file_json_path' => $jsonPath,
                'file_txt_path' => $txtPath,
            ], 'video_id = ? AND lang = ?', [$videoId, $lang]);
        } else {
            Database::insert('video_transcripts', [
                'video_id' => $videoId,
                'lang' => $lang,
                'provider' => $provider,
                'file_json_path' => $jsonPath,
                'file_txt_path' => $txtPath,
            ]);
        }
    }

    private static function ensureLocalAssetsColumn(): void
    {
        try {
            $col = Database::selectOne("SHOW COLUMNS FROM video_metadata LIKE ?", ['local_assets']);
            if (!$col) {
                Database::query("ALTER TABLE video_metadata ADD COLUMN local_assets JSON NULL");
            }
        } catch (Exception $e) {
            // Best effort; ignore if cannot alter
        }
    }

    private static function normalizeDate($v): ?string
    {
        if (!$v) return null;
        // Numeric timestamp
        if (is_numeric($v)) {
            $ts = (int)$v;
            return date('Y-m-d H:i:s', $ts);
        }
        if (is_string($v)) {
            // Handle ISO8601 with timezone: 2024-03-08T08:00:01-08:00 or 2024-03-08T08:00:01Z
            try {
                $dt = new DateTime($v);
                return $dt->format('Y-m-d H:i:s');
            } catch (Exception $e) {
                // Try common replacements
                $s = str_replace('T', ' ', $v);
                $s = preg_replace('/Z$/', '', $s);
                // Remove timezone offset like +03:00 or -08:00
                $s = preg_replace('/[\+\-]\d{2}:?\d{2}$/', '', $s);
                if (strtotime($s) !== false) return date('Y-m-d H:i:s', strtotime($s));
            }
        }
        return null;
    }
}
