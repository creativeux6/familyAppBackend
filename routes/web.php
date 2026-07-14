<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect('/web'));

Route::get('/swagger', fn () => redirect('/api/documentation'));

Route::view('/web/{any?}', 'web')->where('any', '.*');

Route::get('/panel/{any?}', function (?string $any = null) {
    $suffix = $any ? '/'.$any : '';

    return redirect('/web'.$suffix);
})->where('any', '.*');
