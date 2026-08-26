<?php

namespace App\Modules\StoragePlans\Services;

use App\Models\MediaFile;
use App\Models\StorageUsageLog;
use App\Models\User;
use App\Models\UserPlanAssignment;
use App\Models\UserStorageUsage;
use App\Modules\Devices\Services\PushNotificationService;
use App\Modules\StoragePlans\Support\B2CostEstimator;
use App\Modules\StoragePlans\Support\StoragePoolContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StorageQuotaService
{
    public const FREE_QUOTA_BYTES = 5 * 1024 * 1024 * 1024;

    public const ACTION_UPLOAD = 'upload';

    public const ACTION_STREAM = 'stream';

    public const ACTION_DOWNLOAD = 'download';

    public const ACTION_PREVIEW = 'preview';

    public const ACTION_FILE_VIEW = 'file_view';

    public const ACTION_DELETE = 'delete';

    public function __construct(
        private readonly PlanAssignmentService $assignmentService,
        private readonly StoragePoolService $poolService,
        private readonly B2CostEstimator $costEstimator,
    ) {}

    public function poolContext(User $user): StoragePoolContext
    {
        $this->assignmentService->ensureDefaultFreePlan($user);

        return $this->poolService->context($user->fresh());
    }

    public function quotaBytes(User $user): int
    {
        return max(1, (int) $this->poolContext($user)->usage->storage_limit_bytes);
    }

    public function accessQuotaBytes(User $user): int
    {
        return max(1, (int) $this->poolContext($user)->usage->monthly_access_limit_bytes);
    }

    public function isUnlimited(User $user): bool
    {
        return false;
    }

    public function storedBytes(User $user): int
    {
        return (int) $this->poolContext($user)->usage->storage_used_bytes;
    }

    public function ownedBytes(User $user): int
    {
        return (int) $user->storage_used_bytes;
    }

    public function usedBytes(User $user): int
    {
        return $this->storedBytes($user);
    }

    public function isOverQuota(User $user): bool
    {
        return $this->storedBytes($user) >= $this->quotaBytes($user);
    }

    public function readBytes(User $user): int
    {
        return (int) $user->storage_read_bytes;
    }

    public function accessUsedBytes(User $user): int
    {
        $usage = $this->poolContext($user)->usage;
        $usage->syncMonthlyAccess();

        return (int) $usage->monthly_access_bytes;
    }

    public function accessRemainingBytes(User $user): int
    {
        return max(0, $this->accessQuotaBytes($user) - $this->accessUsedBytes($user));
    }

    public function isAccessSoftGated(User $user, ?string $action = null): bool
    {
        $softRemaining = max(0, (int) config('media.access_soft_remaining_bytes', 512 * 1024 * 1024));
        if ($this->accessRemainingBytes($user) <= $softRemaining) {
            return true;
        }

        $usage = $this->poolContext($user)->usage;
        $meterRemaining = match ($action) {
            self::ACTION_STREAM => (int) $usage->streaming_limit_bytes - (int) $usage->streamed_bytes,
            self::ACTION_DOWNLOAD => (int) $usage->download_limit_bytes - (int) $usage->downloaded_bytes,
            self::ACTION_FILE_VIEW, self::ACTION_PREVIEW => (int) $usage->file_view_limit_bytes - (int) $usage->file_viewed_bytes,
            default => null,
        };

        return $meterRemaining !== null && $meterRemaining <= $softRemaining;
    }

    public function largeFileBytes(): int
    {
        return max(1, (int) config('media.large_file_bytes', 100 * 1024 * 1024));
    }

    public function upgradeMessage(): string
    {
        return (string) config(
            'media.access_upgrade_message',
            'Too many requests. Please upgrade your subscription.',
        );
    }

    public function paymentLockMessage(): string
    {
        return (string) config(
            'media.payment_lock_message',
            'Media is locked because payment failed. Retry payment to continue.',
        );
    }

    public function isMediaLocked(User $user): bool
    {
        return $this->poolContext($user)->assignment->isMediaLocked();
    }

    public function assertNotMediaLocked(User $user): void
    {
        if ($this->isMediaLocked($user)) {
            throw ValidationException::withMessages([
                'storage' => [$this->paymentLockMessage()],
            ]);
        }
    }

    /** @return array<string, mixed> */
    public function summary(User $user): array
    {
        $context = $this->poolContext($user);
        $assignment = $context->assignment;
        $usage = $context->usage;
        $quota = max(1, (int) $usage->storage_limit_bytes);
        $stored = (int) $usage->storage_used_bytes;
        $plan = $assignment->plan;

        return [
            'quota_bytes' => $quota,
            'stored_bytes' => $stored,
            'used_bytes' => $stored,
            'remaining_bytes' => max(0, $quota - $stored),
            'unlimited' => false,
            'over_quota' => $stored >= $quota,
            'using_default_quota' => $assignment->source === 'system_default',
            'plan' => $plan ? StoragePlanService::formatPlan($plan) : null,
            'assignment' => [
                'id' => $assignment->id,
                'starts_at' => $assignment->starts_at?->toIso8601String(),
                'ends_at' => $assignment->ends_at?->toIso8601String(),
                'source' => $assignment->source,
                'billing_status' => $assignment->billing_status,
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function adminSummary(User $user): array
    {
        $context = $this->poolContext($user);
        $assignment = $context->assignment->loadMissing(['plan', 'pendingPlan', 'user']);
        $usage = $context->usage;
        $usage->syncMonthlyAccess();
        $plan = $assignment->plan;
        $costs = $this->costEstimator->estimate($usage);
        $revenueUsd = ((int) ($plan?->display_price_cents ?? 0)) / 100;
        $roster = $this->poolService->roster($assignment, $usage);
        $memberCount = $this->poolService->memberSeatCount($assignment, $usage);

        $base = $this->summary($user);

        return array_merge($base, [
            'access_quota_bytes' => (int) $usage->monthly_access_limit_bytes,
            'access_used_bytes' => (int) $usage->monthly_access_bytes,
            'access_remaining_bytes' => max(0, (int) $usage->monthly_access_limit_bytes - (int) $usage->monthly_access_bytes),
            'access_period_ends_at' => $usage->period_end?->toIso8601String(),
            'access_soft_gated' => $this->isAccessSoftGated($user),
            'lifetime_read_bytes' => $this->readBytes($user),
            'large_file_bytes' => $this->largeFileBytes(),
            'owned_bytes' => $this->ownedBytes($user),
            'pool_owner_uuid' => $assignment->user?->uuid,
            'pool_owner_name' => $assignment->user?->display_name,
            'is_pool_owner' => $context->isOwner,
            'billing_status' => $assignment->billing_status,
            'pending_plan' => $assignment->pendingPlan
                ? StoragePlanService::formatPlan($assignment->pendingPlan)
                : null,
            'streamed_bytes' => (int) $usage->streamed_bytes,
            'downloaded_bytes' => (int) $usage->downloaded_bytes,
            'file_viewed_bytes' => (int) $usage->file_viewed_bytes,
            'streaming_limit_bytes' => (int) $usage->streaming_limit_bytes,
            'download_limit_bytes' => (int) $usage->download_limit_bytes,
            'file_view_limit_bytes' => (int) $usage->file_view_limit_bytes,
            'upload_requests' => (int) $usage->upload_requests,
            'download_requests' => (int) $usage->download_requests,
            'stream_requests' => (int) $usage->stream_requests,
            'file_view_requests' => (int) $usage->file_view_requests,
            'estimated_storage_cost_usd' => $costs['storage_usd'],
            'estimated_egress_cost_usd' => $costs['egress_usd'],
            'estimated_cost_usd' => $costs['estimated_usd'],
            'revenue_usd' => round($revenueUsd, 4),
            'net_contribution_usd' => round($revenueUsd - $costs['estimated_usd'], 4),
            'membership' => [
                'is_shared' => (bool) $usage->is_shared,
                'max_shared_members' => (int) $usage->max_shared_members,
                'member_count' => $memberCount,
                'empty_seats' => $this->poolService->emptySeats($usage, $memberCount),
                'members' => $roster->map(fn ($row) => [
                    'user_uuid' => $row->user?->uuid,
                    'display_name' => $row->user?->display_name,
                    'role' => $row->role,
                    'locked' => true,
                ])->values()->all(),
            ],
            'past_periods' => UserStorageUsage::query()
                ->where('assignment_id', $assignment->id)
                ->whereNotNull('closed_at')
                ->orderByDesc('period_start')
                ->limit(12)
                ->get()
                ->map(fn (UserStorageUsage $row) => [
                    'period_start' => $row->period_start?->toIso8601String(),
                    'period_end' => $row->period_end?->toIso8601String(),
                    'storage_used_bytes' => (int) $row->storage_used_bytes,
                    'monthly_access_bytes' => (int) $row->monthly_access_bytes,
                    'streamed_bytes' => (int) $row->streamed_bytes,
                    'downloaded_bytes' => (int) $row->downloaded_bytes,
                    'file_viewed_bytes' => (int) $row->file_viewed_bytes,
                ])
                ->values()
                ->all(),
        ]);
    }

    public function assertCanStore(User $user, int $sizeBytes): void
    {
        $this->assertNotMediaLocked($user);

        if ($sizeBytes <= 0) {
            throw ValidationException::withMessages([
                'size_bytes' => ['File size must be greater than zero.'],
            ]);
        }

        if ($this->storedBytes($user) + $sizeBytes > $this->quotaBytes($user)) {
            throw ValidationException::withMessages([
                'size_bytes' => [
                    'Storage limit reached. Please subscribe to a paid plan.',
                ],
            ]);
        }
    }

    public function assertCanAccessLibrary(User $user): void
    {
        if ($this->storedBytes($user) >= $this->quotaBytes($user)) {
            throw ValidationException::withMessages([
                'storage' => [
                    'Storage limit reached. Please subscribe to a paid plan.',
                ],
            ]);
        }
    }

    public function assertCanOpenMedia(User $user, MediaFile $media): void
    {
        $this->assertNotMediaLocked($user);

        if (! $this->isAccessSoftGated($user)) {
            return;
        }

        if ((int) $media->size_bytes <= $this->largeFileBytes()) {
            return;
        }

        throw ValidationException::withMessages([
            'storage' => [$this->upgradeMessage()],
        ]);
    }

    public function assertCanShare(User $user): void
    {
        $this->assertNotMediaLocked($user);
    }

    public function addUsage(User $user, int $sizeBytes): void
    {
        $this->addStoredUsage($user, $sizeBytes);
    }

    public function addStoredUsage(User $user, int $sizeBytes): void
    {
        if ($sizeBytes <= 0) {
            return;
        }

        $this->mutatePool($user, function (UserStorageUsage $usage, StoragePoolContext $context) use ($user, $sizeBytes) {
            $usage->storage_used_bytes = (int) $usage->storage_used_bytes + $sizeBytes;
            $usage->upload_requests = (int) $usage->upload_requests + 1;
            $this->writeLog($context, $user, self::ACTION_UPLOAD, $sizeBytes, 'upload', null);
        });

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

        $this->mutatePool($user, function (UserStorageUsage $usage, StoragePoolContext $context) use ($user, $sizeBytes) {
            $usage->storage_used_bytes = max(0, (int) $usage->storage_used_bytes - $sizeBytes);
            $this->writeLog($context, $user, self::ACTION_DELETE, -$sizeBytes, 'delete', null);
        });

        $user->update([
            'storage_used_bytes' => max(0, $this->ownedBytes($user->fresh()) - $sizeBytes),
        ]);
    }

    /**
     * Charge a metered read. $action: stream|download|file_view|preview.
     * One hit per (user, media, action) window when $mediaUuid is provided.
     */
    public function chargeReadTransfer(
        User $user,
        int $sizeBytes,
        bool $isChatMedia = false,
        string $action = self::ACTION_DOWNLOAD,
        ?string $mediaUuid = null,
    ): void {
        unset($isChatMedia);

        if ($sizeBytes <= 0) {
            return;
        }

        $action = $this->normalizeAction($action);
        if ($mediaUuid && ! $this->meterWindowAllows($user, $mediaUuid, $action)) {
            return;
        }

        $this->mutatePool($user, function (UserStorageUsage $usage, StoragePoolContext $context) use ($user, $sizeBytes, $action, $mediaUuid) {
            if ($action === self::ACTION_STREAM) {
                $usage->streamed_bytes = (int) $usage->streamed_bytes + $sizeBytes;
                $usage->stream_requests = (int) $usage->stream_requests + 1;
                $op = 'download';
            } elseif (in_array($action, [self::ACTION_FILE_VIEW, self::ACTION_PREVIEW], true)) {
                $usage->file_viewed_bytes = (int) $usage->file_viewed_bytes + $sizeBytes;
                $usage->file_view_requests = (int) $usage->file_view_requests + 1;
                $op = 'download';
            } else {
                $usage->downloaded_bytes = (int) $usage->downloaded_bytes + $sizeBytes;
                $usage->download_requests = (int) $usage->download_requests + 1;
                $op = 'download';
            }
            $usage->syncMonthlyAccess();
            $this->writeLog($context, $user, $action, $sizeBytes, $op, $mediaUuid);
        });

        $user->increment('storage_read_bytes', $sizeBytes);
        $user->refresh();
        $this->maybeNotifyAccessThresholds($user);
    }

    public function resetAccessUsage(User $user): void
    {
        $context = $this->poolContext($user);
        $usage = $context->usage;
        $usage->streamed_bytes = 0;
        $usage->downloaded_bytes = 0;
        $usage->file_viewed_bytes = 0;
        $usage->monthly_access_bytes = 0;
        $usage->download_requests = 0;
        $usage->stream_requests = 0;
        $usage->file_view_requests = 0;
        $usage->save();

        $user->update([
            'storage_read_period_bytes' => 0,
            'storage_access_warn_level' => 0,
        ]);
    }

    public function ensureAccessPeriod(User $user): void
    {
        $this->poolContext($user);
    }

    public function rollAccessPeriodsDue(): int
    {
        return 0;
    }

    public function providerName(): string
    {
        $disk = (string) config('media.disk', 'local');

        return $disk === 'b2' ? 'backblaze_b2' : $disk;
    }

    private function meterWindowAllows(User $user, string $mediaUuid, string $action): bool
    {
        $window = max(60, (int) config('media.read_meter_window_seconds', 3600));
        $cacheKey = "media:metered:{$user->id}:{$mediaUuid}:{$action}";

        return Cache::add($cacheKey, true, $window);
    }

    private function normalizeAction(string $action): string
    {
        $action = strtolower($action);
        if ($action === self::ACTION_PREVIEW) {
            return self::ACTION_FILE_VIEW;
        }

        if (! in_array($action, [self::ACTION_STREAM, self::ACTION_DOWNLOAD, self::ACTION_FILE_VIEW], true)) {
            return self::ACTION_DOWNLOAD;
        }

        return $action;
    }

    private function mutatePool(User $user, callable $callback): void
    {
        DB::transaction(function () use ($user, $callback) {
            $context = $this->poolContext($user);
            $usage = UserStorageUsage::query()->where('id', $context->usage->id)->lockForUpdate()->firstOrFail();
            $fresh = new StoragePoolContext($user, $context->assignment, $usage, $context->isOwner);
            $callback($usage, $fresh);
            $usage->save();
        });
    }

    private function writeLog(
        StoragePoolContext $context,
        User $actor,
        string $action,
        int $bytes,
        string $operationType,
        ?string $mediaUuid,
    ): void {
        StorageUsageLog::query()->create([
            'user_id' => $actor->id,
            'media_file_uuid' => $mediaUuid,
            'assignment_id' => $context->assignment->id,
            'user_storage_usage_id' => $context->usage->id,
            'action' => $action,
            'bytes_used' => $bytes,
            'provider' => $this->providerName(),
            'operation_type' => $operationType,
        ]);
    }

    private function maybeNotifyAccessThresholds(User $user): void
    {
        $accessQuota = $this->accessQuotaBytes($user);
        $used = $this->accessUsedBytes($user);
        $remaining = max(0, $accessQuota - $used);
        $warnLevels = config('media.access_warn_remaining_bytes', [
            2 * 1024 * 1024 * 1024,
            1 * 1024 * 1024 * 1024,
        ]);
        rsort($warnLevels);

        $currentLevel = (int) $user->storage_access_warn_level;
        $newLevel = $currentLevel;

        foreach (array_values($warnLevels) as $index => $thresholdRemaining) {
            $level = $index + 1;
            if ($remaining <= (int) $thresholdRemaining && $currentLevel < $level) {
                $newLevel = max($newLevel, $level);
            }
        }

        if ($newLevel <= $currentLevel) {
            return;
        }

        $user->update(['storage_access_warn_level' => $newLevel]);

        try {
            app(PushNotificationService::class)->notifyAccessUsageWarning(
                $user,
                $this->upgradeMessage(),
            );
        } catch (\Throwable) {
            // Push is best-effort.
        }
    }
}
