<?php
return [
    'rapidapi_key' => getenv('RAPIDAPI_KEY') ?: 'YOUR_RAPIDAPI_KEY',
    // Varsayılanı ytjar / yt-api'ye çeviriyoruz
    'rapidapi_host' => getenv('RAPIDAPI_HOST') ?: 'yt-api.p.rapidapi.com',
    'results_per_page' => 10,
    'region_code' => 'TR',
    // Önbellek kullanım süresi (saniye). 0 = kapalı.
    'cache_ttl_seconds' => 21600,
    // Analiz servisi (Codefast AI)
    'ai_endpoint' => getenv('CODEFAST_API_ENDPOINT') ?: 'https://api.codefast.app/v1/chat/completions',
    'ai_model' => getenv('CODEFAST_MODEL') ?: 'gpt-5-chat',
    'ai_api_key' => getenv('CODEFAST_API_KEY') ?: 'YOUR_CODEFAST_API_KEY',
];
