<?php

return [
    'enabled' => (bool) env('SHARED_SPEECH_ENABLED', false),
    // This service is on the same host. Only this numeric loopback origin is allowed.
    'url' => env('SHARED_SPEECH_URL', 'http://127.0.0.1:8092'),
    'client_id' => env('SHARED_SPEECH_CLIENT_ID', 'luczor'),
    'token' => env('SHARED_SPEECH_TOKEN', ''),
    // An absolute file outside the checkout takes precedence and fails closed.
    'token_file' => env('SHARED_SPEECH_TOKEN_FILE', ''),
    'connect_timeout_seconds' => 2,
    'timeout_seconds' => 150,
    'status_timeout_seconds' => 5,
    'requests_per_minute' => 30,
];
