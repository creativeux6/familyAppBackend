<?php

namespace App\Modules\StoragePlans\Services;

use App\Models\PlanAssignmentMember;
use App\Models\StoragePlan;
use App\Models\User;
use App\Models\UserPlanAssignment;
use App\Models\UserStorageUsage;
use App\Modules\Groups\Services\ConnectedMemberGuard;
use App\Modules\StoragePlans\Support\StoragePoolContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StoragePoolService
{
    public function __construct(
        private readonly ConnectedMemberGuard $connectedMemberGuard,
    ) {}

    public function context(User $user): StoragePoolContext
    {
        $membership = $this->currentMembership($user);
        if ($membership) {
            $assignment = $membership->assignment()->with('plan')->first();
            if ($assignment && $assignment->is_active) {
                $usage = $this->ensureOpenUsage($assignment);

                return new StoragePoolContext(
                    $user,
                    $assignment,
                    $usage,
                    $membership->role === PlanAssignmentMember::ROLE_OWNER,
                );
            }
        }

        $assignment = $this->ownedActiveAssignment($user);
        if (! $assignment) {
            throw ValidationException::withMessages([
                'storage' => ['No active storage plan assignment.'],
            ]);
        }

        return new StoragePoolContext(
            $user,
            $assignment,
            $this->ensureOpenUsage($assignment),
            true,
        );
    }

    public function ownedActiveAssignment(User $user): ?UserPlanAssignment
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

    public function ensureOpenUsage(UserPlanAssignment $assignment): UserStorageUsage
    {
        $assignment->loadMissing('plan', 'user');

        $usage = UserStorageUsage::query()
            ->where('assignment_id', $assignment->id)
            ->whereNull('closed_at')
            ->latest('id')
            ->first();

        if ($usage) {
            $this->ensureOwnerMember($assignment, $usage);

            return $usage;
        }

        return $this->openPeriod($assignment, (int) ($assignment->user?->storage_used_bytes ?? 0));
    }

    public function openPeriod(UserPlanAssignment $assignment, int $carryStock = 0, ?StoragePlan $plan = null): UserStorageUsage
    {
        $assignment->loadMissing('plan');
        $plan ??= $assignment->plan;
        if (! $plan) {
            throw ValidationException::withMessages([
                'assignment' => ['Assignment has no storage plan.'],
            ]);
        }

        $periodStart = $assignment->starts_at?->copy() ?? now();
        $latest = UserStorageUsage::query()
            ->where('assignment_id', $assignment->id)
            ->latest('period_start')
            ->first();
        if ($latest?->period_end) {
            $periodStart = Carbon::parse($latest->period_end);
        } elseif ($latest?->period_start) {
            $periodStart = Carbon::parse($latest->period_start);
        }

        $existing = UserStorageUsage::query()
            ->where('assignment_id', $assignment->id)
            ->where('period_start', $periodStart)
            ->first();
        if ($existing && $existing->closed_at === null) {
            $this->ensureOwnerMember($assignment, $existing);

            return $existing;
        }

        $usage = new UserStorageUsage([
            'assignment_id' => $assignment->id,
            'storage_used_bytes' => max(0, $carryStock),
            'monthly_access_bytes' => 0,
            'streamed_bytes' => 0,
            'downloaded_bytes' => 0,
            'file_viewed_bytes' => 0,
            'upload_requests' => 0,
            'download_requests' => 0,
            'stream_requests' => 0,
            'file_view_requests' => 0,
            'period_start' => $periodStart,
            'period_end' => $assignment->ends_at,
            'closed_at' => null,
        ]);
        $usage->applyPlanSnapshot($plan);
        $usage->save();

        $this->ensureOwnerMember($assignment, $usage);

        return $usage->fresh();
    }

    public function rollPeriod(UserPlanAssignment $assignment, ?StoragePlan $nextPlan = null): UserStorageUsage
    {
        return DB::transaction(function () use ($assignment, $nextPlan) {
            $current = UserStorageUsage::query()
                ->where('assignment_id', $assignment->id)
                ->whereNull('closed_at')
                ->lockForUpdate()
                ->first();

            $stock = (int) ($current?->storage_used_bytes ?? $assignment->user?->storage_used_bytes ?? 0);
            if ($current) {
                $current->closed_at = now();
                $current->period_end = $current->period_end ?? now();
                $current->save();
            }

            $plan = $nextPlan ?? $assignment->plan;
            $usage = $this->openPeriod($assignment, $stock, $plan);
            $this->resetRosterToOwner($assignment, $usage);

            return $usage;
        });
    }

    public function refreshOpenSnapshot(StoragePlan $plan): void
    {
        $assignmentIds = UserPlanAssignment::query()
            ->where('storage_plan_uuid', $plan->uuid)
            ->where('is_active', true)
            ->pluck('id');

        UserStorageUsage::query()
            ->whereIn('assignment_id', $assignmentIds)
            ->whereNull('closed_at')
            ->each(function (UserStorageUsage $usage) use ($plan) {
                $usage->applyPlanSnapshot($plan);
                $usage->save();
            });
    }

    public function ensureOwnerMember(UserPlanAssignment $assignment, UserStorageUsage $usage): PlanAssignmentMember
    {
        return PlanAssignmentMember::query()->firstOrCreate(
            [
                'assignment_id' => $assignment->id,
                'user_id' => $assignment->user_id,
                'period_start' => $usage->period_start,
            ],
            ['role' => PlanAssignmentMember::ROLE_OWNER],
        );
    }

    public function resetRosterToOwner(UserPlanAssignment $assignment, UserStorageUsage $usage): void
    {
        PlanAssignmentMember::query()
            ->where('assignment_id', $assignment->id)
            ->where('period_start', $usage->period_start)
            ->where('role', PlanAssignmentMember::ROLE_MEMBER)
            ->delete();

        $this->ensureOwnerMember($assignment, $usage);
    }

    public function addMember(User $owner, User $member): PlanAssignmentMember
    {
        if ($owner->id === $member->id) {
            throw ValidationException::withMessages([
                'user_uuid' => ['You are already on this plan.'],
            ]);
        }

        if (! $this->connectedMemberGuard->areConnected($owner, $member)) {
            throw ValidationException::withMessages([
                'user_uuid' => ['You can only add people who are in your contacts.'],
            ]);
        }

        $context = $this->context($owner);
        if (! $context->isOwner) {
            throw ValidationException::withMessages([
                'storage' => ['Only the plan owner can add members.'],
            ]);
        }

        $usage = $context->usage;
        if (! $usage->is_shared) {
            throw ValidationException::withMessages([
                'storage' => ['This plan does not include shared seats.'],
            ]);
        }

        $memberCount = $this->memberSeatCount($context->assignment, $usage);
        if ($memberCount >= (int) $usage->max_shared_members) {
            throw ValidationException::withMessages([
                'user_uuid' => ['No empty seats left on this plan until the next cycle or an upgrade.'],
            ]);
        }

        if ($this->currentMembership($member)) {
            throw ValidationException::withMessages([
                'user_uuid' => ['That person is already on a shared plan this cycle.'],
            ]);
        }

        $ownedShared = $this->ownedActiveAssignment($member);
        if ($ownedShared?->plan?->is_shared) {
            throw ValidationException::withMessages([
                'user_uuid' => ['That person already owns a shared plan.'],
            ]);
        }

        return PlanAssignmentMember::query()->create([
            'assignment_id' => $context->assignment->id,
            'user_id' => $member->id,
            'role' => PlanAssignmentMember::ROLE_MEMBER,
            'period_start' => $usage->period_start,
        ]);
    }

    /** @return Collection<int, PlanAssignmentMember> */
    public function roster(UserPlanAssignment $assignment, UserStorageUsage $usage): Collection
    {
        return PlanAssignmentMember::query()
            ->where('assignment_id', $assignment->id)
            ->where('period_start', $usage->period_start)
            ->with('user')
            ->orderByRaw("role = 'owner' desc")
            ->orderBy('id')
            ->get();
    }

    public function memberSeatCount(UserPlanAssignment $assignment, UserStorageUsage $usage): int
    {
        return PlanAssignmentMember::query()
            ->where('assignment_id', $assignment->id)
            ->where('period_start', $usage->period_start)
            ->where('role', PlanAssignmentMember::ROLE_MEMBER)
            ->count();
    }

    public function emptySeats(UserStorageUsage $usage, int $memberCount): int
    {
        return max(0, (int) $usage->max_shared_members - $memberCount);
    }

    public function currentMembership(User $user): ?PlanAssignmentMember
    {
        return PlanAssignmentMember::query()
            ->where('user_id', $user->id)
            ->whereHas('assignment', fn ($q) => $q->where('is_active', true))
            ->whereExists(function ($q) {
                $q->selectRaw('1')
                    ->from('user_storage_usage')
                    ->whereColumn('user_storage_usage.assignment_id', 'plan_assignment_members.assignment_id')
                    ->whereColumn('user_storage_usage.period_start', 'plan_assignment_members.period_start')
                    ->whereNull('closed_at');
            })
            ->with('assignment.plan')
            ->latest('id')
            ->first();
    }

    /** @return array<string, mixed> */
    public function membershipPayload(User $user): array
    {
        $context = $this->context($user);
        $roster = $this->roster($context->assignment, $context->usage);
        $memberCount = $this->memberSeatCount($context->assignment, $context->usage);

        return [
            'is_owner' => $context->isOwner,
            'is_shared' => (bool) $context->usage->is_shared,
            'max_shared_members' => (int) $context->usage->max_shared_members,
            'member_count' => $memberCount,
            'empty_seats' => $this->emptySeats($context->usage, $memberCount),
            'period_start' => $context->usage->period_start?->toIso8601String(),
            'period_end' => $context->usage->period_end?->toIso8601String(),
            'members' => $roster->map(fn (PlanAssignmentMember $row) => [
                'user_uuid' => $row->user?->uuid,
                'display_name' => $row->user?->display_name,
                'role' => $row->role,
                'locked' => true,
            ])->values()->all(),
        ];
    }
}
