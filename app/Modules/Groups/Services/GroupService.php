<?php

namespace App\Modules\Groups\Services;

use App\Models\Connection;
use App\Models\Group;
use App\Models\GroupEncryptionGeneration;
use App\Models\GroupMember;
use App\Models\Message;
use App\Models\User;
use App\Modules\Groups\Events\GroupDeleted;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class GroupService
{
    public function __construct(
        private readonly ConnectedMemberGuard $connectedMemberGuard,
    ) {}

    /** @param  list<string>  $memberUserUuids */
    public function create(User $user, string $name, ?string $description, array $memberUserUuids): array
    {
        $memberUserUuids = array_values(array_unique($memberUserUuids));

        if (count($memberUserUuids) < 1) {
            throw ValidationException::withMessages([
                'member_user_uuids' => ['Add at least one connected family member (minimum 2 people per group).'],
            ]);
        }

        $targets = User::query()->whereIn('uuid', $memberUserUuids)->get();

        if ($targets->count() !== count($memberUserUuids)) {
            throw ValidationException::withMessages([
                'member_user_uuids' => ['One or more users were not found.'],
            ]);
        }

        $this->connectedMemberGuard->assertAllConnected($user, $targets);

        return DB::transaction(function () use ($user, $name, $description, $targets) {
            $group = Group::create([
                'uuid' => (string) Str::uuid(),
                'type' => 'group',
                'name' => $name,
                'description' => $description,
                'created_by_user_id' => $user->id,
                'member_count' => 0,
            ]);

            $this->addMemberRecord($group, $user, 'owner');

            foreach ($targets as $target) {
                $this->addMemberRecord($group, $target, 'member');
            }

            GroupEncryptionGeneration::create([
                'group_uuid' => $group->uuid,
                'generation' => 1,
                'created_by_user_id' => $user->id,
                'reason' => 'initial',
            ]);

            $group->update(['member_count' => $group->members()->count()]);

            return $this->formatGroup($group->fresh(['members.user']));
        });
    }

    /** @return array<string, mixed> */
    public function findOrCreateDirect(User $user, string $otherUserUuid): array
    {
        if ($otherUserUuid === $user->uuid) {
            throw ValidationException::withMessages([
                'user_uuid' => ['You cannot start a direct chat with yourself.'],
            ]);
        }

        $other = User::query()->where('uuid', $otherUserUuid)->first();

        if (! $other) {
            throw ValidationException::withMessages([
                'user_uuid' => ['User not found.'],
            ]);
        }

        $this->connectedMemberGuard->assertAllConnected($user, collect([$other]));

        $directKey = $this->directKey($user->id, $other->id);
        $existing = Group::query()
            ->where('type', 'direct')
            ->where('direct_key', $directKey)
            ->first();

        if ($existing) {
            if (! $existing->members()->where('user_id', $user->id)->exists()) {
                throw ValidationException::withMessages([
                    'user_uuid' => ['You are not a member of this conversation.'],
                ]);
            }

            return $this->formatGroup($existing->load(['members.user:id,uuid,display_name']), $user);
        }

        return DB::transaction(function () use ($user, $other, $directKey) {
            $group = Group::create([
                'uuid' => (string) Str::uuid(),
                'type' => 'direct',
                'direct_key' => $directKey,
                'name' => $other->display_name ?? 'Direct chat',
                'description' => null,
                'created_by_user_id' => $user->id,
                'member_count' => 0,
            ]);

            $this->addMemberRecord($group, $user, 'owner');
            $this->addMemberRecord($group, $other, 'member');

            GroupEncryptionGeneration::create([
                'group_uuid' => $group->uuid,
                'generation' => 1,
                'created_by_user_id' => $user->id,
                'reason' => 'initial',
            ]);

            $group->update(['member_count' => $group->members()->count()]);

            return $this->formatGroup($group->fresh(['members.user:id,uuid,display_name']), $user);
        });
    }

    /** @return array<string, mixed> */
    public function listForUser(User $user): array
    {
        $groups = Group::query()
            ->whereHas('members', fn ($q) => $q->where('user_id', $user->id))
            ->with(['members.user:id,uuid,display_name'])
            ->latest()
            ->get();

        return [
            'groups' => $groups->map(fn (Group $group) => $this->formatGroup($group, $user))->values()->all(),
            'connected_contacts' => $this->connectedContactsFor($user),
        ];
    }

    public function show(User $user, string $groupUuid): array
    {
        $group = $this->requireGroupMember($user, $groupUuid);

        return $this->formatGroup($group->load(['members.user:id,uuid,display_name']), $user);
    }

    public function update(User $user, string $groupUuid, array $data): array
    {
        $group = $this->requireGroupMember($user, $groupUuid);
        $this->assertCanManage($user, $group);

        if ($group->isDirect()) {
            unset($data['name']);
        }

        $updates = [];

        if (array_key_exists('name', $data)) {
            $updates['name'] = $data['name'];
        }

        if (array_key_exists('description', $data)) {
            $updates['description'] = $data['description'];
        }

        if ($updates !== []) {
            $group->update($updates);
        }

        return $this->formatGroup($group->fresh(['members.user:id,uuid,display_name']));
    }

    /** @param  list<string>  $userUuids */
    public function addMembers(User $user, string $groupUuid, array $userUuids): array
    {
        $group = $this->requireGroupMember($user, $groupUuid);

        if ($group->isDirect()) {
            throw ValidationException::withMessages([
                'group' => ['You cannot add members to a direct chat.'],
            ]);
        }

        $this->assertCanManage($user, $group);

        $userUuids = array_values(array_unique($userUuids));
        $targets = User::query()->whereIn('uuid', $userUuids)->get();

        if ($targets->isEmpty()) {
            throw ValidationException::withMessages([
                'user_uuids' => ['Provide at least one user to add.'],
            ]);
        }

        $this->connectedMemberGuard->assertAllConnected($user, $targets);

        foreach ($targets as $target) {
            if ($group->members()->where('user_id', $target->id)->exists()) {
                continue;
            }

            $this->addMemberRecord($group, $target, 'member');
        }

        $group->update(['member_count' => $group->members()->count()]);

        return $this->formatGroup($group->fresh(['members.user:id,uuid,display_name']));
    }

    public function removeMember(User $user, string $groupUuid, string $targetUserUuid): array
    {
        $group = $this->requireGroupMember($user, $groupUuid);
        $target = User::query()->where('uuid', $targetUserUuid)->firstOrFail();
        $membership = $group->members()->where('user_id', $target->id)->first();

        if (! $membership) {
            throw ValidationException::withMessages([
                'user_uuid' => ['User is not in this group.'],
            ]);
        }

        $isSelf = $target->id === $user->id;
        $isOwner = $membership->role === 'owner';

        if ($group->isDirect()) {
            $this->broadcastGroupDeleted($group->uuid);
            $group->delete();

            return ['message' => 'Conversation deleted.'];
        }

        if (! $isSelf && ! $this->canManage($user, $group)) {
            throw ValidationException::withMessages([
                'user_uuid' => ['Only group owner or admin can remove other members.'],
            ]);
        }

        if ($isOwner && ! $isSelf) {
            throw ValidationException::withMessages([
                'user_uuid' => ['Transfer ownership before removing the owner.'],
            ]);
        }

        $remaining = $group->members()->count() - 1;

        if ($remaining < 2) {
            throw ValidationException::withMessages([
                'group' => ['Groups must have at least 2 members. Delete the group instead.'],
            ]);
        }

        $membership->delete();
        $group->update(['member_count' => $group->members()->count()]);

        return ['message' => $isSelf ? 'You left the group.' : 'Member removed from group.'];
    }

    public function delete(User $user, string $groupUuid): array
    {
        $group = $this->requireGroupMember($user, $groupUuid);
        $membership = $group->members()->where('user_id', $user->id)->firstOrFail();

        if ($group->isDirect()) {
            $this->broadcastGroupDeleted($group->uuid);
            $group->delete();

            return ['message' => 'Conversation deleted.'];
        }

        if ($membership->role !== 'owner') {
            throw ValidationException::withMessages([
                'group' => ['Only the group owner can delete the group.'],
            ]);
        }

        $this->broadcastGroupDeleted($group->uuid);
        $group->delete();

        return ['message' => 'Group deleted.'];
    }

    public function realtimeConfig(User $user, string $groupUuid): array
    {
        $this->requireGroupMember($user, $groupUuid);

        return $this->reverbConnectionConfig();
    }

    /** @return array<string, mixed> */
    public function reverbConnectionConfig(): array
    {
        return [
            'channel_prefix' => 'private-group.',
            // Prefer API Sanctum auth — /broadcasting/auth is web/session oriented
            // and rejects mobile bearer tokens with HTTP 403.
            'auth_endpoint' => url('/api/v1/broadcasting/auth'),
            'reverb' => [
                'key' => config('broadcasting.connections.reverb.key'),
                'host' => config('broadcasting.connections.reverb.options.host'),
                'port' => (int) config('broadcasting.connections.reverb.options.port'),
                'scheme' => config('broadcasting.connections.reverb.options.scheme'),
            ],
        ];
    }

    public function requireGroupMember(User $user, string $groupUuid): Group
    {
        $group = Group::query()->where('uuid', $groupUuid)->first();

        if (! $group) {
            throw ValidationException::withMessages([
                'group_uuid' => ['Group not found.'],
            ]);
        }

        if (! $group->members()->where('user_id', $user->id)->exists()) {
            throw ValidationException::withMessages([
                'group_uuid' => ['You are not a member of this group.'],
            ]);
        }

        return $group;
    }

    public function isGroupMember(User $user, string $groupUuid): bool
    {
        return GroupMember::query()
            ->where('group_uuid', $groupUuid)
            ->where('user_id', $user->id)
            ->exists();
    }

    private function addMemberRecord(Group $group, User $user, string $role): GroupMember
    {
        return GroupMember::create([
            'group_uuid' => $group->uuid,
            'user_id' => $user->id,
            'role' => $role,
            'joined_at' => now(),
        ]);
    }

    private function assertCanManage(User $user, Group $group): void
    {
        if (! $this->canManage($user, $group)) {
            throw ValidationException::withMessages([
                'group' => ['Only group owner or admin can perform this action.'],
            ]);
        }
    }

    private function canManage(User $user, Group $group): bool
    {
        return $group->members()
            ->where('user_id', $user->id)
            ->whereIn('role', ['owner', 'admin'])
            ->exists();
    }

    /** @return array<string, mixed> */
    private function formatGroup(Group $group, ?User $viewer = null): array
    {
        $membership = $viewer
            ? $group->members->firstWhere('user_id', $viewer->id)
            : null;

        $lastMessage = Message::query()
            ->where('group_uuid', $group->uuid)
            ->with('sender:id,uuid,display_name')
            ->orderByDesc('created_at')
            ->orderByDesc('uuid')
            ->first();

        $unreadCount = 0;

        if ($membership) {
            $query = Message::query()
                ->where('group_uuid', $group->uuid)
                ->where('sender_user_id', '!=', $membership->user_id);

            if ($membership->last_read_at) {
                $query->where('created_at', '>', $membership->last_read_at);
            }

            $unreadCount = $query->count();
        }

        return [
            'uuid' => $group->uuid,
            'type' => $group->type ?? 'group',
            'name' => $group->name,
            'display_name' => $this->resolveDisplayName($group, $viewer),
            'description' => $group->description,
            'member_count' => $group->member_count,
            'other_member' => $this->resolveOtherMember($group, $viewer),
            'members' => $group->members->map(fn (GroupMember $member) => [
                'user_uuid' => $member->user->uuid,
                'display_name' => $member->user->display_name,
                'role' => $member->role,
                'joined_at' => $member->joined_at?->toIso8601String(),
            ])->values()->all(),
            'created_at' => $group->created_at?->toIso8601String(),
            'unread_count' => $unreadCount,
            'last_message' => $lastMessage ? [
                'uuid' => $lastMessage->uuid,
                'sender_display_name' => $lastMessage->sender->display_name,
                'type' => $lastMessage->type,
                'is_deleted' => $lastMessage->trashed(),
                'created_at' => $lastMessage->created_at?->toIso8601String(),
            ] : null,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function connectedContactsFor(User $user): array
    {
        $connections = Connection::query()
            ->where('status', 'connected')
            ->where(function ($query) use ($user) {
                $query->where('requester_user_id', $user->id)
                    ->orWhere('recipient_user_id', $user->id);
            })
            ->with([
                'requester:id,uuid,display_name',
                'recipient:id,uuid,display_name',
            ])
            ->get();

        $contacts = [];

        foreach ($connections as $connection) {
            $other = $connection->requester_user_id === $user->id
                ? $connection->recipient
                : $connection->requester;

            if (! $other) {
                continue;
            }

            $directKey = $this->directKey($user->id, $other->id);
            $directGroup = Group::query()
                ->where('type', 'direct')
                ->where('direct_key', $directKey)
                ->first();

            $contacts[] = [
                'user_uuid' => $other->uuid,
                'display_name' => $other->display_name,
                'direct_group_uuid' => $directGroup?->uuid,
            ];
        }

        usort($contacts, fn (array $a, array $b) => strcasecmp($a['display_name'], $b['display_name']));

        return $contacts;
    }

    private function directKey(int $userIdA, int $userIdB): string
    {
        [$lower, $higher] = $userIdA < $userIdB
            ? [$userIdA, $userIdB]
            : [$userIdB, $userIdA];

        return "{$lower}:{$higher}";
    }

    private function resolveDisplayName(Group $group, ?User $viewer): string
    {
        if (! $group->isDirect() || ! $viewer) {
            return $group->name;
        }

        $other = $this->resolveOtherMember($group, $viewer);

        return $other['display_name'] ?? $group->name;
    }

    /** @return array{user_uuid: string, display_name: string}|null */
    private function resolveOtherMember(Group $group, ?User $viewer): ?array
    {
        if (! $group->isDirect() || ! $viewer) {
            return null;
        }

        $otherMember = $group->members
            ->first(fn (GroupMember $member) => $member->user_id !== $viewer->id);

        if (! $otherMember?->user) {
            return null;
        }

        return [
            'user_uuid' => $otherMember->user->uuid,
            'display_name' => $otherMember->user->display_name,
        ];
    }

    private function broadcastGroupDeleted(string $groupUuid): void
    {
        broadcast(new GroupDeleted($groupUuid));
    }

    public function totalUnreadCountForUser(User $user): int
    {
        $memberships = GroupMember::query()
            ->where('user_id', $user->id)
            ->get(['group_uuid', 'user_id', 'last_read_at']);

        $total = 0;

        foreach ($memberships as $membership) {
            $query = Message::query()
                ->where('group_uuid', $membership->group_uuid)
                ->where('sender_user_id', '!=', $membership->user_id);

            if ($membership->last_read_at) {
                $query->where('created_at', '>', $membership->last_read_at);
            }

            $total += $query->count();
        }

        return $total;
    }

    /** @return Collection<int, User> */
    public function groupMemberUsersExcept(string $groupUuid, int $exceptUserId): Collection
    {
        return User::query()
            ->whereIn('id', GroupMember::query()
                ->where('group_uuid', $groupUuid)
                ->where('user_id', '!=', $exceptUserId)
                ->pluck('user_id'))
            ->get();
    }
}
