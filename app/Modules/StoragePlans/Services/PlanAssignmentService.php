<?php

namespace App\Modules\StoragePlans\Services;

use App\Models\StoragePlan;
use App\Models\User;
use App\Models\UserPlanAssignment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PlanAssignmentService
{
    public function assign(
        User $user,
        StoragePlan $plan,
        ?User $assignedBy,
        string $source = 'admin_manual',
        ?\DateTimeInterface $startsAt = null,
        ?\DateTimeInterface $endsAt = null,
    ): UserPlanAssignment {
        if (! $plan->is_active) {
            throw ValidationException::withMessages([
                'storage_plan_uuid' => ['Cannot assign an inactive plan.'],
            ]);
        }

        return DB::transaction(function () use ($user, $plan, $assignedBy, $source, $startsAt, $endsAt) {
            UserPlanAssignment::query()
                ->where('user_id', $user->id)
                ->where('is_active', true)
                ->update(['is_active' => false]);

            return UserPlanAssignment::create([
                'user_id' => $user->id,
                'storage_plan_uuid' => $plan->uuid,
                'source' => $source,
                'assigned_by_user_id' => $assignedBy?->id,
                'starts_at' => $startsAt ?? now(),
                'ends_at' => $endsAt,
                'is_active' => true,
            ]);
        });
    }

    public function revoke(int $assignmentId): UserPlanAssignment
    {
        $assignment = UserPlanAssignment::query()->find($assignmentId);

        if (! $assignment) {
            throw ValidationException::withMessages([
                'assignment' => ['Assignment not found.'],
            ]);
        }

        $assignment->update(['is_active' => false]);

        return $assignment->fresh();
    }

    public function ensureDefaultFreePlan(User $user): void
    {
        if ($this->activeAssignment($user) !== null) {
            return;
        }

        $freePlan = StoragePlan::query()
            ->where('slug', 'free')
            ->where('is_active', true)
            ->first();

        if (! $freePlan) {
            $freePlan = StoragePlan::query()->create([
                'uuid' => (string) Str::uuid(),
                'name' => 'Free',
                'description' => 'Default plan for every new account. 5 GB combined storage and read/egress quota.',
                'slug' => 'free',
                'quota_bytes' => 5 * 1024 * 1024 * 1024,
                'display_price_cents' => 0,
                'currency' => 'USD',
                'is_active' => true,
                'sort_order' => 10,
            ]);
        } elseif ((int) $freePlan->quota_bytes !== 5 * 1024 * 1024 * 1024) {
            $freePlan->update(['quota_bytes' => 5 * 1024 * 1024 * 1024]);
        }

        $this->assign($user, $freePlan, null, 'system_default');
    }

    public function activeAssignment(User $user): ?UserPlanAssignment
    {
        return UserPlanAssignment::query()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->where('starts_at', '<=', now())
            ->where(function ($query) {
                $query->whereNull('ends_at')->orWhere('ends_at', '>', now());
            })
            ->with('plan')
            ->latest('id')
            ->first();
    }

    public function assignmentForUser(User $targetUser): array
    {
        $assignment = $this->activeAssignment($targetUser);

        return [
            'user_uuid' => $targetUser->uuid,
            'assignment' => $assignment ? $this->formatAssignment($assignment) : null,
        ];
    }

    /** @return array<string, mixed> */
    public function formatAssignment(UserPlanAssignment $assignment): array
    {
        return [
            'id' => $assignment->id,
            'storage_plan_uuid' => $assignment->storage_plan_uuid,
            'source' => $assignment->source,
            'starts_at' => $assignment->starts_at?->toIso8601String(),
            'ends_at' => $assignment->ends_at?->toIso8601String(),
            'is_active' => $assignment->is_active,
            'plan' => $assignment->plan ? StoragePlanService::formatPlan($assignment->plan) : null,
        ];
    }
}
