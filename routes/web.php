<?php

use App\Http\Controllers\LegalController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect('/web'));

Route::get('/privacy', [LegalController::class, 'privacy'])->name('legal.privacy');
Route::get('/account-deletion', [LegalController::class, 'accountDeletionForm'])->name('legal.account-deletion');
Route::post('/account-deletion', [LegalController::class, 'accountDeletionSubmit'])
    ->middleware('throttle:auth-login')
    ->name('legal.account-deletion.submit');

Route::get('/swagger', fn () => redirect('/api/documentation'));

Route::view('/web/{any?}', 'web')->where('any', '.*');

Route::get('/panel/{any?}', function (?string $any = null) {
    $suffix = $any ? '/'.$any : '';

    return redirect('/web'.$suffix);
})->where('any', '.*');
