<?php

use App\Http\Controllers\Api\V1\ConversationContentController;
use App\Http\Controllers\Api\V1\CoordinatedArtifactController;
use App\Http\Controllers\Api\V1\CoordinatedJobController;
use App\Http\Controllers\Api\V1\DeviceCoordinationController;
use App\Http\Controllers\Api\V1\ProjectMirrorController;
use App\Http\Controllers\Api\V1\WorkflowMatrixController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('/conversations/{externalId}/messages', [ConversationContentController::class, 'show'])->middleware('luczor.api:brain.read')->whereUuid('externalId');
    Route::post('/conversations/{externalId}/messages', [ConversationContentController::class, 'store'])->middleware('luczor.api:brain.write')->whereUuid('externalId');
    Route::patch('/conversations/{externalId}', [ConversationContentController::class, 'update'])->middleware('luczor.api:brain.write')->whereUuid('externalId');
    Route::post('/workflows/{workflowDefinition}/test-matrices', [WorkflowMatrixController::class, 'store'])->middleware('luczor.api:brain.write')->whereNumber('workflowDefinition');
    Route::get('/workflow-test-matrices/{matrixId}', [WorkflowMatrixController::class, 'show'])->middleware('luczor.api:brain.read')->whereUuid('matrixId');
    Route::middleware('luczor.api:device.connect')->group(function () {
        Route::get('/coordination', [DeviceCoordinationController::class, 'show']);
        Route::get('/coordination/identities', [DeviceCoordinationController::class, 'identity']);
        Route::post('/coordination/identity', [DeviceCoordinationController::class, 'identity']);
        Route::post('/coordination/heartbeat', [DeviceCoordinationController::class, 'heartbeat']);
        Route::get('/coordination/jobs/pending', [CoordinatedJobController::class, 'pending']);
        Route::post('/coordination/jobs/{publicId}/{action}', [CoordinatedJobController::class, 'mutate'])
            ->whereUuid('publicId')->whereIn('action', ['claim', 'progress', 'complete', 'cancel-ack']);
        Route::put('/coordination/jobs/{publicId}/artifacts/{sha256}', [CoordinatedArtifactController::class, 'content'])->whereUuid('publicId')->where('sha256', '[a-f0-9]{64}');
    });
    Route::middleware('luczor.api:device.jobs.write')->group(function () {
        Route::post('/coordination/jobs', [CoordinatedJobController::class, 'store']);
        Route::post('/coordination/jobs/{publicId}/cancel', [CoordinatedJobController::class, 'mutate'])->whereUuid('publicId')->defaults('action', 'cancel');
        Route::post('/coordination/jobs/{publicId}/adopt', [CoordinatedJobController::class, 'mutate'])->whereUuid('publicId')->defaults('action', 'adopt');
    });
    Route::middleware('luczor.api:device.jobs.read')->group(function () {
        Route::get('/coordination/jobs', [CoordinatedJobController::class, 'index']);
        Route::get('/coordination/jobs/{publicId}', [CoordinatedJobController::class, 'show'])->whereUuid('publicId');
        Route::get('/coordination/jobs/{publicId}/artifacts/{sha256}', [CoordinatedArtifactController::class, 'content'])->whereUuid('publicId')->where('sha256', '[a-f0-9]{64}');
    });
    Route::prefix('/projects/{project}/mirror')->whereNumber('project')->group(function () {
        Route::middleware('luczor.api:brain.read')->group(function () {
            Route::get('/', [ProjectMirrorController::class, 'head']);
            Route::get('/chunks/{sha256}', [ProjectMirrorController::class, 'chunk'])->where('sha256', '[a-f0-9]{64}');
            Route::get('/manifests/{manifestId}', [ProjectMirrorController::class, 'show'])->whereUuid('manifestId');
            Route::get('/proposals', [ProjectMirrorController::class, 'proposals']);
        });
        Route::middleware('luczor.api:brain.write')->group(function () {
            Route::post('/lease', [ProjectMirrorController::class, 'lease']);
            Route::put('/chunks/{sha256}', [ProjectMirrorController::class, 'chunk'])->where('sha256', '[a-f0-9]{64}');
            Route::post('/manifests', [ProjectMirrorController::class, 'create']);
            Route::put('/manifests/{manifestId}/entries', [ProjectMirrorController::class, 'entries'])->whereUuid('manifestId');
            Route::post('/manifests/{manifestId}/publish', [ProjectMirrorController::class, 'publish'])->whereUuid('manifestId');
            Route::post('/manifests/{manifestId}/propose', [ProjectMirrorController::class, 'propose'])->whereUuid('manifestId');
        });
    });
});
