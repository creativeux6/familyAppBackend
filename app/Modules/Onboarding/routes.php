<?php

use App\Modules\Onboarding\Http\Controllers\OnboardingController;
use Illuminate\Support\Facades\Route;

Route::prefix('onboarding')->middleware('auth:sanctum')->group(function () {
    Route::get('/status', [OnboardingController::class, 'status']);
    Route::post('/start-solo', [OnboardingController::class, 'startSolo']);
    Route::post('/lookup-member-code', [OnboardingController::class, 'lookupMemberCode']);
    Route::post('/join-by-member-code', [OnboardingController::class, 'joinByMemberCode']);
    Route::post('/find-by-relatives', [OnboardingController::class, 'findByRelatives']);
    Route::post('/parent-context', [OnboardingController::class, 'storeParentContext']);
    Route::get('/declared-relatives', [OnboardingController::class, 'declaredRelatives']);
    Route::post('/declared-relatives', [OnboardingController::class, 'syncDeclaredRelatives']);
    Route::post('/questionnaire', [OnboardingController::class, 'submitQuestionnaire']);
    Route::get('/match-result', [OnboardingController::class, 'matchResult']);
    Route::post('/confirm-family', [OnboardingController::class, 'confirmFamily']);
});
