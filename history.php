<?php
declare(strict_types=1);

$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/lib/Database.php';
require_once __DIR__ . '/lib/Auth.php';
require_once __DIR__ . '/lib/History.php';

// Initialize database and session
Database::init($config['database']);
Auth::startSession($config['session']);
Auth::requireLogin();

function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function slugify(string $s): string {
    $s = trim($s);
    $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
    $s = strtolower($s);
    $s = preg_replace('~[^a-z0-9]+~', '-', $s) ?: '';
    $s = trim($s, '-');
    return $s ?: 'untitled';
}

$typeFilter = isset($_GET['type']) ? (string)$_GET['type'] : 'all'; // all|keyword|channel|analysis
$history = new History(Auth::userId());

if ($typeFilter === 'analysis') {
    // Get analysis history from database
    $userId = Auth::userId();
    $analysisRows = Database::select(
        "SELECT id, analysis_type, mode, query, created_at, is_saved, file_path
         FROM analysis_results
         WHERE user_id = ?
         ORDER BY created_at DESC
         LIMIT 100",
        [$userId]
    );
    $rows = [];
} else {
    $dbRows = $history->getHistory($typeFilter, 100);

    // Transform database rows to match old format
    $rows = [];
    foreach ($dbRows as $dbRow) {
        $meta = json_decode($dbRow['metadata'] ?? '{}', true);
        if (!is_array($meta)) $meta = [];

        $rows[] = [
            'id' => (int)$dbRow['id'],
            'ts' => strtotime($dbRow['created_at']),
            'query' => $dbRow['query'],
            'meta' => $meta,
            'kind' => $dbRow['search_type'],
        ];
    }
    $analysisRows = [];
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
<body class="bg-gray-50">
<div class="container mx-auto max-w-6xl px-4 py-6">
    <?php include __DIR__ . '/includes/navbar.php'; ?>

    <div class="flex items-center justify-between mb-4">
        <h2 class="text-lg font-medium text-gray-700">
            <?= $typeFilter === 'analysis' ? '📊 Analiz Geçmişi' : '🔍 Arama Geçmişi' ?>
        </h2>
        <div class="flex gap-2 text-sm">
            <a class="px-3 py-1 rounded-md border border-gray-300 <?= $typeFilter==='all'?'bg-indigo-100 border-indigo-400 text-indigo-900 font-semibold':'bg-gray-100 text-gray-800 hover:bg-gray-200' ?>" href="?type=all">Tümü</a>
            <a class="px-3 py-1 rounded-md border border-gray-300 <?= $typeFilter==='keyword'?'bg-indigo-100 border-indigo-400 text-indigo-900 font-semibold':'bg-gray-100 text-gray-800 hover:bg-gray-200' ?>" href="?type=keyword">Kelime</a>
            <a class="px-3 py-1 rounded-md border border-gray-300 <?= $typeFilter==='channel'?'bg-indigo-100 border-indigo-400 text-indigo-900 font-semibold':'bg-gray-100 text-gray-800 hover:bg-gray-200' ?>" href="?type=channel">Kanal</a>
            <a class="px-3 py-1 rounded-md border border-gray-300 <?= $typeFilter==='analysis'?'bg-indigo-100 border-indigo-400 text-indigo-900 font-semibold':'bg-gray-100 text-gray-800 hover:bg-gray-200' ?>" href="?type=analysis">Analizler</a>
        </div>
    </div>

    <div class="bg-white border border-gray-200 rounded-xl overflow-hidden">
        <?php if ($typeFilter === 'analysis'): ?>
        <!-- Analysis History Table -->
        <table class="min-w-full text-sm">
            <thead class="bg-gray-50 border-b border-gray-200">
                <tr>
                    <th class="text-left px-3 py-2 text-gray-600">Zaman</th>
                    <th class="text-left px-3 py-2 text-gray-600">Analiz Tipi</th>
                    <th class="text-left px-3 py-2 text-gray-600">Mod</th>
                    <th class="text-left px-3 py-2 text-gray-600">Sorgu</th>
                    <th class="text-left px-3 py-2 text-gray-600">Aksiyon</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$analysisRows): ?>
                <tr><td class="px-3 py-3 text-gray-500" colspan="5">Analiz kaydı bulunamadı.</td></tr>
            <?php else: ?>
                <?php foreach ($analysisRows as $row): ?>
                <tr class="border-b border-gray-100">
                    <td class="px-3 py-2 text-gray-700 align-top"><?= e(date('Y-m-d H:i', strtotime($row['created_at']))) ?></td>
                    <td class="px-3 py-2 align-top">
                        <span class="inline-block rounded-full border border-blue-300 bg-blue-50 text-blue-700 px-2 py-0.5 text-xs"><?= e($row['analysis_type']) ?></span>
                    </td>
                    <td class="px-3 py-2 text-gray-600 align-top"><?= e($row['mode']) ?></td>
                    <td class="px-3 py-2 text-gray-900 align-top break-all"><?= e($row['query']) ?></td>
                    <td class="px-3 py-2 align-top">
                        <div class="flex gap-2">
                            <?php if ($row['file_path'] && file_exists($row['file_path'])): ?>
                                <a class="px-3 py-1 rounded-md border border-gray-300 bg-gray-100 text-gray-800 hover:bg-gray-200 text-xs" href="<?= e('output/' . basename(dirname($row['file_path'])) . '/' . basename($row['file_path'])) ?>" target="_blank" title="Markdown dosyasını aç">📄 MD</a>
                            <?php endif; ?>

                            <!-- Analize Geç Butonu - Tekrar Analiz Et -->
                            <form method="post" action="analyze.php" class="inline">
                                <input type="hidden" name="mode" value="<?= e($row['mode']) ?>">
                                <input type="hidden" name="payload" value='<?= e($row['input_data']) ?>'>
                                <?php if ($row['mode'] === 'channel'): ?>
                                    <input type="hidden" name="channelId" value="<?= e($row['query']) ?>">
                                <?php else: ?>
                                    <input type="hidden" name="q" value="<?= e($row['query']) ?>">
                                <?php endif; ?>
                                <button type="submit" class="px-3 py-1 rounded-md border border-indigo-300 bg-indigo-600 text-white hover:bg-indigo-500 text-xs" title="Bu veriyle yeni analiz yap">📊 Analize Geç</button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
        <?php else: ?>
        <!-- Search History Table -->
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
                        <div class="flex gap-2">
                            <?php if ($kind === 'channel'): ?>
                                <a class="px-3 py-1 rounded-md border border-gray-300 bg-gray-100 text-gray-800 hover:bg-gray-200 text-xs" href="channel.php?url=<?= urlencode($r['query']) ?>&kind=<?= e((string)($meta['kind'] ?? 'videos')) ?>&sort=<?= e((string)($meta['sort'] ?? 'views')) ?>&limit=25" title="Kanal sayfasını tekrar aç">🔄 Tekrar Aç</a>
                            <?php else: ?>
                                <a class="px-3 py-1 rounded-md border border-gray-300 bg-gray-100 text-gray-800 hover:bg-gray-200 text-xs" href="index.php?q=<?= urlencode($r['query']) ?>" title="Aramayı tekrar yap">🔄 Tekrar Ara</a>
                            <?php endif; ?>

                            <!-- Kaydedilmiş JSON varsa Analize Geç -->
                            <?php
                            // Check for saved JSON file (short variant için)
                            $outputDir = __DIR__ . '/output';
                            $jsonFile = null;
                            if ($kind === 'channel' && !empty($meta['channelId'])) {
                                // Kanal için: channel_[name]_[id]/short/ klasörünü kontrol et
                                $channelId = $meta['channelId'];
                                // Önce kanal adıyla klasör ara
                                $channelDirs = glob($outputDir . '/channel_*_' . $channelId);
                                if (empty($channelDirs)) {
                                    // Eski format: sadece ID ile
                                    $channelDirs = glob($outputDir . '/channel_' . $channelId);
                                }
                                if (!empty($channelDirs)) {
                                    $channelDir = $channelDirs[0];
                                    // Önce short klasörüne bak
                                    $shortDir = $channelDir . '/short';
                                    if (is_dir($shortDir)) {
                                        $files = glob($shortDir . '/*.json');
                                        if (!empty($files)) {
                                            $jsonFile = $files[0];
                                        }
                                    }
                                    // Short yoksa ana klasöre bak (eski format)
                                    if (!$jsonFile && is_dir($channelDir)) {
                                        $files = glob($channelDir . '/*.json');
                                        if (!empty($files)) {
                                            $jsonFile = $files[0];
                                        }
                                    }
                                }
                            } else {
                                // Arama için: search_[slug]/short/ klasörünü kontrol et
                                $searchSlug = slugify($r['query']);
                                $searchDir = $outputDir . '/search_' . $searchSlug;
                                if (is_dir($searchDir)) {
                                    // Önce short klasörüne bak
                                    $shortDir = $searchDir . '/short';
                                    if (is_dir($shortDir)) {
                                        $files = glob($shortDir . '/*.json');
                                        if (!empty($files)) {
                                            $jsonFile = $files[0];
                                        }
                                    }
                                    // Short yoksa ana klasöre bak (eski format)
                                    if (!$jsonFile) {
                                        $files = glob($searchDir . '/*.json');
                                        if (!empty($files)) {
                                            $jsonFile = $files[0];
                                        }
                                    }
                                }
                            }

                            if ($jsonFile && file_exists($jsonFile)):
                                $relativePath = str_replace($outputDir . '/', '', $jsonFile);
                            ?>
                                <form method="post" action="analyze.php" class="inline">
                                    <input type="hidden" name="saved_json" value="<?= e($relativePath) ?>">
                                    <button type="submit" class="px-3 py-1 rounded-md border border-indigo-300 bg-indigo-600 text-white hover:bg-indigo-500 text-xs" title="Kaydedilmiş JSON ile analiz yap">📊 Analize Geç</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

    <div class="mt-4 text-sm text-gray-500">
        <?php if ($typeFilter === 'analysis'): ?>
            Toplam <?= count($analysisRows) ?> analiz kaydı
        <?php else: ?>
            Toplam <?= count($rows) ?> arama kaydı
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>

