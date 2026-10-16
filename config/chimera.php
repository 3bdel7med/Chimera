<?php
return [
    'default' => env('CHIMERA_AI_DRIVER', 'gemini'),
    
    'api_key' => env('GEMINI_API_KEY'),
    'gemini_model' => env('CHIMERA_GEMINI_MODEL', 'gemini-3.1-flash-lite'),
    'url' => env('CHIMERA_GEMINI_URL', 'https://generativelanguage.googleapis.com/v1beta/models/'),

    'providers' => [
        'gemini' => [
            'api_key' => env('GEMINI_API_KEY'),
            'driver' => 'gemini',
            'model' => env('CHIMERA_GEMINI_MODEL', 'gemini-3.1-flash-lite'),
            'url' => env('CHIMERA_GEMINI_URL', 'https://generativelanguage.googleapis.com/v1beta/models/'),
        ],
    ],

    'auto_migrate' => true,
    'max_tokens' => (int) env('CHIMERA_MAX_TOKENS', 32768),
    'timeout' => (int) env('CHIMERA_TIMEOUT', 180),
    'test_env' => ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:'],
    'test_timeout' => 300,
];