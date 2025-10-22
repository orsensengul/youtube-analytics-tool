<?php
declare(strict_types=1);

/**
 * AI Provider Test Tool
 * Tüm AI provider'ları test eder ve yanıtlarını gösterir
 */

$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/lib/AIProvider.php';

// Session başlat (AIProvider için gerekli)
session_start();

// Test mesajı
$testMessage = $_POST['test_message'] ?? 'Merhaba! Bu bir test mesajıdır. Lütfen kısa bir yanıt ver.';
$testResults = [];
$providerStatus = [];

// AIProvider'ı başlat
$aiProvider = new AIProvider($config['ai_providers'], $config['ai_error_reset_time']);

// Eğer form gönderildiyse test yap
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test_all'])) {
    foreach ($config['ai_providers'] as $provider) {
        $startTime = microtime(true);

        // Her provider için ayrı test
        $result = testSingleProvider($provider, $testMessage);

        $endTime = microtime(true);
        $duration = round(($endTime - $startTime) * 1000, 2); // ms

        $testResults[$provider['name']] = [
            'success' => $result['success'],
            'response' => $result['data'] ?? null,
            'error' => $result['error'] ?? null,
            'duration' => $duration,
            'provider_info' => [
                'endpoint' => $provider['endpoint'],
                'model' => $provider['model'],
                'priority' => $provider['priority'],
                'timeout' => $provider['timeout_seconds'],
            ]
        ];
    }
}

// Provider durumlarını al
$providerStatus = $aiProvider->getProviderStatus();

/**
 * Tek bir provider'ı test et
 */
function testSingleProvider(array $provider, string $message): array {
    $body = json_encode([
        'model' => $provider['model'],
        'messages' => [
            ['role' => 'user', 'content' => $message]
        ],
        'stream' => false,
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init($provider['endpoint']);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $provider['key'],
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_TIMEOUT => $provider['timeout_seconds'],
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        return [
            'success' => false,
            'data' => null,
            'error' => 'cURL error: ' . $curlError,
        ];
    }

    if ($httpCode >= 400) {
        return [
            'success' => false,
            'data' => null,
            'error' => "HTTP $httpCode: " . substr($response, 0, 500),
        ];
    }

    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return [
            'success' => false,
            'data' => null,
            'error' => 'Invalid JSON response: ' . substr($response, 0, 500),
        ];
    }

    $content = $data['choices'][0]['message']['content'] ?? null;
    if (!$content) {
        return [
            'success' => false,
            'data' => null,
            'error' => 'No content in response. Full response: ' . json_encode($data),
        ];
    }

    return [
        'success' => true,
        'data' => $content,
        'error' => null,
    ];
}

