<?php

use App\Modules\Account\Http\Controllers\AccountController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::delete('/account', [AccountController::class, 'destroy']);
});
