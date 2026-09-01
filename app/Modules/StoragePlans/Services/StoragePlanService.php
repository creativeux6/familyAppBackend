<?php

namespace App\Modules\StoragePlans\Services;

use App\Models\StoragePlan;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StoragePlanService
{
    public function __construct(
        private readonly StoragePoolService $poolService,
        private readonly PlanAssignmentService $assignmentService,
    ) {}

    /** @return array<string, mixed> */
    public function listActive(): array
    {
        $plans = StoragePlan::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return [
            'plans' => $plans->map(fn (StoragePlan $plan) => self::formatPlan($plan))->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function listAll(): array
    {
        $plans = StoragePlan::query()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return [
            'plans' => $plans->map(fn (StoragePlan $plan) => self::formatPlan($plan))->values()->all(),
        ];
    }

    /** @param  array<string, mixed>  $data */
    public function create(array $data): array
    {
        if (StoragePlan::query()->where('slug', $data['slug'])->exists()) {
            throw ValidationException::withMessages([
                'slug' => ['Plan slug already exists.'],
            ]);
        }

        $normalized = $this->normalizePlanPayload($data, $data['slug']);

        $plan = StoragePlan::create(array_merge($normalized, [
            'uuid' => (string) Str::uuid(),
            'slug' => $data['slug'],
            'name' => $data['name'],
        ]));

        return self::formatPlan($plan);
    }

    /** @param  array<string, mixed>  $data */
    public function update(string $uuid, array $data): array
    {
        $plan = $this->requirePlan($uuid);

        if (isset($data['slug']) && $data['slug'] !== $plan->slug) {
            if (StoragePlan::query()->where('slug', $data['slug'])->where('uuid', '!=', $uuid)->exists()) {
                throw ValidationException::withMessages([
                    'slug' => ['Plan slug already exists.'],
                ]);
            }
        }

        $slug = $data['slug'] ?? $plan->slug;
        $normalized = $this->normalizePlanPayload($data, $slug, $plan);
        $plan->update($normalized);

        $fresh = $plan->fresh();
        $this->poolService->refreshOpenSnapshot($fresh);

        return self::formatPlan($fresh);
    }

    public function requirePlan(string $uuid): StoragePlan
    {
        $plan = StoragePlan::query()->where('uuid', $uuid)->first();

        if (! $plan) {
            throw ValidationException::withMessages([
                'storage_plan_uuid' => ['Storage plan not found.'],
            ]);
        }

        return $plan;
    }

    public function requireUser(string $userUuid): User
    {
        $user = User::query()->where('uuid', $userUuid)->first();

        if (! $user) {
            throw ValidationException::withMessages([
                'user_uuid' => ['User not found.'],
            ]);
        }

        return $user;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizePlanPayload(array $data, string $slug, ?StoragePlan $existing = null): array
    {
        $storage = (int) ($data['storage_limit_bytes'] ?? $data['quota_bytes'] ?? $existing?->storageLimitBytes() ?? 0);
        if ($storage < 1) {
            throw ValidationException::withMessages([
                'quota_bytes' => ['Storage limit is required.'],
            ]);
        }

        $access = (int) ($data['monthly_access_limit_bytes'] ?? $existing?->monthlyAccessLimitBytes() ?: $storage * 3);
        $stream = (int) ($data['streaming_limit_bytes'] ?? $existing?->streamingLimitBytes() ?: $access);
        $download = (int) ($data['download_limit_bytes'] ?? $existing?->downloadLimitBytes() ?: $storage);
        $fileView = (int) ($data['file_view_limit_bytes'] ?? $existing?->fileViewLimitBytes() ?: $access);

        $isShared = array_key_exists('is_shared', $data)
            ? (bool) $data['is_shared']
            : (bool) ($existing?->is_shared ?? false);
        $maxMembers = (int) ($data['max_shared_members'] ?? $existing?->max_shared_members ?? 0);
        if ($isShared) {
            $maxMembers = max(1, $maxMembers);
        } else {
            $maxMembers = 0;
        }

        $period = $data['billing_period']
            ?? $existing?->billing_period
            ?? ($slug === 'free' ? PlanAssignmentService::PERIOD_YEARLY : PlanAssignmentService::PERIOD_MONTHLY);
        if ($slug === 'free') {
            $period = PlanAssignmentService::PERIOD_YEARLY;
            $isShared = false;
            $maxMembers = 0;
        }
        if ($isShared) {
            $period = PlanAssignmentService::PERIOD_MONTHLY;
        }

        $payload = [
            'quota_bytes' => $storage,
            'storage_limit_bytes' => $storage,
            'monthly_access_limit_bytes' => $access,
            'streaming_limit_bytes' => $stream,
            'download_limit_bytes' => $download,
            'file_view_limit_bytes' => $fileView,
            'is_shared' => $isShared,
            'max_shared_members' => $maxMembers,
            'max_users' => $isShared ? 1 + $maxMembers : 1,
            'warning_percentage' => (int) ($data['warning_percentage'] ?? $existing?->warning_percentage ?? 80),
            'soft_limit_percentage' => (int) ($data['soft_limit_percentage'] ?? $existing?->soft_limit_percentage ?? 90),
            'hard_limit_percentage' => (int) ($data['hard_limit_percentage'] ?? $existing?->hard_limit_percentage ?? 100),
            'billing_period' => $period,
        ];

        foreach (['name', 'description', 'slug', 'play_product_id', 'display_price_cents', 'currency', 'is_active', 'sort_order'] as $key) {
            if (array_key_exists($key, $data)) {
                $payload[$key] = $data[$key];
            }
        }

        if ($slug === 'free' && isset($payload['name'])) {
            $payload['billing_period'] = PlanAssignmentService::PERIOD_YEARLY;
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    public static function formatPlan(StoragePlan $plan): array
    {
        $period = app(PlanAssignmentService::class)->normalizePeriod(
            $plan->billing_period,
            $plan->slug,
        );

        return [
            'uuid' => $plan->uuid,
            'name' => $plan->name,
            'description' => $plan->description,
            'slug' => $plan->slug,
            'quota_bytes' => $plan->storageLimitBytes(),
            'storage_limit_bytes' => $plan->storageLimitBytes(),
            'monthly_access_limit_bytes' => $plan->monthlyAccessLimitBytes(),
            'streaming_limit_bytes' => $plan->streamingLimitBytes(),
            'download_limit_bytes' => $plan->downloadLimitBytes(),
            'file_view_limit_bytes' => $plan->fileViewLimitBytes(),
            'max_users' => (int) $plan->max_users,
            'is_shared' => (bool) $plan->is_shared,
            'max_shared_members' => $plan->maxSharedMembers(),
            'warning_percentage' => (int) $plan->warning_percentage,
            'soft_limit_percentage' => (int) $plan->soft_limit_percentage,
            'hard_limit_percentage' => (int) $plan->hard_limit_percentage,
            'display_price_cents' => $plan->display_price_cents,
            'currency' => $plan->currency,
            'billing_period' => $period,
            'billing_period_label' => $period === PlanAssignmentService::PERIOD_YEARLY ? 'Yearly' : 'Monthly',
            'play_product_id' => $plan->play_product_id,
            'is_active' => $plan->is_active,
            'sort_order' => $plan->sort_order,
        ];
    }
}
