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

    /**
     * Recent share activity for the home feed (incoming + outgoing).
     *
     * @return array{items: list<array<string, mixed>>, days: int}
     */
    public function recentActivityForUser(User $user, int $days = 2, int $limit = 20): array
    {
        $days = max(1, min(14, $days));
        $limit = max(1, min(50, $limit));
        $since = now()->subDays($days);

        $incoming = MediaPermission::query()
            ->with([
                'mediaFile.event:uuid,title',
                'grantedBy:id,uuid,display_name',
                'group:uuid,name,type',
            ])
            ->where('user_id', $user->id)
            ->where('granted_by_user_id', '!=', $user->id)
            ->where('created_at', '>=', $since)
            ->whereHas('mediaFile', fn ($query) => $query->where('status', 'active'))
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        $outgoing = MediaPermission::query()
            ->with([
                'mediaFile.event:uuid,title',
                'user:id,uuid,display_name',
                'group:uuid,name,type',
            ])
            ->where('granted_by_user_id', $user->id)
            ->where(function ($query) use ($user) {
                $query->where(function ($direct) use ($user) {
                    $direct->whereNull('group_uuid')
                        ->whereNotNull('user_id')
                        ->where('user_id', '!=', $user->id);
                })->orWhereNotNull('group_uuid');
            })
            ->where('created_at', '>=', $since)
            ->whereHas('mediaFile', fn ($query) => $query->where('status', 'active'))
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        $items = $incoming
            ->map(fn (MediaPermission $permission) => $this->formatActivityItem(
                permission: $permission,
                viewer: $user,
                direction: 'incoming',
            ))
            ->concat($outgoing->map(fn (MediaPermission $permission) => $this->formatActivityItem(
                permission: $permission,
                viewer: $user,
                direction: 'outgoing',
            )))
            ->filter()
            ->sortByDesc(fn (array $item) => $item['created_at'])
            ->values()
            ->take($limit)
            ->values()
            ->all();

        return [
            'days' => $days,
            'items' => $items,
        ];
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

    /**
     * @return array<string, mixed>|null
     */
    private function formatActivityItem(
        MediaPermission $permission,
        User $viewer,
        string $direction,
    ): ?array {
        $media = $permission->mediaFile;
        if ($media === null || $media->status !== 'active') {
            return null;
        }

        $metadata = is_array($media->metadata) ? $media->metadata : [];
        $source = (string) ($metadata['source'] ?? 'gallery');
        $isChat = $source === 'chat' || $permission->group_uuid !== null;
        $kind = $this->mediaKindLabel($media->mime_type, $metadata, $media->display_name);
        $eventTitle = $media->event?->title;
        $groupName = $permission->group?->name;

        if ($direction === 'incoming') {
            $actorName = $permission->grantedBy?->display_name ?: 'Someone';
            $actorUuid = $permission->grantedBy?->uuid;
            $counterpartName = $actorName;
            $counterpartUuid = $actorUuid;
            $summary = $this->incomingSummary(
                actorName: $actorName,
                kind: $kind,
                isChat: $isChat,
                groupName: $groupName,
                eventTitle: $eventTitle,
            );
        } else {
            $targetName = $permission->user?->display_name
                ?: ($groupName ?: 'family');
            $counterpartName = $targetName;
            $counterpartUuid = $permission->user?->uuid;
            $actorName = 'You';
            $actorUuid = $viewer->uuid;
            $summary = $this->outgoingSummary(
                targetName: $targetName,
                kind: $kind,
                isChat: $isChat,
                groupName: $groupName,
                eventTitle: $eventTitle,
            );
        }

        return [
            'id' => (string) $permission->id,
            'direction' => $direction,
            'summary' => $summary,
            'media_kind' => $kind,
            'media_uuid' => $media->uuid,
            'media_display_name' => $media->display_name,
            'has_thumbnail' => $media->hasThumbnail(),
            'is_chat' => $isChat,
            'group_uuid' => $permission->group_uuid,
            'group_name' => $groupName,
            'event_uuid' => $media->media_event_uuid,
            'event_title' => $eventTitle,
            'actor_name' => $actorName,
            'actor_uuid' => $actorUuid,
            'counterpart_name' => $counterpartName,
            'counterpart_uuid' => $counterpartUuid,
            'created_at' => optional($permission->created_at)?->toIso8601String(),
        ];
    }

    private function incomingSummary(
        string $actorName,
        string $kind,
        bool $isChat,
        ?string $groupName,
        ?string $eventTitle,
    ): string {
        if ($eventTitle !== null && trim($eventTitle) !== '') {
            return "{$actorName} added a {$kind} to {$eventTitle}";
        }

        if ($isChat) {
            $where = ($groupName !== null && trim($groupName) !== '')
                ? $groupName
                : 'chat';

            return "{$actorName} shared a {$kind} in {$where}";
        }

        return "{$actorName} shared a {$kind} with you";
    }

    private function outgoingSummary(
        string $targetName,
        string $kind,
        bool $isChat,
        ?string $groupName,
        ?string $eventTitle,
    ): string {
        if ($eventTitle !== null && trim($eventTitle) !== '') {
            return "You added a {$kind} to {$eventTitle}";
        }

        if ($isChat) {
            $where = ($groupName !== null && trim($groupName) !== '')
                ? $groupName
                : $targetName;

            return "You shared a {$kind} in {$where}";
        }

        return "You shared a {$kind} to {$targetName}";
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function mediaKindLabel(?string $mimeType, array $metadata, ?string $displayName): string
    {
        $mime = strtolower((string) ($metadata['original_mime_type'] ?? $mimeType ?? ''));
        $name = strtolower((string) $displayName);

        if (str_starts_with($mime, 'image/') || preg_match('/\.(jpe?g|png|gif|webp|heic)$/', $name) === 1) {
            return $mime === 'image/gif' || str_ends_with($name, '.gif') ? 'GIF' : 'photo';
        }

        if (str_starts_with($mime, 'video/') || preg_match('/\.(mp4|mov|m4v|webm|mkv)$/', $name) === 1) {
            return 'video';
        }

        if (str_starts_with($mime, 'audio/') || preg_match('/\.(m4a|aac|mp3|wav)$/', $name) === 1) {
            return 'voice note';
        }

        return 'file';
    }
}
