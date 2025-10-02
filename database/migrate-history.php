<?php
/**
 * Migrate JSONL history to database
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$config = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/Database.php';

Database::init($config['database']);

$jsonlFile = __DIR__ . '/../storage/history.jsonl';

if (!file_exists($jsonlFile)) {
    echo "JSONL file not found: $jsonlFile\n";
    exit;
}

echo "<h1>History Migration: JSONL to Database</h1>";
echo "<pre>";

$lines = file($jsonlFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
echo "Found " . count($lines) . " history entries in JSONL\n\n";

$imported = 0;
$skipped = 0;

foreach ($lines as $line) {
    $data = json_decode($line, true);
    if (!$data) {
        $skipped++;
        continue;
    }

    $ts = $data['ts'] ?? time();
    $query = $data['query'] ?? '';
    $meta = $data['meta'] ?? [];

    $type = $meta['type'] ?? 'keyword';
    $count = $meta['count'] ?? 0;
    $videoIds = isset($meta['ids']) && is_array($meta['ids']) ? implode(',', $meta['ids']) : null;

    try {
        Database::insert('search_history', [
            'user_id' => null, // Anonymous since we don't know which user
            'search_type' => $type,
            'query' => $query,
            'metadata' => json_encode($meta, JSON_UNESCAPED_UNICODE),
            'result_count' => $count,
            'video_ids' => $videoIds,
            'created_at' => date('Y-m-d H:i:s', $ts),
        ]);
        $imported++;
        echo "✓ Imported: $query (type: $type, count: $count)\n";
    } catch (Exception $e) {
        $skipped++;
        echo "✗ Failed: $query - " . $e->getMessage() . "\n";
    }
}

echo "\n";
echo "✓ Migration completed!\n";
echo "  Imported: $imported\n";
echo "  Skipped: $skipped\n";
echo "  Total: " . count($lines) . "\n";

// Show database content
echo "\n";
echo "Database content:\n";
$history = Database::select("SELECT * FROM search_history ORDER BY created_at DESC");
echo "Total records in database: " . count($history) . "\n";

foreach ($history as $row) {
    echo "  - {$row['created_at']} | {$row['search_type']} | {$row['query']}\n";
}

echo "</pre>";
echo "<p><a href='../index.php'>Go to Index</a></p>";
