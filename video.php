<?php
declare(strict_types=1);

$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/lib/Database.php';
require_once __DIR__ . '/lib/Auth.php';
require_once __DIR__ . '/lib/UserManager.php';
require_once __DIR__ . '/lib/AIProvider.php';
require_once __DIR__ . '/lib/Cache.php';
require_once __DIR__ . '/lib/History.php';
require_once __DIR__ . '/lib/VideoId.php';
require_once __DIR__ . '/lib/AssetDownloader.php';
require_once __DIR__ . '/lib/RapidApiClient.php';
require_once __DIR__ . '/services/YoutubeService.php';
require_once __DIR__ . '/services/TranscriptService.php';
require_once __DIR__ . '/services/VideoAssetManager.php';
require_once __DIR__ . '/lib/DbMigrator.php';
require_once __DIR__ . '/lib/AppState.php';

// Initialize
Database::init($config['database']);
Auth::startSession($config['session']);
Auth::requireLogin();
DbMigrator::run();

function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
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

// Build details array from DB row to avoid network fetch
function buildDetailsFromDb(array $row): array {
    $tags = [];
    if (!empty($row['tags'])) {
        $t = json_decode($row['tags'], true);
        if (is_array($t)) $tags = $t;
    }
    $thumbs = [];
    if (!empty($row['thumbnails'])) {
        $th = json_decode($row['thumbnails'], true);
        if (is_array($th)) $thumbs = $th;
    }
    $raw = [
        'title' => $row['title'] ?? '',
        'description' => $row['description'] ?? '',
        'channelTitle' => $row['channel_title'] ?? '',
        'publishDate' => $row['published_at'] ?? null,
        'thumbnails' => $thumbs ?: null,
    ];
    return [
        'snippet' => [
            'title' => $row['title'] ?? '',
            'publishedAt' => $row['published_at'] ?? null,
            'tags' => $tags,
        ],
        'statistics' => [
            'viewCount' => isset($row['view_count']) ? (int)$row['view_count'] : 0,
            'likeCount' => isset($row['like_count']) ? (int)$row['like_count'] : 0,
        ],
        'raw' => $raw,
    ];
}

$rapidKey = (string)($config['rapidapi_key'] ?? '');
$rapidHost = (string)($config['rapidapi_host'] ?? 'yt-api.p.rapidapi.com');
$cacheTtl = (int)($config['cache_ttl_seconds'] ?? 21600);

$idParam = isset($_REQUEST['id']) ? trim((string)$_REQUEST['id']) : '';
$urlParam = isset($_REQUEST['url']) ? trim((string)$_REQUEST['url']) : '';
$back = isset($_REQUEST['back']) ? trim((string)$_REQUEST['back']) : '';
$action = isset($_POST['action']) ? (string)$_POST['action'] : '';
$lang = isset($_POST['lang']) ? (string)$_POST['lang'] : 'tr';
$analysisType = isset($_POST['analysis_type']) ? (string)$_POST['analysis_type'] : '';
$customPrompt = isset($_POST['custom_prompt']) ? trim((string)$_POST['custom_prompt']) : '';
$saveResult = isset($_POST['save']) && $_POST['save'] === '1';
$debug = isset($_GET['debug']) && $_GET['debug'] === '1';

$error = '';
$infoMsg = '';
$aiResult = '';
$videoId = VideoId::parse($idParam ?: $urlParam ?: '');
$loadResultId = isset($_GET['load_result']) ? (int)$_GET['load_result'] : 0;
$offline = AppState::isOffline($config);

$client = null;
$yt = null;
$tsService = null;
if ($rapidKey) {
    $client = new RapidApiClient($rapidKey, $rapidHost);
    $yt = new YoutubeService($client, $rapidHost);
    $transcriptHost = $config['transcript_api_host'] ?? 'youtube-transcripts.p.rapidapi.com';
    $tsService = new TranscriptService(
        $client,
        $rapidHost,
        new RapidApiClient($rapidKey, $transcriptHost),
        $transcriptHost
    );
}

// Preload details without network: DB first, then file cache
$cache = new Cache(__DIR__ . '/storage/cache');
$details = null;
if ($videoId) {
    $rowDb = Database::selectOne("SELECT * FROM video_metadata WHERE video_id = ?", [$videoId]);
    if ($rowDb) {
        $details = buildDetailsFromDb($rowDb);
    } else {
        $ckey = 'video:' . $rapidHost . ':' . $videoId;
        $c = $cache->get($ckey, $cacheTtl);
        if ($c && isset($c['details'])) $details = $c['details'];
    }
}

