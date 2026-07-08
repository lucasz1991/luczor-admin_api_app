<?php

use App\Http\Controllers\Admin\DashboardController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::middleware(['auth:sanctum', config('jetstream.auth_session'), 'verified'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::post('/dashboard/api-keys', [DashboardController::class, 'storeApiKey'])->name('dashboard.api-keys.store');
    Route::post('/dashboard/api-keys/{apiKey}/toggle', [DashboardController::class, 'toggleApiKey'])->name('dashboard.api-keys.toggle');
    Route::post('/dashboard/provider-credentials', [DashboardController::class, 'storeProviderCredential'])->name('dashboard.provider-credentials.store');
    Route::post('/dashboard/model-profiles', [DashboardController::class, 'storeModelProfile'])->name('dashboard.model-profiles.store');
    Route::post('/dashboard/model-use-cases', [DashboardController::class, 'storeModelUseCase'])->name('dashboard.model-use-cases.store');
    Route::post('/dashboard/model-use-case-entries', [DashboardController::class, 'storeModelUseCaseEntry'])->name('dashboard.model-use-case-entries.store');
});
