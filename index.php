<?php
declare(strict_types=1);

$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/lib/Database.php';
require_once __DIR__ . '/lib/Auth.php';
require_once __DIR__ . '/lib/Cache.php';
require_once __DIR__ . '/lib/History.php';
require_once __DIR__ . '/lib/UserManager.php';
require_once __DIR__ . '/services/YoutubeService.php';
require_once __DIR__ . '/lib/DbMigrator.php';
require_once __DIR__ . '/lib/RapidApiClient.php';
require_once __DIR__ . '/lib/AssetDownloader.php';
require_once __DIR__ . '/services/TranscriptService.php';
require_once __DIR__ . '/services/VideoAssetManager.php';
require_once __DIR__ . '/lib/AppState.php';

// Initialize database and session
Database::init($config['database']);
Auth::startSession($config['session']);
Auth::requireLogin();
// Auto-migrate DB schema (best-effort)
DbMigrator::run();

function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function parse_intish($v): int {
    if (is_numeric($v)) return (int)$v;
    if (is_string($v)) {
        $s = str_replace([',', ' '], ['', ''], $v);
        if (preg_match('/^(\d+)([kKmMbB])$/', $s, $m)) {
            $base = (int)$m[1];
            $mul = ['k'=>1e3,'K'=>1e3,'m'=>1e6,'M'=>1e6,'b'=>1e9,'B'=>1e9][$m[2]];
            return (int)round($base * $mul);
        }
        if (is_numeric($s)) return (int)$s;
    }
    return 0;
}

function get_id_from_item(array $item): ?string {
    return $item['id']['videoId'] ?? ($item['videoId'] ?? ($item['id'] ?? ($item['video']['videoId'] ?? null)));
}

function sort_results(array $results, array $detailsMap, string $sort): array {
    if ($sort === 'relevance') return $results;
    $getMetric = function(array $item) use ($detailsMap, $sort): array {
        $id = get_id_from_item($item) ?? '';
        $detail = $detailsMap[$id] ?? [];
        $stats = $detail['statistics'] ?? [];
        $sn = $detail['snippet'] ?? [];
        $views = parse_intish($stats['viewCount'] ?? 0);
        $likes = parse_intish($stats['likeCount'] ?? 0);
        $publishedAt = $sn['publishedAt'] ?? '';
        $ts = $publishedAt ? (strtotime($publishedAt) ?: 0) : 0;
        switch ($sort) {
            case 'views': return [$views, $likes, $ts];
            case 'likes': return [$likes, $views, $ts];
            case 'date': return [$ts, $views, $likes];
            default: return [0,0,0];
        }
    };
    usort($results, function($a, $b) use ($getMetric, $sort) {
        [$av, $al, $at] = $getMetric($a);
        [$bv, $bl, $bt] = $getMetric($b);
        // Descending for chosen metric; tie-breakers also descending
        if ($av !== $bv) return ($av < $bv) ? 1 : -1;
        if ($al !== $bl) return ($al < $bl) ? 1 : -1;
        if ($at !== $bt) return ($at < $bt) ? 1 : -1;
        return 0;
    });
    return $results;
}

$rapidKey = $config['rapidapi_key'] ?? '';
$rapidHost = $config['rapidapi_host'] ?? 'yt-api.p.rapidapi.com';
$maxResults = (int)($config['results_per_page'] ?? 10);
$regionCode = $config['region_code'] ?? 'TR';
$cacheTtl = (int)($config['cache_ttl_seconds'] ?? 0);

$query = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$debug = isset($_GET['debug']) && $_GET['debug'] === '1';
$sort = isset($_GET['sort']) ? trim((string)$_GET['sort']) : 'relevance';
$error = '';
$results = [];
$tagsByVideo = [];
$detailsMap = [];
$fromCache = false;
$history = new History(Auth::userId());
$tsService = null;
if (!empty($rapidKey)) {
    $transcriptHost = $config['transcript_api_host'] ?? 'youtube-transcripts.p.rapidapi.com';
    $tsService = new TranscriptService(
        new RapidApiClient($rapidKey, $rapidHost),
        $rapidHost,
        new RapidApiClient($rapidKey, $transcriptHost),
        $transcriptHost
    );
}
$offline = AppState::isOffline($config);
$sourceLabel = '';

