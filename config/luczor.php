<?php

return [
    'api_prefix' => env('LUCZOR_API_PREFIX', '/api/v1'),
    'allow_registration' => filter_var(env('LUCZOR_ALLOW_REGISTRATION', true), FILTER_VALIDATE_BOOLEAN),
    'default_model_profile' => env('LUCZOR_DEFAULT_MODEL_PROFILE', 'luczor-default'),
];
