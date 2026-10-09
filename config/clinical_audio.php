<?php

return [
    'disk' => env('ENSHA_AUDIO_DISK', 'local'),
    'max_bytes' => (int) env('ENSHA_AUDIO_MAX_BYTES', 262144000),
    'chunk_bytes' => (int) env('ENSHA_AUDIO_CHUNK_BYTES', 6291456),
    'retention_days' => (int) env('ENSHA_AUDIO_RETENTION_DAYS', 365),
    'transcription' => [
        'endpoint' => env('ENSHA_TRANSCRIPTION_URL'),
        'token' => env('ENSHA_TRANSCRIPTION_TOKEN'),
        'model' => env('ENSHA_TRANSCRIPTION_MODEL', 'whisper-1'),
        'language' => env('ENSHA_TRANSCRIPTION_LANGUAGE', 'fa'),
        'timeout' => (int) env('ENSHA_TRANSCRIPTION_TIMEOUT', 900),
        'provider' => env('ENSHA_TRANSCRIPTION_PROVIDER', 'whisper-compatible'),
    ],
];
