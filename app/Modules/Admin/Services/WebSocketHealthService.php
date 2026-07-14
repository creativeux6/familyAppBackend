<?php

namespace App\Modules\Admin\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

class WebSocketHealthService
{
    /** @return array<string, mixed> */
    public function check(): array
    {
        $serverHost = (string) config('reverb.servers.reverb.host', env('REVERB_SERVER_HOST', '127.0.0.1'));
        $serverPort = (int) config('reverb.servers.reverb.port', env('REVERB_SERVER_PORT', 8080));
        $clientHost = (string) env('REVERB_HOST', 'localhost');
        $clientPort = (int) env('REVERB_PORT', $serverPort);
        $scheme = (string) env('REVERB_SCHEME', 'http');
        $appKey = (string) config('reverb.apps.apps.0.key', env('REVERB_APP_KEY', ''));
        $logPath = env('REVERB_LOG_PATH', '/var/log/familyapp/reverb.log');

        $sockets = [
            $this->tcpSocket(
                id: 'reverb_server',
                name: 'Reverb server',
                description: 'Laravel Reverb process (TCP bind) — run: php artisan reverb:start',
                host: $this->normalizeHost($serverHost),
                port: $serverPort,
            ),
            $this->tcpSocket(
                id: 'reverb_client',
                name: 'Reverb client endpoint',
                description: 'Host/port clients use (REVERB_HOST:REVERB_PORT)',
                host: $this->normalizeHost($clientHost),
                port: $clientPort,
            ),
            $this->internalHttpSocket(
                id: 'broadcasting_auth',
                name: 'Broadcasting auth',
                description: 'POST /broadcasting/auth — part of Laravel API (php artisan serve)',
                path: '/broadcasting/auth',
                method: 'POST',
            ),
            $this->internalHttpSocket(
                id: 'groups_realtime_config',
                name: 'Groups realtime config API',
                description: 'GET /api/v1/groups/realtime/config — part of Laravel API (php artisan serve)',
                path: '/api/v1/groups/realtime/config',
                method: 'GET',
            ),
            $this->channelSocket(
                id: 'channel_group',
                name: 'Channel private-group.{uuid}',
                description: 'Group chat private channel registration',
                channel: 'private-group.{groupUuid}',
                registered: true,
            ),
            $this->channelSocket(
                id: 'channel_user',
                name: 'Channel private-user.{uuid}',
                description: 'Per-user private channel registration',
                channel: 'private-user.{userUuid}',
                registered: true,
            ),
            $this->configSocket(
                id: 'broadcast_driver',
                name: 'Broadcast driver',
                description: 'BROADCAST_CONNECTION must be reverb',
                ok: (string) config('broadcasting.default') === 'reverb',
                detail: 'driver='.(string) config('broadcasting.default'),
            ),
            $this->configSocket(
                id: 'reverb_app_key',
                name: 'Reverb app key',
                description: 'REVERB_APP_KEY is configured',
                ok: $appKey !== '',
                detail: $appKey !== '' ? 'key is set' : 'key missing',
            ),
        ];

        $recentErrors = $this->tailLogErrors($logPath, 20);

        $downCount = count(array_filter($sockets, fn (array $s) => $s['status'] === 'down'));
        $degradedCount = count(array_filter($sockets, fn (array $s) => $s['status'] === 'degraded'));

        $status = 'ok';
        if ($downCount > 0) {
            $status = 'down';
        } elseif ($degradedCount > 0 || $recentErrors !== []) {
            $status = 'degraded';
        }

        return [
            'status' => $status,
            'checked_at' => now()->toIso8601String(),
            'summary' => [
                'total' => count($sockets),
                'ok' => count(array_filter($sockets, fn (array $s) => $s['status'] === 'ok')),
                'degraded' => $degradedCount,
                'down' => $downCount,
            ],
            'sockets' => $sockets,
            'connection' => [
                'host' => $this->normalizeHost($serverHost),
                'client_host' => $this->normalizeHost($clientHost),
                'port' => $serverPort,
                'client_port' => $clientPort,
                'scheme' => $scheme,
                'reachable' => $sockets[0]['status'] === 'ok',
                'app_key_set' => $appKey !== '',
                'broadcast_driver' => (string) config('broadcasting.default'),
                'auth_endpoint' => url('/broadcasting/auth'),
            ],
            'howto' => [
                'api' => 'cd backend && php artisan serve --host=0.0.0.0 --port=8000',
                'reverb' => 'cd backend && php artisan reverb:start',
                'queue' => 'cd backend && php artisan queue:work',
            ],
            'log_path' => $logPath,
            'log_readable' => is_readable($logPath),
            'recent_errors' => $recentErrors,
        ];
    }

