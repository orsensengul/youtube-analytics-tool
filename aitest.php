<?php
declare(strict_types=1);

$config = require __DIR__ . '/config.php';

function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

$aiKey = (string)($config['ai_api_key'] ?? '');
$aiEndpoint = (string)($config['ai_endpoint'] ?? '');
$aiModel = (string)($config['ai_model'] ?? '');

$msg = isset($_POST['msg']) ? trim((string)$_POST['msg']) : 'hangi yapay zekasın?';
$do = isset($_POST['do']) && $_POST['do'] === '1';

$error = '';
$answer = '';
$reqBody = '';
$rawResp = '';

if ($do) {
    if (!$aiKey || $aiKey === 'YOUR_CODEFAST_API_KEY') {
        $error = 'config.php içinde ai_api_key ayarlı değil.';
    } elseif (!$aiEndpoint || !$aiModel) {
        $error = 'AI endpoint/model yapılandırması eksik.';
    } else {
        $payload = [
            'model' => $aiModel,
            'messages' => [[ 'role' => 'user', 'content' => $msg ]],
            'stream' => false,
        ];
        $reqBody = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $ch = curl_init($aiEndpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $reqBody,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $aiKey,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT => 30,
        ]);
        $resp = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        $rawResp = (string)$resp;
        if ($err) {
            $error = 'cURL hata: ' . $err;
        } elseif ($http >= 400) {
            $error = 'HTTP ' . $http . ' — Yanıt: ' . $rawResp;
        } else {
            $data = json_decode($rawResp, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $error = 'Geçersiz JSON yanıtı';
            } else {
                $answer = (string)($data['choices'][0]['message']['content'] ?? '');
                if ($answer === '') {
                    $error = 'Yanıt içeriği bulunamadı.';
                }
            }
        }
    }
}

?><!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>AI Test</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="assets/styles.css">
</head>
<body>
<div class="container mx-auto max-w-3xl p-4">
    <div class="flex items-center justify-between mb-4">
        <h1 class="text-xl text-gray-900 font-semibold">AI Test — Codefast</h1>
        <a class="text-sm px-3 py-1 rounded-md border border-gray-300 bg-gray-100 text-gray-800 hover:bg-gray-200" href="index.php">Ana sayfa</a>
    </div>

    <?php if ($error): ?>
        <div class="alert error mb-3"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post" class="bg-white border border-gray-200 rounded-xl p-3 mb-4">
        <label class="block text-sm text-gray-600 mb-1">Soru</label>
        <input type="text" name="msg" value="<?= e($msg) ?>" class="w-full px-3 py-2 rounded-md border border-gray-300 bg-white text-gray-900" />
        <div class="mt-3">
            <button class="px-4 py-2 rounded-md border border-gray-300 bg-indigo-600 text-white hover:bg-indigo-500" type="submit" name="do" value="1">Gönder</button>
        </div>
    </form>

    <?php if ($do): ?>
        <div class="bg-white border border-gray-200 rounded-xl p-3 mb-4">
            <h2 class="text-base font-semibold mb-2">Gönderilen Sorgu</h2>
            <div class="text-sm text-gray-600 mb-1">Model: <code><?= e($aiModel) ?></code> • Endpoint: <code><?= e($aiEndpoint) ?></code></div>
            <pre style="white-space:pre-wrap;word-break:break-word;" class="text-gray-900"><?= e($msg) ?></pre>
        </div>
    <?php endif; ?>

    <?php if ($answer): ?>
        <div class="bg-white border border-gray-200 rounded-xl p-3 mb-4">
            <h2 class="text-base font-semibold mb-2">Cevap</h2>
            <pre style="white-space:pre-wrap;word-break:break-word;" class="text-gray-900"><?= e($answer) ?></pre>
        </div>
    <?php endif; ?>

    <?php if ($reqBody || $rawResp): ?>
        <details class="bg-white border border-gray-200 rounded-xl p-3">
            <summary class="cursor-pointer select-none font-medium">Debug</summary>
            <?php if ($reqBody): ?>
                <div class="mt-2">
                    <div class="text-sm text-gray-600 mb-1">İstek Gövdesi</div>
                    <pre style="white-space:pre-wrap;word-break:break-word;" class="text-gray-900"><?= e($reqBody) ?></pre>
                </div>
            <?php endif; ?>
            <?php if ($rawResp): ?>
                <div class="mt-3">
                    <div class="text-sm text-gray-600 mb-1">Ham Yanıt</div>
                    <pre style="white-space:pre-wrap;word-break:break-word;" class="text-gray-900"><?= e($rawResp) ?></pre>
                </div>
            <?php endif; ?>
        </details>
    <?php endif; ?>
</div>
</body>
</html>
