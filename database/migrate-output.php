<?php
/**
 * Migrate output folder data to database
 * - Video JSON files -> video_metadata table
 * - Analysis MD files -> analysis_results table
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$config = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/Database.php';

Database::init($config['database']);

echo "<h1>Output Folder Migration</h1>";
echo "<pre>";

$outputDir = __DIR__ . '/../output';

if (!is_dir($outputDir)) {
    echo "❌ Output directory not found: $outputDir\n";
    exit;
}

// Get all channel directories
$channelDirs = glob($outputDir . '/channel_*', GLOB_ONLYDIR);
echo "Found " . count($channelDirs) . " channel directories\n\n";

$stats = [
    'videos_imported' => 0,
    'videos_skipped' => 0,
    'videos_updated' => 0,
    'analyses_imported' => 0,
    'analyses_skipped' => 0,
];

foreach ($channelDirs as $channelDir) {
    $channelName = basename($channelDir);
    echo "📁 Processing: $channelName\n";
    echo str_repeat('-', 80) . "\n";

    // Extract channel ID from directory name
    preg_match('/channel_(.+)$/', $channelName, $matches);
    $channelId = $matches[1] ?? null;

    // Process JSON files (video data)
    $jsonFiles = glob($channelDir . '/*.json');
    echo "  Found " . count($jsonFiles) . " JSON files\n";

    foreach ($jsonFiles as $jsonFile) {
        echo "  Processing: " . basename($jsonFile) . "\n";

        $content = file_get_contents($jsonFile);
        $data = json_decode($content, true);

        if (!$data || !isset($data['items'])) {
            echo "    ⚠️  Invalid JSON structure, skipped\n";
            $stats['videos_skipped']++;
            continue;
        }

        $items = $data['items'];
        echo "    Found " . count($items) . " videos\n";

        foreach ($items as $item) {
            if (!isset($item['id']) || $item['type'] !== 'video') {
                continue;
            }

            $videoId = $item['id'];
            $title = $item['title'] ?? '';
            $description = $item['description'] ?? '';
            $channelIdFromItem = $item['channel']['id'] ?? $channelId;
            $channelTitle = $item['channel']['title'] ?? '';
            $publishedAt = isset($item['published']['iso'])
                ? date('Y-m-d H:i:s', strtotime($item['published']['iso']))
                : null;
            $viewCount = $item['metrics']['views'] ?? 0;
            $likeCount = $item['metrics']['likes'] ?? 0;
            $tags = isset($item['tags']) && is_array($item['tags'])
                ? json_encode($item['tags'], JSON_UNESCAPED_UNICODE)
                : null;
            $rawData = json_encode($item, JSON_UNESCAPED_UNICODE);

            try {
                // Use INSERT ON DUPLICATE KEY UPDATE
                $pdo = Database::getInstance();
                $sql = "
                    INSERT INTO video_metadata
                    (video_id, title, description, channel_id, channel_title,
                     published_at, view_count, like_count, tags, raw_data)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        title = VALUES(title),
                        description = VALUES(description),
                        view_count = VALUES(view_count),
                        like_count = VALUES(like_count),
                        tags = VALUES(tags),
                        raw_data = VALUES(raw_data),
                        updated_at = CURRENT_TIMESTAMP
                ";

                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    $videoId, $title, $description, $channelIdFromItem, $channelTitle,
                    $publishedAt, $viewCount, $likeCount, $tags, $rawData
                ]);

                if ($stmt->rowCount() === 1) {
                    $stats['videos_imported']++;
                } elseif ($stmt->rowCount() === 2) {
                    $stats['videos_updated']++;
                }
            } catch (Exception $e) {
                echo "    ❌ Failed to save video $videoId: " . $e->getMessage() . "\n";
                $stats['videos_skipped']++;
            }
        }
    }

    // Process Markdown files (analysis results)
    $mdFiles = glob($channelDir . '/analysis_*.md');
    echo "  Found " . count($mdFiles) . " analysis files\n";

    foreach ($mdFiles as $mdFile) {
        $filename = basename($mdFile);
        echo "  Processing: $filename\n";

        // Parse filename: analysis_20251002_154306_titles.md
        if (preg_match('/analysis_(\d{8})_(\d{6})_(\w+)\.md$/', $filename, $matches)) {
            $date = $matches[1];
            $time = $matches[2];
            $analysisType = $matches[3]; // titles, tags, seo, descriptions, etc.

            $createdAt = DateTime::createFromFormat('Ymd_His', $date . '_' . $time);
            if (!$createdAt) {
                echo "    ⚠️  Invalid date format, using current time\n";
                $createdAt = new DateTime();
            }

            $content = file_get_contents($mdFile);

            // Check if already exists
            $existing = Database::select(
                "SELECT id FROM analysis_results WHERE file_path = ?",
                [$mdFile]
            );

            if (!empty($existing)) {
                echo "    ⏭️  Already imported, skipped\n";
                $stats['analyses_skipped']++;
                continue;
            }

            $analysisData = [
                'user_id' => 1, // Default to admin user
                'analysis_type' => $analysisType,
                'mode' => 'channel',
                'query' => $channelId,
                'input_data' => json_encode(['channel_id' => $channelId], JSON_UNESCAPED_UNICODE),
                'prompt' => "Analysis of $analysisType for channel $channelId",
                'ai_provider' => 'codefast',
                'ai_model' => 'gpt-5-chat',
                'result' => $content,
                'is_saved' => true,
                'file_path' => $mdFile,
                'created_at' => $createdAt->format('Y-m-d H:i:s'),
            ];

            try {
                Database::insert('analysis_results', $analysisData);
                $stats['analyses_imported']++;
                echo "    ✅ Imported analysis: $analysisType\n";
            } catch (Exception $e) {
                echo "    ❌ Failed: " . $e->getMessage() . "\n";
                $stats['analyses_skipped']++;
            }
        } else {
            echo "    ⚠️  Filename format not recognized, skipped\n";
            $stats['analyses_skipped']++;
        }
    }

    echo "\n";
}

echo str_repeat('=', 80) . "\n";
echo "✅ Migration Completed!\n\n";
echo "📊 Statistics:\n";
echo "  Videos:\n";
echo "    - Imported: {$stats['videos_imported']}\n";
echo "    - Updated: {$stats['videos_updated']}\n";
echo "    - Skipped: {$stats['videos_skipped']}\n";
echo "  Analysis:\n";
echo "    - Imported: {$stats['analyses_imported']}\n";
echo "    - Skipped: {$stats['analyses_skipped']}\n";
echo "\n";

// Show database content
echo "📋 Database Summary:\n";
$videoCount = Database::select("SELECT COUNT(*) as count FROM video_metadata")[0]['count'];
$analysisCount = Database::select("SELECT COUNT(*) as count FROM analysis_results")[0]['count'];
echo "  - Total videos in database: $videoCount\n";
echo "  - Total analyses in database: $analysisCount\n";

echo "\n";
echo "🔍 Recent Videos:\n";
$recentVideos = Database::select(
    "SELECT video_id, title, view_count, published_at
     FROM video_metadata
     ORDER BY created_at DESC
     LIMIT 5"
);
foreach ($recentVideos as $video) {
    echo "  - [{$video['video_id']}] {$video['title']} ({$video['view_count']} views)\n";
}

echo "\n";
echo "🔍 Recent Analyses:\n";
$recentAnalyses = Database::select(
    "SELECT analysis_type, query, created_at
     FROM analysis_results
     ORDER BY created_at DESC
     LIMIT 5"
);
foreach ($recentAnalyses as $analysis) {
    echo "  - {$analysis['analysis_type']} for {$analysis['query']} ({$analysis['created_at']})\n";
}

echo "</pre>";
echo "<p><a href='../index.php'>Ana Sayfaya Dön</a></p>";
