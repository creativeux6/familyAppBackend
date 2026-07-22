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

        // Outermost API middleware so auth / validation failures are still logged.
        $middleware->api(prepend: [
            \App\Http\Middleware\SecurityHeaders::class,
            \App\Http\Middleware\LogApiResponse::class,
        ]);

        // Default ceiling for authenticated API traffic (per user / IP).
        $middleware->throttleApi('api');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        // Fallback for failures that bypass the middleware catch (should be rare).
        $exceptions->reportable(function (\Throwable $e): void {
            $request = request();
            if (! $request instanceof Request || ! $request->is('api/*')) {
                return;
            }

            if ($request->attributes->get('api_response_logged')) {
                return;
            }

            if (
                $request->is('api/*/client-errors')
                || $request->is('*/client-errors')
                || $request->is('api/*/admin/system-logs*')
                || $request->is('*/admin/system-logs*')
            ) {
                return;
            }

            $status = \App\Modules\Admin\Services\SystemErrorLogService::resolveStatus($e);
            if ($status < 400) {
                return;
            }

            try {
                app(\App\Modules\Admin\Services\SystemErrorLogService::class)->recordException(
                    $e,
                    $request->user(),
                    $request->method(),
                    '/'.$request->path(),
                    $status,
                    $request->ip(),
                    $request->header('X-Request-Id') ?: (string) Str::uuid(),
                );
                $request->attributes->set('api_response_logged', true);
            } catch (\Throwable) {
                // ignore
            }
        });
    })->create();
