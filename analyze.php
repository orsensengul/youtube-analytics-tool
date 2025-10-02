<?php
declare(strict_types=1);

$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/lib/Database.php';
require_once __DIR__ . '/lib/Auth.php';

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

$mode = isset($_POST['mode']) ? (string)$_POST['mode'] : '';
$variant = isset($_POST['variant']) ? (string)$_POST['variant'] : 'short';
$payload = isset($_POST['payload']) ? (string)$_POST['payload'] : '';
$back = isset($_POST['back']) ? (string)$_POST['back'] : '';
$analysisType = isset($_POST['analysis']) ? (string)$_POST['analysis'] : '';
$customPrompt = isset($_POST['custom_prompt']) ? trim((string)$_POST['custom_prompt']) : '';
$followUp = isset($_POST['follow_up']) ? trim((string)$_POST['follow_up']) : '';
$saving = isset($_POST['save']) && $_POST['save'] === '1';
$aiKey = (string)($config['ai_api_key'] ?? '');
$aiEndpoint = (string)($config['ai_endpoint'] ?? '');
$aiModel = (string)($config['ai_model'] ?? '');

// Chat history management
if (!isset($_SESSION['chat_history'])) {
    $_SESSION['chat_history'] = [];
}

// Clear chat if requested
if (isset($_POST['clear_chat'])) {
    $_SESSION['chat_history'] = [];
}

// Get current chat history
$chatHistory = $_SESSION['chat_history'];

$error = '';
$result = isset($_POST['result']) ? (string)$_POST['result'] : '';
$json = [];
$limited = [];
$items = [];
$savedPath = '';

// Handle saved JSON file selection
if (isset($_POST['saved_json']) && !empty($_POST['saved_json'])) {
    $savedJsonPath = $_POST['saved_json'];
    // Security: ensure path is within output directory
    $outputDir = __DIR__ . '/output';
    $realPath = realpath($outputDir . '/' . $savedJsonPath);

    if ($realPath && strpos($realPath, $outputDir) === 0 && file_exists($realPath)) {
        $fileContent = file_get_contents($realPath);
        if ($fileContent !== false) {
            $payload = $fileContent;
            // Extract mode and query from path
            if (strpos($savedJsonPath, 'channel_') === 0) {
                $mode = 'channel';
            } else {
                $mode = 'search';
            }
        } else {
            $error = 'Kaydedilmiş dosya okunamadı.';
        }
    } else {
        $error = 'Geçersiz dosya seçimi.';
    }
}

// Handle file upload
if (isset($_FILES['json_file']) && $_FILES['json_file']['error'] === UPLOAD_ERR_OK) {
    $uploadedFile = $_FILES['json_file']['tmp_name'];
    $fileContent = file_get_contents($uploadedFile);

    if ($fileContent === false) {
        $error = 'Dosya okunamadı.';
    } else {
        $payload = $fileContent;
        // Mode ve query bilgilerini POST'tan al
        $mode = isset($_POST['mode']) ? (string)$_POST['mode'] : 'search';
        $q = isset($_POST['q']) ? (string)$_POST['q'] : '';
    }
}

if ($payload !== '') {
    $json = json_decode($payload, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($json)) {
        $error = 'Geçersiz JSON gönderildi. JSON formatını kontrol edin.';
    } else {
        $items = $json['items'] ?? [];
        if (!is_array($items)) $items = [];
        $limited = array_slice($items, 0, 30); // hep 30
    }
}

// Get saved JSON files for selection
function getSavedJsonFiles(): array {
    $outputDir = __DIR__ . '/output';
    $files = [];

    if (!is_dir($outputDir)) {
        return $files;
    }

    $dirs = glob($outputDir . '/*', GLOB_ONLYDIR);
    foreach ($dirs as $dir) {
        $jsonFiles = glob($dir . '/*.json');
        foreach ($jsonFiles as $jsonFile) {
            $relativePath = str_replace($outputDir . '/', '', $jsonFile);
            $fileName = basename($jsonFile);
            $dirName = basename(dirname($jsonFile));

            // Get file info
            $fileSize = filesize($jsonFile);
            $fileTime = filemtime($jsonFile);

            $files[] = [
                'path' => $relativePath,
                'name' => $fileName,
                'dir' => $dirName,
                'size' => $fileSize,
                'time' => $fileTime,
                'display' => $dirName . ' / ' . $fileName
            ];
        }
    }

    // Sort by time descending
    usort($files, function($a, $b) {
        return $b['time'] - $a['time'];
    });

    return $files;
}

$savedJsonFiles = getSavedJsonFiles();

