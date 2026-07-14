<?php

use App\Modules\StoragePlans\Http\Controllers\AdminStoragePlanController;
use App\Modules\StoragePlans\Http\Controllers\StorageQuotaController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::prefix('storage')->group(function () {
        Route::get('/quota', [StorageQuotaController::class, 'quota']);
        Route::get('/plans', [StorageQuotaController::class, 'plans']);
    });

    Route::prefix('admin/storage')->middleware('role:super_admin|admin')->group(function () {
        Route::get('/plans', [AdminStoragePlanController::class, 'index']);
        Route::post('/plans', [AdminStoragePlanController::class, 'store']);
        Route::patch('/plans/{uuid}', [AdminStoragePlanController::class, 'update']);
        Route::get('/users/{userUuid}/assignment', [AdminStoragePlanController::class, 'userAssignment']);
        Route::post('/users/{userUuid}/assign', [AdminStoragePlanController::class, 'assign']);
        Route::post('/assignments/{id}/revoke', [AdminStoragePlanController::class, 'revoke']);
    });
});
