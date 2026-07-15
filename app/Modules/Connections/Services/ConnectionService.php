<?php

namespace App\Modules\Connections\Services;

use App\Models\Connection;
use App\Models\FamilyMember;
use App\Models\User;
use App\Modules\Connections\Events\ConnectionUpdated;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ConnectionService
{
    /** @return array<string, mixed> */
    public function suggestions(User $user): array
    {
        $member = $this->requireFamilyMember($user);

        $members = FamilyMember::query()
            ->where('family_uuid', $member->family_uuid)
            ->where('is_anonymous', false)
            ->whereNotNull('user_id')
            ->where('user_id', '!=', $user->id)
            ->with('user:id,uuid,display_name,is_anonymous')
            ->get()
            ->filter(fn (FamilyMember $m) => $m->user !== null && ! $m->user->is_anonymous)
            ->map(fn (FamilyMember $m) => $this->formatSuggestionMember($user, $m))
            ->values();

        return [
            'family_uuid' => $member->family_uuid,
            'is_anonymous' => (bool) $user->is_anonymous,
            'members' => $members->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function listConnections(User $user, ?string $status = null): array
    {
        $query = Connection::query()
            ->where(function ($q) use ($user) {
                $q->where('requester_user_id', $user->id)
                    ->orWhere('recipient_user_id', $user->id);
            })
            ->with(['requester:id,uuid,display_name', 'recipient:id,uuid,display_name']);

        if ($status) {
            $query->where('status', $status);
        }

        return [
            'connections' => $query->latest()->get()->map(
                fn (Connection $connection) => $this->formatConnection($connection, $user)
            )->values()->all(),
        ];
    }

    public function pendingReceivedCount(User $user): int
    {
        return Connection::query()
            ->where('recipient_user_id', $user->id)
            ->where('status', 'pending')
            ->count();
    }

    /** @return array<string, mixed> */
    public function sendRequest(User $user, string $targetUserUuid): array
    {
        $target = $this->resolveConnectableUser($user, $targetUserUuid);
        $connection = $this->createOrRefreshRequest($user, $target);
        $this->notifyParty($user, $target, $connection, 'request_sent');

        return $this->formatConnection($connection->load(['requester:id,uuid,display_name', 'recipient:id,uuid,display_name']), $user);
    }

    /**
     * @param  list<string>  $targetUserUuids
     * @return array<string, mixed>
     */
    public function sendBulkRequests(User $user, array $targetUserUuids): array
    {
        return $this->sendToMany($user, $targetUserUuids);
    }

    /** @return array<string, mixed> */
    public function connectAll(User $user): array
    {
        $member = $this->requireFamilyMember($user);

        $targetUuids = FamilyMember::query()
            ->where('family_uuid', $member->family_uuid)
            ->where('is_anonymous', false)
            ->whereNotNull('user_id')
            ->where('user_id', '!=', $user->id)
            ->with('user:id,uuid,is_anonymous')
            ->get()
            ->filter(fn (FamilyMember $m) => $m->user !== null && ! $m->user->is_anonymous)
            ->map(fn (FamilyMember $m) => $m->user->uuid)
            ->values()
            ->all();

        return $this->sendToMany($user, $targetUuids);
    }

    /** @return array<string, mixed> */
    public function accept(User $user, string $connectionUuid): array
    {
        $connection = $this->findOwnedConnection($user, $connectionUuid);

        if ($connection->recipient_user_id !== $user->id) {
            throw ValidationException::withMessages([
                'connection' => ['Only the recipient can accept this request.'],
            ]);
        }

        if ($connection->status !== 'pending') {
            throw ValidationException::withMessages([
                'connection' => ['Only pending requests can be accepted.'],
            ]);
        }

        $connection->update([
            'status' => 'connected',
            'connected_at' => now(),
        ]);

        $fresh = $connection->fresh(['requester:id,uuid,display_name', 'recipient:id,uuid,display_name']);
        $other = $fresh->requester_user_id === $user->id ? $fresh->recipient : $fresh->requester;
        $this->notifyParty($user, $other, $fresh, 'accepted');

        return $this->formatConnection($fresh, $user);
    }

    /** @return array<string, mixed> */
    public function reject(User $user, string $connectionUuid): array
    {
        $connection = $this->findOwnedConnection($user, $connectionUuid);

        if ($connection->recipient_user_id !== $user->id) {
            throw ValidationException::withMessages([
                'connection' => ['Only the recipient can reject this request.'],
            ]);
        }

        if ($connection->status !== 'pending') {
            throw ValidationException::withMessages([
                'connection' => ['Only pending requests can be rejected.'],
            ]);
        }

        $connection->update(['status' => 'rejected', 'connected_at' => null]);

        $fresh = $connection->fresh(['requester:id,uuid,display_name', 'recipient:id,uuid,display_name']);
        $other = $fresh->requester_user_id === $user->id ? $fresh->recipient : $fresh->requester;
        $this->notifyParty($user, $other, $fresh, 'rejected');

        return $this->formatConnection($fresh, $user);
    }

    /** @return array<string, mixed> */
    public function disconnect(User $user, string $connectionUuid): array
    {
        $connection = $this->findOwnedConnection($user, $connectionUuid);

        if ($connection->status !== 'connected') {
            throw ValidationException::withMessages([
                'connection' => ['Only connected users can be disconnected.'],
            ]);
        }

        $connection->update(['status' => 'disconnected', 'connected_at' => null]);

        $fresh = $connection->fresh(['requester:id,uuid,display_name', 'recipient:id,uuid,display_name']);
        $other = $fresh->requester_user_id === $user->id ? $fresh->recipient : $fresh->requester;
        $this->notifyParty($user, $other, $fresh, 'disconnected');

        return $this->formatConnection($fresh, $user);
    }

    /** @return array<string, mixed> */
    public function block(User $user, string $connectionUuid): array
    {
        $connection = $this->findOwnedConnection($user, $connectionUuid);

        if ($connection->status === 'blocked') {
            throw ValidationException::withMessages([
                'connection' => ['User is already blocked.'],
            ]);
        }

        $connection->update(['status' => 'blocked', 'connected_at' => null]);

        $fresh = $connection->fresh(['requester:id,uuid,display_name', 'recipient:id,uuid,display_name']);
        $other = $fresh->requester_user_id === $user->id ? $fresh->recipient : $fresh->requester;
        $this->notifyParty($user, $other, $fresh, 'blocked');

        return $this->formatConnection($fresh, $user);
    }

    /** @param  list<string>  $targetUserUuids */
    private function sendToMany(User $user, array $targetUserUuids): array
    {
        $created = 0;
        $skipped = 0;
        $connections = [];

        foreach (array_unique($targetUserUuids) as $targetUserUuid) {
            try {
                $target = $this->resolveConnectableUser($user, $targetUserUuid);
                $existing = $this->findBetweenUsers($user->id, $target->id);

                if ($existing && in_array($existing->status, ['pending', 'connected', 'blocked'], true)) {
                    $skipped++;

                    continue;
                }

                $connection = $this->createOrRefreshRequest($user, $target);
                $this->notifyParty($user, $target, $connection, 'request_sent');
                $created++;
                $connections[] = $this->formatConnection(
                    $connection->load(['requester:id,uuid,display_name', 'recipient:id,uuid,display_name']),
                    $user,
                );
            } catch (ValidationException) {
                $skipped++;
            }
        }

        return [
            'created' => $created,
            'skipped' => $skipped,
            'connections' => $connections,
        ];
    }

    private function createOrRefreshRequest(User $requester, User $recipient): Connection
    {
        if ($requester->id === $recipient->id) {
            throw ValidationException::withMessages([
                'user_uuid' => ['You cannot connect with yourself.'],
            ]);
        }

        $existing = $this->findBetweenUsers($requester->id, $recipient->id);

        if ($existing) {
            if (in_array($existing->status, ['pending', 'connected'], true)) {
                throw ValidationException::withMessages([
                    'user_uuid' => ['A connection already exists with this user.'],
                ]);
            }

            if ($existing->status === 'blocked') {
                throw ValidationException::withMessages([
                    'user_uuid' => ['This user is blocked.'],
                ]);
            }

            $existing->update([
                'requester_user_id' => $requester->id,
                'recipient_user_id' => $recipient->id,
                'status' => 'pending',
                'connected_at' => null,
            ]);

            return $existing->fresh();
        }

        return Connection::create([
            'uuid' => (string) Str::uuid(),
            'requester_user_id' => $requester->id,
            'recipient_user_id' => $recipient->id,
            'status' => 'pending',
        ]);
    }

    private function notifyParty(User $actor, User $notifyUser, Connection $connection, string $action): void
    {
        if ($actor->id === $notifyUser->id) {
            return;
        }

        event(new ConnectionUpdated(
            actor: $actor,
            notifyUser: $notifyUser,
            connection: $connection,
            action: $action,
        ));
    }

    private function resolveConnectableUser(User $user, string $targetUserUuid): User
    {
        $member = $this->requireFamilyMember($user);

        $targetMember = FamilyMember::query()
            ->where('family_uuid', $member->family_uuid)
            ->where('is_anonymous', false)
            ->whereHas('user', fn ($q) => $q->where('uuid', $targetUserUuid)->where('is_anonymous', false))
            ->with('user')
            ->first();

        if (! $targetMember?->user) {
            throw ValidationException::withMessages([
                'user_uuid' => ['User is not an active member of your family.'],
            ]);
        }

        return $targetMember->user;
    }

    private function requireFamilyMember(User $user): FamilyMember
    {
        $member = FamilyMember::query()->where('user_id', $user->id)->first();

        if (! $member) {
            throw ValidationException::withMessages([
                'family' => ['Complete onboarding and confirm your family before connecting with members.'],
            ]);
        }

        return $member;
    }

    private function findOwnedConnection(User $user, string $connectionUuid): Connection
    {
        $connection = Connection::query()
            ->where('uuid', $connectionUuid)
            ->where(function ($q) use ($user) {
                $q->where('requester_user_id', $user->id)
                    ->orWhere('recipient_user_id', $user->id);
            })
            ->first();

        if (! $connection) {
            throw ValidationException::withMessages([
                'connection' => ['Connection not found.'],
            ]);
        }

        return $connection;
    }

    private function findBetweenUsers(int $userAId, int $userBId): ?Connection
    {
        return Connection::query()
            ->where(function ($q) use ($userAId, $userBId) {
                $q->where('requester_user_id', $userAId)->where('recipient_user_id', $userBId)
                    ->orWhere('requester_user_id', $userBId)->where('recipient_user_id', $userAId);
            })
            ->first();
    }

    /** @return array<string, mixed> */
    private function formatSuggestionMember(User $user, FamilyMember $member): array
    {
        $connection = $this->findBetweenUsers($user->id, $member->user_id);

        return [
            'user_uuid' => $member->user->uuid,
            'display_name' => $member->user->display_name,
            'member_uuid' => $member->uuid,
            'connection_uuid' => $connection?->uuid,
            'connection_status' => $this->perspectiveStatus($connection, $user),
        ];
    }

    /** @return array<string, mixed> */
    private function formatConnection(Connection $connection, User $viewer): array
    {
        $isRequester = $connection->requester_user_id === $viewer->id;
        $other = $isRequester ? $connection->recipient : $connection->requester;

        return [
            'uuid' => $connection->uuid,
            'status' => $connection->status,
            'connected_at' => $connection->connected_at?->toIso8601String(),
            'direction' => $isRequester ? 'sent' : 'received',
            'connection_status' => $this->perspectiveStatus($connection, $viewer),
            'other_user' => [
                'uuid' => $other->uuid,
                'display_name' => $other->display_name,
            ],
        ];
    }

    private function perspectiveStatus(?Connection $connection, User $viewer): ?string
    {
        if (! $connection) {
            return null;
        }

        if ($connection->status === 'pending') {
            return $connection->requester_user_id === $viewer->id ? 'pending_sent' : 'pending_received';
        }

        return $connection->status;
    }
}
