<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect('/api/documentation'));

Route::get('/swagger', fn () => redirect('/api/documentation'));
