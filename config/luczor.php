<?php

return [
    'api_prefix' => env('LUCZOR_API_PREFIX', '/api/v1'),
    'allow_registration' => filter_var(env('LUCZOR_ALLOW_REGISTRATION', true), FILTER_VALIDATE_BOOLEAN),
    'default_model_profile' => env('LUCZOR_DEFAULT_MODEL_PROFILE', 'luczor-default'),

    // Cognee memory engine (server-side). Empty base_url => memory disabled,
    // the sync archive still works. See App\Services\LuczorMemoryService.
    'cognee' => [
        'base_url' => env('COGNEE_BASE_URL', ''),
        'api_key' => env('COGNEE_API_KEY', ''),
        'timeout' => (int) env('COGNEE_TIMEOUT', 15),
    ],
];
