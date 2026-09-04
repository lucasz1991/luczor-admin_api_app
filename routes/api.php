<?php

use App\Http\Controllers\Api\V1\AgentController;
use App\Http\Controllers\Api\V1\AgentEventController;
use App\Http\Controllers\Api\V1\AgentRunController;
use App\Http\Controllers\Api\V1\AppNotificationController;
use App\Http\Controllers\Api\V1\BootstrapController;
use App\Http\Controllers\Api\V1\ContextController;
use App\Http\Controllers\Api\V1\ConversationController;
use App\Http\Controllers\Api\V1\DeviceController;
use App\Http\Controllers\Api\V1\DeviceDebugController;
use App\Http\Controllers\Api\V1\DeviceJobController;
use App\Http\Controllers\Api\V1\GithubController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\LlmController;
use App\Http\Controllers\Api\V1\LocalModelManifestController;
use App\Http\Controllers\Api\V1\McpController;
use App\Http\Controllers\Api\V1\MemoryController;
use App\Http\Controllers\Api\V1\NotificationPreferenceController;
use App\Http\Controllers\Api\V1\PolicyController;
use App\Http\Controllers\Api\V1\PreferenceController;
use App\Http\Controllers\Api\V1\ProjectController;
use App\Http\Controllers\Api\V1\ProxyController;
use App\Http\Controllers\Api\V1\ReverbAuthController;
use App\Http\Controllers\Api\V1\SyncController;
use App\Http\Controllers\Api\V1\TaskController;
use App\Http\Controllers\Api\V1\VoiceAssetController;
use App\Http\Controllers\Api\V1\VoiceManifestController;
use App\Http\Controllers\Api\V1\WorkflowController;
use App\Models\Skill;
use App\Services\ApiActor;
use App\Services\WorkflowTaskCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('/health', HealthController::class)->name('api.v1.health');
    Route::get('/ready', [HealthController::class, 'readiness'])->name('api.v1.ready');
    // Product/API versioning is public; framework details remain an internal
    // deployment diagnostic so this endpoint does not aid fingerprinting.
    Route::get('/version', fn () => response()->json([
        'api' => 'v1',
        'app' => config('app.version', 'dev'),
        'server_time' => now()->toIso8601String(),
    ]))->name('api.v1.version');
    Route::get('/voice/releases/{version}/{file}', VoiceAssetController::class)
        ->where(['version' => '[A-Za-z0-9._-]+', 'file' => '[A-Za-z0-9._-]+'])
        ->name('api.v1.voice.asset');
    Route::get('/agents', [AgentController::class, 'index'])->middleware('luczor.api:brain.read')->name('api.v1.agents.index');
    Route::post('/agents', [AgentController::class, 'store'])->middleware('luczor.api:brain.write')->name('api.v1.agents.store');
    Route::post('/github/webhook', [GithubController::class, 'webhook'])->name('api.v1.github.webhook');

    Route::middleware('luczor.api:settings.read')->group(function () {
        Route::get('/bootstrap', [BootstrapController::class, 'bootstrap'])->name('api.v1.bootstrap');
        Route::get('/model-profiles', [BootstrapController::class, 'modelProfiles'])->name('api.v1.model-profiles');
        Route::get('/runtime-settings', [BootstrapController::class, 'runtimeSettings'])->name('api.v1.runtime-settings');
        Route::get('/local-model/manifest', LocalModelManifestController::class)->name('api.v1.local-model.manifest');
        Route::get('/voice/manifest', VoiceManifestController::class)->name('api.v1.voice.manifest');
    });

    Route::post('/sync/push', [SyncController::class, 'push'])
        ->middleware('luczor.api:sync.write')
        ->name('api.v1.sync.push');

    Route::get('/sync/pull', [SyncController::class, 'pull'])
        ->middleware('luczor.api:sync.read')
        ->name('api.v1.sync.pull');

    // Account-synced client preferences (voice + external agents), LWW + allowlist.
    Route::get('/preferences', [PreferenceController::class, 'index'])
        ->middleware('luczor.api:settings.read')->name('api.v1.preferences.index');
    Route::put('/preferences', [PreferenceController::class, 'update'])
        ->middleware('luczor.api:sync.write')->name('api.v1.preferences.update');

    Route::post('/agent-events', [AgentEventController::class, 'store'])
        ->middleware('luczor.api:brain.write')
        ->name('api.v1.agent-events.store');

    Route::middleware('luczor.api:device.connect')->group(function () {
        Route::get('/realtime/config', [BootstrapController::class, 'realtime'])
            ->name('api.v1.realtime.config');
        Route::post('/devices/register', [DeviceController::class, 'register'])->name('api.v1.devices.register');
        Route::post('/devices/heartbeat', [DeviceController::class, 'heartbeat'])->name('api.v1.devices.heartbeat');
        Route::get('/devices/signing-key', [DeviceController::class, 'signingKey'])->name('api.v1.devices.signing-key');
        Route::get('/devices/jobs/next', [DeviceController::class, 'nextJob'])->name('api.v1.devices.jobs.next');
        Route::get('/devices/debug/poll', [DeviceDebugController::class, 'poll'])->name('api.v1.devices.debug.poll');
        Route::post('/devices/debug/{publicId}/complete', [DeviceDebugController::class, 'complete'])->name('api.v1.devices.debug.complete');
        Route::post('/devices/jobs/{publicId}/approve', [DeviceController::class, 'approveJob'])->name('api.v1.devices.jobs.approve');
        Route::post('/devices/jobs/{publicId}/start', [DeviceController::class, 'startJob'])->name('api.v1.devices.jobs.start');
        Route::post('/devices/jobs/{publicId}/complete', [DeviceController::class, 'completeJob'])->name('api.v1.devices.jobs.complete');
        Route::post('/reverb/auth', ReverbAuthController::class)->name('api.v1.reverb.auth');
        Route::get('/notifications', [AppNotificationController::class, 'index'])->name('api.v1.notifications.index');
        Route::post('/notifications/read-all', [AppNotificationController::class, 'readAll'])->name('api.v1.notifications.read-all');
        Route::post('/notifications/{notificationId}/read', [AppNotificationController::class, 'read'])
            ->where('notificationId', '[A-Za-z0-9][A-Za-z0-9:._-]{0,159}')
            ->name('api.v1.notifications.read');
        Route::get('/notification-preferences', [NotificationPreferenceController::class, 'show'])
            ->name('api.v1.notification-preferences.show');
        Route::put('/notification-preferences', [NotificationPreferenceController::class, 'update'])
            ->name('api.v1.notification-preferences.update');
    });
    Route::get('/devices', [DeviceController::class, 'index'])
        ->middleware('luczor.api:device.jobs.read')->name('api.v1.devices.index');
    Route::post('/device-jobs', [DeviceJobController::class, 'store'])
        ->middleware('luczor.api:device.jobs.write')->name('api.v1.device-jobs.store');
    Route::get('/device-jobs', [DeviceJobController::class, 'index'])
        ->middleware('luczor.api:device.jobs.read')->name('api.v1.device-jobs.index');
    Route::get('/policies', [PolicyController::class, 'index'])
        ->middleware('luczor.api:brain.read')->name('api.v1.policies.index');
    Route::post('/policies', [PolicyController::class, 'store'])
        ->middleware('luczor.api:brain.write')->name('api.v1.policies.store');
    Route::get('/audit-events', [PolicyController::class, 'audit'])
        ->middleware('luczor.api:device.jobs.read')->name('api.v1.audit-events.index');
    Route::get('/projects', [ProjectController::class, 'index'])
        ->middleware('luczor.api:brain.read')->name('api.v1.projects.index');
    Route::post('/projects', [ProjectController::class, 'store'])
        ->middleware('luczor.api:brain.write')->name('api.v1.projects.store');
    Route::get('/projects/{project}', [ProjectController::class, 'show'])
        ->middleware('luczor.api:brain.read')->name('api.v1.projects.show');
    Route::patch('/projects/{project}', [ProjectController::class, 'update'])
        ->middleware('luczor.api:brain.write')->name('api.v1.projects.update');
    // Agent/user task + conversation management (SOLL §8).
    Route::get('/tasks', [TaskController::class, 'index'])
        ->middleware('luczor.api:brain.read')->name('api.v1.tasks.index');
    Route::post('/tasks', [TaskController::class, 'store'])
        ->middleware('luczor.api:brain.write')->name('api.v1.tasks.store');
    Route::patch('/tasks/{externalId}', [TaskController::class, 'update'])
        ->middleware('luczor.api:brain.write')->name('api.v1.tasks.update');
    Route::get('/conversations', [ConversationController::class, 'index'])
        ->middleware('luczor.api:brain.read')->name('api.v1.conversations.index');
    Route::post('/conversations', [ConversationController::class, 'store'])
        ->middleware('luczor.api:brain.write')->name('api.v1.conversations.store');

    Route::get('/mcp/tools', [McpController::class, 'tools'])
        ->middleware('luczor.api:brain.read')->name('api.v1.mcp.tools');
    // The controller enforces the descriptor-specific API-key ability. Do not
    // put a static scope here: MCP contains both read and write tools.
    Route::post('/mcp/call', [McpController::class, 'call'])
        ->middleware('luczor.api')->name('api.v1.mcp.call');

    Route::middleware('luczor.api:brain.read')->group(function () {
        Route::get('/github/repositories', [GithubController::class, 'repositories'])->name('api.v1.github.repositories');
    });
    Route::middleware('luczor.api:brain.write')->group(function () {
        Route::post('/github/repositories/import', [GithubController::class, 'import'])->name('api.v1.github.repositories.import');
        Route::post('/repositories/{repository}/branches', [GithubController::class, 'branch'])->name('api.v1.repositories.branches.store');
        Route::post('/repositories/{repository}/pull-requests', [GithubController::class, 'pullRequest'])->name('api.v1.repositories.pull-requests.store');
        Route::put('/repositories/{repository}/files', [GithubController::class, 'putFile'])->name('api.v1.repositories.files.put');
    });

    Route::middleware('luczor.api:brain.write')->group(function () {
        Route::post('/workflows', [WorkflowController::class, 'storeDefinition'])->name('api.v1.workflows.store');
        Route::post('/workflows/{workflowDefinition}/runs', [WorkflowController::class, 'start'])->name('api.v1.workflows.runs.store');
        Route::post('/workflow-runs/{workflowRun}/advance', [WorkflowController::class, 'advance'])->name('api.v1.workflow-runs.advance');
        Route::post('/workflow-runs/{workflowRun}/cancel', [WorkflowController::class, 'cancel'])->name('api.v1.workflow-runs.cancel');
        Route::post('/workflow-steps/{workflowStep}/complete', [WorkflowController::class, 'completeStep'])->name('api.v1.workflow-steps.complete');
        Route::post('/workflow-steps/{workflowStep}/fail', [WorkflowController::class, 'failStep'])->name('api.v1.workflow-steps.fail');
    });
    Route::get('/workflow-runs/{workflowRun}', [WorkflowController::class, 'show'])
        ->middleware('luczor.api:brain.read')->name('api.v1.workflow-runs.show');

    // Vetted workflow task library (SOLL §14 P12) — the only tasks a definition may use.
    Route::get('/workflows/task-catalog', fn () => response()->json(['data' => WorkflowTaskCatalog::options()]))
        ->middleware('luczor.api:brain.read')->name('api.v1.workflows.task-catalog');

    // SOLL §15 P27 — reusable skill bundles the client/AI may discover and apply.
    Route::get('/skills', function (Request $request) {
        $userId = app(ApiActor::class)->userId($request);
        $skills = Skill::active()
            ->where(fn ($q) => $q->whereNull('user_id')->orWhere('user_id', $userId))
            ->orderByDesc('use_count')->orderBy('name')
            ->get(['id', 'slug', 'name', 'description', 'kind', 'tags', 'workflow_definition_id']);

        return response()->json(['data' => $skills]);
    })->middleware('luczor.api:brain.read')->name('api.v1.skills.index');

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
    });

    // Memory (Cognee behind Laravel + memory_links System-of-Record)
    Route::post('/memory/recall', [MemoryController::class, 'recall'])
        ->middleware('luczor.api:brain.read')->name('api.v1.memory.recall');
    Route::middleware('luczor.api:brain.write')->group(function () {
        Route::post('/memory/remember', [MemoryController::class, 'remember'])->name('api.v1.memory.remember');
        Route::post('/memory/forget', [MemoryController::class, 'forget'])->name('api.v1.memory.forget');
        Route::post('/memory/promote', [MemoryController::class, 'promote'])->name('api.v1.memory.promote');
        Route::post('/memory/improve', [MemoryController::class, 'improve'])
            ->middleware('throttle:memory-improve')
            ->name('api.v1.memory.improve');
    });

    // Context Controller (ranked, budgeted context package)
    Route::post('/context/ask', [ContextController::class, 'ask'])
        ->middleware('luczor.api:brain.read')->name('api.v1.context.ask');
    Route::middleware('luczor.api:brain.read')->group(function () {
        Route::post('/context/code', [ContextController::class, 'code'])->name('api.v1.context.code');
        Route::post('/context/memory', [ContextController::class, 'memory'])->name('api.v1.context.memory');
        Route::post('/context/impact', [ContextController::class, 'impact'])->name('api.v1.context.impact');
        Route::get('/context/{contextId}/explain', [ContextController::class, 'explain'])->name('api.v1.context.explain');
    });
    Route::post('/context/update-memory', [ContextController::class, 'updateMemory'])
        ->middleware('luczor.api:brain.write')->name('api.v1.context.update-memory');

    // LLM Router + metrics
    Route::middleware('luczor.api:brain.read')->group(function () {
        Route::post('/llm/route', [LlmController::class, 'route'])->name('api.v1.llm.route');
        Route::get('/llm/rankings', [LlmController::class, 'rankings'])->name('api.v1.llm.rankings');
        Route::get('/llm/runs', [LlmController::class, 'runs'])->name('api.v1.llm.runs');
        Route::get('/llm/telemetry', [LlmController::class, 'telemetry'])->name('api.v1.llm.telemetry');
        Route::get('/llm/experiments', [LlmController::class, 'experiments'])->name('api.v1.llm.experiments');
        Route::get('/llm/prompts', [LlmController::class, 'prompts'])->name('api.v1.llm.prompts');
    });
    Route::post('/llm/runs/{llmRun}/evaluate', [LlmController::class, 'evaluate'])
        ->middleware('luczor.api:brain.write')
        ->name('api.v1.llm.runs.evaluate');
    Route::post('/llm/runs/request/{requestId}/evaluate', [LlmController::class, 'evaluateByRequest'])
        ->middleware('luczor.api:brain.write')
        ->name('api.v1.llm.runs.evaluate-request');
});
