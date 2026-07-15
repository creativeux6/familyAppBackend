<?php

namespace App\Modules\Admin\Services;

use App\Models\Connection;
use App\Models\User;
use App\Modules\StoragePlans\Services\PlanAssignmentService;
use App\Modules\StoragePlans\Services\StorageQuotaService;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

class AdminUserService
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
        private readonly StorageQuotaService $quotaService,
        private readonly PlanAssignmentService $planAssignmentService,
    ) {}

    /** @return array<string, mixed> */
    public function list(?string $search, bool $includeTrashed, int $page, int $perPage): array
    {
        $now = now();

        $query = User::query()->with([
            'roles',
            'planAssignments' => function ($q) use ($now) {
                $q->where('is_active', true)
                    ->where('starts_at', '<=', $now)
                    ->where(function ($inner) use ($now) {
                        $inner->whereNull('ends_at')->orWhere('ends_at', '>', $now);
                    })
                    ->with('plan')
                    ->latest('id');
            },
        ]);

        if ($includeTrashed) {
            $query->withTrashed();
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('phone', 'like', "%{$search}%")
                    ->orWhere('display_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('uuid', 'like', "%{$search}%");
            });
        }

        $paginator = $query->latest()->paginate($perPage, ['*'], 'page', $page);

        return [
            'users' => collect($paginator->items())->map(fn (User $user) => $this->formatListUser($user))->values()->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    public function show(string $uuid): array
    {
        $user = User::query()->withTrashed()
            ->with(['roles', 'familyMember.family'])
            ->where('uuid', $uuid)
            ->first();

        if (! $user) {
            throw ValidationException::withMessages([
                'user_uuid' => ['User not found.'],
            ]);
        }

        $assignment = $this->planAssignmentService->activeAssignment($user);

        return [
            'user' => $this->formatListUser($user),
            'storage' => $this->quotaService->summary($user),
            'roles' => $user->roles->pluck('name')->values()->all(),
            'management' => $this->formatManagementSummary($user),
            'plan_assignment' => $assignment
                ? $this->planAssignmentService->formatAssignment($assignment)
                : null,
        ];
    }

    /** @param  array<string, mixed>  $data */
    public function update(User $admin, string $uuid, array $data, ?string $ip = null): array
    {
        $user = $this->requireUser($uuid);

        $updates = array_intersect_key($data, array_flip(['display_name', 'is_anonymous']));

        if ($updates !== []) {
            if (isset($updates['display_name'])) {
                $updates['name'] = $updates['display_name'];
            }
            $user->update($updates);
        }

        $this->auditLogService->log($admin, 'user.updated', 'user', $user->uuid, $updates, $ip);

        return $this->show($user->uuid);
    }

    public function ban(User $admin, string $uuid, ?string $ip = null): array
    {
        $user = $this->requireUser($uuid);

        if ($user->id === $admin->id) {
            throw ValidationException::withMessages([
                'user_uuid' => ['You cannot ban your own account.'],
            ]);
        }

        $user->tokens()->delete();
        $user->delete();

        $this->auditLogService->log($admin, 'user.banned', 'user', $user->uuid, [], $ip);

        return ['message' => 'User banned (soft deleted).'];
    }

    public function restore(User $admin, string $uuid, ?string $ip = null): array
    {
        $user = User::query()->withTrashed()->where('uuid', $uuid)->first();

        if (! $user) {
            throw ValidationException::withMessages([
                'user_uuid' => ['User not found.'],
            ]);
        }

        $user->restore();

        $this->auditLogService->log($admin, 'user.restored', 'user', $user->uuid, [], $ip);

        return $this->show($user->uuid);
    }

    public function assignRole(User $admin, string $uuid, string $roleName, ?string $ip = null): array
    {
        $user = $this->requireUser($uuid);
        $role = Role::query()->firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);

        if (! $user->hasRole($roleName)) {
            $user->assignRole($role);
        }

        $this->auditLogService->log($admin, 'user.role_assigned', 'user', $user->uuid, ['role' => $roleName], $ip);

        return $this->show($user->uuid);
    }

    public function removeRole(User $admin, string $uuid, string $roleName, ?string $ip = null): array
    {
        $user = $this->requireUser($uuid);

        if ($user->id === $admin->id && in_array($roleName, ['admin', 'super_admin'], true)) {
            throw ValidationException::withMessages([
                'role' => ['You cannot remove your own admin role.'],
            ]);
        }

        if ($roleName === 'super_admin' && ! $admin->hasRole('super_admin')) {
            throw ValidationException::withMessages([
                'role' => ['Only a super admin can remove the super_admin role.'],
            ]);
        }

        $user->removeRole($roleName);

        $this->auditLogService->log($admin, 'user.role_removed', 'user', $user->uuid, ['role' => $roleName], $ip);

        return $this->show($user->uuid);
    }

    private function requireUser(string $uuid): User
    {
        $user = User::query()->where('uuid', $uuid)->first();

        if (! $user) {
            throw ValidationException::withMessages([
                'user_uuid' => ['User not found.'],
            ]);
        }

        return $user;
    }

    /** @return array<string, mixed> */
    private function formatListUser(User $user): array
    {
        $assignment = $user->relationLoaded('planAssignments')
            ? $user->planAssignments->first()
            : $this->planAssignmentService->activeAssignment($user);

        $plan = $assignment?->plan;

        return [
            'uuid' => $user->uuid,
            'display_name' => $user->display_name,
            'storage_used_bytes' => $user->storage_used_bytes,
            'storage_read_bytes' => $user->storage_read_bytes,
            'storage_total_used_bytes' => (int) $user->storage_used_bytes + (int) $user->storage_read_bytes,
            'plan_name' => $plan?->name,
            'plan_slug' => $plan?->slug,
            'quota_bytes' => $plan ? (int) $plan->quota_bytes : null,
            'billing_period' => $plan
                ? $this->planAssignmentService->normalizePeriod($plan->billing_period, $plan->slug)
                : null,
            'billing_period_label' => $plan
                ? ($this->planAssignmentService->normalizePeriod($plan->billing_period, $plan->slug) === 'yearly'
                    ? 'Yearly'
                    : 'Monthly')
                : null,
            'plan_starts_at' => $assignment?->starts_at?->toIso8601String(),
            'renewal_date' => $assignment?->ends_at?->toIso8601String(),
            'plan_source' => $assignment?->source,
            'roles' => $user->roles->pluck('name')->values()->all(),
            'deleted_at' => $user->deleted_at?->toIso8601String(),
            'created_at' => $user->created_at?->toIso8601String(),
        ];
    }

    /**
     * Operational account summary for admins — no phone, email, or media content.
     *
     * @return array<string, mixed>
     */
    private function formatManagementSummary(User $user): array
    {
        $family = $user->familyMember?->family;
        $inFamily = $family !== null;

        $connectedMembersCount = Connection::query()
            ->where(function ($q) use ($user) {
                $q->where('requester_user_id', $user->id)
                    ->orWhere('recipient_user_id', $user->id);
            })
            ->where('status', 'connected')
            ->count();

        return [
            'account_mode' => $inFamily ? 'family' : 'private',
            'account_mode_label' => $inFamily ? 'Family' : 'Private',
            'family_uuid' => $family?->uuid,
            'family_member_count' => $inFamily ? (int) ($family->member_count ?? 0) : 0,
            'connected_members_count' => $connectedMembersCount,
            'is_anonymous' => (bool) $user->is_anonymous,
        ];
    }
}
