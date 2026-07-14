<?php

namespace App\Modules\Media\Services;

use App\Models\MediaPermission;
use App\Models\User;

class MediaShareInboxService
{
    /** Unseen direct shares (excludes chat-sourced attachments). */
    public function unreadCountForUser(User $user): int
    {
        return $this->unseenPermissionsQuery($user)->count();
    }

    /**
     * @param  list<string>|null  $mediaUuids
     * @return array{marked: int, unread_count: int}
     */
    public function markSeen(User $user, ?array $mediaUuids = null): array
    {
        $query = MediaPermission::query()
            ->where('user_id', $user->id)
            ->whereNull('group_uuid')
            ->whereNull('seen_at');

        if ($mediaUuids !== null && $mediaUuids !== []) {
            $query->whereIn('media_file_uuid', $mediaUuids);
        }

        $marked = $query->update(['seen_at' => now()]);

        return [
            'marked' => $marked,
            'unread_count' => $this->unreadCountForUser($user),
        ];
    }

    public function isUnseenForUser(User $user, string $mediaUuid): bool
    {
        return MediaPermission::query()
            ->where('user_id', $user->id)
            ->where('media_file_uuid', $mediaUuid)
            ->whereNull('group_uuid')
            ->whereNull('seen_at')
            ->exists();
    }

    /** @return \Illuminate\Database\Eloquent\Builder<MediaPermission> */
    private function unseenPermissionsQuery(User $user)
    {
        return MediaPermission::query()
            ->where('user_id', $user->id)
            ->whereNull('group_uuid')
            ->whereNull('seen_at')
            ->whereHas('mediaFile', function ($query) {
                $query->where('status', 'active')
                    ->where(function ($inner) {
                        $inner->whereNull('metadata->source')
                            ->orWhere('metadata->source', '!=', 'chat');
                    });
            });
    }
}
