<?php

$localOrigins = [
    'http://localhost:1420',
    'http://127.0.0.1:1420',
    'http://tauri.localhost',
    'https://tauri.localhost',
    'tauri://localhost',
];
$defaultOrigins = env('APP_ENV', 'production') === 'production' ? [] : $localOrigins;
$allowedOrigins = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('CORS_ALLOWED_ORIGINS', implode(',', $defaultOrigins)))
)));
$allowedOriginPatterns = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('CORS_ALLOWED_ORIGIN_PATTERNS', ''))
)));

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => $allowedOrigins,

    'allowed_origins_patterns' => $allowedOriginPatterns,

    'allowed_headers' => [
        'Accept',
        'Authorization',
        'Content-Type',
        'X-Api-Key',
        'X-Luczor-Correlation-Id',
        'X-Device-Session',
        'X-Requested-With',
    ],

    'exposed_headers' => [
        'X-Luczor-Request-Id',
        'X-Luczor-Correlation-Id',
        'X-Luczor-Use-Case',
        'X-Luczor-Model-Profile',
        'X-Luczor-Model-Id',
        'X-Luczor-Provider',
        'X-Luczor-Review-Enabled',
        'Retry-After',
    ],

    'max_age' => 0,

    'supports_credentials' => false,

];
