<?php

use App\Modules\Avatars\Http\Controllers\AvatarController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/profile/avatar', [AvatarController::class, 'uploadProfile']);
    Route::delete('/profile/avatar', [AvatarController::class, 'deleteProfile']);

    Route::post('/family-tree/members/{memberUuid}/avatar', [AvatarController::class, 'uploadMember']);
    Route::delete('/family-tree/members/{memberUuid}/avatar', [AvatarController::class, 'deleteMember']);

    Route::get('/avatars/{subjectType}/{subjectUuid}/{variant}', [AvatarController::class, 'show'])
        ->whereIn('subjectType', ['users', 'members'])
        ->whereIn('variant', ['thumb', 'master']);
});
