<?php

use App\Modules\Media\Http\Controllers\MediaController;
use App\Modules\Media\Http\Controllers\MediaEventController;
use Illuminate\Support\Facades\Route;

Route::prefix('media')->middleware('auth:sanctum')->group(function () {
    Route::get('/shares/unread-count', [MediaController::class, 'shareUnreadCount']);
    Route::post('/shares/mark-seen', [MediaController::class, 'markSharesSeen']);

    Route::get('/', [MediaController::class, 'index']);
    Route::post('/uploads/initiate', [MediaController::class, 'initiate']);

    Route::get('/events', [MediaEventController::class, 'index']);
    Route::post('/events', [MediaEventController::class, 'store']);
    Route::get('/events/{uuid}', [MediaEventController::class, 'show']);
    Route::patch('/events/{uuid}', [MediaEventController::class, 'update']);
    Route::post('/events/{uuid}/share', [MediaEventController::class, 'share']);
    Route::patch('/events/{uuid}/share-alias', [MediaEventController::class, 'renameShare']);
    Route::delete('/events/{uuid}', [MediaEventController::class, 'destroy']);

    Route::post('/transfers/{transferUuid}/accept', [MediaController::class, 'acceptTransfer']);
    Route::post('/transfers/{transferUuid}/decline', [MediaController::class, 'declineTransfer']);
    Route::post('/transfers/{transferUuid}/cancel', [MediaController::class, 'cancelTransfer']);

    Route::put('/{uuid}/content', [MediaController::class, 'uploadContent']);
    Route::get('/{uuid}/content', [MediaController::class, 'downloadContent']);
    Route::put('/{uuid}/thumbnail', [MediaController::class, 'uploadThumbnail']);
    Route::get('/{uuid}/thumbnail', [MediaController::class, 'downloadThumbnail']);
    Route::put('/{uuid}/stream/manifest', [MediaController::class, 'storeStreamManifest']);
    Route::put('/{uuid}/stream/chunks/{index}', [MediaController::class, 'storeStreamChunk'])
        ->whereNumber('index');
    Route::get('/{uuid}/stream/manifest', [MediaController::class, 'streamManifest']);
    Route::get('/{uuid}/stream/chunks/{index}', [MediaController::class, 'streamChunk'])
        ->whereNumber('index');
    Route::get('/{uuid}/upload/status', [MediaController::class, 'uploadStatus']);
    Route::put('/{uuid}/chunks/{partNumber}', [MediaController::class, 'uploadChunk'])
        ->whereNumber('partNumber');
    Route::delete('/{uuid}/upload', [MediaController::class, 'abortUpload']);
    Route::post('/{uuid}/complete', [MediaController::class, 'complete']);
    Route::patch('/{uuid}/event', [MediaController::class, 'assignEvent']);
    Route::post('/{uuid}/permissions', [MediaController::class, 'grantPermission']);
    Route::post('/{uuid}/co-owners/group', [MediaController::class, 'assignGroupCoOwners']);
    Route::delete('/{uuid}/permissions/{permissionId}', [MediaController::class, 'revokePermission']);
    Route::post('/{uuid}/encryption/envelopes', [MediaController::class, 'storeEnvelopes']);
    Route::get('/{uuid}/encryption/envelopes/me', [MediaController::class, 'myEnvelope']);
    Route::post('/{uuid}/transfer', [MediaController::class, 'initiateTransfer']);

    Route::get('/{uuid}', [MediaController::class, 'show']);
    Route::delete('/{uuid}', [MediaController::class, 'destroy']);
});
