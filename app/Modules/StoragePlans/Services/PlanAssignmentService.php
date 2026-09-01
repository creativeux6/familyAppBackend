<?php

namespace App\Modules\StoragePlans\Services;

use App\Models\StoragePlan;
use App\Models\User;
use App\Models\UserPlanAssignment;
use App\Modules\StoragePlans\Support\StorageBytes;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PlanAssignmentService
{
    public const PERIOD_MONTHLY = 'monthly';

    public const PERIOD_YEARLY = 'yearly';

    public function __construct(
        private readonly StoragePoolService $poolService,
        private readonly PlanBillingService $billingService,
    ) {}

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

            $assignment = UserPlanAssignment::create([
                'user_id' => $user->id,
                'storage_plan_uuid' => $plan->uuid,
                'source' => $source,
                'assigned_by_user_id' => $assignedBy?->id,
                'starts_at' => $start,
                'ends_at' => $end,
                'is_active' => true,
                'billing_status' => UserPlanAssignment::STATUS_ACTIVE,
                'payment_retry_count' => 0,
            ]);

            $this->poolService->openPeriod($assignment->load(['plan', 'user']), (int) $user->storage_used_bytes, $plan);

            return $assignment->fresh('plan');
        });
    }

    public function changePlan(
        User $user,
        StoragePlan $plan,
        ?User $assignedBy,
        string $source = 'admin_manual',
    ): UserPlanAssignment {
        if (! $plan->is_active) {
            throw ValidationException::withMessages([
                'storage_plan_uuid' => ['Cannot assign an inactive plan.'],
            ]);
        }

        $this->ensureDefaultFreePlan($user);
        $current = $this->activeAssignment($user);

        if (! $current) {
            return $this->assign($user, $plan, $assignedBy, $source);
        }

        if ($current->storage_plan_uuid === $plan->uuid) {
            $current->update(['pending_storage_plan_uuid' => null, 'pending_change_at' => null]);

            return $current->fresh('plan');
        }

        $current->loadMissing('plan');
        $from = $current->plan;
        if (! $from) {
            return $this->assign($user, $plan, $assignedBy, $source);
        }

        if ($this->isUpgrade($from, $plan)) {
            $shouldCharge = $source !== 'google_play';
            if ($shouldCharge && ! $this->billingService->charge($user, $plan, 'upgrade')) {
                throw ValidationException::withMessages([
                    'payment' => ['Payment failed. Upgrade was not applied.'],
                ]);
            }

            $wasShared = (bool) $from->is_shared;
            $current->update([
                'storage_plan_uuid' => $plan->uuid,
                'source' => $source,
                'assigned_by_user_id' => $assignedBy?->id,
                'pending_storage_plan_uuid' => null,
                'pending_change_at' => null,
                'billing_status' => UserPlanAssignment::STATUS_ACTIVE,
                'payment_retry_count' => 0,
                'next_retry_at' => null,
                'last_payment_failed_at' => null,
            ]);
            $current = $current->fresh('plan');

            $usage = $this->poolService->ensureOpenUsage($current);
            $usage->applyPlanSnapshot($plan);
            $usage->save();

            if (! $wasShared && $plan->is_shared) {
                $this->poolService->resetRosterToOwner($current, $usage);
            }

            return $current;
        }

        $current->update([
            'pending_storage_plan_uuid' => $plan->uuid,
            'pending_change_at' => now(),
            'assigned_by_user_id' => $assignedBy?->id,
            'source' => $source,
        ]);

        return $current->fresh(['plan', 'pendingPlan']);
    }

    /**
     * Apply a plan immediately (upgrade or downgrade). Used after Google Play
     * has already billed, and when a Play subscription expires.
     *
     * @param  array{play_purchase_token?: ?string, play_product_id?: ?string, play_auto_renewing?: bool, ends_at?: ?\DateTimeInterface}  $play
     */
    public function applyImmediatePlan(
        User $user,
        StoragePlan $plan,
        string $source = 'google_play',
        array $play = [],
    ): UserPlanAssignment {
        if (! $plan->is_active && $plan->slug !== 'free') {
            throw ValidationException::withMessages([
                'storage_plan_uuid' => ['Cannot assign an inactive plan.'],
            ]);
        }

        $this->ensureDefaultFreePlan($user);
        $current = $this->activeAssignment($user);

        $wasShared = false;
        if ($current) {
            $current->loadMissing('plan');
            $wasShared = (bool) $current->plan?->is_shared;
        }
        $playToken = array_key_exists('play_purchase_token', $play)
            ? $play['play_purchase_token']
            : $current?->play_purchase_token;
        $playProduct = array_key_exists('play_product_id', $play)
            ? $play['play_product_id']
            : ($plan->play_product_id ?: $current?->play_product_id);
        $autoRenew = array_key_exists('play_auto_renewing', $play)
            ? (bool) $play['play_auto_renewing']
            : (bool) $current?->play_auto_renewing;
        $endsAt = $play['ends_at'] ?? null;

        if (! $current) {
            $assignment = $this->assign($user, $plan, $user, $source, null, $endsAt instanceof \DateTimeInterface ? $endsAt : null);
            $assignment->update([
                'play_purchase_token' => $playToken,
                'play_product_id' => $playProduct,
                'play_auto_renewing' => $autoRenew,
                'billing_status' => UserPlanAssignment::STATUS_ACTIVE,
            ]);

            return $assignment->fresh('plan');
        }

        $current->update([
            'storage_plan_uuid' => $plan->uuid,
            'source' => $source,
            'assigned_by_user_id' => $user->id,
            'pending_storage_plan_uuid' => null,
            'pending_change_at' => null,
            'billing_status' => UserPlanAssignment::STATUS_ACTIVE,
            'payment_retry_count' => 0,
            'next_retry_at' => null,
            'last_payment_failed_at' => null,
            'play_purchase_token' => $playToken,
            'play_product_id' => $playProduct,
            'play_auto_renewing' => $autoRenew,
            'ends_at' => $endsAt instanceof \DateTimeInterface
                ? $endsAt
                : $current->ends_at,
        ]);
        $current = $current->fresh('plan');

        $usage = $this->poolService->ensureOpenUsage($current);
        $usage->applyPlanSnapshot($plan);
        $usage->save();

        if ($wasShared && ! $plan->is_shared) {
            $this->poolService->resetRosterToOwner($current, $usage);
        } elseif (! $wasShared && $plan->is_shared) {
            $this->poolService->resetRosterToOwner($current, $usage);
        }

        return $current;
    }

    public function cancelPendingChange(User $user): UserPlanAssignment
    {
        $assignment = $this->activeAssignment($user);
        if (! $assignment) {
            throw ValidationException::withMessages([
                'assignment' => ['No active plan assignment.'],
            ]);
        }

        $assignment->update([
            'pending_storage_plan_uuid' => null,
            'pending_change_at' => null,
        ]);

        return $assignment->fresh('plan');
    }

    public function isUpgrade(StoragePlan $from, StoragePlan $to): bool
    {
        $fromCap = $from->storageLimitBytes();
        $toCap = $to->storageLimitBytes();
        if ($toCap !== $fromCap) {
            return $toCap > $fromCap;
        }

        return (int) $to->display_price_cents > (int) $from->display_price_cents;
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

        return $slug === 'free' ? self::PERIOD_YEARLY : self::PERIOD_MONTHLY;
    }

    public function renewAssignment(UserPlanAssignment $assignment): UserPlanAssignment
    {
        $assignment->loadMissing(['plan', 'pendingPlan', 'user']);
        $plan = $assignment->pendingPlan ?: $assignment->plan;

        if (! $plan) {
            throw ValidationException::withMessages([
                'assignment' => ['Assignment has no storage plan.'],
            ]);
        }

        $user = $assignment->user;
        if ($assignment->source === 'google_play') {
            if (! (bool) $assignment->play_auto_renewing) {
                $freePlan = $this->ensureFreePlanRow();
                if ($user) {
                    return $this->applyImmediatePlan($user, $freePlan, 'system_default', [
                        'play_purchase_token' => null,
                        'play_product_id' => null,
                        'play_auto_renewing' => false,
                    ]);
                }
            }
        } elseif ($plan->isPaid() && $user) {
            if (! $this->billingService->charge($user, $plan, 'renewal')) {
                $this->billingService->markPastDue($assignment);
            } else {
                $this->billingService->markActive($assignment);
            }
        }

        if ($assignment->pending_storage_plan_uuid) {
            $assignment->storage_plan_uuid = $assignment->pending_storage_plan_uuid;
            $assignment->pending_storage_plan_uuid = null;
            $assignment->pending_change_at = null;
            $assignment->save();
            $assignment->load('plan');
            $plan = $assignment->plan ?: $plan;
        }

        $cursor = Carbon::parse($assignment->ends_at ?? $assignment->starts_at ?? now());
        do {
            $cursor = $this->computePeriodEnd($plan, $cursor);
        } while ($cursor->lte(now()));

        $assignment->update([
            'ends_at' => $cursor,
            'is_active' => true,
        ]);

        $fresh = $assignment->fresh(['plan', 'user']);
        $this->poolService->rollPeriod($fresh, $plan);

        return $fresh->fresh('plan');
    }

    public function renewDueAssignments(): int
    {
        $due = UserPlanAssignment::query()
            ->where('is_active', true)
            ->whereNotNull('ends_at')
            ->where('ends_at', '<=', now())
            ->with(['plan', 'pendingPlan', 'user'])
            ->get();

        $count = 0;
        foreach ($due as $assignment) {
            if ($assignment->plan === null && $assignment->pendingPlan === null) {
                continue;
            }
            $this->renewAssignment($assignment);
            $count++;
        }

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
            $this->poolService->ensureOpenUsage($active);

            return;
        }

        $expired = UserPlanAssignment::query()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->whereNotNull('ends_at')
            ->where('ends_at', '<=', now())
            ->with(['plan', 'pendingPlan', 'user'])
            ->latest('id')
            ->first();

        if ($expired?->plan || $expired?->pendingPlan) {
            $this->renewAssignment($expired);

            return;
        }

        $freePlan = $this->ensureFreePlanRow();
        $this->assign($user, $freePlan, null, 'system_default');
    }

    public function ensureFreePlanRow(): StoragePlan
    {
        $freePlan = StoragePlan::query()
            ->where('slug', 'free')
            ->where('is_active', true)
            ->first();

        $storage = StorageBytes::fromGib(5);
        $access = StorageBytes::fromGib(15);
        $stream = StorageBytes::fromGib(10);
        $download = StorageBytes::fromGib(5);

        $attrs = [
            'name' => 'Free',
            'description' => 'Default plan for every new account. 5 GB stored, 15 GB monthly access. Renews yearly.',
            'quota_bytes' => $storage,
            'storage_limit_bytes' => $storage,
            'monthly_access_limit_bytes' => $access,
            'streaming_limit_bytes' => $stream,
            'download_limit_bytes' => $download,
            'file_view_limit_bytes' => $access,
            'max_users' => 1,
            'is_shared' => false,
            'max_shared_members' => 0,
            'warning_percentage' => 80,
            'soft_limit_percentage' => 90,
            'hard_limit_percentage' => 100,
            'display_price_cents' => 0,
            'currency' => 'USD',
            'billing_period' => self::PERIOD_YEARLY,
            'is_active' => true,
            'sort_order' => 10,
        ];

        if (! $freePlan) {
            return StoragePlan::query()->create(array_merge($attrs, [
                'uuid' => (string) Str::uuid(),
                'slug' => 'free',
            ]));
        }

        $freePlan->update($attrs);

        return $freePlan->fresh();
    }

    public function activeAssignment(User $user): ?UserPlanAssignment
    {
        return $this->poolService->ownedActiveAssignment($user);
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
        $assignment->loadMissing(['plan', 'pendingPlan']);

        return [
            'id' => $assignment->id,
            'storage_plan_uuid' => $assignment->storage_plan_uuid,
            'source' => $assignment->source,
            'starts_at' => $assignment->starts_at?->toIso8601String(),
            'ends_at' => $assignment->ends_at?->toIso8601String(),
            'renewal_date' => $assignment->ends_at?->toIso8601String(),
            'is_active' => $assignment->is_active,
            'billing_status' => $assignment->billing_status,
            'pending_storage_plan_uuid' => $assignment->pending_storage_plan_uuid,
            'pending_change_at' => $assignment->pending_change_at?->toIso8601String(),
            'plan' => $assignment->plan ? StoragePlanService::formatPlan($assignment->plan) : null,
            'pending_plan' => $assignment->pendingPlan
                ? StoragePlanService::formatPlan($assignment->pendingPlan)
                : null,
        ];
    }
}
