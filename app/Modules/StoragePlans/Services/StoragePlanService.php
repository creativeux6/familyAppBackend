<?php

namespace App\Modules\StoragePlans\Services;

use App\Models\StoragePlan;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StoragePlanService
{
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

        $plan = StoragePlan::create([
            'uuid' => (string) Str::uuid(),
            'name' => $data['name'],
            'slug' => $data['slug'],
            'quota_bytes' => $data['quota_bytes'],
            'display_price_cents' => $data['display_price_cents'] ?? 0,
            'currency' => $data['currency'] ?? 'USD',
            'is_active' => $data['is_active'] ?? true,
            'sort_order' => $data['sort_order'] ?? 0,
        ]);

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

        $plan->update(collect($data)->only([
            'name', 'slug', 'quota_bytes', 'display_price_cents', 'currency', 'is_active', 'sort_order',
        ])->all());

        return self::formatPlan($plan->fresh());
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

    /** @return array<string, mixed> */
    public static function formatPlan(StoragePlan $plan): array
    {
        return [
            'uuid' => $plan->uuid,
            'name' => $plan->name,
            'slug' => $plan->slug,
            'quota_bytes' => $plan->quota_bytes,
            'display_price_cents' => $plan->display_price_cents,
            'currency' => $plan->currency,
            'is_active' => $plan->is_active,
            'sort_order' => $plan->sort_order,
        ];
    }
}
