<?php

use App\Modules\Friends\Http\Controllers\FriendsController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::prefix('friends')->group(function () {
        Route::post('/sync', [FriendsController::class, 'sync'])
            ->middleware('throttle:friends-sync');
        Route::get('/', [FriendsController::class, 'index']);
    });
});