function getAnalysisTypeLabel(string $type): string {
    $labels = [
        'descriptions' => '📝 Açıklamalar Analizi',
        'tags' => '🏷️ Etiketler Analizi',
        'titles' => '📌 Başlıklar Analizi',
        'seo' => '🔍 SEO Analizi',
        'auto-title-generator' => '✨ Otomatik Başlık Üretici',
        'performance-prediction' => '📈 Performans Tahmini',
        'content-gaps' => '🔎 İçerik Boşlukları',
        'trending-topics' => '🔥 Trend Konular',
        'engagement-rate' => '💬 Etkileşim Oranı',
        'best-performers' => '⭐ En İyi Performans',
    ];
    return $labels[$type] ?? '📊 Genel Analiz';
}

function build_prompt(string $type, array $data, string $mode): string {
    $intro = "Aşağıda $mode için 'items' listesinden 30 öğeye kadar özet alanlar var. Türkçe ve maddeler halinde net analiz üret.";
    $jsonPart = json_encode($data, JSON_UNESCAPED_UNICODE);
    switch ($type) {
        case 'descriptions':
            $task = "JSON içindeki description alanlarını incele.\n- Benzerlikler, farklılıklar, ortak temalar\n- SEO ve YouTube aranma açısından güçlü/zayıf yönler\n- Geliştirme önerileri\n- 2-3 örnek optimize açıklama şablonu\nKısa, maddeli ve somut öneriler ver.";
            break;
        case 'tags':
            $task = "JSON içindeki tags alanlarını analiz et.\n- En çok kullanılan etiketler, kümeler\n- Eksik/hatalı etiketler ve öneriler\n- Aranma niyeti (intent) odaklı tag önerileri\n- 10-20 yeni öneri tag listesi (TR odaklı)";
            break;
        case 'titles':
            $task = "JSON içindeki title alanlarını analiz et.\n- Öne çıkan kalıplar\n- CTR'ı artırma önerileri\n- 5-10 örnek yeni başlık önerisi";
            break;
        case 'seo':
            $task = "Kapsamlı SEO özeti çıkar: title, description, tags, izlenme metriklerine göre genel değerlendirme ve hızlı kazanım önerileri. Maddelerle yaz.";
            break;
        case 'auto-title-generator':
            $task = "JSON'daki başarılı başlıkları analiz et ve 20 farklı yeni başlık önerisi üret.\n- 5 clickbait tarzı\n- 5 profesyonel tarzı\n- 5 eğitsel tarzı\n- 5 merak uyandıran tarzı\nHer birini numaralandır ve kategorize et.";
            break;
        case 'performance-prediction':
            $task = "İzlenme verilerini analiz et ve performans tahminleri yap.\n- En iyi performans gösteren içerik özellikleri\n- Başarı olasılığı yüksek içerik tipleri\n- Risk faktörleri\n- Gelecek içerikler için öneriler";
            break;
        case 'content-gaps':
            $task = "İçerik boşluklarını tespit et.\n- Eksik kalan konu alanları\n- Potansiyel fırsatlar\n- Rakiplerin kullandığı ama burada olmayan konular\n- 10-15 yeni içerik fikri önerisi";
            break;
        case 'trending-topics':
            $task = "Yüksek izlenme alan videoların ortak temalarını tespit et.\n- Popüler konular ve trendler\n- Hangi konular daha çok ilgi görüyor\n- Trend takip önerileri\n- Güncel trendlere uyum stratejileri";
            break;
        case 'engagement-rate':
            $task = "Etkileşim oranlarını değerlendir.\n- Like/View oranı analizi\n- En çok etkileşim alan içerik özellikleri\n- Etkileşim artırma stratejileri\n- Topluluk oluşturma önerileri";
            break;
        case 'best-performers':
            $task = "En yüksek izlenmeye sahip videoları analiz et.\n- Ortak başarı faktörleri\n- Başlık, açıklama, tag paternleri\n- Tekrarlanabilir başarı formülü\n- 5-10 somut uygulama önerisi";
            break;
        default:
            $task = "Genel analiz yap.";
    }
    return "$intro\n\nVeri (JSON):\n$jsonPart\n\nGörev:\n$task";
}

