<?php

namespace App\Modules\Admin\Services;

use App\Models\AuditLog;
use App\Models\User;

class AuditLogService
{
    public function log(
        ?User $actor,
        string $action,
        ?string $subjectType = null,
        ?string $subjectId = null,
        array $metadata = [],
        ?string $ipAddress = null,
    ): AuditLog {
        return AuditLog::create([
            'actor_user_id' => $actor?->id,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'metadata' => $metadata ?: null,
            'ip_address' => $ipAddress,
        ]);
    }

    /** @return array<string, mixed> */
    public function list(?string $action, ?string $actorUserUuid, int $page, int $perPage): array
    {
        $query = AuditLog::query()
            ->with('actor:id,uuid,display_name')
            ->latest();

        if ($action) {
            $query->where('action', $action);
        }

        if ($actorUserUuid) {
            $query->whereHas('actor', fn ($q) => $q->where('uuid', $actorUserUuid));
        }

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        return [
            'audit_logs' => collect($paginator->items())->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'subject_type' => $log->subject_type,
                'subject_id' => $log->subject_id,
                'metadata' => $log->metadata,
                'ip_address' => $log->ip_address,
                'actor' => $log->actor ? [
                    'uuid' => $log->actor->uuid,
                    'display_name' => $log->actor->display_name,
                ] : null,
                'created_at' => $log->created_at?->toIso8601String(),
            ])->values()->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }
}
