<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
        apiPrefix: 'api',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        $exceptions->reportable(function (\Throwable $e): void {
            $request = request();
            if (! $request instanceof Request || ! $request->is('api/*')) {
                return;
            }

            $status = method_exists($e, 'getStatusCode')
                ? (int) $e->getStatusCode()
                : 500;

            // Persist server/debug failures and unhandled errors for the admin Logs page.
            if ($status < 500 && ! $e instanceof \Error) {
                return;
            }

            try {
                app(\App\Modules\Admin\Services\SystemErrorLogService::class)->record(
                    $e,
                    $request->user(),
                    $request->method(),
                    '/'.$request->path(),
                    $status >= 400 ? $status : 500,
                    $request->ip(),
                    $request->header('X-Request-Id') ?: (string) Str::uuid(),
                );
            } catch (\Throwable) {
                // ignore
            }
        });
    })->create();