if ($query !== '') {
    // Check data query limit
    if (!UserManager::checkDataQueryLimit(Auth::userId())) {
        $remaining = UserManager::getRemainingDataQueries(Auth::userId());
        $error = 'Günlük veri sorgu limitinize ulaştınız. Kalan: ' . ($remaining['daily'] === -1 ? 'Sınırsız' : $remaining['daily']);
    } elseif (!$rapidKey || $rapidKey === 'YOUR_RAPIDAPI_KEY') {
        $error = 'Lütfen RapidAPI anahtarını config.php dosyasında ayarlayın.';
    } else {
        $client = new RapidApiClient($rapidKey, $rapidHost);
        $yt = new YoutubeService($client, $rapidHost);
        $cache = new Cache(__DIR__ . '/storage/cache');
        $cacheKey = 'search:' . $rapidHost . ':r=' . $regionCode . ':q=' . strtolower($query);
        $cached = $cache->get($cacheKey, $cacheTtl);
        if ($cached) {
            $fromCache = true;
            $sourceLabel = 'Cache';
            $search = $cached['search'] ?? [];
            $results = $cached['results'] ?? [];
            $detailsMap = $cached['detailsMap'] ?? [];
            $tagsByVideo = $cached['tagsByVideo'] ?? [];

            // Geçmişe kaydet (cache'den okunsa da)
            try {
                $ids = [];
                foreach ($results as $it) {
                    $vid = get_id_from_item((array)$it);
                    if ($vid) $ids[] = $vid;
                }
                $history->addSearch($query, [
                    'type' => 'keyword',
                    'host' => $rapidHost,
                    'region' => $regionCode,
                    'count' => count($results),
                    'ids' => $ids,
                ]);
            } catch (Exception $e) {}
            // Otomatik Thumbnail/Transkript topla
            auto_collect_assets($results, $detailsMap, $rapidHost, $tsService);

            // Cache'den gelen sonuçlar için de JSON kaydet
            $nowIso = date('c');
            $shortItems = [];
            $fullItems = [];
            foreach ($results as $item) {
                $id = $item['id']['videoId'] ?? ($item['videoId'] ?? ($item['id'] ?? ($item['video']['videoId'] ?? null)));
                if (!$id) continue;
                $sn = $item['snippet'] ?? [];
                $title = $sn ? ($sn['title'] ?? '') : ($item['title'] ?? '');
                $chanTitle = $sn ? ($sn['channelTitle'] ?? '') : ($item['channelTitle'] ?? ($item['channel']['title'] ?? ''));
                $chanId = $sn['channelId'] ?? ($item['channelId'] ?? ($item['channel']['channelId'] ?? null));
                $detail = $detailsMap[$id] ?? [];
                $desc = $detail['description'] ?? ($detail['video']['description'] ?? ($sn['description'] ?? ($item['description'] ?? null)));
                $tags = $tagsByVideo[$id] ?? [];
                $pubIso = $detail['publishDate'] ?? ($detail['uploadDate'] ?? ($sn['publishedAt'] ?? null));
                $display = $item['publishedTimeText'] ?? ($item['publishedText'] ?? ($sn['publishedAt'] ?? null));
                $views = (int)(($detailsMap[$id]['statistics']['viewCount'] ?? 0));
                $likes = (int)(($detailsMap[$id]['statistics']['likeCount'] ?? 0));
                $itShort = [
                    'id' => $id,
                    'url' => 'https://www.youtube.com/watch?v=' . $id,
                    'title' => $title,
                    'channel' => ['title' => $chanTitle, 'id' => $chanId],
                    'metrics' => ['views' => $views, 'likes' => $likes],
                    'published' => ['iso' => $pubIso, 'display' => $display],
                    'description' => $desc,
                    'tags' => $tags,
                ];
                $itFull = $itShort;
                $itFull['raw'] = [
                    'listItem' => $item,
                    'details' => $detailsMap[$id] ?? null,
                ];
                $shortItems[] = $itShort;
                $fullItems[] = $itFull;
            }

            // Short JSON kaydet
            $shortJsonData = [
                'query' => [ 'q' => $query, 'sort' => $sort, 'limit' => count($results) ],
                'meta' => [ 'region' => $regionCode, 'generatedAt' => $nowIso, 'fromCache' => true ],
                'items' => $shortItems,
            ];
            autoSaveJson($query, $sort, 'short', $shortJsonData);

            // Full JSON kaydet
            $fullJsonData = [
                'query' => ['q' => $query, 'sort' => $sort, 'limit' => count($results)],
                'meta' => [ 'region' => $regionCode, 'providerHost' => $rapidHost, 'cache' => ['ttlSeconds' => $cacheTtl], 'generatedAt' => $nowIso, 'fromCache' => true ],
                'items' => $fullItems,
                'errors' => [],
            ];
            autoSaveJson($query, $sort, 'full', $fullJsonData);
        } else {
            if ($offline) {
                // Try to load from saved JSON files under storage_dir/search_<slug>/full or /short
                $offlineLoaded = load_offline_saved_search($query);
                if ($offlineLoaded) {
                    $fromCache = true;
                    $sourceLabel = 'Offline';
                    $search = ['offline' => true];
                    $results = $offlineLoaded['results'];
                    $detailsMap = $offlineLoaded['detailsMap'];
                    $tagsByVideo = $offlineLoaded['tagsByVideo'];
                    // Also add to history
                    try {
                        $ids = [];
                        foreach ($results as $it) { $vid = get_id_from_item((array)$it); if ($vid) $ids[] = $vid; }
                        $history->addSearch($query, [
                            'type' => 'keyword', 'host' => $rapidHost, 'region' => $regionCode,
                            'count' => count($results), 'ids' => $ids,
                        ]);
                    } catch (Exception $e) {}
                } else {
                    $error = 'Offline mod: Kayıtlı arama bulunamadı.';
                    $search = [];
                }
            } else {
                // Online mod: önce kayıtlı JSON var mı kontrol et
                $saved = load_offline_saved_search($query);
                if ($saved) {
                    $fromCache = true;
                    $sourceLabel = 'Offline';
                    $search = ['offline' => true];
                    $results = $saved['results'];
                    $detailsMap = $saved['detailsMap'];
                    $tagsByVideo = $saved['tagsByVideo'];
                    try {
                        $ids = [];
                        foreach ($results as $it) { $vid = get_id_from_item((array)$it); if ($vid) $ids[] = $vid; }
                        $history->addSearch($query, [
                            'type' => 'keyword', 'host' => $rapidHost, 'region' => $regionCode,
                            'count' => count($results), 'ids' => $ids,
                        ]);
                    } catch (Exception $e) {}
                } else {
                    $search = $yt->search($query, $maxResults, $regionCode);
                    $sourceLabel = 'API';
                }
            }
        }
        if (!empty($search['error'])) {
            $error = 'Arama sırasında hata: ' . e((string)$search['error']);
        } else {
            if (!$fromCache) {
                // Hem youtube-v31 hem de yt-api sonuç şemalarını destekle
                $results = [];
                if (isset($search['items']) && is_array($search['items'])) {
                    $results = $search['items'];
                } elseif (isset($search['data']['results']) && is_array($search['data']['results'])) {
                    $results = $search['data']['results'];
                } elseif (isset($search['results']) && is_array($search['results'])) {
                    $results = $search['results'];
                } elseif (isset($search['data']) && is_array($search['data']) && isset($search['data'][0])) {
                    // Bazı sürümler data'yı direkt liste döndürebilir
                    $results = $search['data'];
                }
                // Sadece geçerli videoId olanları al, mükerrerleri at
                $seen = [];
                $filtered = [];
                foreach ($results as $it) {
                    $vid = get_id_from_item((array)$it);
                    if (!$vid) continue;
                    if (isset($seen[$vid])) continue;
                    $seen[$vid] = true;
                    $filtered[] = $it;
                }
                if ($filtered && count($filtered) > $maxResults) {
                    $filtered = array_slice($filtered, 0, $maxResults);
                }
                $results = $filtered;
                $videoIds = [];
                foreach ($results as $item) {
                    // v31: $item['id']['videoId']
                    // yt-api: $item['videoId'] veya $item['id']
                    $id = $item['id']['videoId'] ?? ($item['videoId'] ?? ($item['id'] ?? ($item['video']['videoId'] ?? null)));
                    if ($id) $videoIds[] = $id;
                }
                if ($videoIds) {
                    $detailsMap = $offline ? [] : $yt->videosDetails($videoIds);
                    if (isset($detailsMap['error'])) {
                        $error = 'Video detayları alınamadı: ' . e((string)$detailsMap['error']);
                    } else {
                        foreach ($videoIds as $vid) {
                            $tags = $detailsMap[$vid]['snippet']['tags'] ?? [];
                            $tagsByVideo[$vid] = $tags;
                        }
                        // Sıralama istemci tarafında yapılacak
                        // Arama geçmişine kaydet (ilk kez işlem)
                        $history->addSearch($query, [
                            'type' => 'keyword',
                            'host' => $rapidHost,
                            'region' => $regionCode,
                            'count' => count($results),
                            'ids' => $videoIds,
                        ]);

                        // Increment data query count and log activity
                        UserManager::incrementDataQueryCount(Auth::userId());
                        UserManager::logActivity(Auth::userId(), 'query_search', [
                            'query' => $query,
                            'results' => count($results)
                        ]);

                        // Otomatik Thumbnail ve Transkript alma + DB'ye yazma
                        auto_collect_assets($results, $detailsMap, $rapidHost, $tsService);

                        if ($cacheTtl > 0) {
                            $cache->set($cacheKey, [
                                'search' => $search,
                                'results' => $results,
                                'detailsMap' => $detailsMap,
                                'tagsByVideo' => $tagsByVideo,
                            ]);
                        }

                        // Otomatik JSON kaydetme - hem short hem full
                        $nowIso = date('c');
                        $shortItems = [];
                        $fullItems = [];
                        foreach ($results as $item) {
                            $id = $item['id']['videoId'] ?? ($item['videoId'] ?? ($item['id'] ?? ($item['video']['videoId'] ?? null)));
                            if (!$id) continue;
                            $sn = $item['snippet'] ?? [];
                            $title = $sn ? ($sn['title'] ?? '') : ($item['title'] ?? '');
                            $chanTitle = $sn ? ($sn['channelTitle'] ?? '') : ($item['channelTitle'] ?? ($item['channel']['title'] ?? ''));
                            $chanId = $sn['channelId'] ?? ($item['channelId'] ?? ($item['channel']['channelId'] ?? null));
                            $detail = $detailsMap[$id] ?? [];
                            $desc = $detail['description'] ?? ($detail['video']['description'] ?? ($sn['description'] ?? ($item['description'] ?? null)));
                            $tags = $tagsByVideo[$id] ?? [];
                            $pubIso = $detail['publishDate'] ?? ($detail['uploadDate'] ?? ($sn['publishedAt'] ?? null));
                            $display = $item['publishedTimeText'] ?? ($item['publishedText'] ?? ($sn['publishedAt'] ?? null));
                            $views = (int)(($detailsMap[$id]['statistics']['viewCount'] ?? 0));
                            $likes = (int)(($detailsMap[$id]['statistics']['likeCount'] ?? 0));
                            $itShort = [
                                'id' => $id,
                                'url' => 'https://www.youtube.com/watch?v=' . $id,
                                'title' => $title,
                                'channel' => ['title' => $chanTitle, 'id' => $chanId],
                                'metrics' => ['views' => $views, 'likes' => $likes],
                                'published' => ['iso' => $pubIso, 'display' => $display],
                                'description' => $desc,
                                'tags' => $tags,
                            ];
                            $itFull = $itShort;
                            $itFull['raw'] = [
                                'listItem' => $item,
                                'details' => $detailsMap[$id] ?? null,
                            ];
                            $shortItems[] = $itShort;
                            $fullItems[] = $itFull;
                        }

                        // Short JSON kaydet
                        $shortJsonData = [
                            'query' => [ 'q' => $query, 'sort' => $sort, 'limit' => count($results) ],
                            'meta' => [ 'region' => $regionCode, 'generatedAt' => $nowIso ],
                            'items' => $shortItems,
                        ];
                        autoSaveJson($query, $sort, 'short', $shortJsonData);

                        // Full JSON kaydet
                        $fullJsonData = [
                            'query' => ['q' => $query, 'sort' => $sort, 'limit' => count($results)],
                            'meta' => [ 'region' => $regionCode, 'providerHost' => $rapidHost, 'cache' => ['ttlSeconds' => $cacheTtl], 'generatedAt' => $nowIso ],
                            'items' => $fullItems,
                            'errors' => $error ? [$error] : [],
                        ];
                        autoSaveJson($query, $sort, 'full', $fullJsonData);
                    }
                }
            }
        }
    }
}

