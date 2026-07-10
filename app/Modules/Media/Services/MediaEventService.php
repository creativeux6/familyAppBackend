<?php

namespace App\Modules\Media\Services;

use App\Models\MediaEvent;
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
    public function listForUser(User $user): array
    {
        return MediaEvent::query()
            ->where('owner_user_id', $user->id)
            ->withCount([
                'files as file_count' => fn ($query) => $query
                    ->whereIn('status', ['pending_upload', 'active']),
            ])
            ->orderByDesc('event_date')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (MediaEvent $event) => $this->formatEvent($event))
            ->values()
            ->all();
    }

    /** @param  array<string, mixed>  $data */
    public function create(User $user, array $data): array
    {
        $event = MediaEvent::create([
            'uuid' => (string) Str::uuid(),
            'owner_user_id' => $user->id,
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
        $event = $this->requireOwnedEvent($user, $uuid);

        return $this->formatEvent(
            $event->loadCount([
                'files as file_count' => fn ($query) => $query
                    ->whereIn('status', ['pending_upload', 'active']),
            ])
        );
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

        if ($eventUuid !== null) {
            $this->requireOwnedEvent($user, $eventUuid);
        }

        $media->update(['media_event_uuid' => $eventUuid]);

        return app(MediaUploadService::class)->formatMedia($media->fresh(['event:uuid,title']));
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
    public function formatEvent(MediaEvent $event): array
    {
        return [
            'uuid' => $event->uuid,
            'title' => $event->title,
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
}
