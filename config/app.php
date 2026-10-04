<?php

return [
    'name' => env('APP_NAME', 'Ensha'),
    'env' => env('APP_ENV', 'production'),
    'debug' => (bool) env('APP_DEBUG', false),
    'url' => env('APP_URL', 'http://localhost/ensha'),
    'timezone' => 'Asia/Tehran',
    'locale' => 'fa',
    'fallback_locale' => 'en',
    'faker_locale' => 'fa_IR',
    'cipher' => 'AES-256-CBC',
    'key' => env('APP_KEY'),
    'previous_keys' => [
        ...array_filter(explode(',', (string) env('APP_PREVIOUS_KEYS', ''))),
    ],
    'maintenance' => [
        'driver' => 'file',
        'store' => 'database',
    ],
];
