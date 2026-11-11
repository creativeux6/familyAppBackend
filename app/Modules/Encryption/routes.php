<?php

use App\Modules\Encryption\Http\Controllers\IdentityKeyController;
use App\Modules\Encryption\Http\Controllers\IdentityVaultController;
use App\Modules\Encryption\Http\Controllers\KeyBackupController;
use Illuminate\Support\Facades\Route;

Route::prefix('encryption')->middleware('auth:sanctum')->group(function () {
    Route::post('/identity-key', [IdentityKeyController::class, 'store']);
    Route::get('/identity-key/{userUuid}', [IdentityKeyController::class, 'show']);
    Route::get('/key-backup', [KeyBackupController::class, 'show']);
    Route::post('/key-backup', [KeyBackupController::class, 'store']);
    Route::put('/identity-vault', [IdentityVaultController::class, 'store']);
    Route::get('/identity-vault', [IdentityVaultController::class, 'show']);
});
