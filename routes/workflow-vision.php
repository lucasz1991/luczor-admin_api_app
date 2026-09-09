<?php

use App\Http\Controllers\Api\V1\WorkflowVisionController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/proxy/vision')->middleware(['luczor.api:proxy.use', 'throttle:30,1'])->group(function () {
    Route::get('/capabilities', [WorkflowVisionController::class, 'capabilities']);
    Route::post('/', [WorkflowVisionController::class, 'infer']);
});
