<?php

use App\Http\Controllers\Api\V1\AgentEventController;
use App\Http\Controllers\Api\V1\BootstrapController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\SyncController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('/health', HealthController::class)->name('api.v1.health');

    Route::middleware('luczor.api:settings.read')->group(function () {
        Route::get('/bootstrap', [BootstrapController::class, 'bootstrap'])->name('api.v1.bootstrap');
        Route::get('/model-profiles', [BootstrapController::class, 'modelProfiles'])->name('api.v1.model-profiles');
        Route::get('/runtime-settings', [BootstrapController::class, 'runtimeSettings'])->name('api.v1.runtime-settings');
    });

    Route::post('/sync/push', [SyncController::class, 'push'])
        ->middleware('luczor.api:sync.write')
        ->name('api.v1.sync.push');

    Route::get('/sync/pull', [SyncController::class, 'pull'])
        ->middleware('luczor.api:sync.read')
        ->name('api.v1.sync.pull');

    Route::post('/agent-events', [AgentEventController::class, 'store'])
        ->middleware('luczor.api:brain.write')
        ->name('api.v1.agent-events.store');
});
