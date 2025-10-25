<?php
return [
    // RapidAPI Configuration
    'rapidapi_key' => getenv('RAPIDAPI_KEY') ?: 'fc9d6c84b6msh84f84ef59aca41cp1767e2jsn33216a761633',
    'rapidapi_host' => getenv('RAPIDAPI_HOST') ?: 'yt-api.p.rapidapi.com',
    'results_per_page' => 10,
    'region_code' => 'TR',

    // Cache Configuration
    'cache_ttl_seconds' => 21600, // 6 hours

    // AI Configuration - Provider-based with automatic fallback
    'ai_providers' => [
        [
            'name' => 'codefast',
            'endpoint' => getenv('CODEFAST_API_ENDPOINT') ?: 'https://api13.codefast.app/v1/chat/completions',
            'key' => getenv('CODEFAST_API_KEY') ?: 'sk-16NEg2MxmBG89xQP0DOYHL7gWJJk1NgMKtEguL1R80L425t5',
            'model' => getenv('CODEFAST_MODEL') ?: 'gpt-5-chat',
            'priority' => 1,
            'error_threshold' => 3,
            'timeout_seconds' => 60,
        ],
         [
           'name' => 'openrouter',
            'endpoint' => 'https://openrouter.ai/api/v1/chat/completions',
            'key' => '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',
            'model' => 'openai/gpt-oss-20b:free',
            'priority' => 1,
            'error_threshold' => 2,
            'timeout_seconds' => 60,
        ],
        [
           'name' => 'openrouter2',
            'endpoint' => 'https://openrouter.ai/api/v1/chat/completions',
            'key' => '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',
            'model' => 'google/gemini-2.0-flash-exp:free',
            'priority' => 3,
            'error_threshold' => 3,
            'timeout_seconds' => 60,
        ],

        // Add more providers as needed:
        // [
        //     'name' => 'openai',
        //     'endpoint' => 'https://api.openai.com/v1/chat/completions',
        //     'key' => 'YOUR_OPENAI_KEY',
        //     'model' => 'gpt-4o-mini',
        //     'priority' => 2,
        //     'error_threshold' => 3,
        //     'timeout_seconds' => 60,
        // ],
    ],
    'ai_error_reset_time' => 300, // Reset error counters after 5 minutes

    // Legacy AI config (backward compatibility)
    'ai_endpoint' => getenv('CODEFAST_API_ENDPOINT') ?: 'https://api.codefast.app/v1/chat/completions',
    'ai_model' => getenv('CODEFAST_MODEL') ?: 'gpt-5',
    'ai_api_key' => getenv('CODEFAST_API_KEY') ?: 'sk-16NEg2MxmBG89xQP0DOYHL7gWJJk1NgMKtEguL1R80L425t5',

    // Database Configuration
    'database' => [
        'host' => getenv('DB_HOST') ?: 'localhost',
        'port' => getenv('DB_PORT') ?: 3306,
        'database' => getenv('DB_NAME') ?: 'ymt-lokal',
        'username' => getenv('DB_USER') ?: 'root',
        'password' => getenv('DB_PASS') ?: '',
        'charset' => 'utf8mb4',
    ],

    // Session Configuration
    'session' => [
        'name' => 'YMT_SESSION',
        'lifetime' => 86400, // 24 hours
        'cookie_secure' => false, // Set true for HTTPS
        'cookie_httponly' => true,
    ],
];
