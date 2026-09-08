<?php

use App\Http\Controllers\Api\V1\WorkflowTriggerController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('/workflow-hooks/{publicId}', [WorkflowTriggerController::class, 'hook'])->whereUuid('publicId')->middleware('throttle:60,1');
    Route::middleware('luczor.api:brain.read')->group(function () {
        Route::get('/workflow-trigger-sources', [WorkflowTriggerController::class, 'sources']);
        Route::get('/workflows/{workflowDefinition}/triggers', [WorkflowTriggerController::class, 'index']);
        Route::get('/workflows/{workflowDefinition}/automation', [WorkflowTriggerController::class, 'automation']);
        Route::get('/workflow-triggers/{workflowTrigger}/deliveries', [WorkflowTriggerController::class, 'deliveries']);
    });
    Route::middleware('luczor.api:brain.write')->group(function () {
        Route::post('/workflows/{workflowDefinition}/triggers', [WorkflowTriggerController::class, 'store']);
        Route::patch('/workflow-triggers/{workflowTrigger}', [WorkflowTriggerController::class, 'update']);
        Route::delete('/workflow-triggers/{workflowTrigger}', [WorkflowTriggerController::class, 'destroy']);
        Route::post('/workflow-trigger-deliveries/{workflowTriggerDelivery}/retry', [WorkflowTriggerController::class, 'retryDelivery']);
        Route::post('/workflows/{workflowDefinition}/automation', [WorkflowTriggerController::class, 'configureAutomation']);
    });
    Route::middleware('luczor.api:device.connect')->group(function () {
        Route::get('/workflow-triggers/device', [WorkflowTriggerController::class, 'deviceTriggers']);
        Route::post('/workflow-events', [WorkflowTriggerController::class, 'fileEvent']);
    });
});
