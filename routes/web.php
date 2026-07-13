<?php

use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\GithubOAuthController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::middleware(['auth:sanctum', config('jetstream.auth_session'), 'verified'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::post('/dashboard/api-keys', [DashboardController::class, 'storeApiKey'])->name('dashboard.api-keys.store');
    Route::post('/dashboard/api-keys/{apiKey}/toggle', [DashboardController::class, 'toggleApiKey'])->name('dashboard.api-keys.toggle');
    Route::get('/github/redirect', [GithubOAuthController::class, 'redirect'])->name('github.redirect');
    Route::get('/github/callback', [GithubOAuthController::class, 'callback'])->name('github.callback');

    Route::middleware('role:admin')->group(function () {
        Route::get('/admin/{page}', [DashboardController::class, 'page'])->name('admin.page');
        Route::post('/dashboard/provider-credentials', [DashboardController::class, 'storeProviderCredential'])->name('dashboard.provider-credentials.store');
        Route::post('/dashboard/provider-credentials/{providerCredential}/toggle', [DashboardController::class, 'toggleProviderCredential'])->name('dashboard.provider-credentials.toggle');
        Route::post('/dashboard/model-profiles', [DashboardController::class, 'storeModelProfile'])->name('dashboard.model-profiles.store');
        Route::post('/dashboard/model-profiles/{modelProfile}/toggle', [DashboardController::class, 'toggleModelProfile'])->name('dashboard.model-profiles.toggle');
        Route::put('/dashboard/model-profiles/{modelProfile}', [DashboardController::class, 'updateModelProfile'])->name('dashboard.model-profiles.update');
        Route::delete('/dashboard/model-profiles/{modelProfile}', [DashboardController::class, 'destroyModelProfile'])->name('dashboard.model-profiles.destroy');
        Route::post('/dashboard/model-use-cases', [DashboardController::class, 'storeModelUseCase'])->name('dashboard.model-use-cases.store');
        Route::post('/dashboard/model-use-case-entries', [DashboardController::class, 'storeModelUseCaseEntry'])->name('dashboard.model-use-case-entries.store');
        Route::post('/dashboard/model-use-case-entries/reorder', [DashboardController::class, 'reorderModelUseCaseEntries'])->name('dashboard.model-use-case-entries.reorder');
        Route::post('/dashboard/model-use-case-entries/{modelUseCaseEntry}/toggle', [DashboardController::class, 'toggleModelUseCaseEntry'])->name('dashboard.model-use-case-entries.toggle');
        Route::delete('/dashboard/model-use-case-entries/{modelUseCaseEntry}', [DashboardController::class, 'destroyModelUseCaseEntry'])->name('dashboard.model-use-case-entries.destroy');
        Route::post('/dashboard/model-profiles/reorder', [DashboardController::class, 'reorderModelProfiles'])->name('dashboard.model-profiles.reorder');
        Route::post('/dashboard/provider-prices', [DashboardController::class, 'storeProviderPrice'])->name('dashboard.provider-prices.store');
        Route::post('/dashboard/prompt-templates', [DashboardController::class, 'storePromptTemplate'])->name('dashboard.prompt-templates.store');
        Route::post('/dashboard/context-strategies', [DashboardController::class, 'storeContextStrategy'])->name('dashboard.context-strategies.store');
        Route::post('/dashboard/network-policies', [DashboardController::class, 'storeNetworkPolicy'])->name('dashboard.network-policies.store');
        Route::post('/dashboard/llm-experiments', [DashboardController::class, 'storeLlmExperiment'])->name('dashboard.llm-experiments.store');
        Route::post('/dashboard/agent-profiles', [DashboardController::class, 'storeAgentProfile'])->name('dashboard.agent-profiles.store');
        Route::get('/dashboard/telemetry/export', [DashboardController::class, 'exportTelemetry'])->name('dashboard.telemetry.export');
        Route::post('/dashboard/devices/{device}/debug', [DashboardController::class, 'requestDeviceDebug'])->name('dashboard.devices.debug.request');
        Route::get('/dashboard/device-debug/{debugRequest}/download', [DashboardController::class, 'downloadDeviceDebug'])->name('dashboard.devices.debug.download');
        Route::post('/dashboard/settings', [DashboardController::class, 'storeSettings'])->name('dashboard.settings.store');
        Route::post('/dashboard/workflows', [DashboardController::class, 'storeWorkflow'])->name('dashboard.workflows.store');
        Route::post('/dashboard/workflows/import', [DashboardController::class, 'importWorkflow'])->name('dashboard.workflows.import');
        Route::post('/dashboard/workflows/{workflowDefinition}/start', [DashboardController::class, 'startWorkflow'])->name('dashboard.workflows.start');
        Route::get('/dashboard/workflows/{workflowDefinition}/export', [DashboardController::class, 'exportWorkflow'])->name('dashboard.workflows.export');
        Route::delete('/dashboard/workflows/{workflowDefinition}', [DashboardController::class, 'deleteWorkflow'])->name('dashboard.workflows.destroy');
    });
});
