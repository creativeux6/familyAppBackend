<?php

use App\Modules\Auth\Http\Controllers\AuthController;
use App\Modules\Auth\Http\Controllers\SessionController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [SessionController::class, 'me']);
        Route::post('/logout', [SessionController::class, 'logout']);
        Route::post('/refresh', [SessionController::class, 'refresh']);
    });
});
