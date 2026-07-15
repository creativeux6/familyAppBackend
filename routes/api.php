<?php

use App\Http\Controllers\BroadcastingAuthController;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1 — module routes
|--------------------------------------------------------------------------
| Each module lives in app/Modules/{ModuleName}/routes.php
| Controllers → Services pattern per module.
| Swagger docs: php artisan l5-swagger:generate
| UI: GET /api/documentation
*/

Route::prefix('v1')->group(function () {
    Route::post('/broadcasting/auth', BroadcastingAuthController::class)
        ->middleware('auth:sanctum');

    foreach (File::glob(app_path('Modules/*/routes.php')) as $moduleRoutes) {
        require $moduleRoutes;
    }
});
