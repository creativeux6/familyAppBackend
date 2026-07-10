<?php

namespace App\Modules\Media\Services;

use App\Models\MediaKeyEnvelope;
use App\Models\MediaPermission;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class MediaPermissionService
{
    public function __construct(
        private readonly MediaAccessService $accessService,
    ) {}

    public function grantToUser(User $owner, string $mediaUuid, string $targetUserUuid, string $access = 'view'): array
    {
        $media = $this->accessService->requireMedia($mediaUuid);
        $this->accessService->assertOwner($owner, $media);

        if ($media->status !== 'active') {
            throw ValidationException::withMessages([
                'media' => ['Only active files can be shared.'],
            ]);
        }

        $recipient = User::query()->where('uuid', $targetUserUuid)->firstOrFail();
        $this->accessService->assertCanGrantToUser($owner, $recipient);

        $permission = MediaPermission::updateOrCreate(
            [
                'media_file_uuid' => $media->uuid,
                'user_id' => $recipient->id,
                'group_uuid' => null,
            ],
            [
                'access' => $access,
                'granted_by_user_id' => $owner->id,
            ]
        );

        return $this->formatPermission($permission->load('user:id,uuid,display_name'));
    }

    public function grantToGroup(User $owner, string $mediaUuid, string $groupUuid, string $access = 'view'): array
    {
        $media = $this->accessService->requireMedia($mediaUuid);
        $this->accessService->assertOwner($owner, $media);
        $this->accessService->assertCanGrantToGroup($owner, $groupUuid);

        if ($media->status !== 'active') {
            throw ValidationException::withMessages([
                'media' => ['Only active files can be shared.'],
            ]);
        }

        $permission = MediaPermission::updateOrCreate(
            [
                'media_file_uuid' => $media->uuid,
                'user_id' => null,
                'group_uuid' => $groupUuid,
            ],
            [
                'access' => $access,
                'granted_by_user_id' => $owner->id,
            ]
        );

        return $this->formatPermission($permission);
    }

    public function revoke(User $owner, string $mediaUuid, int $permissionId): array
    {
        $media = $this->accessService->requireMedia($mediaUuid);
        $this->accessService->assertOwner($owner, $media);

        $permission = MediaPermission::query()
            ->where('id', $permissionId)
            ->where('media_file_uuid', $media->uuid)
            ->first();

        if (! $permission) {
            throw ValidationException::withMessages([
                'permission' => ['Permission not found.'],
            ]);
        }

        $permission->delete();

        return ['message' => 'Permission revoked.'];
    }

    /** @param  list<array{recipient_user_uuid: string, wrapped_content_key: string}>  $envelopes */
    public function storeEnvelopes(User $owner, string $mediaUuid, array $envelopes, int $version = 1): array
    {
        $media = $this->accessService->requireMedia($mediaUuid);
        $this->accessService->assertOwner($owner, $media);

        $stored = 0;

        foreach ($envelopes as $envelope) {
            $recipient = User::query()->where('uuid', $envelope['recipient_user_uuid'])->first();

            if (! $recipient) {
                throw ValidationException::withMessages([
                    'envelopes' => ['Recipient not found.'],
                ]);
            }

            $this->accessService->assertCanGrantToUser($owner, $recipient);

            $binary = base64_decode($envelope['wrapped_content_key'], true);

            if ($binary === false || $binary === '') {
                throw ValidationException::withMessages([
                    'envelopes' => ['Invalid wrapped_content_key encoding.'],
                ]);
            }

            MediaKeyEnvelope::updateOrCreate(
                [
                    'media_file_uuid' => $media->uuid,
                    'recipient_user_id' => $recipient->id,
                ],
                [
                    'wrapped_content_key' => $binary,
                    'encryption_version' => $version,
                ]
            );

            $stored++;
        }

        return ['message' => 'Key envelopes stored.', 'stored' => $stored];
    }

    public function myEnvelope(User $user, string $mediaUuid): array
    {
        $media = $this->accessService->requireMedia($mediaUuid);
        $this->accessService->assertCanView($user, $media);

        $envelope = MediaKeyEnvelope::query()
            ->where('media_file_uuid', $media->uuid)
            ->where('recipient_user_id', $user->id)
            ->first();

        if (! $envelope) {
            throw ValidationException::withMessages([
                'media' => ['No key envelope found for you on this file.'],
            ]);
        }

        return [
            'media_file_uuid' => $media->uuid,
            'wrapped_content_key' => base64_encode($envelope->wrapped_content_key),
            'encryption_version' => $envelope->encryption_version,
        ];
    }

    /** @return array<string, mixed> */
    private function formatPermission(MediaPermission $permission): array
    {
        return [
            'id' => $permission->id,
            'media_file_uuid' => $permission->media_file_uuid,
            'user_uuid' => $permission->user?->uuid,
            'group_uuid' => $permission->group_uuid,
            'access' => $permission->access,
        ];
    }
}
