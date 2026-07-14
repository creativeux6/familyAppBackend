<?php

use App\Modules\FamilyTree\Http\Controllers\FamilyTreeController;
use Illuminate\Support\Facades\Route;

Route::prefix('family-tree')->middleware('auth:sanctum')->group(function () {
    Route::get('/', [FamilyTreeController::class, 'index']);
    Route::get('/family-info', [FamilyTreeController::class, 'familyInfo']);
    Route::patch('/family-info', [FamilyTreeController::class, 'updateFamilyInfo']);
    Route::post('/member-candidates', [FamilyTreeController::class, 'matchCandidates']);
    Route::post('/members', [FamilyTreeController::class, 'addMember']);
    Route::get('/members/{memberUuid}', [FamilyTreeController::class, 'member']);
    Route::get('/kinship/{targetMemberUuid}', [FamilyTreeController::class, 'kinship']);
});
