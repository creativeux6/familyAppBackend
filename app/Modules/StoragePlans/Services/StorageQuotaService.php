<?php

namespace App\Modules\StoragePlans\Services;

use App\Models\User;
use Illuminate\Validation\ValidationException;

class StorageQuotaService
{
    public function __construct(
        private readonly PlanAssignmentService $assignmentService,
    ) {}

    public function quotaBytes(User $user): int
    {
        $assignment = $this->assignmentService->activeAssignment($user);

        if ($assignment?->plan) {
            return (int) $assignment->plan->quota_bytes;
        }

        return (int) config('media.default_quota_bytes');
    }

    public function isUnlimited(User $user): bool
    {
        return $this->quotaBytes($user) <= 0;
    }

    public function usedBytes(User $user): int
    {
        return (int) $user->storage_used_bytes;
    }

    public function remainingBytes(User $user): int
    {
        if ($this->isUnlimited($user)) {
            return PHP_INT_MAX;
        }

        return max(0, $this->quotaBytes($user) - $this->usedBytes($user));
    }

    /** @return array<string, mixed> */
    public function summary(User $user): array
    {
        $assignment = $this->assignmentService->activeAssignment($user);
        $unlimited = $this->isUnlimited($user);

        return [
            'quota_bytes' => $this->quotaBytes($user),
            'used_bytes' => $this->usedBytes($user),
            'remaining_bytes' => $unlimited ? null : $this->remainingBytes($user),
            'unlimited' => $unlimited,
            'using_default_quota' => $assignment === null,
            'plan' => $assignment?->plan ? StoragePlanService::formatPlan($assignment->plan) : null,
            'assignment' => $assignment ? [
                'id' => $assignment->id,
                'starts_at' => $assignment->starts_at?->toIso8601String(),
                'ends_at' => $assignment->ends_at?->toIso8601String(),
                'source' => $assignment->source,
            ] : null,
        ];
    }

    public function assertCanStore(User $user, int $sizeBytes): void
    {
        if ($sizeBytes <= 0) {
            throw ValidationException::withMessages([
                'size_bytes' => ['File size must be greater than zero.'],
            ]);
        }

        if ($this->isUnlimited($user)) {
            return;
        }

        if ($this->usedBytes($user) + $sizeBytes > $this->quotaBytes($user)) {
            throw ValidationException::withMessages([
                'size_bytes' => ['Storage quota exceeded.'],
            ]);
        }
    }

    public function addUsage(User $user, int $sizeBytes): void
    {
        $user->increment('storage_used_bytes', $sizeBytes);
    }

    public function removeUsage(User $user, int $sizeBytes): void
    {
        $user->update([
            'storage_used_bytes' => max(0, $this->usedBytes($user) - $sizeBytes),
        ]);
    }
}