function e(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>AI Provider Test Tool</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50">
    <div class="min-h-screen py-8 px-4">
        <div class="max-w-6xl mx-auto space-y-6">
            <!-- Header -->
            <div class="bg-white shadow-md rounded-lg p-6">
                <h1 class="text-3xl font-extrabold text-gray-900 mb-2">
                    🤖 AI Provider Test Tool
                </h1>
                <p class="text-gray-600">
                    Tüm AI provider'larınızı test edin ve yanıtlarını karşılaştırın
                </p>
            </div>

            <!-- Test Form -->
            <div class="bg-white shadow-md rounded-lg p-6">
                <form method="POST" class="space-y-4">
                    <div>
                        <label for="test_message" class="block text-sm font-medium text-gray-700 mb-2">
                            Test Mesajı:
                        </label>
                        <textarea
                            id="test_message"
                            name="test_message"
                            rows="3"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                            placeholder="AI'ya göndermek istediğiniz test mesajını yazın..."
                        ><?= e($testMessage) ?></textarea>
                    </div>

                    <button
                        type="submit"
                        name="test_all"
                        value="1"
                        class="w-full bg-blue-600 hover:bg-blue-700 text-white font-medium py-3 px-4 rounded-lg transition duration-200"
                    >
                        🚀 Tüm Provider'ları Test Et
                    </button>
                </form>
            </div>

            <!-- Provider Status -->
            <?php if (!empty($providerStatus)): ?>
            <div class="bg-white shadow-md rounded-lg p-6">
                <h2 class="text-xl font-bold text-gray-900 mb-4">📊 Provider Durumları</h2>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <?php foreach ($providerStatus as $name => $status): ?>
                        <div class="border rounded-lg p-4 <?= $status['status'] === 'active' ? 'bg-green-50 border-green-200' : 'bg-red-50 border-red-200' ?>">
                            <h3 class="font-semibold text-gray-900 mb-2"><?= e($name) ?></h3>
                            <div class="text-sm space-y-1">
                                <p>
                                    <span class="text-gray-600">Durum:</span>
                                    <span class="font-medium <?= $status['status'] === 'active' ? 'text-green-700' : 'text-red-700' ?>">
                                        <?= e($status['status']) ?>
                                    </span>
                                </p>
                                <p>
                                    <span class="text-gray-600">Öncelik:</span>
                                    <span class="font-medium"><?= $status['priority'] ?></span>
                                </p>
                                <p>
                                    <span class="text-gray-600">Hata:</span>
                                    <span class="font-medium"><?= $status['error_count'] ?> / <?= $status['threshold'] ?></span>
                                </p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Test Results -->
            <?php if (!empty($testResults)): ?>
            <div class="space-y-4">
                <h2 class="text-2xl font-bold text-gray-900">📝 Test Sonuçları</h2>

                <?php foreach ($testResults as $providerName => $result): ?>
                <div class="bg-white shadow-md rounded-lg overflow-hidden">
                    <!-- Provider Header -->
                    <div class="px-6 py-4 <?= $result['success'] ? 'bg-green-100 border-b border-green-200' : 'bg-red-100 border-b border-red-200' ?>">
                        <div class="flex items-center justify-between">
                            <h3 class="text-lg font-bold text-gray-900">
                                <?= $result['success'] ? '✅' : '❌' ?> <?= e($providerName) ?>
                            </h3>
                            <span class="text-sm font-medium <?= $result['success'] ? 'text-green-700' : 'text-red-700' ?>">
                                <?= $result['duration'] ?>ms
                            </span>
                        </div>
                    </div>

                    <!-- Provider Info -->
                    <div class="px-6 py-3 bg-gray-50 border-b">
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                            <div>
                                <span class="text-gray-600">Model:</span>
                                <span class="font-medium ml-1"><?= e($result['provider_info']['model']) ?></span>
                            </div>
                            <div>
                                <span class="text-gray-600">Öncelik:</span>
                                <span class="font-medium ml-1"><?= $result['provider_info']['priority'] ?></span>
                            </div>
                            <div>
                                <span class="text-gray-600">Timeout:</span>
                                <span class="font-medium ml-1"><?= $result['provider_info']['timeout'] ?>s</span>
                            </div>
                            <div class="col-span-2 md:col-span-1">
                                <span class="text-gray-600">Endpoint:</span>
                                <span class="font-medium ml-1 text-xs break-all"><?= e(parse_url($result['provider_info']['endpoint'], PHP_URL_HOST)) ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- Response/Error -->
                    <div class="px-6 py-4">
                        <?php if ($result['success']): ?>
                            <div>
                                <h4 class="text-sm font-semibold text-gray-700 mb-2">Yanıt:</h4>
                                <div class="bg-gray-50 rounded-lg p-4 border border-gray-200">
                                    <p class="text-gray-800 whitespace-pre-wrap"><?= e($result['response']) ?></p>
                                </div>
                            </div>
                        <?php else: ?>
                            <div>
                                <h4 class="text-sm font-semibold text-red-700 mb-2">Hata:</h4>
                                <div class="bg-red-50 rounded-lg p-4 border border-red-200">
                                    <p class="text-red-800 text-sm font-mono break-all"><?= e($result['error']) ?></p>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- Info -->
            <div class="bg-blue-50 border border-blue-200 rounded-lg p-6">
                <h3 class="font-semibold text-blue-900 mb-3">ℹ️ Bilgi</h3>
                <ul class="text-sm text-blue-800 space-y-2">
                    <li>• Her provider bağımsız olarak test edilir</li>
                    <li>• Yanıt süreleri milisaniye (ms) cinsinden gösterilir</li>
                    <li>• Provider durumları session'da saklanır</li>
                    <li>• Hata sayacı <?= $config['ai_error_reset_time'] ?> saniye sonra sıfırlanır</li>
                    <li>• Öncelik değeri düşük olan provider'lar önce denenir</li>
                </ul>
            </div>

            <!-- Back Link -->
            <div class="text-center">
                <a href="index.php" class="text-blue-600 hover:text-blue-800 text-sm font-medium">
                    ← Ana Sayfaya Dön
                </a>
            </div>
        </div>
    </div>
</body>
</html>
