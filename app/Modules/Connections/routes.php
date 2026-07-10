<?php

use App\Modules\Connections\Http\Controllers\ConnectionController;
use App\Modules\Connections\Http\Controllers\PrivacyController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::prefix('connections')->group(function () {
        Route::get('/suggestions', [ConnectionController::class, 'suggestions']);
        Route::post('/bulk', [ConnectionController::class, 'bulk']);
        Route::post('/connect-all', [ConnectionController::class, 'connectAll']);
        Route::get('/', [ConnectionController::class, 'index']);
        Route::post('/', [ConnectionController::class, 'store']);
        Route::post('/{uuid}/accept', [ConnectionController::class, 'accept']);
        Route::post('/{uuid}/reject', [ConnectionController::class, 'reject']);
        Route::post('/{uuid}/disconnect', [ConnectionController::class, 'disconnect']);
        Route::post('/{uuid}/block', [ConnectionController::class, 'block']);
    });

    Route::prefix('privacy')->group(function () {
        Route::get('/', [PrivacyController::class, 'show']);
        Route::patch('/anonymity', [PrivacyController::class, 'updateAnonymity']);
    });
});