// Handle custom prompt or follow-up
if ($customPrompt && $limited && (!$saving || $result === '')) {
    if (!$aiKey || $aiKey === 'YOUR_CODEFAST_API_KEY') {
        $error = 'Lütfen config.php içinde ai_api_key değerini ayarlayın.';
    } elseif (!$aiEndpoint || !$aiModel) {
        $error = 'AI endpoint/model yapılandırması eksik.';
    } else {
        $payloadData = [];
        foreach ($limited as $it) {
            $payloadData[] = [
                'title' => $it['title'] ?? '',
                'description' => $it['description'] ?? '',
                'tags' => $it['tags'] ?? [],
                'views' => $it['metrics']['views'] ?? null,
            ];
        }

        // Build messages array with chat history
        $messages = [];

        // Add context about the data
        $dataContext = "Video verileri:\n" . json_encode($payloadData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $messages[] = ['role' => 'system', 'content' => 'Sen YouTube videolarını analiz eden bir uzmansın. Türkçe ve maddeler halinde net analizler yaparsın.'];
        $messages[] = ['role' => 'user', 'content' => $dataContext];
        $messages[] = ['role' => 'assistant', 'content' => 'Video verilerini aldım. Nasıl yardımcı olabilirim?'];

        // Add custom prompt
        $messages[] = ['role' => 'user', 'content' => $customPrompt];

        // Make API request
        $body = json_encode([
            'model' => $aiModel,
            'messages' => $messages,
            'stream' => false,
        ], JSON_UNESCAPED_UNICODE);

        $startTime = microtime(true);
        $ch = curl_init($aiEndpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $aiKey,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT => 60,
        ]);
        $resp = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) {
            $error = 'AI isteği hata: ' . $err;
        } elseif ($http >= 400) {
            $error = 'AI HTTP hata: ' . $http . ' ' . $resp;
        } else {
            $data = json_decode($resp, true);
            $content = $data['choices'][0]['message']['content'] ?? '';
            $result = is_string($content) ? $content : json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

            // Save to chat history
            $_SESSION['chat_history'][] = ['role' => 'user', 'content' => $customPrompt];
            $_SESSION['chat_history'][] = ['role' => 'assistant', 'content' => $result];
            $chatHistory = $_SESSION['chat_history'];
        }
    }
}

// Handle follow-up question
if ($followUp && !empty($chatHistory)) {
    if (!$aiKey || $aiKey === 'YOUR_CODEFAST_API_KEY') {
        $error = 'Lütfen config.php içinde ai_api_key değerini ayarlayın.';
    } elseif (!$aiEndpoint || !$aiModel) {
        $error = 'AI endpoint/model yapılandırması eksik.';
    } else {
        // Build messages from chat history + new follow-up
        $messages = [];
        $messages[] = ['role' => 'system', 'content' => 'Sen YouTube videolarını analiz eden bir uzmansın. Türkçe ve maddeler halinde net analizler yaparsın.'];

        foreach ($chatHistory as $msg) {
            $messages[] = $msg;
        }

        $messages[] = ['role' => 'user', 'content' => $followUp];

        // Make API request
        $body = json_encode([
            'model' => $aiModel,
            'messages' => $messages,
            'stream' => false,
        ], JSON_UNESCAPED_UNICODE);

        $ch = curl_init($aiEndpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $aiKey,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT => 60,
        ]);
        $resp = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) {
            $error = 'AI isteği hata: ' . $err;
        } elseif ($http >= 400) {
            $error = 'AI HTTP hata: ' . $http . ' ' . $resp;
        } else {
            $data = json_decode($resp, true);
            $content = $data['choices'][0]['message']['content'] ?? '';
            $result = is_string($content) ? $content : json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

            // Save to chat history
            $_SESSION['chat_history'][] = ['role' => 'user', 'content' => $followUp];
            $_SESSION['chat_history'][] = ['role' => 'assistant', 'content' => $result];
            $chatHistory = $_SESSION['chat_history'];
        }
    }
}