// Otomatik JSON kaydetme fonksiyonu
function autoSaveJson(string $query, string $sort, string $variant, array $data): void {
    $baseDir = storage_base_dir();
    $qSlug = slugify($query);
    $dir = $baseDir . '/search_' . $qSlug . '/' . $variant;
    @mkdir($dir, 0777, true);
    $ts = date('Ymd_His');
    $file = sprintf('search_%s_%s_%s.json', $qSlug, $sort, $ts);
    $path = $dir . '/' . $file;
    $pretty = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    @file_put_contents($path, $pretty);
}

function slugify(string $s): string {
    $s = trim($s);
    $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
    $s = strtolower($s);
    $s = preg_replace('~[^a-z0-9]+~', '-', $s) ?: '';
    $s = trim($s, '-');
    return $s ?: 'untitled';
}

function storage_base_dir(): string {
    $cfg = include __DIR__ . '/config.php';
    $base = $cfg['storage_dir'] ?? (__DIR__ . '/output');
    @mkdir($base, 0777, true);
    if (!is_dir($base) || !is_writable($base)) {
        $tmp = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'ymt-output';
        @mkdir($tmp, 0777, true);
        if (is_dir($tmp) && is_writable($tmp)) return $tmp;
    }
    return $base;
}

function pick_thumb_url_from_details(array $raw, string $videoId): string {
    if (!empty($raw['thumbnails'])) {
        $t = $raw['thumbnails'];
        if (is_array($t)) {
            foreach (['maxres','maxresdefault','high','hqdefault','standard','sddefault','medium','mqdefault','default'] as $k) {
                if (isset($t[$k]['url'])) return (string)$t[$k]['url'];
            }
            foreach ($t as $it) if (is_array($it) && !empty($it['url'])) return (string)$it['url'];
        }
    }
    return 'https://i.ytimg.com/vi/' . $videoId . '/hqdefault.jpg';
}