// Auto-collect assets on initial GET (thumbnail + transcript if missing) and write to History
if (!$error && $videoId && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $raw0 = $details['raw'] ?? [];
    VideoAssetManager::ensureVideo($videoId, [
        'title' => $raw0['title'] ?? '',
        'description' => $raw0['description'] ?? '',
        'channel_title' => $raw0['channelTitle'] ?? ($raw0['channel']['title'] ?? ''),
        'published_at' => $details['snippet']['publishedAt'] ?? ($raw0['publishDate'] ?? null),
        'tags' => $details['snippet']['tags'] ?? [],
        'thumbnails' => $raw0['thumbnails'] ?? null,
        'raw' => $raw0,
    ]);
$dir0 = storage_base_dir() . '/video_' . $videoId;
    @mkdir($dir0, 0777, true);
    // Store thumbnail inside the video-specific folder
    $thumbPath0 = $dir0 . '/' . $videoId . '.jpg';
    if (!is_file($thumbPath0)) {
        $turl0 = '';
        if (!empty($raw0['thumbnails'])) $turl0 = selfPickThumbUrl($raw0['thumbnails']);
        if (!$turl0) $turl0 = 'https://i.ytimg.com/vi/' . $videoId . '/hqdefault.jpg';
        $dl0 = AssetDownloader::download($turl0, $thumbPath0);
        if ($dl0['success']) {
            VideoAssetManager::saveThumbPath($videoId, $thumbPath0);
        }
    } else {
        VideoAssetManager::saveThumbPath($videoId, $thumbPath0);
    }
    $hasDbTranscript = (bool)Database::selectOne("SELECT id FROM video_transcripts WHERE video_id = ? LIMIT 1", [$videoId]);
    $existing0 = glob($dir0 . '/*_transcript.txt');
if (!$hasDbTranscript && !$existing0 && $tsService && !$offline) {
        if (UserManager::checkDataQueryLimit(Auth::userId())) {
            $tr0 = $tsService->getTranscript($videoId, ['tr','en']);
            if ($tr0['success']) {
                $l0 = $tr0['lang'] ?: 'auto';
                $j0 = $dir0 . '/' . $videoId . '_transcript.json';
                $t0 = $dir0 . '/' . $videoId . '_transcript.txt';
                @file_put_contents($j0, json_encode(['segments' => $tr0['segments']], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                @file_put_contents($t0, $tr0['text']);
                $prov0 = $tr0['provider'] ?? $rapidHost;
                VideoAssetManager::saveTranscriptPaths($videoId, $l0, $j0, $t0, $prov0);
                UserManager::incrementDataQueryCount(Auth::userId());
                UserManager::logActivity(Auth::userId(), 'fetch_transcript', ['videoId' => $videoId, 'lang' => $l0]);
            }
        }
    }

    // Geçmişe 'video' kaydı (tekilleştirilmiş)
    try {
        $userId = Auth::userId();
        $historyV = new History($userId);
        $title0 = $raw0['title'] ?? ($details['snippet']['title'] ?? '');
        $meta0 = [
            'type' => 'video',
            'title' => $title0,
            'has_thumb' => is_file($thumbPath0),
            'has_transcript' => (bool)glob($dir0 . '/*_transcript.txt'),
            'host' => $rapidHost,
        ];
        // Son kaydı bul; varsa güncelle, yoksa ekle
        $last = Database::selectOne(
            "SELECT id, created_at FROM search_history WHERE user_id = ? AND search_type = 'video' AND query = ? ORDER BY created_at DESC LIMIT 1",
            [$userId, $videoId]
        );
        if ($last) {
            Database::update(
                'search_history',
                [
                    'metadata' => json_encode($meta0 + ['count' => 1], JSON_UNESCAPED_UNICODE),
                    'result_count' => 1,
                ],
                'id = ?',
                [$last['id']]
            );
        } else {
            $historyV->addSearch($videoId, $meta0 + ['count' => 1]);
        }
    } catch (Exception $e) { /* ignore */ }
}

// As a last resort, fetch details from API if still missing
if (!$error && $videoId && !$details && $yt && !$offline) {
    $map = $yt->videosDetails([$videoId]);
    if (empty($map['error'])) {
        $details = $map[$videoId] ?? null;
        if ($details) $cache->set('video:' . $rapidHost . ':' . $videoId, ['details' => $details]);
    }
}

$cache = new Cache(__DIR__ . '/storage/cache');
$details = null; // normalized map entry like from YoutubeService::videosDetails

if ($videoId) {
    $cacheKey = 'video:' . $rapidHost . ':' . $videoId;
    $cached = $cache->get($cacheKey, $cacheTtl);
    if ($cached && isset($cached['details'])) {
        $details = $cached['details'];
    } elseif ($yt) {
        $map = $yt->videosDetails([$videoId]);
        if (!empty($map['error'])) {
            $error = 'Video detayları alınamadı: ' . e((string)$map['error']);
        } else {
            $details = $map[$videoId] ?? null;
            if ($details) {
                $cache->set($cacheKey, ['details' => $details]);
            }
        }
    } else {
        $error = 'RapidAPI anahtarı ayarlı değil.';
    }
}

// Handle POST actions (requires $videoId)
if (!$error && $videoId && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Download thumbnail
    if ($action === 'download_thumb') {
        if (!UserManager::checkDataQueryLimit(Auth::userId())) {
            $rem = UserManager::getRemainingDataQueries(Auth::userId());
            $error = 'Günlük veri sorgu limitinize ulaştınız. Kalan: ' . ($rem['daily'] === -1 ? 'Sınırsız' : $rem['daily']);
        } else {
            $thumbUrl = '';
            // Try details.raw thumbnails
            $raw = $details['raw'] ?? [];
            if (!empty($raw['thumbnails'])) {
                // pick the best looking (maxres/hq)
                $thumbUrl = selfPickThumbUrl($raw['thumbnails']);
            }
            // Fallbacks
            if (!$thumbUrl) {
                $cands = [
                    'https://i.ytimg.com/vi/' . $videoId . '/maxresdefault.jpg',
                    'https://i.ytimg.com/vi/' . $videoId . '/sddefault.jpg',
                    'https://i.ytimg.com/vi/' . $videoId . '/hqdefault.jpg',
                    'https://i.ytimg.com/vi/' . $videoId . '/mqdefault.jpg',
                ];
                foreach ($cands as $u) { $thumbUrl = $u; break; }
            }
            if ($thumbUrl) {
                $dir = __DIR__ . '/output/video_' . $videoId;
                @mkdir($dir, 0777, true);
                $dest = $dir . '/' . $videoId . '.jpg';
                $dl = AssetDownloader::download($thumbUrl, $dest);
                if ($dl['success']) {
                    $infoMsg = 'Thumbnail indirildi: ' . basename($dest) . ' (' . number_format($dl['bytes']) . ' bayt)';
                    VideoAssetManager::saveThumbPath($videoId, $dest);
                    // Thumbnail indirme bir data sorgusu sayılmaz
                    UserManager::logActivity(Auth::userId(), 'fetch_thumbnail', ['videoId' => $videoId]);
                } else {
                    $error = 'Thumbnail indirilemedi: ' . e((string)$dl['error']);
                }
            } else {
                $error = 'Uygun thumbnail URL bulunamadı.';
            }
        }
    }

    // Fetch transcript
    if (!$error && $action === 'fetch_transcript') {
        if (!UserManager::checkDataQueryLimit(Auth::userId())) {
            $rem = UserManager::getRemainingDataQueries(Auth::userId());
            $error = 'Günlük veri sorgu limitinize ulaştınız. Kalan: ' . ($rem['daily'] === -1 ? 'Sınırsız' : $rem['daily']);
        } else {
            if (!$tsService) {
                $error = 'Transcript servisi hazır değil (RapidAPI yapılandırmasını kontrol edin).';
            } else {
                $langs = array_unique(array_values(array_filter([$lang, 'tr', 'en'])));
                $res = $tsService->getTranscript($videoId, $langs);
                if ($res['success']) {
                    $dir = __DIR__ . '/output/video_' . $videoId;
                    @mkdir($dir, 0777, true);
                    $l = $res['lang'] ?: 'auto';
                    $jsonPath = $dir . '/' . $videoId . '_transcript.json';
                    $txtPath = $dir . '/' . $videoId . '_transcript.txt';
                    @file_put_contents($jsonPath, json_encode(['segments' => $res['segments']], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                    @file_put_contents($txtPath, $res['text']);
                    $infoMsg = 'Transkript alındı: ' . basename($jsonPath) . ' ve ' . basename($txtPath);
                    VideoAssetManager::saveTranscriptPaths($videoId, $l, $jsonPath, $txtPath, $rapidHost);
                    UserManager::incrementDataQueryCount(Auth::userId());
                    UserManager::logActivity(Auth::userId(), 'fetch_transcript', ['videoId' => $videoId, 'lang' => $l]);
                } else {
                    $error = 'Transkript alınamadı: ' . e((string)$res['error']);
                }
            }
        }
    }

    // AI analysis (type or custom prompt)
    if (!$error && ($action === 'analyze')) {
        // Build context data
        $title = (string)($details['raw']['title'] ?? ($details['snippet']['title'] ?? ''));
        $desc = (string)($details['raw']['description'] ?? ($details['description'] ?? ''));
        if (strlen($desc) > 2000) $desc = substr($desc, 0, 2000) . '...';
        $tags = $details['snippet']['tags'] ?? [];
        $views = (int)($details['statistics']['viewCount'] ?? 0);
        $likes = (int)($details['statistics']['likeCount'] ?? 0);
        $publishedAt = (string)($details['snippet']['publishedAt'] ?? ($details['raw']['publishDate'] ?? ''));
        $channel = (string)($details['raw']['channelTitle'] ?? ($details['raw']['channel']['title'] ?? ''));

        // Try to include transcript summary if exists
        $transcriptText = '';
        $glob = glob(storage_base_dir() . '/video_' . $videoId . '/*_transcript.txt');
        if ($glob) {
            $t = @file_get_contents($glob[0]);
            if ($t !== false) {
                $transcriptText = (string)$t;
                if (strlen($transcriptText) > 2000) $transcriptText = substr($transcriptText, 0, 2000) . '...';
            }
        }

        // Prepare messages
        $messages = [];
        $messages[] = ['role' => 'system', 'content' => 'Sen YouTube videolarını analiz eden bir uzmansın. Türkçe ve maddeler halinde net, somut öneriler ver.'];
        $context = [
            'title' => $title,
            'description' => $desc,
            'tags' => $tags,
            'metrics' => ['views' => $views, 'likes' => $likes],
            'publishedAt' => $publishedAt,
            'channel' => $channel,
            'transcript' => $transcriptText ? '[TRUNCATED] ' . $transcriptText : null,
        ];
        $messages[] = ['role' => 'user', 'content' => 'Video verileri:\n' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)];

        // Task prompt: prefer DB template if available
        $task = '';
        if ($analysisType) {
            $tplRow = Database::selectOne("SELECT prompt_template FROM analysis_prompts WHERE analysis_type = ? LIMIT 1", [$analysisType]);
            if ($tplRow && !empty($tplRow['prompt_template'])) {
                $task = (string)$tplRow['prompt_template'];
            }
        }
        // Fallbacks
        switch ($task ? '__db__' : $analysisType) {
            case 'title':
                $task = "Başlığı CTR odaklı iyileştir. 10-20 öneri üret, numaralı liste yap.";
                break;
            case 'description':
                $task = "Açıklamayı SEO ve izlenme açısından iyileştir. 2-3 şablon öner.";
                break;
            case 'tags':
                $task = "TR odaklı 15-25 etkili etiket öner. Kümeler halinde ver.";
                break;
            case 'seo':
                $task = "Güçlü/zayıf yönleri analiz et ve hızlı kazanım önerileri sun. Maddeler halinde yaz.";
                break;
            case 'thumb-hook':
                $task = "Thumbnail metni ve hook önerileri üret. 10 kısa, vurucu öneri ver.";
                break;
            default:
                $task = $customPrompt ?: 'Genel analiz yap.';
        }
        $messages[] = ['role' => 'user', 'content' => $task];

        // Limits and provider
        if (!UserManager::checkAnalysisQueryLimit(Auth::userId())) {
            $rem = UserManager::getRemainingAnalysisQueries(Auth::userId());
            $error = 'Günlük analiz sorgu limitinize ulaştınız. Kalan: ' . ($rem['daily'] === -1 ? 'Sınırsız' : $rem['daily']);
        } else {
            $aiProvider = new AIProvider($config['ai_providers'] ?? [], (int)($config['ai_error_reset_time'] ?? 300));
            // Count attempt
            UserManager::incrementAnalysisQueryCount(Auth::userId());
            $apiResult = $aiProvider->request($messages);
            if ($apiResult['success']) {
                $aiResult = (string)$apiResult['data'];
                UserManager::logActivity(Auth::userId(), 'video_analysis', ['videoId' => $videoId, 'type' => $analysisType ?: 'custom', 'status' => 'success']);

                // Optional save immediately
                if ($saveResult && $aiResult) {
                $dir = storage_base_dir() . '/video_' . $videoId;
                @mkdir($dir, 0777, true);
                $ts = date('Ymd_His');
                $typeSlug = $analysisType ?: 'custom';
                $path = $dir . '/analysis_' . $ts . '_' . $typeSlug . '.md';
                @file_put_contents($path, $aiResult);
                // Save DB record
                    try {
                        Database::insert('analysis_results', [
                            'user_id' => Auth::user()['id'] ?? null,
                            'analysis_type' => $typeSlug,
                            'mode' => 'video',
                            'query' => $videoId,
                            'input_data' => json_encode($context, JSON_UNESCAPED_UNICODE),
                            'prompt' => $task,
                            'ai_provider' => $apiResult['provider'] ?? 'unknown',
                            'ai_model' => '',
                            'result' => $aiResult,
                            'is_saved' => true,
                            'file_path' => $path,
                        ]);
                    } catch (Exception $e) { /* ignore */ }
                    $infoMsg = 'Analiz kaydedildi: ' . basename($path);
                }
            } else {
                $error = 'AI isteği başarısız: ' . e((string)$apiResult['error']);
                UserManager::logActivity(Auth::userId(), 'video_analysis', ['videoId' => $videoId, 'type' => $analysisType ?: 'custom', 'status' => 'failed']);
            }
        }
    }
}

// Load previously saved analysis if requested
if (!$error && $videoId && $loadResultId > 0) {
    $rowPrev = Database::selectOne("SELECT result FROM analysis_results WHERE id = ? AND mode='video' AND query = ?", [$loadResultId, $videoId]);
    if ($rowPrev && !empty($rowPrev['result'])) {
        $aiResult = (string)$rowPrev['result'];
        $infoMsg = 'Önceden kaydedilmiş analiz yüklendi.';
    }
}

function selfPickThumbUrl($thumbs): string
{
    // Try common structures
    if (is_array($thumbs)) {
        // If it is an associative array with quality keys
        foreach (['maxres', 'maxresdefault', 'high', 'hqdefault', 'standard', 'sddefault', 'medium', 'mqdefault', 'default'] as $k) {
            if (isset($thumbs[$k]['url'])) return (string)$thumbs[$k]['url'];
        }
        // If it is an array of objects
        foreach ($thumbs as $t) {
            if (is_array($t) && !empty($t['url'])) return (string)$t['url'];
        }
    }
    return '';
}

?><!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Tekli Video Analizi</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="assets/styles.css">
    <style>
        .alert{background:#fff7ed;border:1px solid #fed7aa;color:#7c2d12;border-radius:8px;padding:10px;margin-bottom:12px}
    </style>
    </head>
<body class="bg-gray-50">
<div class="container mx-auto max-w-5xl px-4 py-6">
    <?php include __DIR__ . '/includes/navbar.php'; ?>

    <?php if ($back): ?>
        <div class="mb-4">
            <a class="text-sm px-3 py-2 rounded-md border border-gray-300 bg-gray-100 text-gray-800 hover:bg-gray-200" href="<?= e($back) ?>">← Geri</a>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert">❌ <?= e($error) ?></div>
    <?php endif; ?>
    <?php if ($infoMsg): ?>
        <div class="alert" style="background:#ecfdf5;border-color:#a7f3d0;color:#065f46;">✅ <?= e($infoMsg) ?></div>
    <?php endif; ?>

    <?php if (!$videoId): ?>
        <div class="bg-white border border-gray-200 rounded-xl p-6">
            <h2 class="text-lg font-semibold mb-3">Tekli Video Analizi</h2>
            <form method="get" class="flex gap-2">
                <input type="text" name="url" class="flex-1 px-3 py-2 rounded-md border border-gray-300" placeholder="Video URL veya ID">
                <button class="px-3 py-2 rounded-md border border-gray-300 bg-gray-100 hover:bg-gray-200" type="submit">Aç</button>
            </form>
        </div>
    <?php else: ?>
        <?php
        $title = (string)($details['raw']['title'] ?? ($details['snippet']['title'] ?? ''));
        $desc = (string)($details['raw']['description'] ?? ($details['description'] ?? ''));
        $tags = $details['snippet']['tags'] ?? [];
        $views = (int)($details['statistics']['viewCount'] ?? 0);
        $likes = (int)($details['statistics']['likeCount'] ?? 0);
        $publishedAt = (string)($details['snippet']['publishedAt'] ?? ($details['raw']['publishDate'] ?? ''));
        $thumb = '';
        $raw = $details['raw'] ?? [];
        if (!empty($raw['thumbnails'])) $thumb = selfPickThumbUrl($raw['thumbnails']);
        if (!$thumb) $thumb = 'https://i.ytimg.com/vi/' . $videoId . '/hqdefault.jpg';
        ?>

        <div class="bg-white border border-gray-200 rounded-xl p-6 mb-6">
            <div class="flex gap-4">
                <div class="w-56 shrink-0">
                    <img class="w-full rounded-lg border" src="<?= e($thumb) ?>" alt="<?= e($title) ?>">
                </div>
                <div class="flex-1">
                    <h1 class="text-xl font-semibold mb-1"><?= e($title) ?></h1>
                    <?php if (Auth::isAdmin()): ?>
                        <div class="text-xs inline-block px-2 py-0.5 rounded-full border <?= $offline ? 'border-yellow-300 bg-yellow-50 text-yellow-800' : 'border-gray-300 bg-gray-100 text-gray-600' ?>">Kaynak: <?= $details ? 'DB/Cache' : ($offline ? 'Offline' : 'API') ?></div>
                    <?php endif; ?>
                    <div class="text-sm text-gray-600 mb-2">Video ID: <code><?= e($videoId) ?></code></div>
                    <div class="text-sm text-gray-600 mb-2 flex gap-4">
                        <span>👁 <?= number_format($views) ?></span>
                        <span>❤️ <?= number_format($likes) ?></span>
                        <?php if ($publishedAt): ?><span>🗓 <?= e($publishedAt) ?></span><?php endif; ?>
                    </div>
                    <?php if ($tags): ?>
                        <div class="flex flex-wrap gap-2 mb-2">
                            <?php foreach ($tags as $t): $rt = trim((string)$t); if ($rt==='') continue; ?>
                                <span class="inline-block bg-gray-100 border border-gray-300 text-gray-700 rounded-full px-2 py-1 text-xs">#<?= e($rt) ?></span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <?php if ($desc): ?>
                        <p class="text-sm text-gray-700" style="display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden;"><?= e($desc) ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="grid md:grid-cols-2 gap-6">
            <div class="bg-white border border-gray-200 rounded-xl p-6">
                <h3 class="font-semibold mb-3">📥 Thumbnail / Transkript</h3>
                <?php
                    // Prepare direct thumbnail open link (serve-file if exists; else YouTube URL)
                    $thumbRel = 'video_' . $videoId . '/' . $videoId . '.jpg';
                    $thumbFsPathBtn = storage_base_dir() . '/' . $thumbRel;
                    $thumbOpenUrl = is_file($thumbFsPathBtn)
                        ? ('serve-file.php?p=' . urlencode($thumbRel))
                        : ($thumb ? $thumb : ('https://i.ytimg.com/vi/' . $videoId . '/hqdefault.jpg'));
                ?>
                <div class="flex gap-2 mb-3">
                    <a href="<?= e($thumbOpenUrl) ?>" target="_blank" class="px-3 py-2 rounded-md border border-gray-300 bg-gray-100 hover:bg-gray-200">Thumbnail’ı Aç</a>
                </div>
                <?php
                    $transRelBtn = 'video_' . $videoId . '/' . $videoId . '_transcript.txt';
                    $transFsPathBtn = storage_base_dir() . '/' . $transRelBtn;
                    $transOpenUrl = is_file($transFsPathBtn) ? ('serve-file.php?p=' . urlencode($transRelBtn)) : '';
                ?>
                <div class="flex gap-2 items-center">
                    <?php if ($transOpenUrl): ?>
                        <a href="<?= e($transOpenUrl) ?>" target="_blank" class="px-3 py-2 rounded-md border border-gray-300 bg-gray-100 hover:bg-gray-200">Transkripti Aç</a>
                    <?php else: ?>
                        <span class="text-sm text-gray-500">Transkript henüz mevcut değil.</span>
                    <?php endif; ?>
                </div>
                <div class="mt-3 text-sm text-gray-600">
                    <?php
                        $base = storage_base_dir();
                        $thumbFile = 'video_' . $videoId . '/' . $videoId . '.jpg';
                        $thumbFsPath = $base . '/' . $thumbFile;
                        $transDir = $base . '/video_' . $videoId;
                        $transcripts = glob($transDir . '/*_transcript.txt') ?: [];
                    ?>
                    <div class="space-y-1">
                        <div>Thumbnail:
                            <?php if (is_file($thumbFsPath)): ?>
                                <a class="underline" href="serve-file.php?p=<?= urlencode($thumbFile) ?>" target="_blank"><?= e($videoId . '.jpg') ?></a>
                            <?php else: ?>
                                <span class="text-gray-500">—</span>
                            <?php endif; ?>
                        </div>
                        <div>Transkriptler:
                            <?php if ($transcripts): ?>
                                <?php foreach ($transcripts as $tf): $rel = ltrim(str_replace($base, '', $tf), '/'); ?>
                                    <a class="underline mr-2" href="serve-file.php?p=<?= urlencode($rel) ?>" target="_blank"><?= e(basename($tf)) ?></a>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <span class="text-gray-500">—</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white border border-gray-200 rounded-xl p-6">
                <h3 class="font-semibold mb-3">🧠 AI Analiz</h3>
                <form method="post" class="space-y-3">
                    <input type="hidden" name="id" value="<?= e($videoId) ?>">
                    <input type="hidden" name="back" value="<?= e($back) ?>">
                    <div class="flex gap-2">
                        <select name="analysis_type" class="px-2 py-2 rounded-md border border-gray-300">
                            <option value="title">Başlık İyileştirme</option>
                            <option value="description">Açıklama İyileştirme</option>
                            <option value="tags">Etiket Önerileri</option>
                            <option value="seo">SEO Özeti</option>
                            <option value="thumb-hook">Thumbnail Metni/Hook</option>
                            <option value="custom">Özel İstek</option>
                        </select>
                        <label class="inline-flex items-center gap-2 text-sm text-gray-700"><input type="checkbox" name="save" value="1"> Kaydet</label>
                    </div>
                    <textarea name="custom_prompt" rows="4" class="w-full px-3 py-2 rounded-md border border-gray-300" placeholder="Özel analiz isteğiniz (opsiyonel)"></textarea>
                    <div>
                        <button name="action" value="analyze" class="px-3 py-2 rounded-md border border-gray-300 bg-indigo-600 text-white hover:bg-indigo-700">Analiz Et</button>
                    </div>
                </form>
                <?php if ($aiResult): ?>
                    <div class="mt-3">
                        <h4 class="font-semibold mb-2">Sonuç</h4>
                        <pre class="text-sm whitespace-pre-wrap break-words p-3 border rounded-md bg-gray-50"><?= e($aiResult) ?></pre>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="bg-white border border-gray-200 rounded-xl p-6 mt-6">
            <h3 class="font-semibold mb-3">📚 Önceden Kaydedilmiş Analizler</h3>
            <?php $saved = Database::select("SELECT id, analysis_type, created_at FROM analysis_results WHERE mode='video' AND query = ? ORDER BY created_at DESC LIMIT 20", [$videoId]); ?>
            <?php if (!$saved): ?>
                <div class="text-sm text-gray-500">Kayıtlı analiz bulunamadı.</div>
            <?php else: ?>
                <ul class="text-sm text-gray-700 space-y-2">
                    <?php foreach ($saved as $s): ?>
                        <li class="flex items-center justify-between">
                            <span>
                                <span class="inline-block rounded-full border border-indigo-300 bg-indigo-50 text-indigo-700 px-2 py-0.5 text-xs"><?= e($s['analysis_type']) ?></span>
                                <span class="ml-2 text-gray-500"><?= e(date('Y-m-d H:i', strtotime($s['created_at']))) ?></span>
                            </span>
                            <a class="px-2 py-1 rounded-md border border-gray-300 bg-gray-100 hover:bg-gray-200" href="?id=<?= e($videoId) ?>&load_result=<?= (int)$s['id'] ?>">Görüntüle</a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <?php if ($debug): ?>
            <div class="bg-white border border-gray-200 rounded-xl p-6 mt-6">
                <h3 class="font-semibold mb-2">Debug</h3>
                <pre class="text-xs whitespace-pre-wrap break-words"><?= e(json_encode($details['raw'] ?? $details, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) ?></pre>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
</body>
</html>