if ($analysisType && $limited && (!$saving || $result === '')) {
    if (!$aiKey || $aiKey === 'YOUR_CODEFAST_API_KEY') {
        $error = 'Lütfen config.php içinde ai_api_key değerini ayarlayın.';
    } elseif (!$aiEndpoint || !$aiModel) {
        $error = 'AI endpoint/model yapılandırması eksik.';
    } else {
        $payloadData = [];
        foreach ($limited as $it) {
            $payloadData[] = [
                'title' => $it['title'] ?? '',
                'description' => $it['description'] ?? '',
                'tags' => $it['tags'] ?? [],
                'views' => $it['metrics']['views'] ?? null,
            ];
        }
        $prompt = build_prompt($analysisType, $payloadData, $mode ?: 'search');

        // Create cache key from analysis parameters
        $cacheKeyData = [
            'type' => $analysisType,
            'mode' => $mode,
            'data' => $payloadData,
        ];
        $cacheKey = 'analysis_' . md5(json_encode($cacheKeyData, JSON_UNESCAPED_UNICODE));

        // Check cache first
        $userId = Auth::user()['id'] ?? null;
        $cached = Database::select(
            "SELECT response_data, created_at FROM api_cache
             WHERE cache_key = ? AND cache_type = 'search' AND expires_at > NOW()
             LIMIT 1",
            [$cacheKey]
        );

        if (!empty($cached)) {
            // Use cached result
            $cacheData = json_decode($cached[0]['response_data'], true);
            $result = $cacheData['result'] ?? '';
            $cacheAge = time() - strtotime($cached[0]['created_at']);
            $cacheMinutes = round($cacheAge / 60);
            $error = "✅ Cache'den yüklendi ($cacheMinutes dakika önce)";

            // Update cache hit count and last accessed
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare("UPDATE api_cache SET hit_count = hit_count + 1, last_accessed = NOW() WHERE cache_key = ?");
            $stmt->execute([$cacheKey]);

            // Initialize chat history with cached result
            if ($result) {
                $dataContext = "Video verileri:\n" . json_encode($payloadData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                $systemMsg = "Sen YouTube videolarını analiz eden bir uzmansın. Kullanıcının verdiği video verilerini analiz edip Türkçe olarak detaylı raporlar sunuyorsun.";

                $_SESSION['chat_history'] = [
                    ['role' => 'system', 'content' => $systemMsg],
                    ['role' => 'user', 'content' => $dataContext],
                    ['role' => 'assistant', 'content' => 'Video verilerini aldım. Nasıl yardımcı olabilirim?'],
                    ['role' => 'user', 'content' => $prompt],
                    ['role' => 'assistant', 'content' => $result]
                ];
            }
        } else {
            // No cache, make API request
            $body = json_encode([
                'model' => $aiModel,
                'messages' => [[ 'role' => 'user', 'content' => $prompt ]],
                'stream' => false,
            ], JSON_UNESCAPED_UNICODE);

            $startTime = microtime(true);
            $ch = curl_init($aiEndpoint);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $aiKey,
                    'Content-Type: application/json',
                    'Accept: application/json',
                ],
                CURLOPT_TIMEOUT => 60,
            ]);
            $resp = curl_exec($ch);
            $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            $processingTime = round((microtime(true) - $startTime) * 1000);

            if ($err) {
                $error = 'AI isteği hata: ' . $err;
            } elseif ($http >= 400) {
                $error = 'AI HTTP hata: ' . $http . ' ' . $resp;
            } else {
                $data = json_decode($resp, true);
                $content = $data['choices'][0]['message']['content'] ?? '';
                $result = is_string($content) ? $content : json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

                // Store in cache (6 hours TTL)
                try {
                    Database::insert('api_cache', [
                        'user_id' => $userId,
                        'cache_key' => $cacheKey,
                        'cache_type' => 'search',
                        'provider' => 'codefast',
                        'request_params' => json_encode($cacheKeyData, JSON_UNESCAPED_UNICODE),
                        'response_data' => json_encode(['result' => $result], JSON_UNESCAPED_UNICODE),
                        'ttl_seconds' => 21600, // 6 hours
                    ]);
                } catch (Exception $e) {
                    // Cache save failed, but don't break the flow
                }
            }
        }

        // Initialize chat history with this button-based analysis
        if ($result) {
            $dataContext = "Video verileri:\n" . json_encode($payloadData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            $systemMsg = "Sen YouTube videolarını analiz eden bir uzmansın. Kullanıcının verdiği video verilerini analiz edip Türkçe olarak detaylı raporlar sunuyorsun.";

            // Build chat history as if it was a conversation
            $_SESSION['chat_history'] = [
                ['role' => 'system', 'content' => $systemMsg],
                ['role' => 'user', 'content' => $dataContext],
                ['role' => 'assistant', 'content' => 'Video verilerini aldım. Nasıl yardımcı olabilirim?'],
                ['role' => 'user', 'content' => $prompt],
                ['role' => 'assistant', 'content' => $result]
            ];
        }
    }
}

// Kaydetme
if ($result && $saving) {
    $baseDir = __DIR__ . '/output';
    @mkdir($baseDir, 0777, true);
    $ts = date('Ymd_His');
    $typeSlug = $analysisType ?: 'analysis';
    if ($mode === 'channel') {
        $channelId = (string)($_POST['channelId'] ?? '');
        $slug = 'channel_' . preg_replace('~[^A-Za-z0-9_-]+~', '', $channelId);
        $dir = $baseDir . '/' . $slug;
        @mkdir($dir, 0777, true);
        $path = $dir . '/analysis_' . $ts . '_' . $typeSlug . '.md';
    } else {
        $q = (string)($_POST['q'] ?? '');
        $slug = slugify($q);
        $dir = $baseDir . '/search_' . $slug;
        @mkdir($dir, 0777, true);
        $path = $dir . '/analysis_' . $ts . '_' . $typeSlug . '.md';
    }

    // Save to file
    if (@file_put_contents($path, $result) !== false) {
        $savedPath = 'ymt/output/' . basename(dirname($path)) . '/' . basename($path);

        // Save to database
        try {
            $userId = Auth::user()['id'] ?? null;
            $query = $mode === 'channel' ? $channelId : ($q ?? '');

            Database::insert('analysis_results', [
                'user_id' => $userId,
                'analysis_type' => $analysisType,
                'mode' => $mode,
                'query' => $query,
                'input_data' => json_encode($limited, JSON_UNESCAPED_UNICODE),
                'prompt' => build_prompt($analysisType, $payloadData ?? [], $mode),
                'ai_provider' => 'codefast',
                'ai_model' => $aiModel,
                'result' => $result,
                'is_saved' => true,
                'file_path' => $path,
            ]);
        } catch (Exception $e) {
            // Database save failed, but file is saved
        }
    } else {
        $error = $error ?: 'Dosya yazılamadı.';
    }
}

