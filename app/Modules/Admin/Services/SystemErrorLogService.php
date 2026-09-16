<?php

namespace App\Modules\Admin\Services;

use App\Models\SystemErrorLog;
use App\Models\User;
use App\Support\AdminDiagnostic;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class SystemErrorLogService
{
    public static function resolveStatus(Throwable $e): int
    {
        if ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
            return (int) $e->getStatusCode();
        }

        if (method_exists($e, 'getStatusCode')) {
            $code = (int) $e->getStatusCode();
            if ($code >= 400) {
                return $code;
            }
        }

        if ($e instanceof \Illuminate\Validation\ValidationException) {
            return (int) ($e->status ?? 422);
        }

        if ($e instanceof \Illuminate\Auth\AuthenticationException) {
            return 401;
        }

        if ($e instanceof \Illuminate\Auth\Access\AuthorizationException) {
            return 403;
        }

        if ($e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
            return 404;
        }

        if ($e instanceof \Error) {
            return 500;
        }

        return 500;
    }

    public function record(
        Throwable $e,
        ?User $user,
        ?string $method,
        ?string $path,
        ?int $statusCode,
        ?string $ip = null,
        ?string $requestId = null,
        ?int $durationMs = null,
    ): void {
        $this->recordException($e, $user, $method, $path, $statusCode, $ip, $requestId, $durationMs);
    }

    public function recordException(
        Throwable $e,
        ?User $user,
        ?string $method,
        ?string $path,
        ?int $statusCode,
        ?string $ip = null,
        ?string $requestId = null,
        ?int $durationMs = null,
        ?string $responseBody = null,
    ): void {
        $this->insertRow([
            'uuid' => (string) Str::uuid(),
            'user_id' => $user?->id,
            'method' => $method ? strtoupper(substr($method, 0, 16)) : null,
            'path' => $path ? substr($path, 0, 512) : null,
            'status_code' => $statusCode,
            'exception_class' => substr(class_basename($e), 0, 255),
            'message' => Str::limit($this->buildExceptionMessage($e, $durationMs), 2000),
            'trace' => Str::limit($this->buildExceptionTrace($e, $responseBody), 8000),
            'request_id' => $requestId,
            'ip_address' => $ip,
            'occurred_at' => now(),
        ]);
    }

    public function recordHttpResponse(
        ?User $user,
        ?string $method,
        ?string $path,
        int $statusCode,
        ?string $ip = null,
        ?string $requestId = null,
        ?int $durationMs = null,
        ?string $responseBody = null,
        ?Throwable $exception = null,
    ): void {
        if ($exception instanceof Throwable && $statusCode >= 400) {
            $this->recordException(
                $exception,
                $user,
                $method,
                $path,
                $statusCode,
                $ip,
                $requestId,
                $durationMs,
                $responseBody,
            );

            return;
        }

        $suffix = $durationMs !== null ? " ({$durationMs}ms)" : '';
        $summary = $this->summarizeResponseBody($responseBody);
        $label = $statusCode >= 400
            ? "HTTP {$statusCode} error"
            : "HTTP {$statusCode} OK";
        $message = $summary !== null
            ? "{$label}{$suffix}: {$summary}"
            : "{$label}{$suffix}";

        $this->insertRow([
            'uuid' => (string) Str::uuid(),
            'user_id' => $user?->id,
            'method' => $method ? strtoupper(substr($method, 0, 16)) : null,
            'path' => $path ? substr($path, 0, 512) : null,
            'status_code' => $statusCode,
            'exception_class' => $statusCode >= 400 ? 'HttpResponse' : 'HttpSuccess',
            'message' => Str::limit($message, 2000),
            'trace' => $responseBody ? Str::limit($responseBody, 8000) : null,
            'request_id' => $requestId,
            'ip_address' => $ip,
            'occurred_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $extra
     */
    public function recordMonolog(string $level, string $message, array $context = [], array $extra = []): void
    {
        $exception = $context['exception'] ?? null;
        if ($exception instanceof Throwable) {
            $this->recordException(
                $exception,
                null,
                null,
                'log/'.strtolower($level),
                self::resolveStatus($exception),
                null,
                (string) Str::uuid(),
                null,
                json_encode([
                    'monolog_level' => $level,
                    'monolog_message' => $message,
                    'context' => $this->safeContext($context),
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: null,
            );

            return;
        }

        $this->insertRow([
            'uuid' => (string) Str::uuid(),
            'user_id' => null,
            'method' => 'LOG',
            'path' => 'log/'.strtolower($level),
            'status_code' => 500,
            'exception_class' => 'Monolog'.Str::studly(strtolower($level)),
            'message' => Str::limit("[{$level}] {$message}", 2000),
            'trace' => Str::limit(json_encode([
                'context' => $this->safeContext($context),
                'extra' => $this->safeContext($extra),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '', 8000),
            'request_id' => (string) Str::uuid(),
            'ip_address' => null,
            'occurred_at' => now(),
        ]);
    }

    public function recordEvent(
        string $event,
        string $message,
        int $statusCode = 200,
        ?User $user = null,
        ?string $path = null,
        ?string $method = 'EVENT',
        ?string $detail = null,
        ?string $ip = null,
    ): void {
        $this->insertRow([
            'uuid' => (string) Str::uuid(),
            'user_id' => $user?->id,
            'method' => $method ? strtoupper(substr($method, 0, 16)) : 'EVENT',
            'path' => substr($path ?: 'event/'.$event, 0, 512),
            'status_code' => $statusCode,
            'exception_class' => substr($event, 0, 255),
            'message' => Str::limit($message, 2000),
            'trace' => $detail ? Str::limit($detail, 8000) : null,
            'request_id' => (string) Str::uuid(),
            'ip_address' => $ip,
            'occurred_at' => now(),
        ]);
    }

    /** @return array{ok: bool, uuid: ?string, total: int, table_exists: bool, write_error: ?string} */
    public function probe(?User $user = null): array
    {
        $tableExists = false;
        $writeError = null;
        $uuid = null;

        try {
            $tableExists = Schema::hasTable('system_error_logs');
        } catch (Throwable $e) {
            $writeError = 'schema: '.$e->getMessage();
        }

        if ($tableExists) {
            try {
                $uuid = (string) Str::uuid();
                SystemErrorLog::query()->create([
                    'uuid' => $uuid,
                    'user_id' => $user?->id,
                    'method' => 'PROBE',
                    'path' => '/admin/system-logs/probe',
                    'status_code' => 200,
                    'exception_class' => 'AdminLogProbe',
                    'message' => 'Admin log probe succeeded — logging pipeline is writable.',
                    'trace' => 'probe_at='.now()->toIso8601String(),
                    'request_id' => (string) Str::uuid(),
                    'ip_address' => request()?->ip(),
                    'occurred_at' => now(),
                ]);
            } catch (Throwable $e) {
                $writeError = $e->getMessage();
                $uuid = null;
                $this->fallbackFileWrite('probe_failed: '.$e->getMessage());
            }
        } else {
            $writeError = $writeError ?: 'system_error_logs table is missing — run php artisan migrate';
            $this->fallbackFileWrite($writeError);
        }

        $total = 0;
        try {
            if ($tableExists) {
                $total = (int) SystemErrorLog::query()->count();
            }
        } catch (Throwable) {
            // ignore
        }

        return [
            'ok' => $uuid !== null,
            'uuid' => $uuid,
            'total' => $total,
            'table_exists' => $tableExists,
            'write_error' => $writeError,
        ];
    }

    private function buildExceptionMessage(Throwable $e, ?int $durationMs): string
    {
        $suffix = $durationMs !== null ? " ({$durationMs}ms)" : '';
        $diag = AdminDiagnostic::for($e);

        if ($diag !== null) {
            return "[{$diag['error_code']}] {$diag['message']}{$suffix}";
        }

        if ($e instanceof ValidationException) {
            $flat = collect($e->errors())
                ->flatMap(fn ($messages, $field) => collect($messages)->map(
                    fn ($message) => "{$field}: {$message}",
                ))
                ->implode('; ');

            if ($flat !== '') {
                return "Validation failed{$suffix}: {$flat}";
            }
        }

        $base = $e->getMessage() ?: class_basename($e);
        $code = $e->getCode();
        if (is_int($code) && $code !== 0) {
            return "{$base} [code={$code}]{$suffix}";
        }

        return "{$base}{$suffix}";
    }

    private function buildExceptionTrace(Throwable $e, ?string $responseBody = null): string
    {
        $sections = [];
        $diag = AdminDiagnostic::for($e);

        if ($diag !== null) {
            $sections[] = 'admin_diagnostic: '.json_encode($diag, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $sections[] = 'exception: '.get_class($e);
        if ($e->getCode() !== 0 && $e->getCode() !== '0') {
            $sections[] = 'exception_code: '.$e->getCode();
        }

        if ($e instanceof ValidationException) {
            $sections[] = 'validation_errors: '.json_encode(
                $e->errors(),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
            $sections[] = 'client_message: '.($e->getMessage() ?: 'The given data was invalid.');
        } else {
            $sections[] = 'exception_message: '.($e->getMessage() ?: class_basename($e));
        }

        if ($responseBody) {
            $sections[] = 'response: '.$responseBody;
        }

        if ($previous = $e->getPrevious()) {
            $sections[] = 'previous: '.get_class($previous).': '.$previous->getMessage();
        }

        $sections[] = $e->getTraceAsString();

        return implode("\n\n", $sections);
    }

    private function summarizeResponseBody(?string $responseBody): ?string
    {
        if ($responseBody === null || $responseBody === '') {
            return null;
        }

        $decoded = json_decode($responseBody, true);
        if (! is_array($decoded)) {
            return Str::limit(trim($responseBody), 240);
        }

        if (isset($decoded['message']) && is_string($decoded['message']) && $decoded['message'] !== '') {
            return Str::limit($decoded['message'], 240);
        }

        if (isset($decoded['errors']) && is_array($decoded['errors'])) {
            $flat = collect($decoded['errors'])
                ->flatMap(fn ($messages, $field) => collect((array) $messages)->map(
                    fn ($message) => "{$field}: {$message}",
                ))
                ->implode('; ');

            if ($flat !== '') {
                return Str::limit($flat, 240);
            }
        }

        return Str::limit($responseBody, 240);
    }

    /**
     * Client-reported failures (e.g. nginx 413) that never reach Laravel's exception handler.
     *
     * @param  array{status_code?: int, method?: string, path?: string, message?: string, exception_class?: string, detail?: string}  $payload
     */
    public function recordClientReport(User $user, array $payload, ?string $ip = null): void
    {
        $this->insertRow([
            'uuid' => (string) Str::uuid(),
            'user_id' => $user->id,
            'method' => isset($payload['method'])
                ? strtoupper(substr((string) $payload['method'], 0, 16))
                : null,
            'path' => isset($payload['path'])
                ? substr((string) $payload['path'], 0, 512)
                : null,
            'status_code' => isset($payload['status_code'])
                ? (int) $payload['status_code']
                : null,
            'exception_class' => substr(
                (string) ($payload['exception_class'] ?? 'ClientReportedError'),
                0,
                255,
            ),
            'message' => Str::limit((string) ($payload['message'] ?? 'Client reported error'), 2000),
            'trace' => isset($payload['detail'])
                ? Str::limit((string) $payload['detail'], 8000)
                : null,
            'request_id' => (string) Str::uuid(),
            'ip_address' => $ip,
            'occurred_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    public function list(
        ?string $path = null,
        ?int $statusCode = null,
        ?string $userUuid = null,
        ?string $from = null,
        ?string $to = null,
        ?string $search = null,
        int $page = 1,
        int $perPage = 20,
        ?string $severity = null,
    ): array {
        $query = SystemErrorLog::query()
            ->with(['user:id,uuid,display_name,phone'])
            ->orderByDesc('occurred_at');

        if ($path) {
            $query->where('path', 'like', '%'.$path.'%');
        }

        if ($statusCode) {
            $query->where('status_code', $statusCode);
        }

        if ($severity === 'errors') {
            $query->where(function ($q) {
                $q->where('status_code', '>=', 400)
                    ->orWhereNull('status_code');
            });
        } elseif ($severity === 'success') {
            $query->whereBetween('status_code', [200, 399]);
        }

        if ($userUuid) {
            $query->whereHas('user', fn ($q) => $q->where('uuid', $userUuid));
        }

        if ($from) {
            $query->where('occurred_at', '>=', $from);
        }

        if ($to) {
            // Inclusive end-of-day when only a date is provided.
            $toValue = strlen($to) <= 10 ? $to.' 23:59:59' : $to;
            $query->where('occurred_at', '<=', $toValue);
        }

        if ($search) {
            $term = trim($search);
            if ($term !== '') {
                $query->where(function ($q) use ($term) {
                    $like = '%'.$term.'%';
                    $q->where('message', 'like', $like)
                        ->orWhere('exception_class', 'like', $like)
                        ->orWhere('path', 'like', $like)
                        ->orWhere('method', 'like', $like)
                        ->orWhere('request_id', 'like', $like)
                        ->orWhere('ip_address', 'like', $like);

                    if (ctype_digit($term)) {
                        $q->orWhere('status_code', (int) $term);
                    }
                });
            }
        }

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        return [
            'data' => collect($paginator->items())->map(fn (SystemErrorLog $log) => $this->formatList($log))->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ];
    }

    /** @return list<int> */
    public function distinctStatusCodes(): array
    {
        return SystemErrorLog::query()
            ->whereNotNull('status_code')
            ->distinct()
            ->orderBy('status_code')
            ->pluck('status_code')
            ->map(fn ($code) => (int) $code)
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    public function show(string $uuid): array
    {
        $log = SystemErrorLog::query()
            ->with(['user:id,uuid,display_name,phone'])
            ->where('uuid', $uuid)
            ->firstOrFail();

        return $this->formatDetail($log);
    }

    /** @return array<string, mixed> */
    private function formatList(SystemErrorLog $log): array
    {
        return [
            'uuid' => $log->uuid,
            'occurred_at' => $log->occurred_at?->toIso8601String(),
            'method' => $log->method,
            'path' => $log->path,
            'status_code' => $log->status_code,
            'exception_class' => $log->exception_class,
            'message' => Str::limit($log->message, 200),
            'user' => $log->user ? [
                'uuid' => $log->user->uuid,
                'display_name' => $log->user->display_name,
                'phone' => $log->user->phone,
            ] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function insertRow(array $attributes): void
    {
        try {
            if (! Schema::hasTable('system_error_logs')) {
                $this->fallbackFileWrite('system_error_logs missing: '.json_encode([
                    'path' => $attributes['path'] ?? null,
                    'status' => $attributes['status_code'] ?? null,
                    'message' => $attributes['message'] ?? null,
                ]));

                return;
            }

            SystemErrorLog::query()->create($attributes);
        } catch (Throwable $e) {
            $this->fallbackFileWrite(
                'insert_failed: '.$e->getMessage().' | '.json_encode([
                    'path' => $attributes['path'] ?? null,
                    'status' => $attributes['status_code'] ?? null,
                    'message' => $attributes['message'] ?? null,
                ], JSON_UNESCAPED_SLASHES)
            );
        }
    }

    private function fallbackFileWrite(string $line): void
    {
        try {
            $path = storage_path('logs/admin-system-log-fallback.log');
            @file_put_contents(
                $path,
                '['.date('c').'] '.$line.PHP_EOL,
                FILE_APPEND | LOCK_EX,
            );
        } catch (Throwable) {
            @error_log('[system_error_logs] '.$line);
        }
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function safeContext(array $context): array
    {
        $safe = [];
        foreach ($context as $key => $value) {
            if ($value instanceof Throwable) {
                $safe[$key] = get_class($value).': '.$value->getMessage();
                continue;
            }

            if (is_scalar($value) || $value === null) {
                $safe[$key] = $value;
                continue;
            }

            if (is_array($value)) {
                $safe[$key] = $this->safeContext($value);
                continue;
            }

            $safe[$key] = is_object($value) ? get_class($value) : gettype($value);
        }

        return $safe;
    }

    /** @return array<string, mixed> */
    private function formatDetail(SystemErrorLog $log): array
    {
        return [
            ...$this->formatList($log),
            'message' => $log->message,
            'trace' => $log->trace,
            'request_id' => $log->request_id,
            'ip_address' => $log->ip_address,
        ];
    }
}
