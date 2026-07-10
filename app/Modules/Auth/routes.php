<?php

use App\Modules\Auth\Http\Controllers\AuthController;
use App\Modules\Auth\Http\Controllers\SessionController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/logout', [SessionController::class, 'logout'])->middleware('auth:sanctum');
    Route::post('/refresh', [SessionController::class, 'refresh'])->middleware('auth:sanctum');
});