?><!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Analiz</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="assets/styles.css">
</head>
<body>
<div class="container mx-auto max-w-5xl p-4">
    <?php include __DIR__ . '/includes/navbar.php'; ?>

    <?php if ($back): ?>
        <div class="mb-4">
            <a class="text-sm px-3 py-2 rounded-md border border-gray-300 bg-gray-100 text-gray-800 hover:bg-gray-200" href="<?= e($back) ?>">← Geri</a>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert error mb-3"><?= e($error) ?></div>
    <?php endif; ?>
    <?php if ($savedPath): ?>
        <div class="alert" style="background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;border-radius:8px;padding:10px;margin-bottom:12px;">
            ✅ Kaydedildi: <a href="<?= e($savedPath) ?>" target="_blank" class="underline"><?= e($savedPath) ?></a>
        </div>
    <?php endif; ?>

    <?php if (!$payload): ?>
        <!-- JSON Import Section -->
        <div class="bg-white border border-gray-200 rounded-xl p-6 mb-6">
            <h2 class="text-lg font-semibold text-gray-900 mb-4">📥 JSON Import</h2>
            <p class="text-sm text-gray-600 mb-4">Aşağıdaki yöntemlerden biriyle JSON verisi ekleyerek analiz yapabilirsiniz:</p>

            <!-- Tabs for File Upload vs Manual Input -->
            <div class="mb-6 border-b border-gray-200">
                <div class="flex gap-4">
                    <?php if (!empty($savedJsonFiles)): ?>
                    <button type="button" onclick="showTab('saved')" id="tab-saved" class="px-4 py-2 font-medium text-indigo-600 border-b-2 border-indigo-600">
                        💾 Kaydedilmiş JSON'lar (<?= count($savedJsonFiles) ?>)
                    </button>
                    <?php endif; ?>
                    <button type="button" onclick="showTab('file')" id="tab-file" class="px-4 py-2 font-medium <?= empty($savedJsonFiles) ? 'text-indigo-600 border-b-2 border-indigo-600' : 'text-gray-500 border-b-2 border-transparent hover:text-gray-700' ?>">
                        📁 Dosya Yükle
                    </button>
                    <button type="button" onclick="showTab('manual')" id="tab-manual" class="px-4 py-2 font-medium text-gray-500 border-b-2 border-transparent hover:text-gray-700">
                        ✍️ Manuel Giriş
                    </button>
                </div>
            </div>

            <!-- Saved JSON Files -->
            <?php if (!empty($savedJsonFiles)): ?>
            <div id="form-saved" class="tab-content">
                <form method="post" class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Kaydedilmiş JSON Dosyası Seç:</label>
                        <select name="saved_json" class="w-full px-3 py-2 border border-gray-300 rounded-md" required>
                            <option value="">-- Dosya Seçin --</option>
                            <?php foreach ($savedJsonFiles as $file): ?>
                                <option value="<?= e($file['path']) ?>">
                                    <?= e($file['display']) ?>
                                    (<?= date('Y-m-d H:i', $file['time']) ?>, <?= round($file['size']/1024, 1) ?> KB)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="mt-1 text-xs text-gray-500">Toplam <?= count($savedJsonFiles) ?> kaydedilmiş dosya</p>
                    </div>

                    <button type="submit" class="px-6 py-3 bg-indigo-600 text-white rounded-md hover:bg-indigo-500 font-medium">
                        💾 Seçilen Dosyayı Yükle ve Analiz Et
                    </button>
                </form>
            </div>
            <?php endif; ?>

            <!-- File Upload Form -->
            <div id="form-file" class="tab-content <?= empty($savedJsonFiles) ? '' : 'hidden' ?>">
                <form method="post" enctype="multipart/form-data" class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Mode:</label>
                        <select name="mode" class="w-full px-3 py-2 border border-gray-300 rounded-md" required>
                            <option value="search">Search (Arama Sonuçları)</option>
                            <option value="channel">Channel (Kanal Videoları)</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">JSON Dosyası:</label>
                        <input type="file" name="json_file" accept=".json,.jsonl,.txt" class="w-full px-3 py-2 border border-gray-300 rounded-md" required>
                        <p class="mt-1 text-xs text-gray-500">Kabul edilen formatlar: .json, .jsonl, .txt</p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Query/Channel ID (opsiyonel):</label>
                        <input type="text" name="q" class="w-full px-3 py-2 border border-gray-300 rounded-md" placeholder="örn: yemek videoları veya UC_channelId">
                    </div>

                    <button type="submit" class="px-6 py-3 bg-indigo-600 text-white rounded-md hover:bg-indigo-500 font-medium">
                        📤 Dosyayı Yükle ve Analiz Et
                    </button>
                </form>
            </div>

            <!-- Manual Input Form -->
            <div id="form-manual" class="tab-content hidden">
                <form method="post" class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Mode:</label>
                        <select name="mode" class="w-full px-3 py-2 border border-gray-300 rounded-md" required>
                            <option value="search">Search (Arama Sonuçları)</option>
                            <option value="channel">Channel (Kanal Videoları)</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">JSON Verisi:</label>
                        <textarea name="payload" class="w-full px-3 py-2 border border-gray-300 rounded-md font-mono text-sm" rows="12" placeholder='{"items": [{"id": "video_id", "title": "...", "description": "...", "tags": [...], "metrics": {"views": 1000}}]}' required></textarea>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Query/Channel ID (opsiyonel):</label>
                        <input type="text" name="q" class="w-full px-3 py-2 border border-gray-300 rounded-md" placeholder="örn: yemek videoları veya UC_channelId">
                    </div>

                    <button type="submit" class="px-6 py-3 bg-indigo-600 text-white rounded-md hover:bg-indigo-500 font-medium">
                        ✍️ JSON'u Yükle ve Analiz Et
                    </button>
                </form>
            </div>

            <details class="mt-6">
                <summary class="cursor-pointer text-sm text-gray-600 hover:text-gray-900">JSON Format Örneği</summary>
                <pre class="mt-2 p-3 bg-gray-50 rounded text-xs overflow-auto">{
  "items": [
    {
      "id": "dQw4w9WgXcQ",
      "title": "Örnek Video Başlığı",
      "description": "Video açıklaması...",
      "tags": ["tag1", "tag2", "tag3"],
      "metrics": {
        "views": 10000,
        "likes": 500
      }
    }
  ]
}</pre>
            </details>

            <script>
            function showTab(tab) {
                // Hide all tabs
                document.querySelectorAll('.tab-content').forEach(el => el.classList.add('hidden'));
                document.querySelectorAll('[id^="tab-"]').forEach(el => {
                    el.classList.remove('text-indigo-600', 'border-indigo-600');
                    el.classList.add('text-gray-500', 'border-transparent');
                });

                // Show selected tab
                document.getElementById('form-' + tab).classList.remove('hidden');
                const tabBtn = document.getElementById('tab-' + tab);
                tabBtn.classList.remove('text-gray-500', 'border-transparent');
                tabBtn.classList.add('text-indigo-600', 'border-indigo-600');
            }
            </script>
        </div>
    <?php endif; ?>

    <?php if ($payload): ?>
    <form method="post" class="mb-4">
        <input type="hidden" name="mode" value="<?= e($mode) ?>">
        <input type="hidden" name="variant" value="<?= e($variant) ?>">
        <input type="hidden" name="back" value="<?= e($back) ?>">
        <?php if ($mode === 'channel'): ?>
            <input type="hidden" name="channelId" value="<?= e((string)($_POST['channelId'] ?? '')) ?>">
        <?php else: ?>
            <input type="hidden" name="q" value="<?= e((string)($_POST['q'] ?? '')) ?>">
        <?php endif; ?>
        <textarea name="payload" class="hidden" rows="10" cols="80"><?= e($payload) ?></textarea>

        <!-- Temel Analizler -->
        <div class="mb-4">
            <h3 class="text-sm font-semibold text-gray-700 mb-2">📊 Temel Analizler</h3>
            <div class="flex flex-wrap gap-2">
                <button name="analysis" value="descriptions" class="px-3 py-2 rounded-md border border-gray-300 bg-gray-100 text-gray-800 hover:bg-gray-200" type="submit">Açıklamaları analiz et</button>
                <button name="analysis" value="tags" class="px-3 py-2 rounded-md border border-gray-300 bg-gray-100 text-gray-800 hover:bg-gray-200" type="submit">Etiketleri analiz et</button>
                <button name="analysis" value="titles" class="px-3 py-2 rounded-md border border-gray-300 bg-gray-100 text-gray-800 hover:bg-gray-200" type="submit">Başlıkları analiz et</button>
                <button name="analysis" value="seo" class="px-3 py-2 rounded-md border border-gray-300 bg-gray-100 text-gray-800 hover:bg-gray-200" type="submit">SEO özet + öneriler</button>
            </div>
        </div>

        <!-- AI-Powered Analizler -->
        <div class="mb-4">
            <h3 class="text-sm font-semibold text-gray-700 mb-2">🤖 AI-Powered Analizler</h3>
            <div class="flex flex-wrap gap-2">
                <button name="analysis" value="auto-title-generator" class="px-3 py-2 rounded-md border border-blue-300 bg-blue-50 text-blue-800 hover:bg-blue-100" type="submit">🎯 Otomatik Başlık Üret (20 adet)</button>
                <button name="analysis" value="content-gaps" class="px-3 py-2 rounded-md border border-blue-300 bg-blue-50 text-blue-800 hover:bg-blue-100" type="submit">🔍 İçerik Boşlukları</button>
                <button name="analysis" value="trending-topics" class="px-3 py-2 rounded-md border border-blue-300 bg-blue-50 text-blue-800 hover:bg-blue-100" type="submit">📈 Trend Konular</button>
            </div>
        </div>

        <!-- Performans Analizleri -->
        <div class="mb-4">
            <h3 class="text-sm font-semibold text-gray-700 mb-2">⚡ Performans Analizleri</h3>
            <div class="flex flex-wrap gap-2">
                <button name="analysis" value="performance-prediction" class="px-3 py-2 rounded-md border border-green-300 bg-green-50 text-green-800 hover:bg-green-100" type="submit">🎲 Performans Tahmini</button>
                <button name="analysis" value="best-performers" class="px-3 py-2 rounded-md border border-green-300 bg-green-50 text-green-800 hover:bg-green-100" type="submit">🏆 En İyi Performans</button>
                <button name="analysis" value="engagement-rate" class="px-3 py-2 rounded-md border border-green-300 bg-green-50 text-green-800 hover:bg-green-100" type="submit">💬 Etkileşim Analizi</button>
            </div>
        </div>

        <!-- Custom Prompt -->
        <div class="bg-gradient-to-r from-purple-50 to-pink-50 border border-purple-200 rounded-xl p-4 mb-4">
            <h3 class="text-sm font-semibold text-purple-900 mb-3">✨ Özel Analiz Promptu (Tam Kontrol)</h3>
            <div class="space-y-3">
                <textarea name="custom_prompt" class="w-full px-3 py-2 border border-purple-300 rounded-md font-mono text-sm" rows="4" placeholder="Örnek: Bu videoların en çok hangi yaş grubuna hitap ettiğini analiz et ve bana 30 yaş altı için 5 içerik önerisi yap.&#10;&#10;Tamamen özelleştirilmiş sorularınızı buraya yazabilirsiniz..."></textarea>
                <button type="submit" class="w-full px-4 py-3 bg-gradient-to-r from-purple-600 to-pink-600 text-white rounded-md hover:from-purple-700 hover:to-pink-700 font-medium">
                    ✨ Özel Prompt ile Analiz Yap
                </button>
            </div>
        </div>
    </form>

    <!-- Chat History Display -->
    <?php if (!empty($chatHistory)): ?>
    <div class="bg-white border border-gray-200 rounded-xl p-4 mb-4">
        <div class="flex items-center justify-between mb-3">
            <h3 class="text-base font-semibold text-gray-900">💬 Sohbet Geçmişi</h3>
            <form method="post" class="inline">
                <input type="hidden" name="mode" value="<?= e($mode) ?>">
                <input type="hidden" name="payload" value='<?= e($payload) ?>'>
                <input type="hidden" name="clear_chat" value="1">
                <button type="submit" class="px-3 py-1 text-sm rounded-md border border-red-300 bg-red-50 text-red-700 hover:bg-red-100">
                    🗑️ Sohbeti Temizle
                </button>
            </form>
        </div>

        <div class="space-y-3 max-h-96 overflow-y-auto">
            <?php foreach ($chatHistory as $idx => $msg): ?>
                <div class="<?= $msg['role'] === 'user' ? 'bg-blue-50 border-blue-200' : 'bg-gray-50 border-gray-200' ?> border rounded-lg p-3">
                    <div class="flex items-center gap-2 mb-2">
                        <span class="text-xs font-semibold <?= $msg['role'] === 'user' ? 'text-blue-700' : 'text-gray-700' ?>">
                            <?= $msg['role'] === 'user' ? '👤 Siz' : '🤖 Asistan' ?>
                        </span>
                    </div>
                    <div class="text-sm text-gray-800 whitespace-pre-wrap"><?= e($msg['content']) ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($payload): ?>
    <details class="bg-white border border-gray-200 rounded-xl p-3 mb-4">
        <summary class="cursor-pointer select-none font-medium">Gönderilen Kısa JSON</summary>
        <pre style="white-space:pre-wrap;word-break:break-word;">
