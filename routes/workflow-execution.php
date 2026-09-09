<?php

use App\Http\Controllers\Api\V1\WorkflowExecutionController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware('luczor.api:brain.write')->group(function () {
    Route::post('/workflow-runs/{workflowRun}/stop-after-step', [WorkflowExecutionController::class, 'stopAfterStep']);
    Route::post('/workflows/device-capabilities', [WorkflowExecutionController::class, 'capabilities']);
    Route::post('/workflows/{workflowDefinition}/test-cases', [WorkflowExecutionController::class, 'storeCase'])->whereNumber('workflowDefinition');
    Route::post('/workflows/{workflowDefinition}/tests', [WorkflowExecutionController::class, 'startTest'])->whereNumber('workflowDefinition');
    Route::post('/workflows/{workflowDefinition}/repair-policy', [WorkflowExecutionController::class, 'policy'])->whereNumber('workflowDefinition');
    Route::post('/workflows/{workflowDefinition}/repairs', [WorkflowExecutionController::class, 'propose'])->whereNumber('workflowDefinition');
    Route::post('/workflow-repairs/{workflowRepair}/activate', [WorkflowExecutionController::class, 'activate'])->whereNumber('workflowRepair');
});
Route::prefix('v1')->middleware('luczor.api:brain.read')->group(function () {
    Route::get('/workflows/{workflowDefinition}/test-cases', [WorkflowExecutionController::class, 'cases'])->whereNumber('workflowDefinition');
    Route::get('/workflows/{workflowDefinition}/tests', [WorkflowExecutionController::class, 'tests'])->whereNumber('workflowDefinition');
    Route::get('/workflows/{workflowDefinition}/repairs', [WorkflowExecutionController::class, 'repairs'])->whereNumber('workflowDefinition');
    Route::get('/workflow-tests/{workflowTest}', [WorkflowExecutionController::class, 'test'])->whereNumber('workflowTest');
});