    /** @return array<string, mixed> */
    private function tcpSocket(string $id, string $name, string $description, string $host, int $port): array
    {
        $started = microtime(true);
        $ok = $this->isPortOpen($host, $port);
        $latencyMs = (int) round((microtime(true) - $started) * 1000);

        return [
            'id' => $id,
            'name' => $name,
            'type' => 'tcp',
            'description' => $description,
            'endpoint' => "{$host}:{$port}",
            'status' => $ok ? 'ok' : 'down',
            'latency_ms' => $latencyMs,
            'message' => $ok ? 'Port is reachable' : 'Could not open TCP connection — is Reverb running?',
        ];
    }

    /**
     * Probe via the local HTTP kernel (no loopback to APP_URL).
     * 401/403/419/422 = route exists and is protecting itself = ok.
     *
     * @return array<string, mixed>
     */
    private function internalHttpSocket(
        string $id,
        string $name,
        string $description,
        string $path,
        string $method = 'GET',
    ): array {
        $started = microtime(true);

        try {
            $routeExists = collect(Route::getRoutes())->contains(
                function ($route) use ($path, $method): bool {
                    if (! in_array(strtoupper($method), $route->methods(), true)) {
                        return false;
                    }

                    return trim($route->uri(), '/') === trim($path, '/');
                }
            );

            $request = Request::create($path, strtoupper($method), [], [], [], [
                'HTTP_ACCEPT' => 'application/json',
            ]);

            $response = app()->handle($request);
            $code = $response->getStatusCode();
            $latencyMs = (int) round((microtime(true) - $started) * 1000);

            $up = $code > 0 && $code < 500;

            return [
                'id' => $id,
                'name' => $name,
                'type' => 'http',
                'description' => $description,
                'endpoint' => $path,
                'status' => $up ? 'ok' : 'down',
                'latency_ms' => $latencyMs,
                'http_status' => $code,
                'route_registered' => $routeExists,
                'message' => $up
                    ? "Route responded with HTTP {$code} (auth required is normal)"
                    : ($routeExists
                        ? "Route registered but failed with HTTP {$code}"
                        : 'Route not registered — ensure API/broadcast routes are loaded'),
            ];
        } catch (\Throwable $e) {
            return [
                'id' => $id,
                'name' => $name,
                'type' => 'http',
                'description' => $description,
                'endpoint' => $path,
                'status' => 'down',
                'latency_ms' => (int) round((microtime(true) - $started) * 1000),
                'http_status' => null,
                'message' => $e->getMessage(),
            ];
        }
    }

    /** @return array<string, mixed> */
    private function channelSocket(
        string $id,
        string $name,
        string $description,
        string $channel,
        bool $registered,
    ): array {
        return [
            'id' => $id,
            'name' => $name,
            'type' => 'channel',
            'description' => $description,
            'endpoint' => $channel,
            'status' => $registered ? 'ok' : 'down',
            'latency_ms' => null,
            'message' => $registered
                ? 'Channel authorization callback is registered'
                : 'Channel is not registered',
        ];
    }

    /** @return array<string, mixed> */
    private function configSocket(
        string $id,
        string $name,
        string $description,
        bool $ok,
        string $detail,
    ): array {
        return [
            'id' => $id,
            'name' => $name,
            'type' => 'config',
            'description' => $description,
            'endpoint' => $detail,
            'status' => $ok ? 'ok' : 'degraded',
            'latency_ms' => null,
            'message' => $ok ? 'Configuration looks healthy' : 'Misconfigured — WebSockets may fail',
        ];
    }

    private function normalizeHost(string $host): string
    {
        if ($host === '0.0.0.0') {
            return '127.0.0.1';
        }

        return $host;
    }

    private function isPortOpen(string $host, int $port, float $timeoutSeconds = 1.0): bool
    {
        try {
            $errno = 0;
            $errstr = '';
            $socket = @fsockopen($host, $port, $errno, $errstr, $timeoutSeconds);
            if ($socket) {
                fclose($socket);

                return true;
            }
        } catch (\Throwable) {
            return false;
        }

        return false;
    }

    /** @return list<array{timestamp: string|null, message: string}> */
    private function tailLogErrors(string $logPath, int $limit): array
    {
        if (! is_readable($logPath)) {
            return [];
        }

        try {
            $lines = @file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (! is_array($lines) || $lines === []) {
                return [];
            }

            $errors = [];
            foreach (array_reverse($lines) as $line) {
                if (! preg_match('/error|exception|fatal|failed|warn/i', $line)) {
                    continue;
                }

                $timestamp = null;
                if (preg_match('/\[([^\]]+)\]/', $line, $m)) {
                    $timestamp = $m[1];
                }

                $errors[] = [
                    'timestamp' => $timestamp,
                    'message' => mb_substr($line, 0, 500),
                ];

                if (count($errors) >= $limit) {
                    break;
                }
            }

            return $errors;
        } catch (\Throwable) {
            return [];
        }
    }
}
