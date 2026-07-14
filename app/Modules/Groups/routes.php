<?php

use App\Modules\Groups\Http\Controllers\GroupController;
use App\Modules\Groups\Http\Controllers\GroupEncryptionController;
use App\Modules\Groups\Http\Controllers\GroupMessageController;
use Illuminate\Support\Facades\Route;

Route::prefix('groups')->middleware('auth:sanctum')->group(function () {
    Route::get('/', [GroupController::class, 'index']);
    Route::post('/', [GroupController::class, 'store']);
    Route::post('/direct', [GroupController::class, 'storeDirect']);

    Route::get('/realtime/config', [GroupController::class, 'realtimeConfigGlobal']);

    Route::get('/{uuid}/realtime', [GroupController::class, 'realtime']);
    Route::get('/{uuid}/messages', [GroupMessageController::class, 'index']);
    Route::post('/{uuid}/messages', [GroupMessageController::class, 'store']);
    Route::post('/{uuid}/read', [GroupMessageController::class, 'markRead']);
    Route::patch('/{uuid}/messages/{messageUuid}', [GroupMessageController::class, 'update']);
    Route::delete('/{uuid}/messages/{messageUuid}', [GroupMessageController::class, 'destroy']);
    Route::post('/{uuid}/encryption/envelopes', [GroupEncryptionController::class, 'storeEnvelopes']);
    Route::get('/{uuid}/encryption/envelopes/me', [GroupEncryptionController::class, 'myEnvelope']);

    Route::get('/{uuid}', [GroupController::class, 'show']);
    Route::patch('/{uuid}', [GroupController::class, 'update']);
    Route::delete('/{uuid}', [GroupController::class, 'destroy']);
    Route::post('/{uuid}/members', [GroupController::class, 'addMembers']);
    Route::delete('/{uuid}/members/{userUuid}', [GroupController::class, 'removeMember']);
});
