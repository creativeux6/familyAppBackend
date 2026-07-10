<?php

use App\Modules\Admin\Http\Controllers\AdminAbuseReportController;
use App\Modules\Admin\Http\Controllers\AdminAuditLogController;
use App\Modules\Admin\Http\Controllers\AdminDashboardController;
use App\Modules\Admin\Http\Controllers\AdminUserController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'role:admin'])->prefix('admin')->group(function () {
    Route::get('/dashboard', [AdminDashboardController::class, 'index']);

    Route::get('/users', [AdminUserController::class, 'index']);
    Route::get('/users/{uuid}', [AdminUserController::class, 'show']);
    Route::patch('/users/{uuid}', [AdminUserController::class, 'update']);
    Route::delete('/users/{uuid}', [AdminUserController::class, 'destroy']);
    Route::post('/users/{uuid}/restore', [AdminUserController::class, 'restore']);
    Route::post('/users/{uuid}/roles', [AdminUserController::class, 'assignRole']);
    Route::delete('/users/{uuid}/roles/{role}', [AdminUserController::class, 'removeRole']);

    Route::get('/audit-logs', [AdminAuditLogController::class, 'index']);

    Route::get('/abuse-reports', [AdminAbuseReportController::class, 'index']);
    Route::patch('/abuse-reports/{uuid}', [AdminAbuseReportController::class, 'update']);
});
