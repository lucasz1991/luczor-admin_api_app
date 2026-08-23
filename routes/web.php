<?php

use App\Http\Controllers\Admin\ApiKeyController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\ModelConfigurationController;
use App\Http\Controllers\Admin\PersonaSkillController;
use App\Http\Controllers\Admin\SystemOperationsController;
use App\Http\Controllers\Admin\WorkflowController;
use App\Http\Controllers\GithubOAuthController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::middleware(['auth:sanctum', config('jetstream.auth_session'), 'verified'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::post('/dashboard/api-keys', [ApiKeyController::class, 'storeApiKey'])->name('dashboard.api-keys.store');
    Route::post('/dashboard/api-keys/{apiKey}/toggle', [ApiKeyController::class, 'toggleApiKey'])->name('dashboard.api-keys.toggle');
    Route::get('/github/redirect', [GithubOAuthController::class, 'redirect'])->name('github.redirect');
    Route::get('/github/callback', [GithubOAuthController::class, 'callback'])->name('github.callback');

    Route::middleware('role:admin')->group(function () {
        Route::get('/admin/{page}', [DashboardController::class, 'page'])->name('admin.page');
        Route::post('/dashboard/provider-credentials', [ModelConfigurationController::class, 'storeProviderCredential'])->name('dashboard.provider-credentials.store');
        Route::post('/dashboard/provider-credentials/{providerCredential}/toggle', [ModelConfigurationController::class, 'toggleProviderCredential'])->name('dashboard.provider-credentials.toggle');
        Route::post('/dashboard/model-profiles', [ModelConfigurationController::class, 'storeModelProfile'])->name('dashboard.model-profiles.store');
        Route::post('/dashboard/model-profiles/{modelProfile}/toggle', [ModelConfigurationController::class, 'toggleModelProfile'])->name('dashboard.model-profiles.toggle');
        Route::put('/dashboard/model-profiles/{modelProfile}', [ModelConfigurationController::class, 'updateModelProfile'])->name('dashboard.model-profiles.update');
        Route::delete('/dashboard/model-profiles/{modelProfile}', [ModelConfigurationController::class, 'destroyModelProfile'])->name('dashboard.model-profiles.destroy');
        Route::post('/dashboard/model-use-cases', [ModelConfigurationController::class, 'storeModelUseCase'])->name('dashboard.model-use-cases.store');
        Route::post('/dashboard/model-use-case-entries', [ModelConfigurationController::class, 'storeModelUseCaseEntry'])->name('dashboard.model-use-case-entries.store');
        Route::post('/dashboard/model-use-case-entries/reorder', [ModelConfigurationController::class, 'reorderModelUseCaseEntries'])->name('dashboard.model-use-case-entries.reorder');
        Route::post('/dashboard/model-use-case-entries/{modelUseCaseEntry}/toggle', [ModelConfigurationController::class, 'toggleModelUseCaseEntry'])->name('dashboard.model-use-case-entries.toggle');
        Route::delete('/dashboard/model-use-case-entries/{modelUseCaseEntry}', [ModelConfigurationController::class, 'destroyModelUseCaseEntry'])->name('dashboard.model-use-case-entries.destroy');
        Route::post('/dashboard/model-profiles/reorder', [ModelConfigurationController::class, 'reorderModelProfiles'])->name('dashboard.model-profiles.reorder');
        Route::post('/dashboard/provider-prices', [ModelConfigurationController::class, 'storeProviderPrice'])->name('dashboard.provider-prices.store');
        Route::post('/dashboard/prompt-templates', [ModelConfigurationController::class, 'storePromptTemplate'])->name('dashboard.prompt-templates.store');
        Route::post('/dashboard/context-strategies', [ModelConfigurationController::class, 'storeContextStrategy'])->name('dashboard.context-strategies.store');
        Route::post('/dashboard/network-policies', [ModelConfigurationController::class, 'storeNetworkPolicy'])->name('dashboard.network-policies.store');
        Route::post('/dashboard/llm-experiments', [ModelConfigurationController::class, 'storeLlmExperiment'])->name('dashboard.llm-experiments.store');
        Route::post('/dashboard/agent-profiles', [ModelConfigurationController::class, 'storeAgentProfile'])->name('dashboard.agent-profiles.store');

        Route::post('/dashboard/personas', [PersonaSkillController::class, 'storePersona'])->name('dashboard.personas.store');
        Route::post('/dashboard/personas/{persona}/activate', [PersonaSkillController::class, 'activatePersona'])->name('dashboard.personas.activate');
        Route::post('/dashboard/personas/deactivate', [PersonaSkillController::class, 'deactivatePersonas'])->name('dashboard.personas.deactivate');
        Route::post('/dashboard/skills', [PersonaSkillController::class, 'storeSkill'])->name('dashboard.skills.store');
        Route::post('/dashboard/skills/{skill}/toggle', [PersonaSkillController::class, 'toggleSkill'])->name('dashboard.skills.toggle');
        Route::post('/dashboard/skills/{skill}/run', [PersonaSkillController::class, 'runSkill'])->name('dashboard.skills.run');
        Route::delete('/dashboard/skills/{skill}', [PersonaSkillController::class, 'deleteSkill'])->name('dashboard.skills.destroy');
        Route::post('/dashboard/model-use-cases/{modelUseCase}/review', [PersonaSkillController::class, 'updateUseCaseReview'])->name('dashboard.model-use-cases.review');

        Route::get('/dashboard/telemetry/export', [SystemOperationsController::class, 'exportTelemetry'])->name('dashboard.telemetry.export');
        Route::post('/dashboard/devices/{device}/debug', [SystemOperationsController::class, 'requestDeviceDebug'])->name('dashboard.devices.debug.request');
        Route::get('/dashboard/device-debug/{debugRequest}/download', [SystemOperationsController::class, 'downloadDeviceDebug'])->name('dashboard.devices.debug.download');
        Route::post('/dashboard/settings', [SystemOperationsController::class, 'storeSettings'])->name('dashboard.settings.store');

        Route::post('/dashboard/workflows', [WorkflowController::class, 'storeWorkflow'])->name('dashboard.workflows.store');
        Route::post('/dashboard/workflows/import', [WorkflowController::class, 'importWorkflow'])->name('dashboard.workflows.import');
        Route::post('/dashboard/workflows/template', [WorkflowController::class, 'createWorkflowFromTemplate'])->name('dashboard.workflows.template');
        Route::post('/dashboard/workflows/plan', [WorkflowController::class, 'planWorkflow'])->name('dashboard.workflows.plan');
        Route::post('/dashboard/workflows/{workflowDefinition}/start', [WorkflowController::class, 'startWorkflow'])->name('dashboard.workflows.start');
        Route::get('/dashboard/workflows/{workflowDefinition}/export', [WorkflowController::class, 'exportWorkflow'])->name('dashboard.workflows.export');
        Route::delete('/dashboard/workflows/{workflowDefinition}', [WorkflowController::class, 'deleteWorkflow'])->name('dashboard.workflows.destroy');
        Route::put('/dashboard/workflows/{workflowDefinition}', [WorkflowController::class, 'updateWorkflow'])->name('dashboard.workflows.update');
        Route::post('/dashboard/workflows/{workflowDefinition}/duplicate', [WorkflowController::class, 'duplicateWorkflow'])->name('dashboard.workflows.duplicate');
        Route::post('/dashboard/workflows/{workflowDefinition}/toggle', [WorkflowController::class, 'toggleWorkflow'])->name('dashboard.workflows.toggle');
        Route::post('/dashboard/workflows/{workflowDefinition}/lock', [WorkflowController::class, 'toggleWorkflowLock'])->name('dashboard.workflows.lock');
        Route::get('/dashboard/workflow-runs/{workflowRun}/status', [WorkflowController::class, 'workflowRunStatus'])->name('dashboard.workflow-runs.status');
        Route::post('/dashboard/workflow-runs/{workflowRun}/cancel', [WorkflowController::class, 'cancelWorkflowRun'])->name('dashboard.workflow-runs.cancel');
        Route::post('/dashboard/workflow-steps/{workflowStep}/approve', [WorkflowController::class, 'approveWorkflowStep'])->name('dashboard.workflow-steps.approve');
    });
});
