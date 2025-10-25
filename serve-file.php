<?php
declare(strict_types=1);

// Secure file serving from storage_base_dir only
$config = require __DIR__ . '/config.php';

function storage_base_dir_sf(array $config): string {
    $base = $config['storage_dir'] ?? (__DIR__ . '/output');
    if (!is_dir($base)) @mkdir($base, 0777, true);
    if (!is_dir($base) || !is_readable($base)) {
        $tmp = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'ymt-output';
        if (is_dir($tmp)) return $tmp;
    }
    return $base;
}

$p = isset($_GET['p']) ? (string)$_GET['p'] : '';
$base = storage_base_dir_sf($config);

// Normalize and prevent path traversal
$target = realpath($base . DIRECTORY_SEPARATOR . ltrim($p, '/\\'));
if (!$target || strpos($target, realpath($base)) !== 0 || !is_file($target)) {
    http_response_code(404);
    echo 'Not found';
    exit;
}

// Content-type by extension
$ext = strtolower(pathinfo($target, PATHINFO_EXTENSION));
$types = [
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'gif' => 'image/gif',
    'webp' => 'image/webp',
    'json' => 'application/json; charset=utf-8',
    'txt' => 'text/plain; charset=utf-8',
    'md' => 'text/plain; charset=utf-8',
];
$ctype = $types[$ext] ?? 'application/octet-stream';
header('Content-Type: ' . $ctype);
header('Content-Length: ' . filesize($target));
header('X-Content-Type-Options: nosniff');
readfile($target);
exit;

