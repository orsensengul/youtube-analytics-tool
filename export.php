<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

function respond($ok, $data = []) {
    http_response_code($ok ? 200 : 400);
    echo json_encode(['ok' => $ok] + $data, JSON_UNESCAPED_UNICODE);
    exit;
}

function slugify(string $s): string {
    $s = trim($s);
    $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
    $s = strtolower($s);
    $s = preg_replace('~[^a-z0-9]+~', '-', $s) ?: '';
    $s = trim($s, '-');
    return $s ?: 'untitled';
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, ['error' => 'POST required']);
}

$mode = $_POST['mode'] ?? '';
$variant = $_POST['variant'] ?? 'short'; // short|full
$json = $_POST['json'] ?? '';
$autoSave = isset($_POST['auto_save']) && $_POST['auto_save'] === '1';

if (!$mode || !$json) {
    respond(false, ['error' => 'Missing fields']);
}

// Validate JSON
$decoded = json_decode($json, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    respond(false, ['error' => 'Invalid JSON payload']);
}

$baseDir = __DIR__ . '/output';
@mkdir($baseDir, 0777, true);

$ts = date('Ymd_His');

if ($mode === 'channel') {
    $channelId = (string)($_POST['channelId'] ?? '');
    $channelName = (string)($_POST['channelName'] ?? '');
    $kind = (string)($_POST['kind'] ?? 'videos');
    $sort = (string)($_POST['sort'] ?? 'views');
    if ($channelId === '') respond(false, ['error' => 'channelId required']);

    // Kanal adını slug'a çevir
    $channelSlug = $channelName ? slugify($channelName) : '';
    $folderName = $channelSlug ? 'channel_' . $channelSlug . '_' . $channelId : 'channel_' . preg_replace('~[^A-Za-z0-9_-]+~', '', $channelId);

    // Variant'a göre alt klasör oluştur
    $dir = $baseDir . '/' . $folderName . '/' . $variant;
    @mkdir($dir, 0777, true);

    $file = sprintf('channel_%s_%s_%s_%s.json', $channelId, $kind, $sort, $ts);
    $path = $dir . '/' . $file;
} elseif ($mode === 'search') {
    $q = (string)($_POST['q'] ?? '');
    $sort = (string)($_POST['sort'] ?? 'relevance');
    if ($q === '') respond(false, ['error' => 'q required']);
    $qSlug = slugify($q);

    // Variant'a göre alt klasör oluştur
    $dir = $baseDir . '/search_' . $qSlug . '/' . $variant;
    @mkdir($dir, 0777, true);

    $file = sprintf('search_%s_%s_%s.json', $qSlug, $sort, $ts);
    $path = $dir . '/' . $file;
} else {
    respond(false, ['error' => 'Unknown mode']);
}

$pretty = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
if (@file_put_contents($path, $pretty) === false) {
    respond(false, ['error' => 'Write failed']);
}

respond(true, [
    'path' => 'ymt/output/' . basename(dirname(dirname($path))) . '/' . $variant . '/' . basename($path),
    'variant' => $variant,
]);

