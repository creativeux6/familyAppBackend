<?php

use App\Modules\Devices\Http\Controllers\DevicePushTokenController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->prefix('devices')->group(function () {
    Route::post('/push-token', [DevicePushTokenController::class, 'store']);
    Route::delete('/push-token', [DevicePushTokenController::class, 'destroy']);
});
