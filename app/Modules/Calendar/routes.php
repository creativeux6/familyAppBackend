<?php

use App\Modules\Calendar\Http\Controllers\CalendarController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum'])->prefix('calendar')->group(function () {
    Route::get('/', [CalendarController::class, 'index']);
    Route::get('/today', [CalendarController::class, 'today']);
    Route::post('/reminders', [CalendarController::class, 'storeReminder']);
    Route::delete('/reminders/{reminderUuid}', [CalendarController::class, 'destroyReminder']);
});
