<?php

use App\Modules\Auth\Http\Controllers\AuthController;
use App\Modules\Auth\Http\Controllers\SessionController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register'])
        ->middleware('throttle:auth-register');
    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('throttle:auth-login');
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])
        ->middleware('throttle:auth-password');
    Route::post('/reset-password', [AuthController::class, 'resetPassword'])
        ->middleware('throttle:auth-password');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [SessionController::class, 'me']);
        Route::post('/logout', [SessionController::class, 'logout']);
        Route::post('/refresh', [SessionController::class, 'refresh']);
    });
});