function auto_collect_assets(array $results, array $detailsMap, string $host, ?TranscriptService $tsService): void {
    foreach ($results as $it) {
        $vid = get_id_from_item((array)$it);
        if (!$vid) continue;

        // Ensure video metadata row
        $raw = $detailsMap[$vid]['raw'] ?? [];
        $title = $raw['title'] ?? '';
        $desc = $raw['description'] ?? '';
        $channelTitle = $raw['channelTitle'] ?? ($raw['channel']['title'] ?? '');
        $published = $detailsMap[$vid]['snippet']['publishedAt'] ?? ($raw['publishDate'] ?? null);
        $tags = $detailsMap[$vid]['snippet']['tags'] ?? [];
        $thumbs = $raw['thumbnails'] ?? null;
        VideoAssetManager::ensureVideo($vid, [
            'title' => $title,
            'description' => $desc,
            'channel_title' => $channelTitle,
            'published_at' => $published,
            'tags' => $tags,
            'thumbnails' => $thumbs,
            'raw' => $raw,
        ]);

        // Thumbnail download if not exists
        // Save thumbnail into the video's own folder
        $dir = storage_base_dir() . '/video_' . $vid;
        @mkdir($dir, 0777, true);
        $thumbPath = $dir . '/' . $vid . '.jpg';
        if (!is_file($thumbPath)) {
            $thumbUrl = pick_thumb_url_from_details($raw, $vid);
            $dl = AssetDownloader::download($thumbUrl, $thumbPath);
            if ($dl['success']) {
                VideoAssetManager::saveThumbPath($vid, $thumbPath);
            }
        } else {
            VideoAssetManager::saveThumbPath($vid, $thumbPath);
        }

        // Transcript fetch (counts towards data limit) if not existing
        if ($tsService) {
            $dir = storage_base_dir() . '/video_' . $vid;
            @mkdir($dir, 0777, true);
            $existingTxt = glob($dir . '/*_transcript.txt');
            if (!$existingTxt) {
                if (UserManager::checkDataQueryLimit(Auth::userId())) {
                    $res = $tsService->getTranscript($vid, ['tr','en']);
                    if ($res['success']) {
                        $l = $res['lang'] ?: 'auto';
                        $jsonPath = $dir . '/' . $vid . '_transcript.json';
                        $txtPath = $dir . '/' . $vid . '_transcript.txt';
                        @file_put_contents($jsonPath, json_encode(['segments' => $res['segments']], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                        @file_put_contents($txtPath, $res['text']);
                        $prov = $res['provider'] ?? $host;
                        VideoAssetManager::saveTranscriptPaths($vid, $l, $jsonPath, $txtPath, $prov);
                        UserManager::incrementDataQueryCount(Auth::userId());
                        UserManager::logActivity(Auth::userId(), 'fetch_transcript', ['videoId' => $vid, 'lang' => $l]);
                    }
                }
            }
        }
    }
}

// Load last saved search JSON (full preferred) for offline usage
function load_offline_saved_search(string $query): ?array {
    $base = storage_base_dir();
    $slug = slugify($query);
    $dirs = [
        $base . '/search_' . $slug . '/full',
        $base . '/search_' . $slug . '/short',
    ];
    $latest = null; $latestMTime = 0;
    foreach ($dirs as $d) {
        if (!is_dir($d)) continue;
        foreach (glob($d . '/*.json') ?: [] as $f) {
            $t = @filemtime($f) ?: 0;
            if ($t > $latestMTime) { $latest = $f; $latestMTime = $t; }
        }
    }
    if (!$latest) return null;
    $raw = @file_get_contents($latest); if ($raw === false) return null;
    $data = json_decode($raw, true); if (!is_array($data)) return null;
    $items = $data['items'] ?? [];
    if (!is_array($items)) $items = [];
    $results = [];
    $detailsMap = [];
    $tagsByVideo = [];
    foreach ($items as $it) {
        $id = $it['id'] ?? ($it['raw']['details']['id'] ?? ($it['raw']['listItem']['id']['videoId'] ?? null));
        if (!$id && isset($it['raw']['listItem'])) {
            $lid = $it['raw']['listItem'];
            $id = $lid['id']['videoId'] ?? ($lid['videoId'] ?? ($lid['id'] ?? null));
        }
        if (!$id) continue;
        // Prefer original list item if present
        $listItem = $it['raw']['listItem'] ?? null;
        if (is_array($listItem)) {
            $results[] = $listItem;
        } else {
            // Minimal stub
            $results[] = [
                'id' => ['videoId' => $id],
                'title' => $it['title'] ?? '',
                'channelTitle' => $it['channel']['title'] ?? ($it['channelTitle'] ?? ''),
                'description' => $it['description'] ?? '',
            ];
        }
        if (isset($it['raw']['details']) && is_array($it['raw']['details'])) {
            $detailsMap[$id] = $it['raw']['details'];
        }
        if (isset($it['tags']) && is_array($it['tags'])) {
            $tagsByVideo[$id] = $it['tags'];
        }
    }
    return [
        'results' => $results,
        'detailsMap' => $detailsMap,
        'tagsByVideo' => $tagsByVideo,
    ];
}

?><!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>YouTube Arama + Etiketler (RapidAPI)</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="assets/styles.css">
</head>
<body class="bg-gray-50">
    <div class="container mx-auto max-w-6xl px-4 py-6">
        <?php include __DIR__ . '/includes/navbar.php'; ?>

        <div class="flex items-center justify-between mb-3">
            <?php if ($query !== '' && !$error): ?>
                <h2 class="text-lg font-medium text-gray-700">Sonuçlar: "<?= e($query) ?>"
                    <?php if (class_exists('Auth') && Auth::isAdmin() && $sourceLabel): ?>
                        <span class="ml-2 text-xs inline-block px-2 py-0.5 rounded-full border <?php
                            echo $sourceLabel==='API' ? 'border-green-300 bg-green-50 text-green-700'
                                : ($sourceLabel==='Cache' ? 'border-blue-300 bg-blue-50 text-blue-700'
                                : 'border-yellow-300 bg-yellow-50 text-yellow-800');
                        ?>">Kaynak: <?= e($sourceLabel) ?></span>
                    <?php endif; ?>
                </h2>
                <div class="flex gap-2">
                    <button class="px-3 py-1 rounded-md border border-indigo-300 bg-indigo-600 text-white hover:bg-indigo-500" type="button" onclick="analyzeSearchNow()">📊 Analiz Et</button>
                </div>
            <?php else: ?>
                <h2 class="text-lg font-medium text-gray-700">YouTube Video Arama</h2>
            <?php endif; ?>
        </div>

        <form method="get" class="search-form flex gap-2 items-center mb-4">
            <input class="flex-1 px-3 py-2 rounded-md border border-slate-700 bg-slate-900 text-slate-100 focus:outline-none focus:ring-2 focus:ring-blue-600" type="text" name="q" placeholder="Aramak istediğiniz kelime" value="<?= e($query) ?>" required>
            <select id="sortSelect" name="sort" title="Sırala" class="sort-select px-3 py-2 rounded-md border border-slate-700 bg-slate-900 text-slate-100">
                <option value="relevance" <?= $sort==='relevance'?'selected':'' ?>>Alaka (varsayılan)</option>
                <option value="views" <?= $sort==='views'?'selected':'' ?>>İzlenme (çoktan aza)</option>
                <option value="likes" <?= $sort==='likes'?'selected':'' ?>>Like (çoktan aza)</option>
                <option value="date" <?= $sort==='date'?'selected':'' ?>>Tarih (yeniden eskiye)</option>
            </select>
            <button class="px-4 py-2 rounded-md border border-slate-700 bg-blue-600 text-white hover:bg-blue-500" type="submit">Ara</button>
            <label class="flex items-center gap-2 text-slate-400 text-xs ml-2">
                <input type="checkbox" name="debug" value="1" <?= $debug ? 'checked' : '' ?>> debug
            </label>
        </form>
        <div class="collector bg-white border border-gray-200 rounded-xl p-3 mb-4">
            <div class="collector-head text-xs text-gray-500 mb-2">Seçilen Etiketler</div>
            <div class="collector-actions flex gap-2 items-center">
                <input class="flex-1 px-3 py-2 rounded-md border border-gray-300 bg-white text-gray-900" type="text" id="collected" placeholder="Etiketler buraya virgülle eklenecek" readonly>
                <button class="px-3 py-2 rounded-md border border-gray-300 bg-gray-100 text-gray-800 hover:bg-gray-200" type="button" id="copyTags">Kopyala</button>
                <button class="px-3 py-2 rounded-md border border-gray-300 bg-gray-100 text-gray-800 hover:bg-gray-200" type="button" id="clearTags">Temizle</button>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="alert error"><?= e($error) ?></div>
        <?php endif; ?>

        <?php if ($query !== '' && !$error): ?>
            <div class="results grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <?php if (!$results): ?>
                    <div class="empty text-gray-500">Sonuç bulunamadı.</div>
                <?php else: ?>
                    <?php foreach ($results as $item): ?>
                        <?php
                        // Ortak alanlar (v31 ve yt-api için esnek yakalama)
                        $id = $item['id']['videoId'] ?? ($item['videoId'] ?? ($item['id'] ?? ''));
                        $sn = $item['snippet'] ?? [];
                        $detail = $detailsMap[$id] ?? [];
                        $stats = $detail['statistics'] ?? [];
                        $views = (int)($stats['viewCount'] ?? 0);
                        $likes = (int)($stats['likeCount'] ?? 0);
                        if ($sn) {
                            // youtube-v31 şeması
                            $thumb = $sn['thumbnails']['medium']['url'] ?? ($sn['thumbnails']['default']['url'] ?? '');
                            $title = $sn['title'] ?? '';
                            $channel = $sn['channelTitle'] ?? '';
                            $published = $sn['publishedAt'] ?? '';
                            $desc = $sn['description'] ?? '';
                        } else {
                            // yt-api şeması
                            $title = $item['title'] ?? '';
                            $channel = $item['channelTitle'] ?? ($item['channel']['title'] ?? '');
                            $published = $item['publishedTimeText'] ?? ($item['publishedText'] ?? '');
                            $desc = $item['description'] ?? '';
                            // Thumbnail yakalama
                            if (!empty($item['thumbnail']) && is_array($item['thumbnail'])) {
                                $first = $item['thumbnail'][0] ?? [];
                                $thumb = is_array($first) ? ($first['url'] ?? '') : (string)$first;
                            } elseif (!empty($item['thumbnails']) && is_array($item['thumbnails'])) {
                                $first = $item['thumbnails'][0] ?? [];
                                $thumb = is_array($first) ? ($first['url'] ?? '') : (string)$first;
                            } else {
                                $thumb = '';
                            }
                        }
                        $publishedIso = $detail['snippet']['publishedAt'] ?? '';
                        $tags = $tagsByVideo[$id] ?? [];
                        ?>
                        <div class="card bg-white border border-gray-200 rounded-xl overflow-hidden flex flex-col" data-id="<?= e($id) ?>" data-views="<?= e((string)$views) ?>" data-likes="<?= e((string)$likes) ?>" data-date="<?= e($publishedIso) ?>">
                            <a class="thumb" href="https://www.youtube.com/watch?v=<?= e($id) ?>" target="_blank" rel="noopener">
                                <?php if ($thumb): ?><img class="w-full block" src="<?= e($thumb) ?>" alt="<?= e($title) ?>"><?php endif; ?>
                            </a>
                            <div class="content p-3 flex flex-col gap-2">
                                <h3 class="title m-0 text-base leading-tight font-medium"><a class="text-gray-900 hover:underline" href="https://www.youtube.com/watch?v=<?= e($id) ?>" target="_blank" rel="noopener"><?= e($title) ?></a></h3>
                                <div class="flex justify-between items-center text-xs text-gray-500">
                                    <div>Kanal: <?= e($channel) ?></div>
                                    <div class="flex gap-3">
                                        <span title="İzlenme">👁 <?= number_format($views) ?></span>
                                        <span title="Like">❤️ <?= number_format($likes) ?></span>
                                    </div>
                                </div>
                                <div class="meta text-xs text-gray-500">Yayın: <?= e($published) ?></div>
                                <?php if ($desc): ?><p class="desc text-sm text-gray-700 m-0" style="display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden;">
                                    <?= e($desc) ?>
                                </p><?php endif; ?>
                                <?php if ($tags): ?>
                                    <div class="tags flex flex-wrap gap-2">
                                        <?php foreach ($tags as $t): $rt = trim((string)$t); if ($rt==='') continue; ?>
                                            <span class="tag inline-block bg-gray-100 border border-gray-300 text-gray-700 rounded-full px-2 py-1 text-xs cursor-pointer hover:bg-gray-200" data-tag="<?= e($rt) ?>">#<?= e($rt) ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="no-tags text-xs text-gray-500">Etiket bulunamadı.</div>
                                <?php endif; ?>
                                <div class="pt-2">
                                    <a class="inline-flex items-center px-3 py-1.5 rounded-md border border-indigo-300 bg-indigo-50 text-indigo-700 hover:bg-indigo-100 text-xs" href="video.php?id=<?= e($id) ?>&back=<?= e(urlencode($_SERVER['REQUEST_URI'] ?? 'index.php')) ?>">🔎 Bu Videoyu Analiz Et</a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <?php if ($debug && $query !== ''): ?>
            <div class="card" style="margin-top:12px;padding:12px;">
                <details>
                    <summary>Ham arama yanıtı (debug)</summary>
                    <pre style="white-space:pre-wrap;word-break:break-word;"><?= e(json_encode($search, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
                </details>
            </div>
        <?php endif; ?>
    </div>
    <script>
    (function(){
        const input = document.getElementById('collected');
        const copyBtn = document.getElementById('copyTags');
        const clearBtn = document.getElementById('clearTags');
        const sortSelect = document.getElementById('sortSelect');
        const results = document.querySelector('.results');
        const load = () => {
            try { input.value = localStorage.getItem('collectedTags') || ''; } catch(_) {}
        };
        const save = () => {
            try { localStorage.setItem('collectedTags', input.value); } catch(_) {}
        };
        load();
        document.addEventListener('click', function(ev){
            const el = ev.target.closest('.tag');
            if (!el) return;
            const tag = (el.getAttribute('data-tag') || '').trim();
            if (!tag) return;
            ev.preventDefault();
            // Mevcut virgülle ayrılmış listeden set oluştur
            const current = input.value.trim();
            const arr = current ? current.split(',').map(s=>s.trim()).filter(Boolean) : [];
            if (!arr.includes(tag)) arr.push(tag);
            input.value = arr.join(', ');
            save();
        });
        copyBtn.addEventListener('click', async () => {
            input.select();
            try { await navigator.clipboard.writeText(input.value); } catch(_) {}
        });
        clearBtn.addEventListener('click', () => { input.value=''; save(); });
        function sortCards(mode){
            if (!results) return;
            const cards = Array.from(results.querySelectorAll('.card'));
            const key = {
                views: c => parseInt(c.getAttribute('data-views')||'0', 10),
                likes: c => parseInt(c.getAttribute('data-likes')||'0', 10),
                date:  c => Date.parse(c.getAttribute('data-date')||'') || 0,
            }[mode];
            if (!key) return; // relevance = mevcut sıra
            cards.sort((a,b)=>{
                const av = key(a); const bv = key(b);
                return bv - av; // desc
            });
            cards.forEach(c=>results.appendChild(c));
        }
        if (sortSelect) {
            sortSelect.addEventListener('change', function(){
                const mode = this.value;
                if (mode === 'relevance') return; // mevcut sıra
                sortCards(mode);
            });
            // Sayfa ilk yüklendiğinde seçili moda göre uygula
            if (sortSelect.value !== 'relevance') sortCards(sortSelect.value);
        }
        window.analyzeSearchNow = function(){
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'analyze.php';
            const add = (k,v)=>{ const i=document.createElement('input'); i.type='hidden'; i.name=k; i.value=v; form.appendChild(i); };
            add('mode','search');
            add('q', <?= json_encode($query) ?>);
            add('back', window.location.href);
            document.body.appendChild(form);
            form.submit();
        }
    })();
    </script>

    <?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
