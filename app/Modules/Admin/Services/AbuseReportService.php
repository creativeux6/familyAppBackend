<?php

namespace App\Modules\Admin\Services;

use App\Models\AbuseReport;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class AbuseReportService
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
    ) {}

    /** @return array<string, mixed> */
    public function list(?string $status, int $page, int $perPage): array
    {
        $query = AbuseReport::query()
            ->with(['reporter:id,uuid,display_name', 'resolver:id,uuid,display_name'])
            ->latest();

        if ($status) {
            $query->where('status', $status);
        }

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        return [
            'reports' => collect($paginator->items())->map(fn (AbuseReport $report) => $this->format($report))->values()->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    public function updateStatus(User $admin, string $uuid, string $status, ?string $ip = null): array
    {
        $report = AbuseReport::query()->where('uuid', $uuid)->first();

        if (! $report) {
            throw ValidationException::withMessages([
                'uuid' => ['Abuse report not found.'],
            ]);
        }

        $report->update([
            'status' => $status,
            'resolved_by_user_id' => in_array($status, ['resolved', 'dismissed'], true) ? $admin->id : null,
        ]);

        $this->auditLogService->log(
            $admin,
            'abuse_report.updated',
            'abuse_report',
            $report->uuid,
            ['status' => $status],
            $ip,
        );

        return $this->format($report->fresh(['reporter', 'resolver']));
    }

    /** @return array<string, mixed> */
    private function format(AbuseReport $report): array
    {
        return [
            'uuid' => $report->uuid,
            'subject_type' => $report->subject_type,
            'subject_id' => $report->subject_id,
            'reason' => $report->reason,
            'status' => $report->status,
            'reporter' => $report->reporter ? [
                'uuid' => $report->reporter->uuid,
                'display_name' => $report->reporter->display_name,
            ] : null,
            'resolved_by' => $report->resolver ? [
                'uuid' => $report->resolver->uuid,
                'display_name' => $report->resolver->display_name,
            ] : null,
            'created_at' => $report->created_at?->toIso8601String(),
            'updated_at' => $report->updated_at?->toIso8601String(),
        ];
    }
}
