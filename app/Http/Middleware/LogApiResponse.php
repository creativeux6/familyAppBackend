<?php

namespace App\Http\Middleware;

use App\Modules\Admin\Services\SystemErrorLogService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Records every API response (2xx–5xx) into system_error_logs for the admin Logs page.
 */
class LogApiResponse
{
    public function __construct(
        private readonly SystemErrorLogService $logService,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->shouldLog($request)) {
            return $next($request);
        }

        $requestId = $request->header('X-Request-Id') ?: (string) Str::uuid();
        $startedAt = microtime(true);

        try {
            $response = $next($request);
        } catch (Throwable $e) {
            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
            $this->logService->recordException(
                $e,
                $request->user(),
                $request->method(),
                '/'.$request->path(),
                SystemErrorLogService::resolveStatus($e),
                $request->ip(),
                $requestId,
                $durationMs,
            );
            $request->attributes->set('api_response_logged', true);

            throw $e;
        }

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
        $this->logService->recordHttpResponse(
            $request->user(),
            $request->method(),
            '/'.$request->path(),
            (int) $response->getStatusCode(),
            $request->ip(),
            $requestId,
            $durationMs,
            $this->responseSummary($response),
        );
        $request->attributes->set('api_response_logged', true);

        return $response;
    }

    private function shouldLog(Request $request): bool
    {
        if (! $request->is('api/*')) {
            return false;
        }

        // Avoid recursive / high-chatter admin log endpoints.
        if (
            $request->is('api/*/client-errors')
            || $request->is('*/client-errors')
            || $request->is('api/*/admin/system-logs')
            || $request->is('api/*/admin/system-logs/*')
            || $request->is('*/admin/system-logs')
            || $request->is('*/admin/system-logs/*')
            || $request->is('api/*/admin/websocket-health')
            || $request->is('*/admin/websocket-health')
        ) {
            return false;
        }

        return true;
    }

    private function responseSummary(Response $response): ?string
    {
        $contentType = (string) $response->headers->get('Content-Type', '');
        if (! str_contains($contentType, 'json')) {
            return 'content-type: '.$contentType.'; size='.strlen((string) $response->getContent());
        }

        $content = (string) $response->getContent();
        if ($content === '') {
            return null;
        }

        return Str::limit($content, 4000);
    }
}
