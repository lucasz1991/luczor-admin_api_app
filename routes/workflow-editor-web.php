<?php

use App\Http\Controllers\Admin\WorkflowEditorController;
use Illuminate\Support\Facades\Route;

// Included inside the existing authenticated, verified, active-admin web group.
Route::prefix('/dashboard/workflows/{workflowDefinition}')->whereNumber('workflowDefinition')->group(function () {
    Route::get('/editor-state', [WorkflowEditorController::class, 'state'])->name('dashboard.workflows.editor.state');
    Route::put('', [WorkflowEditorController::class, 'save'])->name('dashboard.workflows.update');
    Route::post('/test-cases', [WorkflowEditorController::class, 'storeCase'])->name('dashboard.workflows.editor.test-cases');
    Route::post('/tests', [WorkflowEditorController::class, 'startTest'])->name('dashboard.workflows.editor.tests');
    Route::post('/repairs', [WorkflowEditorController::class, 'propose'])->name('dashboard.workflows.editor.repairs');
    Route::post('/run-controls', [WorkflowEditorController::class, 'controlRun'])->name('dashboard.workflows.editor.run-controls');
    Route::get('/operations/{operationId}', [WorkflowEditorController::class, 'operation'])->whereUuid('operationId')->name('dashboard.workflows.editor.operations');
});
