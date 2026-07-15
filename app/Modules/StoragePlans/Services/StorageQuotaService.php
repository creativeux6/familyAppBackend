<?php

namespace App\Modules\StoragePlans\Services;

use App\Models\User;
use Illuminate\Validation\ValidationException;

class StorageQuotaService
{
    public const FREE_QUOTA_BYTES = 5 * 1024 * 1024 * 1024;

    public function __construct(
        private readonly PlanAssignmentService $assignmentService,
    ) {}

    public function quotaBytes(User $user): int
    {
        $this->assignmentService->ensureDefaultFreePlan($user);
        $assignment = $this->assignmentService->activeAssignment($user);

        $bytes = (int) ($assignment?->plan?->quota_bytes ?? self::FREE_QUOTA_BYTES);

        // Plans must be finite; never treat missing/zero as unlimited.
        return max($bytes, 1);
    }

    public function isUnlimited(User $user): bool
    {
        return false;
    }

    /**
     * Stored (uploaded) bytes currently held against the plan.
     * Decremented when the user deletes / transfers away ownership of stored data.
     */
    public function storedBytes(User $user): int
    {
        return (int) $user->storage_used_bytes;
    }

    /**
     * Cumulative S3/API egress (reads): full file, thumbnail, and stream chunk transfers.
     * Never reclaimed — egress already happened and was billed.
     */
    public function readBytes(User $user): int
    {
        return (int) $user->storage_read_bytes;
    }

    /**
     * Combined plan usage: stored uploads + all reads. This is what quota enforces.
     */
    public function usedBytes(User $user): int
    {
        return $this->storedBytes($user) + $this->readBytes($user);
    }

    public function isOverQuota(User $user): bool
    {
        return $this->usedBytes($user) >= $this->quotaBytes($user);
    }

    public function remainingBytes(User $user): int
    {
        return max(0, $this->quotaBytes($user) - $this->usedBytes($user));
    }

    /** @return array<string, mixed> */
    public function summary(User $user): array
    {
        $this->assignmentService->ensureDefaultFreePlan($user);
        $assignment = $this->assignmentService->activeAssignment($user);
        $overQuota = $this->isOverQuota($user);

        return [
            'quota_bytes' => $this->quotaBytes($user),
            'stored_bytes' => $this->storedBytes($user),
            'read_bytes' => $this->readBytes($user),
            'used_bytes' => $this->usedBytes($user),
            'remaining_bytes' => $this->remainingBytes($user),
            'unlimited' => false,
            'over_quota' => $overQuota,
            'using_default_quota' => $assignment !== null && $assignment->source === 'system_default',
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

        if ($this->usedBytes($user) + $sizeBytes > $this->quotaBytes($user)) {
            throw ValidationException::withMessages([
                'size_bytes' => [
                    'Storage limit reached. Please subscribe to a paid plan.',
                ],
            ]);
        }
    }

    /**
     * Enforce remaining quota before charging a read/transfer.
     * Call for gallery (and any enforced) reads; chat may record without enforcing.
     */
    public function assertCanTransfer(User $user, int $sizeBytes): void
    {
        if ($sizeBytes <= 0) {
            return;
        }

        if ($this->usedBytes($user) + $sizeBytes > $this->quotaBytes($user)) {
            throw ValidationException::withMessages([
                'storage' => [
                    'Storage limit reached. Please subscribe to a paid plan.',
                ],
            ]);
        }
    }

    public function assertCanAccessLibrary(User $user): void
    {
        if ($this->isOverQuota($user)) {
            throw ValidationException::withMessages([
                'storage' => [
                    'Storage limit reached. Please subscribe to a paid plan.',
                ],
            ]);
        }
    }

    /** Record uploaded/stored bytes (reclaimable on delete). */
    public function addUsage(User $user, int $sizeBytes): void
    {
        $this->addStoredUsage($user, $sizeBytes);
    }

    public function addStoredUsage(User $user, int $sizeBytes): void
    {
        if ($sizeBytes <= 0) {
            return;
        }

        $user->increment('storage_used_bytes', $sizeBytes);
    }

    public function removeUsage(User $user, int $sizeBytes): void
    {
        $this->removeStoredUsage($user, $sizeBytes);
    }

    public function removeStoredUsage(User $user, int $sizeBytes): void
    {
        if ($sizeBytes <= 0) {
            return;
        }

        $user->update([
            'storage_used_bytes' => max(0, $this->storedBytes($user) - $sizeBytes),
        ]);
    }

    /**
     * Record S3/API read (egress) bytes against the user's plan.
     * Always increments read counter. Optionally enforces remaining quota first.
     */
    public function addReadUsage(User $user, int $sizeBytes, bool $enforceQuota = true): void
    {
        if ($sizeBytes <= 0) {
            return;
        }

        if ($enforceQuota) {
            $this->assertCanTransfer($user, $sizeBytes);
        }

        $user->increment('storage_read_bytes', $sizeBytes);
    }

    /**
     * Charge a media transfer to the viewer. Chat media still counts toward
     * quota usage, but does not block the transfer when over limit.
     */
    public function chargeReadTransfer(User $user, int $sizeBytes, bool $isChatMedia = false): void
    {
        $this->addReadUsage($user, $sizeBytes, enforceQuota: ! $isChatMedia);
    }
}
