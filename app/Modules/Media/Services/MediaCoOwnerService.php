<?php

namespace App\Modules\Media\Services;

use App\Models\GroupMember;
use App\Models\MediaFile;
use App\Models\MediaLibraryItem;
use App\Models\MediaPermission;
use App\Models\User;
use App\Modules\StoragePlans\Services\StorageQuotaService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class MediaCoOwnerService
{
    public function __construct(
        private readonly MediaAccessService $accessService,
        private readonly StorageQuotaService $quotaService,
    ) {}

    public function isChatMedia(MediaFile $media): bool
    {
        $metadata = is_array($media->metadata) ? $media->metadata : [];

        return ($metadata['source'] ?? null) === 'chat';
    }

    /** @return array<string, mixed> */
    public function assignGroupChatCoOwners(User $uploader, string $mediaUuid, string $groupUuid): array
    {
        $media = $this->accessService->requireMedia($mediaUuid);
        $this->accessService->assertOwner($uploader, $media);

        if ($media->status !== 'active') {
            throw ValidationException::withMessages([
                'media' => ['Only active files can be shared in chat.'],
            ]);
        }

        if (! $this->isChatMedia($media)) {
            throw ValidationException::withMessages([
                'media' => ['Co-ownership assignment is only for chat attachments.'],
            ]);
        }

        $this->accessService->assertCanGrantToGroup($uploader, $groupUuid);

        $memberIds = GroupMember::query()
            ->where('group_uuid', $groupUuid)
            ->pluck('user_id')
            ->unique()
            ->values();

        return DB::transaction(function () use ($uploader, $media, $memberIds) {
            $assigned = 0;

            foreach ($memberIds as $memberId) {
                $this->ensureLibraryItem((int) $memberId, $media);

                if ((int) $memberId === (int) $uploader->id) {
                    continue;
                }

                MediaPermission::updateOrCreate(
                    [
                        'media_file_uuid' => $media->uuid,
                        'user_id' => (int) $memberId,
                        'group_uuid' => null,
                    ],
                    [
                        'access' => 'owner',
                        'granted_by_user_id' => $uploader->id,
                    ]
                );

                $assigned++;
            }

            return [
                'message' => 'Co-owners assigned.',
                'media_file_uuid' => $media->uuid,
                'co_owner_count' => $assigned + 1,
            ];
        });
    }

    public function chargeAccessQuotaIfNeeded(User $user, MediaFile $media): void
    {
        if (! $this->isChatMedia($media) || $media->status !== 'active') {
            return;
        }

        if ((int) $media->uploaded_by_user_id === (int) $user->id) {
            return;
        }

        $item = $this->ensureLibraryItem($user->id, $media);

        if ($item->quota_charged_at !== null) {
            return;
        }

        $this->quotaService->assertCanStore($user, (int) $media->size_bytes);
        $this->quotaService->addUsage($user, (int) $media->size_bytes);
        $item->update(['quota_charged_at' => now()]);
    }

    /** @return array<string, mixed> */
    public function removeFromUserLibrary(User $user, MediaFile $media): array
    {
        if (! $this->accessService->canView($user, $media)) {
            throw ValidationException::withMessages([
                'media' => ['You do not have permission to access this file.'],
            ]);
        }

        return DB::transaction(function () use ($user, $media) {
            $item = $this->ensureLibraryItem($user->id, $media);

            if ($item->removed_at === null) {
                if ($item->quota_charged_at !== null) {
                    $this->quotaService->removeUsage($user, (int) $media->size_bytes);
                }

                $item->update(['removed_at' => now()]);
            }

            if ($this->shouldPurgeStorage($media)) {
                $this->purgeStorage($media);

                return ['message' => 'File removed and deleted from storage.'];
            }

            return ['message' => 'Removed from your library.'];
        });
    }

    public function isRemovedForUser(User $user, MediaFile $media): bool
    {
        return MediaLibraryItem::query()
            ->where('media_file_uuid', $media->uuid)
            ->where('user_id', $user->id)
            ->whereNotNull('removed_at')
            ->exists();
    }

    /** @return list<int> */
    public function activeHolderUserIds(MediaFile $media): array
    {
        $holderIds = collect([$media->owner_user_id]);

        $coOwnerIds = MediaPermission::query()
            ->where('media_file_uuid', $media->uuid)
            ->where('access', 'owner')
            ->whereNotNull('user_id')
            ->pluck('user_id');

        return $holderIds
            ->merge($coOwnerIds)
            ->unique()
            ->filter(function (int $userId) use ($media) {
                return ! MediaLibraryItem::query()
                    ->where('media_file_uuid', $media->uuid)
                    ->where('user_id', $userId)
                    ->whereNotNull('removed_at')
                    ->exists();
            })
            ->values()
            ->all();
    }

    private function shouldPurgeStorage(MediaFile $media): bool
    {
        return $this->activeHolderUserIds($media) === [];
    }

    private function purgeStorage(MediaFile $media): void
    {
        if ($media->status === 'active') {
            Storage::disk((string) config('media.disk'))->delete($media->s3_key);
        }

        $media->update(['status' => 'deleted']);
        $media->delete();
    }

    private function ensureLibraryItem(int $userId, MediaFile $media): MediaLibraryItem
    {
        return MediaLibraryItem::query()->firstOrCreate(
            [
                'media_file_uuid' => $media->uuid,
                'user_id' => $userId,
            ],
            [
                'quota_charged_at' => null,
                'removed_at' => null,
            ]
        );
    }
}
