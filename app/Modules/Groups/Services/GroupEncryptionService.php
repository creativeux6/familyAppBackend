<?php

namespace App\Modules\Groups\Services;

use App\Models\GroupEncryptionGeneration;
use App\Models\GroupKeyEnvelope;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GroupEncryptionService
{
    public function __construct(
        private readonly GroupService $groupService,
    ) {}

    /** @param  list<array{recipient_user_uuid: string, wrapped_group_key: string}>  $envelopes */
    public function storeEnvelopes(User $user, string $groupUuid, int $generation, array $envelopes, int $version = 1): array
    {
        $group = $this->groupService->requireGroupMember($user, $groupUuid);
        $this->assertCanManageEncryption($user, $group->uuid);

        $generationRecord = GroupEncryptionGeneration::query()
            ->where('group_uuid', $group->uuid)
            ->where('generation', $generation)
            ->first();

        if (! $generationRecord) {
            throw ValidationException::withMessages([
                'generation' => ['Encryption generation not found for this group.'],
            ]);
        }

        $stored = 0;

        DB::transaction(function () use ($group, $generation, $envelopes, $version, &$stored) {
            foreach ($envelopes as $envelope) {
                $recipient = User::query()->where('uuid', $envelope['recipient_user_uuid'])->first();

                if (! $recipient || ! $group->members()->where('user_id', $recipient->id)->exists()) {
                    throw ValidationException::withMessages([
                        'envelopes' => ['Each recipient must be a group member.'],
                    ]);
                }

                $binary = base64_decode($envelope['wrapped_group_key'], true);

                if ($binary === false || $binary === '') {
                    throw ValidationException::withMessages([
                        'envelopes' => ['Invalid wrapped_group_key encoding.'],
                    ]);
                }

                GroupKeyEnvelope::updateOrCreate(
                    [
                        'group_uuid' => $group->uuid,
                        'generation' => $generation,
                        'recipient_user_id' => $recipient->id,
                    ],
                    [
                        'wrapped_group_key' => $binary,
                        'encryption_version' => $version,
                    ]
                );

                $stored++;
            }
        });

        return [
            'message' => 'Group key envelopes stored.',
            'generation' => $generation,
            'stored' => $stored,
        ];
    }

    public function myEnvelope(User $user, string $groupUuid, ?int $generation = null): array
    {
        $group = $this->groupService->requireGroupMember($user, $groupUuid);

        $generation ??= GroupEncryptionGeneration::query()
            ->where('group_uuid', $group->uuid)
            ->max('generation');

        $envelope = GroupKeyEnvelope::query()
            ->where('group_uuid', $group->uuid)
            ->where('generation', $generation)
            ->where('recipient_user_id', $user->id)
            ->first();

        if (! $envelope) {
            throw ValidationException::withMessages([
                'generation' => ['No group key envelope found for you in this generation.'],
            ]);
        }

        return [
            'group_uuid' => $group->uuid,
            'generation' => $envelope->generation,
            'wrapped_group_key' => base64_encode($envelope->wrapped_group_key),
            'encryption_version' => $envelope->encryption_version,
        ];
    }

    private function assertCanManageEncryption(User $user, string $groupUuid): void
    {
        $canManage = \App\Models\GroupMember::query()
            ->where('group_uuid', $groupUuid)
            ->where('user_id', $user->id)
            ->whereIn('role', ['owner', 'admin'])
            ->exists();

        if (! $canManage) {
            throw ValidationException::withMessages([
                'group' => ['Only group owner or admin can manage encryption envelopes.'],
            ]);
        }
    }
}