<?= e(json_encode($json ?: [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?>
        </pre>
    </details>
    <?php endif; ?>

    <?php if ($result): ?>
        <!-- Analiz Bilgi Kartı -->
        <div class="bg-gradient-to-r from-blue-50 to-indigo-50 border-l-4 border-blue-500 rounded-lg p-4 mb-4 shadow-sm">
            <div class="flex items-center gap-2 mb-3">
                <span class="text-lg font-bold text-blue-900">📊 Aktif Analiz</span>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-3 text-sm">
                <!-- Analiz Türü -->
                <div class="bg-white rounded-md p-3 border border-blue-200">
                    <div class="text-xs text-gray-600 mb-1">Analiz Türü</div>
                    <div class="font-semibold text-gray-900">
                        <?php if ($customPrompt): ?>
                            ✨ Özel Prompt
                        <?php elseif ($followUp): ?>
                            💬 Takip Sorusu
                        <?php else: ?>
                            <?= e(getAnalysisTypeLabel($analysisType)) ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Kaynak Bilgisi -->
                <div class="bg-white rounded-md p-3 border border-blue-200">
                    <div class="text-xs text-gray-600 mb-1">Veri Kaynağı</div>
                    <div class="font-semibold text-gray-900">
                        <?php if ($mode === 'channel'): ?>
                            <?php
                            $channelId = (string)($_POST['channelId'] ?? '');
                            // JSON'dan kanal adını çekmeyi dene
                            $channelTitle = '';
                            if (!empty($json['channel']['title'])) {
                                $channelTitle = $json['channel']['title'];
                            } elseif (!empty($limited[0]['channelTitle'])) {
                                $channelTitle = $limited[0]['channelTitle'];
                            }
                            ?>
                            📺 Kanal
                            <?php if ($channelTitle): ?>
                                <div class="text-xs text-gray-600 mt-1"><?= e($channelTitle) ?></div>
                            <?php endif; ?>
                            <div class="text-xs text-gray-500 mt-1 font-mono"><?= e($channelId) ?></div>
                        <?php else: ?>
                            🔍 Arama
                            <?php
                            $searchQuery = (string)($_POST['q'] ?? '');
                            if ($searchQuery):
                            ?>
                                <div class="text-xs text-gray-600 mt-1">"<?= e($searchQuery) ?>"</div>
                            <?php endif; ?>
                        <?php endif; ?>

                        <?php if (isset($_POST['saved_json'])): ?>
                            <div class="text-xs text-purple-600 mt-1">💾 Kaydedilmiş JSON</div>
                        <?php elseif (isset($_FILES['json_file'])): ?>
                            <div class="text-xs text-green-600 mt-1">📤 Yüklenen Dosya</div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Veri Miktarı -->
                <div class="bg-white rounded-md p-3 border border-blue-200">
                    <div class="text-xs text-gray-600 mb-1">Analiz Edilen Veri</div>
                    <div class="font-semibold text-gray-900">
                        <?= count($limited) ?> video
                        <?php if (count($items) > count($limited)): ?>
                            <div class="text-xs text-gray-500 mt-1">(Toplam: <?= count($items) ?>)</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="bg-white border border-gray-200 rounded-xl p-3">
            <div class="flex items-center justify-between mb-2">
                <h2 class="text-base font-semibold">Analiz Sonucu</h2>
                <form method="post" class="m-0">
                    <input type="hidden" name="mode" value="<?= e($mode) ?>">
                    <input type="hidden" name="variant" value="<?= e($variant) ?>">
                    <input type="hidden" name="analysis" value="<?= e($analysisType) ?>">
                    <input type="hidden" name="payload" value='<?= e($payload) ?>'>
                    <input type="hidden" name="result" value='<?= e($result) ?>'>
                    <input type="hidden" name="save" value="1">
                    <?php if ($mode === 'channel'): ?>
                        <input type="hidden" name="channelId" value="<?= e((string)($_POST['channelId'] ?? '')) ?>">
                    <?php else: ?>
                        <input type="hidden" name="q" value="<?= e((string)($_POST['q'] ?? '')) ?>">
                    <?php endif; ?>
                    <button class="px-3 py-2 rounded-md border border-gray-300 bg-gray-100 text-gray-800 hover:bg-gray-200" type="submit">Sonucu Kaydet (MD)</button>
                </form>
            </div>
            <pre style="white-space:pre-wrap;word-break:break-word;"><?= e($result) ?></pre>
        </div>

        <!-- Follow-up Question Form -->
        <form method="post" class="mt-4">
            <input type="hidden" name="mode" value="<?= e($mode) ?>">
            <input type="hidden" name="payload" value='<?= e($payload) ?>'>
            <?php if ($mode === 'channel'): ?>
                <input type="hidden" name="channelId" value="<?= e((string)($_POST['channelId'] ?? '')) ?>">
            <?php else: ?>
                <input type="hidden" name="q" value="<?= e((string)($_POST['q'] ?? '')) ?>">
            <?php endif; ?>
            <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                <label class="block text-sm font-semibold text-blue-900 mb-2">💬 Sohbete Devam Et</label>
                <textarea name="follow_up" rows="3" class="w-full px-3 py-2 border border-blue-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="Ek soru sorun veya daha fazla detay isteyin..." required></textarea>
                <button type="submit" class="mt-2 px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500">
                    💬 Takip Sorusu Sor
                </button>
            </div>
        </form>
    <?php endif; ?>

    <?php endif; // end if ($payload) ?>
</div>
</body>
</html>
