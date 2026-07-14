<?php

namespace App\Modules\Media\Services;

use App\Models\MediaEvent;
use App\Models\MediaEventShare;
use App\Models\MediaFile;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MediaEventService
{
    public function __construct(
        private readonly MediaAccessService $accessService,
    ) {}

    /** @return list<array<string, mixed>> */
    public function listForUser(User $user, ?string $scope = null): array
    {
        $scopeFilter = is_string($scope) && in_array($scope, [MediaEvent::SCOPE_PRIVATE, MediaEvent::SCOPE_GALLERY], true)
            ? $scope
            : null;

        $owned = MediaEvent::query()
            ->where('owner_user_id', $user->id)
            ->when($scopeFilter !== null, fn ($query) => $query->where('scope', $scopeFilter))
            ->withCount([
                'files as file_count' => fn ($query) => $query
                    ->whereIn('status', ['pending_upload', 'active']),
            ])
            ->orderByDesc('event_date')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (MediaEvent $event) => $this->formatEvent($event, isOwned: true))
            ->values();

        $shared = MediaEventShare::query()
            ->where('recipient_user_id', $user->id)
            ->with([
                'event' => fn ($query) => $query->withCount([
                    'files as file_count' => fn ($fileQuery) => $fileQuery
                        ->whereIn('status', ['pending_upload', 'active']),
                ]),
                'sharedBy:id,uuid,display_name',
                'event.owner:id,uuid,display_name',
            ])
            ->whereHas('event', function ($query) use ($scopeFilter) {
                if ($scopeFilter !== null) {
                    $query->where('scope', $scopeFilter);
                }
            })
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (MediaEventShare $share) => $this->formatSharedEvent($share))
            ->values();

        return $owned
            ->concat($shared)
            ->sortByDesc(fn (array $event) => $event['created_at'] ?? '')
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    public function registerShare(
        User $user,
        string $eventUuid,
        string $recipientUserUuid,
        string $access = 'view',
    ): array {
        $event = $this->requireOwnedEvent($user, $eventUuid);
        $recipient = User::query()->where('uuid', $recipientUserUuid)->firstOrFail();

        if ($recipient->id === $user->id) {
            throw ValidationException::withMessages([
                'recipient_user_uuid' => ['You cannot share an event with yourself.'],
            ]);
        }

        if ($event->scope !== MediaEvent::SCOPE_GALLERY) {
            throw ValidationException::withMessages([
                'event' => ['Only gallery event folders can be shared.'],
            ]);
        }

        if (! in_array($access, ['view', 'owner'], true)) {
            $access = 'view';
        }

        $share = MediaEventShare::query()
            ->where('media_event_uuid', $event->uuid)
            ->where('recipient_user_id', $recipient->id)
            ->first();

        if ($share === null) {
            $share = MediaEventShare::create([
                'uuid' => (string) Str::uuid(),
                'media_event_uuid' => $event->uuid,
                'recipient_user_id' => $recipient->id,
                'shared_by_user_id' => $user->id,
                'access' => $access,
                'seen_at' => null,
            ]);
        } else {
            $share->update([
                'shared_by_user_id' => $user->id,
                'access' => $access,
                'seen_at' => null,
            ]);
        }

        return $this->formatSharedEvent(
            $share->fresh([
                'event',
                'sharedBy:id,uuid,display_name',
                'event.owner:id,uuid,display_name',
            ])
        );
    }

    /** @return array<string, mixed> */
    public function renameShareAlias(User $user, string $eventUuid, string $aliasTitle): array
    {
        $share = MediaEventShare::query()
            ->where('media_event_uuid', $eventUuid)
            ->where('recipient_user_id', $user->id)
            ->with([
                'event' => fn ($query) => $query->withCount([
                    'files as file_count' => fn ($fileQuery) => $fileQuery
                        ->whereIn('status', ['pending_upload', 'active']),
                ]),
                'sharedBy:id,uuid,display_name',
                'event.owner:id,uuid,display_name',
            ])
            ->first();

        if (! $share) {
            throw ValidationException::withMessages([
                'event' => ['Shared event folder not found.'],
            ]);
        }

        $share->update([
            'alias_title' => trim($aliasTitle),
        ]);

        return $this->formatSharedEvent($share->fresh());
    }

    public function requireAccessibleEvent(User $user, string $uuid): MediaEvent
    {
        $event = MediaEvent::query()->where('uuid', $uuid)->first();

        if (! $event) {
            throw ValidationException::withMessages([
                'media_event_uuid' => ['Event not found.'],
            ]);
        }

        if ($event->owner_user_id === $user->id) {
            return $event;
        }

        $hasShare = MediaEventShare::query()
            ->where('media_event_uuid', $uuid)
            ->where('recipient_user_id', $user->id)
            ->exists();

        if (! $hasShare) {
            throw ValidationException::withMessages([
                'media_event_uuid' => ['Event not found.'],
            ]);
        }

        return $event;
    }

    /** @return list<string> */
    public function accessibleEventUuidsForUser(User $user, ?string $scope = null): array
    {
        $scopeFilter = is_string($scope) && in_array($scope, [MediaEvent::SCOPE_PRIVATE, MediaEvent::SCOPE_GALLERY], true)
            ? $scope
            : null;

        $owned = MediaEvent::query()
            ->where('owner_user_id', $user->id)
            ->when($scopeFilter !== null, fn ($query) => $query->where('scope', $scopeFilter))
            ->pluck('uuid');

        $shared = MediaEventShare::query()
            ->where('recipient_user_id', $user->id)
            ->whereHas('event', function ($query) use ($scopeFilter) {
                if ($scopeFilter !== null) {
                    $query->where('scope', $scopeFilter);
                }
            })
            ->pluck('media_event_uuid');

        return $owned->merge($shared)->unique()->values()->all();
    }

    /** @param  array<string, mixed>  $data */
    public function create(User $user, array $data): array
    {
        $scope = $data['scope'] ?? MediaEvent::SCOPE_PRIVATE;
        if (! in_array($scope, [MediaEvent::SCOPE_PRIVATE, MediaEvent::SCOPE_GALLERY], true)) {
            $scope = MediaEvent::SCOPE_PRIVATE;
        }

        $event = MediaEvent::create([
            'uuid' => (string) Str::uuid(),
            'owner_user_id' => $user->id,
            'scope' => $scope,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'event_date' => $data['event_date'] ?? null,
            'location' => $data['location'] ?? null,
            'event_type' => $data['event_type'] ?? MediaEvent::TYPE_GENERAL,
            'status' => $data['status'] ?? MediaEvent::STATUS_DRAFT,
            'starts_at' => $data['starts_at'] ?? null,
            'ends_at' => $data['ends_at'] ?? null,
            'timezone' => $data['timezone'] ?? null,
            'currency' => $data['currency'] ?? 'USD',
            'management_enabled' => false,
            'management_meta' => null,
            'notes' => $data['notes'] ?? null,
        ]);

        return $this->formatEvent($event->fresh());
    }

    public function show(User $user, string $uuid): array
    {
        $event = $this->requireAccessibleEvent($user, $uuid);

        if ($event->owner_user_id === $user->id) {
            return $this->formatEvent(
                $event->loadCount([
                    'files as file_count' => fn ($query) => $query
                        ->whereIn('status', ['pending_upload', 'active']),
                ]),
                isOwned: true,
            );
        }

        $share = MediaEventShare::query()
            ->where('media_event_uuid', $uuid)
            ->where('recipient_user_id', $user->id)
            ->with([
                'event' => fn ($query) => $query->withCount([
                    'files as file_count' => fn ($fileQuery) => $fileQuery
                        ->whereIn('status', ['pending_upload', 'active']),
                ]),
                'sharedBy:id,uuid,display_name',
                'event.owner:id,uuid,display_name',
            ])
            ->firstOrFail();

        return $this->formatSharedEvent($share);
    }

    /** @param  array<string, mixed>  $data */
    public function update(User $user, string $uuid, array $data): array
    {
        $event = $this->requireOwnedEvent($user, $uuid);

        $event->update([
            'title' => $data['title'] ?? $event->title,
            'description' => array_key_exists('description', $data)
                ? $data['description']
                : $event->description,
            'event_date' => array_key_exists('event_date', $data)
                ? $data['event_date']
                : $event->event_date,
            'location' => array_key_exists('location', $data)
                ? $data['location']
                : $event->location,
            'event_type' => $data['event_type'] ?? $event->event_type,
            'status' => $data['status'] ?? $event->status,
            'starts_at' => array_key_exists('starts_at', $data)
                ? $data['starts_at']
                : $event->starts_at,
            'ends_at' => array_key_exists('ends_at', $data)
                ? $data['ends_at']
                : $event->ends_at,
            'timezone' => array_key_exists('timezone', $data)
                ? $data['timezone']
                : $event->timezone,
            'currency' => $data['currency'] ?? $event->currency,
            'notes' => array_key_exists('notes', $data)
                ? $data['notes']
                : $event->notes,
        ]);

        return $this->formatEvent($event->fresh());
    }

    public function delete(User $user, string $uuid): array
    {
        $event = $this->requireOwnedEvent($user, $uuid);

        MediaFile::query()
            ->where('media_event_uuid', $event->uuid)
            ->update(['media_event_uuid' => null]);

        $event->delete();

        return ['message' => 'Event deleted. Files were moved to General.'];
    }

    public function assignFile(User $user, string $mediaUuid, ?string $eventUuid): array
    {
        $media = $this->accessService->requireMedia($mediaUuid);
        $this->accessService->assertOwner($user, $media);

        $metadata = is_array($media->metadata) ? $media->metadata : [];

        if ($eventUuid !== null) {
            $event = $this->requireOwnedEvent($user, $eventUuid);
            $metadata['visibility'] = $event->scope === MediaEvent::SCOPE_GALLERY
                ? 'gallery'
                : 'private';
            $media->update([
                'media_event_uuid' => $eventUuid,
                'metadata' => $metadata,
            ]);
        } else {
            $media->update(['media_event_uuid' => null]);
        }

        return app(MediaUploadService::class)->formatMedia($media->fresh(['event:uuid,title']));
    }

    public function assertEventMatchesUploadScope(
        User $user,
        string $eventUuid,
        ?array $metadata,
    ): void {
        $event = $this->requireOwnedEvent($user, $eventUuid);
        $visibility = is_array($metadata) ? ($metadata['visibility'] ?? null) : null;

        if ($event->scope === MediaEvent::SCOPE_GALLERY && $visibility !== 'gallery') {
            throw ValidationException::withMessages([
                'media_event_uuid' => ['Gallery event folders require gallery uploads.'],
            ]);
        }

        if ($event->scope === MediaEvent::SCOPE_PRIVATE && $visibility === 'gallery') {
            throw ValidationException::withMessages([
                'media_event_uuid' => ['Private event folders cannot receive gallery uploads.'],
            ]);
        }
    }

    public function requireOwnedEvent(User $user, string $uuid): MediaEvent
    {
        $event = MediaEvent::query()->where('uuid', $uuid)->first();

        if (! $event || $event->owner_user_id !== $user->id) {
            throw ValidationException::withMessages([
                'media_event_uuid' => ['Event not found.'],
            ]);
        }

        return $event;
    }

    /** @return array<string, mixed> */
    public function formatEvent(MediaEvent $event, bool $isOwned = true): array
    {
        return [
            'uuid' => $event->uuid,
            'scope' => $event->scope ?? MediaEvent::SCOPE_PRIVATE,
            'title' => $event->title,
            'original_title' => $event->title,
            'is_owned' => $isOwned,
            'can_share' => $isOwned && ($event->scope ?? MediaEvent::SCOPE_PRIVATE) === MediaEvent::SCOPE_GALLERY,
            'can_rename' => $isOwned,
            'description' => $event->description,
            'event_date' => $event->event_date?->format('Y-m-d'),
            'location' => $event->location,
            'event_type' => $event->event_type ?? MediaEvent::TYPE_GENERAL,
            'status' => $event->status ?? MediaEvent::STATUS_DRAFT,
            'starts_at' => $event->starts_at?->toIso8601String(),
            'ends_at' => $event->ends_at?->toIso8601String(),
            'timezone' => $event->timezone,
            'currency' => $event->currency ?? 'USD',
            'management_enabled' => (bool) ($event->management_enabled ?? false),
            'management_available' => (bool) config('features.event_management_enabled', false),
            'notes' => $event->notes,
            'file_count' => (int) ($event->file_count ?? $event->files()->whereIn('status', ['pending_upload', 'active'])->count()),
            'created_at' => $event->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public function formatSharedEvent(MediaEventShare $share): array
    {
        $event = $share->event;
        if (! $event) {
            throw ValidationException::withMessages([
                'event' => ['Shared event folder is unavailable.'],
            ]);
        }

        $displayTitle = is_string($share->alias_title) && $share->alias_title !== ''
            ? $share->alias_title
            : $event->title;

        return [
            'uuid' => $event->uuid,
            'share_uuid' => $share->uuid,
            'scope' => $event->scope ?? MediaEvent::SCOPE_GALLERY,
            'title' => $displayTitle,
            'original_title' => $event->title,
            'is_owned' => false,
            'can_share' => $share->access === 'owner',
            'can_rename' => true,
            'share_access' => $share->access,
            'owner_user_uuid' => $event->owner?->uuid,
            'owner_display_name' => $event->owner?->display_name,
            'shared_by_display_name' => $share->sharedBy?->display_name,
            'description' => $event->description,
            'event_date' => $event->event_date?->format('Y-m-d'),
            'location' => $event->location,
            'event_type' => $event->event_type ?? MediaEvent::TYPE_GENERAL,
            'status' => $event->status ?? MediaEvent::STATUS_DRAFT,
            'starts_at' => $event->starts_at?->toIso8601String(),
            'ends_at' => $event->ends_at?->toIso8601String(),
            'timezone' => $event->timezone,
            'currency' => $event->currency ?? 'USD',
            'management_enabled' => (bool) ($event->management_enabled ?? false),
            'management_available' => (bool) config('features.event_management_enabled', false),
            'notes' => $event->notes,
            'file_count' => (int) ($event->file_count ?? $event->files()->whereIn('status', ['pending_upload', 'active'])->count()),
            'created_at' => $share->created_at?->toIso8601String() ?? $event->created_at?->toIso8601String(),
        ];
    }
}
