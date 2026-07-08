<?php

use Laravel\Jetstream\Http\Middleware\AuthenticateSession;

return [
    'stack' => 'livewire',
    'middleware' => ['web'],
    'auth_session' => AuthenticateSession::class,
    'guard' => 'sanctum',
    'features' => [
        // Luczor keeps Jetstream as a lean auth/profile shell only.
    ],
];
