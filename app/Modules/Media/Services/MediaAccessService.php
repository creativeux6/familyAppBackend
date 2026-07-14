<?php

namespace App\Modules\Media\Services;

use App\Models\GroupMember;
use App\Models\MediaFile;
use App\Models\MediaLibraryItem;
use App\Models\MediaPermission;
use App\Models\User;
use App\Modules\Groups\Services\ConnectedMemberGuard;
use App\Modules\Groups\Services\GroupService;
use Illuminate\Validation\ValidationException;

class MediaAccessService
{
    public function __construct(
        private readonly ConnectedMemberGuard $connectedMemberGuard,
        private readonly GroupService $groupService,
    ) {}

    public function isOwner(User $user, MediaFile $media): bool
    {
        return $media->owner_user_id === $user->id;
    }

    public function canView(User $user, MediaFile $media): bool
    {
        if ($this->isRemovedForUser($user, $media)) {
            return false;
        }

        if ($this->isOwner($user, $media)) {
            return true;
        }

        if ($media->status !== 'active') {
            return false;
        }

        $direct = MediaPermission::query()
            ->where('media_file_uuid', $media->uuid)
            ->where('user_id', $user->id)
            ->exists();

        if ($direct) {
            return true;
        }

        $groupUuids = GroupMember::query()
            ->where('user_id', $user->id)
            ->pluck('group_uuid');

        return MediaPermission::query()
            ->where('media_file_uuid', $media->uuid)
            ->whereIn('group_uuid', $groupUuids)
            ->exists();
    }

    public function isCoOwner(User $user, MediaFile $media): bool
    {
        if ($this->isOwner($user, $media)) {
            return true;
        }

        return MediaPermission::query()
            ->where('media_file_uuid', $media->uuid)
            ->where('user_id', $user->id)
            ->where('access', 'owner')
            ->exists();
    }

    private function isRemovedForUser(User $user, MediaFile $media): bool
    {
        return MediaLibraryItem::query()
            ->where('media_file_uuid', $media->uuid)
            ->where('user_id', $user->id)
            ->whereNotNull('removed_at')
            ->exists();
    }

    public function assertCanShare(User $user, MediaFile $media): void
    {
        if (! $this->isCoOwner($user, $media)) {
            throw ValidationException::withMessages([
                'media' => ['Only the file owner can share this file.'],
            ]);
        }
    }

    public function assertOwner(User $user, MediaFile $media): void
    {
        if (! $this->isOwner($user, $media)) {
            throw ValidationException::withMessages([
                'media' => ['Only the file owner can perform this action.'],
            ]);
        }
    }

    public function assertCanView(User $user, MediaFile $media): void
    {
        if (! $this->canView($user, $media)) {
            throw ValidationException::withMessages([
                'media' => ['You do not have permission to access this file.'],
            ]);
        }
    }

    public function requireMedia(string $uuid): MediaFile
    {
        $media = MediaFile::query()->where('uuid', $uuid)->first();

        if (! $media) {
            throw ValidationException::withMessages([
                'media_uuid' => ['Media file not found.'],
            ]);
        }

        return $media;
    }

    public function assertCanGrantToUser(User $owner, User $recipient): void
    {
        if ($owner->id === $recipient->id) {
            return;
        }

        if (! $this->connectedMemberGuard->areConnected($owner, $recipient)) {
            throw ValidationException::withMessages([
                'user_uuid' => ['You can only share with connected family members.'],
            ]);
        }
    }

    public function assertCanGrantToGroup(User $owner, string $groupUuid): void
    {
        $this->groupService->requireGroupMember($owner, $groupUuid);
    }
}
