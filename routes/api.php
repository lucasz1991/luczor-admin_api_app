<?php

use App\Http\Controllers\Api\V1\AgentEventController;
use App\Http\Controllers\Api\V1\AgentRunController;
use App\Http\Controllers\Api\V1\BootstrapController;
use App\Http\Controllers\Api\V1\ContextController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\LlmController;
use App\Http\Controllers\Api\V1\MemoryController;
use App\Http\Controllers\Api\V1\ProxyController;
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

    Route::middleware('luczor.api:brain.write')->group(function () {
        Route::post('/agent-runs', [AgentRunController::class, 'store'])->name('api.v1.agent-runs.store');
        Route::patch('/agent-runs/{agentRun}', [AgentRunController::class, 'update'])->name('api.v1.agent-runs.update');
        Route::post('/agent-runs/{agentRun}/tasks', [AgentRunController::class, 'storeTask'])->name('api.v1.agent-runs.tasks.store');
    });
    Route::get('/agent-runs/{agentRun}', [AgentRunController::class, 'show'])
        ->middleware('luczor.api:brain.read')
        ->name('api.v1.agent-runs.show');

    Route::middleware('luczor.api:proxy.use')->group(function () {
        Route::post('/proxy/chat', [ProxyController::class, 'chat'])->name('api.v1.proxy.chat');
        Route::post('/proxy/eleven/tts', [ProxyController::class, 'elevenTts'])->name('api.v1.proxy.eleven.tts');
        Route::post('/proxy/eleven/stt', [ProxyController::class, 'elevenStt'])->name('api.v1.proxy.eleven.stt');
    });

    // Memory (Cognee behind Laravel + memory_links System-of-Record)
    Route::post('/memory/recall', [MemoryController::class, 'recall'])
        ->middleware('luczor.api:brain.read')->name('api.v1.memory.recall');
    Route::middleware('luczor.api:brain.write')->group(function () {
        Route::post('/memory/remember', [MemoryController::class, 'remember'])->name('api.v1.memory.remember');
        Route::post('/memory/forget', [MemoryController::class, 'forget'])->name('api.v1.memory.forget');
        Route::post('/memory/improve', [MemoryController::class, 'improve'])->name('api.v1.memory.improve');
    });

    // Context Controller (ranked, budgeted context package)
    Route::post('/context/ask', [ContextController::class, 'ask'])
        ->middleware('luczor.api:brain.read')->name('api.v1.context.ask');

    // LLM Router + metrics
    Route::middleware('luczor.api:brain.read')->group(function () {
        Route::post('/llm/route', [LlmController::class, 'route'])->name('api.v1.llm.route');
        Route::get('/llm/rankings', [LlmController::class, 'rankings'])->name('api.v1.llm.rankings');
    });
    Route::post('/llm/runs/{llmRun}/evaluate', [LlmController::class, 'evaluate'])
        ->middleware('luczor.api:brain.write')
        ->name('api.v1.llm.runs.evaluate');
});
