<?php

namespace App\Modules\StoragePlans\Services;

use App\Models\StoragePlan;
use App\Models\User;
use App\Models\UserPlanAssignment;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PlanAssignmentService
{
    public const PERIOD_MONTHLY = 'monthly';

    public const PERIOD_YEARLY = 'yearly';

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

            $start = Carbon::parse($startsAt ?? now());
            $end = $endsAt !== null
                ? Carbon::parse($endsAt)
                : $this->computePeriodEnd($plan, $start);

            return UserPlanAssignment::create([
                'user_id' => $user->id,
                'storage_plan_uuid' => $plan->uuid,
                'source' => $source,
                'assigned_by_user_id' => $assignedBy?->id,
                'starts_at' => $start,
                'ends_at' => $end,
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

    /**
     * Next renewal / period end from a period start.
     * Free plan uses yearly; other plans default to monthly.
     */
    public function computePeriodEnd(StoragePlan $plan, \DateTimeInterface $startsAt): Carbon
    {
        $start = Carbon::parse($startsAt);
        $period = $this->normalizePeriod($plan->billing_period, $plan->slug);

        return $period === self::PERIOD_YEARLY
            ? $start->copy()->addYear()
            : $start->copy()->addMonth();
    }

    public function normalizePeriod(?string $period, ?string $slug = null): string
    {
        $value = strtolower((string) $period);

        if (in_array($value, [self::PERIOD_MONTHLY, self::PERIOD_YEARLY], true)) {
            return $value;
        }

        // Free is yearly by product rule when period is missing.
        return $slug === 'free' ? self::PERIOD_YEARLY : self::PERIOD_MONTHLY;
    }

    /**
     * Advance next billing date (ends_at) by one or more plan periods until it is in the future.
     * Does NOT change quota or reset storage usage — only the price cycle rolls forward.
     */
    public function renewAssignment(UserPlanAssignment $assignment): UserPlanAssignment
    {
        $assignment->loadMissing('plan');
        $plan = $assignment->plan;

        if (! $plan) {
            throw ValidationException::withMessages([
                'assignment' => ['Assignment has no storage plan.'],
            ]);
        }

        $cursor = Carbon::parse($assignment->ends_at ?? $assignment->starts_at ?? now());
        do {
            $cursor = $this->computePeriodEnd($plan, $cursor);
        } while ($cursor->lte(now()));

        $assignment->update([
            'ends_at' => $cursor,
            'is_active' => true,
        ]);

        return $assignment->fresh('plan');
    }

    /** @return int Number of assignments renewed */
    public function renewDueAssignments(): int
    {
        $due = UserPlanAssignment::query()
            ->where('is_active', true)
            ->whereNotNull('ends_at')
            ->where('ends_at', '<=', now())
            ->with('plan')
            ->get();

        $count = 0;
        foreach ($due as $assignment) {
            if ($assignment->plan === null) {
                continue;
            }
            $this->renewAssignment($assignment);
            $count++;
        }

        // Legacy open-ended: give them a proper renewal date without waiting for expire.
        $openEnded = UserPlanAssignment::query()
            ->where('is_active', true)
            ->whereNull('ends_at')
            ->with('plan')
            ->get();

        foreach ($openEnded as $assignment) {
            if ($assignment->plan === null) {
                continue;
            }
            $start = Carbon::parse($assignment->starts_at ?? now());
            $end = $this->computePeriodEnd($assignment->plan, $start);
            while ($end->lte(now())) {
                $end = $this->computePeriodEnd($assignment->plan, $end);
            }
            $assignment->update(['ends_at' => $end]);
            $count++;
        }

        return $count;
    }

    public function ensureDefaultFreePlan(User $user): void
    {
        $active = $this->activeAssignment($user);
        if ($active !== null) {
            return;
        }

        // Auto-renew expired assignment on the same plan when possible (esp. Free yearly).
        $expired = UserPlanAssignment::query()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->whereNotNull('ends_at')
            ->where('ends_at', '<=', now())
            ->with('plan')
            ->latest('id')
            ->first();

        if ($expired?->plan) {
            $this->renewAssignment($expired);

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
                'description' => 'Default plan for every new account. 5 GB combined storage and read/egress quota. Renews yearly.',
                'slug' => 'free',
                'quota_bytes' => 5 * 1024 * 1024 * 1024,
                'display_price_cents' => 0,
                'currency' => 'USD',
                'billing_period' => self::PERIOD_YEARLY,
                'is_active' => true,
                'sort_order' => 10,
            ]);
        } else {
            $updates = [];
            if ((int) $freePlan->quota_bytes !== 5 * 1024 * 1024 * 1024) {
                $updates['quota_bytes'] = 5 * 1024 * 1024 * 1024;
            }
            if ($this->normalizePeriod($freePlan->billing_period, 'free') !== self::PERIOD_YEARLY) {
                $updates['billing_period'] = self::PERIOD_YEARLY;
            }
            if ($updates !== []) {
                $freePlan->update($updates);
                $freePlan->refresh();
            }
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
            'renewal_date' => $assignment->ends_at?->toIso8601String(),
            'is_active' => $assignment->is_active,
            'plan' => $assignment->plan ? StoragePlanService::formatPlan($assignment->plan) : null,
        ];
    }
}
