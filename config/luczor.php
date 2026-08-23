<?php

return [
    'api_prefix' => env('LUCZOR_API_PREFIX', '/api/v1'),
    'allow_registration' => filter_var(env('LUCZOR_ALLOW_REGISTRATION', true), FILTER_VALIDATE_BOOLEAN),
    'default_model_profile' => env('LUCZOR_DEFAULT_MODEL_PROFILE', 'chat-fast'),
    'proxy' => [
        'connect_timeout' => (int) env('LUCZOR_PROXY_CONNECT_TIMEOUT', 10),
        'timeout' => (int) env('LUCZOR_PROXY_TIMEOUT', 90),
        'max_output_tokens' => (int) env('LUCZOR_PROXY_MAX_OUTPUT_TOKENS', 8192),
        'max_input_tokens' => (int) env('LUCZOR_PROXY_MAX_INPUT_TOKENS', 24000),
        'requests_per_minute' => (int) env('LUCZOR_PROXY_REQUESTS_PER_MINUTE', 60),
        'max_request_bytes' => (int) env('LUCZOR_PROXY_MAX_REQUEST_BYTES', 16 * 1024 * 1024),
        'max_response_bytes' => (int) env('LUCZOR_PROXY_MAX_RESPONSE_BYTES', 16 * 1024 * 1024),
        'max_stream_bytes' => (int) env('LUCZOR_PROXY_MAX_STREAM_BYTES', 64 * 1024 * 1024),
        'max_stream_frame_bytes' => (int) env('LUCZOR_PROXY_MAX_STREAM_FRAME_BYTES', 1024 * 1024),
    ],
    'device_jobs' => [
        'private_key' => env('LUCZOR_JOB_PRIVATE_KEY', ''),
        'private_key_file' => env('LUCZOR_JOB_PRIVATE_KEY_FILE', ''),
        'ttl_minutes' => (int) env('LUCZOR_JOB_TTL_MINUTES', 15),
    ],
    'voice' => [
        // Release pipeline value: version + per-platform binary/model URLs and SHA-256 hashes.
        'manifest' => json_decode((string) env('LUCZOR_VOICE_MANIFEST_JSON', '{}'), true) ?: [],
        // Prefer a signed envelope file so manifests do not have to be kept in .env.
        'manifest_file' => env('LUCZOR_VOICE_MANIFEST_FILE', ''),
        'release_root' => storage_path('app/voice/releases'),
    ],
    'realtime' => [
        'public_host' => env('LUCZOR_REVERB_PUBLIC_HOST', parse_url(env('APP_URL', ''), PHP_URL_HOST)),
        'public_port' => (int) env('LUCZOR_REVERB_PUBLIC_PORT', parse_url(env('APP_URL', ''), PHP_URL_PORT) ?: 443),
        'public_scheme' => env('LUCZOR_REVERB_PUBLIC_SCHEME', parse_url(env('APP_URL', ''), PHP_URL_SCHEME) ?: 'https'),
        'allow_internal_http' => env('LUCZOR_ALLOW_INTERNAL_REVERB_HTTP', false),
        'internal_host' => env('LUCZOR_INTERNAL_REVERB_HOST'),
    ],
    'notifications' => [
        'queue' => env('LUCZOR_NOTIFICATION_QUEUE', 'default'),
    ],

    // Cognee memory engine (server-side). Empty base_url => memory disabled,
    // the sync archive still works. See App\Services\LuczorMemoryService.
    'cognee' => [
        'base_url' => env('COGNEE_BASE_URL', ''),
        'api_key' => env('COGNEE_API_KEY', ''),
        // Only the bounded /add ingestion and short control-plane calls run
        // in a worker now; LLM-backed cognify is launched asynchronously.
        'timeout' => (int) env('COGNEE_TIMEOUT', 60),
    ],

    // Repository graph policy. The authoritative graph is always local to the
    // desktop; these legacy connector values are ignored by the runtime.
    'graphify' => [
        'mode' => 'local_only',
    ],
];
