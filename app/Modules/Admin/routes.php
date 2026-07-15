<?php

use App\Modules\Admin\Http\Controllers\AdminAbuseReportController;
use App\Modules\Admin\Http\Controllers\AdminAuditLogController;
use App\Modules\Admin\Http\Controllers\AdminDashboardController;
use App\Modules\Admin\Http\Controllers\AdminSystemLogController;
use App\Modules\Admin\Http\Controllers\AdminUserController;
use App\Modules\Admin\Http\Controllers\ClientErrorReportController;
use Illuminate\Support\Facades\Route;

// Any signed-in client can report failures that never hit Laravel (e.g. nginx 413).
Route::middleware(['auth:sanctum'])->post(
    '/client-errors',
    [ClientErrorReportController::class, 'store'],
);

Route::middleware(['auth:sanctum', 'role:super_admin|admin'])->prefix('admin')->group(function () {
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

    Route::get('/system-logs', [AdminSystemLogController::class, 'index']);
    Route::get('/system-logs/status-codes', [AdminSystemLogController::class, 'statusCodes']);
    Route::get('/system-logs/{uuid}', [AdminSystemLogController::class, 'show']);
    Route::get('/websocket-health', [AdminSystemLogController::class, 'websocketHealth']);
});
