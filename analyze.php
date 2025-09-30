<?php
declare(strict_types=1);

$config = require __DIR__ . '/config.php';

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
$saving = isset($_POST['save']) && $_POST['save'] === '1';
$aiKey = (string)($config['ai_api_key'] ?? '');
$aiEndpoint = (string)($config['ai_endpoint'] ?? '');
$aiModel = (string)($config['ai_model'] ?? '');

$error = '';
$result = isset($_POST['result']) ? (string)$_POST['result'] : '';
$json = [];
$limited = [];
$items = [];
$savedPath = '';

if ($payload !== '') {
    $json = json_decode($payload, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($json)) {
        $error = 'Geçersiz JSON gönderildi.';
    } else {
        $items = $json['items'] ?? [];
        if (!is_array($items)) $items = [];
        $limited = array_slice($items, 0, 30); // hep 30
    }
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
        default:
            $task = "Genel analiz yap.";
    }
    return "$intro\n\nVeri (JSON):\n$jsonPart\n\nGörev:\n$task";
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
        $body = json_encode([
            'model' => $aiModel,
            'messages' => [[ 'role' => 'user', 'content' => $prompt ]],
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
        $dir = $baseDir . '/search_' . slugify($q);
        @mkdir($dir, 0777, true);
        $path = $dir . '/analysis_' . $ts . '_' . $typeSlug . '.md';
    }
    if (@file_put_contents($path, $result) !== false) {
        $savedPath = 'ymt/output/' . basename(dirname($path)) . '/' . basename($path);
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
    <div class="flex items-center justify-between mb-4">
        <h1 class="text-xl text-gray-900 font-semibold">Analiz</h1>
        <div class="flex items-center gap-2">
            <?php if ($back): ?>
                <a class="text-sm px-3 py-1 rounded-md border border-gray-300 bg-gray-100 text-gray-800 hover:bg-gray-200" href="<?= e($back) ?>">Geri</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="alert error mb-3"><?= e($error) ?></div>
    <?php endif; ?>
    <?php if ($savedPath): ?>
        <div class="alert" style="background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;border-radius:8px;padding:10px;margin-bottom:12px;">
            Kaydedildi: <a href="<?= e($savedPath) ?>" target="_blank" class="underline"><?= e($savedPath) ?></a>
        </div>
    <?php endif; ?>

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

        <div class="flex flex-wrap gap-2 mb-3">
            <button name="analysis" value="descriptions" class="px-3 py-2 rounded-md border border-gray-300 bg-gray-100 text-gray-800 hover:bg-gray-200" type="submit">Açıklamaları analiz et</button>
            <button name="analysis" value="tags" class="px-3 py-2 rounded-md border border-gray-300 bg-gray-100 text-gray-800 hover:bg-gray-200" type="submit">Etiketleri analiz et</button>
            <button name="analysis" value="titles" class="px-3 py-2 rounded-md border border-gray-300 bg-gray-100 text-gray-800 hover:bg-gray-200" type="submit">Başlıkları analiz et</button>
            <button name="analysis" value="seo" class="px-3 py-2 rounded-md border border-gray-300 bg-gray-100 text-gray-800 hover:bg-gray-200" type="submit">SEO özet + öneriler</button>
        </div>
    </form>

    <?php if ($payload): ?>
    <details class="bg-white border border-gray-200 rounded-xl p-3 mb-4">
        <summary class="cursor-pointer select-none font-medium">Gönderilen Kısa JSON</summary>
        <pre style="white-space:pre-wrap;word-break:break-word;">
<?= e(json_encode($json ?: [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?>
        </pre>
    </details>
    <?php endif; ?>

    <?php if ($result): ?>
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
    <?php endif; ?>
</div>
</body>
</html>
