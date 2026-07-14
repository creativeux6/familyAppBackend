<?php

namespace App\Modules\Admin\Services;

use App\Models\SystemErrorLog;
use App\Models\User;
use Illuminate\Support\Str;
use Throwable;

class SystemErrorLogService
{
    public function record(
        Throwable $e,
        ?User $user,
        ?string $method,
        ?string $path,
        ?int $statusCode,
        ?string $ip = null,
        ?string $requestId = null,
    ): void {
        try {
            SystemErrorLog::query()->create([
                'uuid' => (string) Str::uuid(),
                'user_id' => $user?->id,
                'method' => $method ? strtoupper(substr($method, 0, 16)) : null,
                'path' => $path ? substr($path, 0, 512) : null,
                'status_code' => $statusCode,
                'exception_class' => class_basename($e),
                'message' => Str::limit($e->getMessage() ?: get_class($e), 2000),
                'trace' => Str::limit($e->getTraceAsString(), 8000),
                'request_id' => $requestId,
                'ip_address' => $ip,
                'occurred_at' => now(),
            ]);
        } catch (Throwable) {
            // Never break the original error response.
        }
    }

    /** @return array<string, mixed> */
    public function list(
        ?string $path = null,
        ?int $statusCode = null,
        ?string $userUuid = null,
        ?string $from = null,
        ?string $to = null,
        int $page = 1,
        int $perPage = 20,
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

        if ($userUuid) {
            $query->whereHas('user', fn ($q) => $q->where('uuid', $userUuid));
        }

        if ($from) {
            $query->where('occurred_at', '>=', $from);
        }

        if ($to) {
            $query->where('occurred_at', '<=', $to);
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
