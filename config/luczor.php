<?php

return [
    'api_prefix' => env('LUCZOR_API_PREFIX', '/api/v1'),
    'allow_registration' => filter_var(env('LUCZOR_ALLOW_REGISTRATION', true), FILTER_VALIDATE_BOOLEAN),
    'default_model_profile' => env('LUCZOR_DEFAULT_MODEL_PROFILE', 'chat-fast'),
    'proxy' => [
        'connect_timeout' => (int) env('LUCZOR_PROXY_CONNECT_TIMEOUT', 10),
        'timeout' => (int) env('LUCZOR_PROXY_TIMEOUT', 90),
        'max_output_tokens' => (int) env('LUCZOR_PROXY_MAX_OUTPUT_TOKENS', 8192),
        'requests_per_minute' => (int) env('LUCZOR_PROXY_REQUESTS_PER_MINUTE', 60),
    ],
    'device_jobs' => [
        'private_key' => env('LUCZOR_JOB_PRIVATE_KEY', ''),
        'private_key_file' => env('LUCZOR_JOB_PRIVATE_KEY_FILE', ''),
        'ttl_minutes' => (int) env('LUCZOR_JOB_TTL_MINUTES', 15),
    ],
    'voice' => [
        // Release pipeline value: version + per-platform binary/model URLs and SHA-256 hashes.
        'manifest' => json_decode((string) env('LUCZOR_VOICE_MANIFEST_JSON', '{}'), true) ?: [],
    ],
    'realtime' => [
        'public_host' => env('LUCZOR_REVERB_PUBLIC_HOST', parse_url(env('APP_URL', ''), PHP_URL_HOST)),
        'public_port' => (int) env('LUCZOR_REVERB_PUBLIC_PORT', parse_url(env('APP_URL', ''), PHP_URL_PORT) ?: 443),
        'public_scheme' => env('LUCZOR_REVERB_PUBLIC_SCHEME', parse_url(env('APP_URL', ''), PHP_URL_SCHEME) ?: 'https'),
    ],

    // Cognee memory engine (server-side). Empty base_url => memory disabled,
    // the sync archive still works. See App\Services\LuczorMemoryService.
    'cognee' => [
        'base_url' => env('COGNEE_BASE_URL', ''),
        'api_key' => env('COGNEE_API_KEY', ''),
        'timeout' => (int) env('COGNEE_TIMEOUT', 15),
    ],

    // Optional Graphify connector. Empty base_url keeps the deterministic
    // Git/query fallback active for MVP and tests.
    'graphify' => [
        'base_url' => env('GRAPHIFY_BASE_URL', ''),
        'timeout' => (int) env('GRAPHIFY_TIMEOUT', 10),
    ],
];
