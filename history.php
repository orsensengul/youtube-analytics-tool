<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/History.php';

function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

$file = __DIR__ . '/storage/history.jsonl';
$typeFilter = isset($_GET['type']) ? (string)$_GET['type'] : 'all'; // all|keyword|channel
$rows = [];
if (is_file($file)) {
    $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    for ($i = count($lines) - 1; $i >= 0; $i--) {
        $line = $lines[$i];
        $obj = json_decode($line, true);
        if (!is_array($obj)) continue;
        $meta = $obj['meta'] ?? [];
        $kind = $meta['type'] ?? (isset($meta['channelId']) ? 'channel' : 'keyword');
        if ($typeFilter !== 'all' && $kind !== $typeFilter) continue;
        $rows[] = [
            'ts' => (int)($obj['ts'] ?? time()),
            'query' => (string)($obj['query'] ?? ''),
            'meta' => is_array($meta) ? $meta : [],
            'kind' => $kind,
        ];
    }
}

?><!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Geçmiş</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="assets/styles.css">
</head>
<body>
<div class="container mx-auto max-w-4xl p-4">
    <div class="flex items-center justify-between mb-4">
        <h1 class="text-xl font-semibold text-gray-900">Arama Geçmişi</h1>
        <div class="flex gap-2 text-sm">
            <a class="px-3 py-1 rounded-md border border-gray-300 bg-gray-100 <?= $typeFilter==='all'?'font-semibold':'' ?>" href="?type=all">Tümü</a>
            <a class="px-3 py-1 rounded-md border border-gray-300 bg-gray-100 <?= $typeFilter==='keyword'?'font-semibold':'' ?>" href="?type=keyword">Kelime</a>
            <a class="px-3 py-1 rounded-md border border-gray-300 bg-gray-100 <?= $typeFilter==='channel'?'font-semibold':'' ?>" href="?type=channel">Kanal</a>
        </div>
    </div>

    <div class="bg-white border border-gray-200 rounded-xl overflow-hidden">
        <table class="min-w-full text-sm">
            <thead class="bg-gray-50 border-b border-gray-200">
                <tr>
                    <th class="text-left px-3 py-2 text-gray-600">Zaman</th>
                    <th class="text-left px-3 py-2 text-gray-600">Tür</th>
                    <th class="text-left px-3 py-2 text-gray-600">Sorgu</th>
                    <th class="text-left px-3 py-2 text-gray-600">Bilgi</th>
                    <th class="text-left px-3 py-2 text-gray-600">Aksiyon</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td class="px-3 py-3 text-gray-500" colspan="5">Kayıt bulunamadı.</td></tr>
            <?php else: ?>
                <?php foreach ($rows as $r): $meta = $r['meta']; $kind = $r['kind']; ?>
                <tr class="border-b border-gray-100">
                    <td class="px-3 py-2 text-gray-700 align-top"><?= e(date('Y-m-d H:i', $r['ts'])) ?></td>
                    <td class="px-3 py-2 align-top">
                        <span class="inline-block rounded-full border border-gray-300 bg-gray-100 text-gray-700 px-2 py-0.5 text-xs"><?= e($kind) ?></span>
                    </td>
                    <td class="px-3 py-2 text-gray-900 align-top break-all">
                        <?php if ($kind === 'channel'): ?>
                            <?= e($r['query']) ?>
                            <?php if (!empty($meta['channelId'])): ?><div class="text-xs text-gray-500">(<?= e($meta['channelId']) ?>)</div><?php endif; ?>
                        <?php else: ?>
                            <?= e($r['query']) ?>
                        <?php endif; ?>
                    </td>
                    <td class="px-3 py-2 text-gray-600 align-top">
                        <?php if ($kind === 'channel'): ?>
                            <div>Tür: <?= e((string)($meta['kind'] ?? 'videos')) ?></div>
                            <div>Sıralama: <?= e((string)($meta['sort'] ?? 'views')) ?></div>
                            <div>Adet: <?= (int)($meta['count'] ?? 0) ?></div>
                        <?php else: ?>
                            <div>Adet: <?= (int)($meta['count'] ?? 0) ?></div>
                            <div>Region: <?= e((string)($meta['region'] ?? '')) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="px-3 py-2 align-top">
                        <?php if ($kind === 'channel'): ?>
                            <a class="px-3 py-1 rounded-md border border-gray-300 bg-indigo-600 text-white text-xs" href="channel.php?url=<?= urlencode($r['query']) ?>&kind=<?= e((string)($meta['kind'] ?? 'videos')) ?>&sort=<?= e((string)($meta['sort'] ?? 'views')) ?>&limit=25">Aç</a>
                        <?php else: ?>
                            <a class="px-3 py-1 rounded-md border border-gray-300 bg-indigo-600 text-white text-xs" href="index.php?q=<?= urlencode($r['query']) ?>">Aç</a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="mt-4 text-sm text-gray-500">Dosya: <code><?= e($file) ?></code></div>
</div>
</body>
</html>

