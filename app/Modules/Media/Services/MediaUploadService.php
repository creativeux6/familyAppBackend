<?php

namespace App\Modules\Media\Services;

use App\Models\MediaEvent;
use App\Models\MediaFile;
use App\Models\MediaKeyEnvelope;
use App\Models\User;
use App\Modules\StoragePlans\Services\StorageQuotaService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MediaUploadService
{
    public function __construct(
        private readonly StorageQuotaService $quotaService,
        private readonly MediaAccessService $accessService,
        private readonly MediaChunkedUploadService $chunkedUploadService,
        private readonly MediaCoOwnerService $coOwnerService,
    ) {}

    public function initiate(
        User $user,
        string $displayName,
        int $sizeBytes,
        string $mimeType,
        string $checksumSha256,
        int $encryptionVersion = 1,
        ?string $mediaEventUuid = null,
        ?array $metadata = null,
    ): array {
        $pendingBytes = (int) MediaFile::query()
            ->where('owner_user_id', $user->id)
            ->where('status', 'pending_upload')
            ->get()
            ->reject(fn (MediaFile $media) => $this->coOwnerService->isChatCoOwnerMedia($media))
            ->sum('size_bytes');

        $normalizedMetadata = $this->normalizeMetadata($metadata);

        if ($mediaEventUuid !== null) {
            app(MediaEventService::class)->assertEventMatchesUploadScope(
                $user,
                $mediaEventUuid,
                $normalizedMetadata,
            );
        }

        if (! $this->coOwnerService->isChatCoOwnerMetadata($normalizedMetadata)) {
            $this->quotaService->assertCanStore($user, $sizeBytes + $pendingBytes);
        }

        $uuid = (string) Str::uuid();
        $diskName = (string) config('media.disk');
        $disk = Storage::disk($diskName);
        $bucket = $diskName === 's3' ? (string) config('filesystems.disks.s3.bucket') : 'local';
        $key = $this->storageKey($user, $uuid);

        $media = MediaFile::create([
            'uuid' => $uuid,
            'owner_user_id' => $user->id,
            'uploaded_by_user_id' => $user->id,
            's3_bucket' => $bucket ?: 'local',
            's3_key' => $key,
            'display_name' => $displayName,
            'size_bytes' => $sizeBytes,
            'mime_type' => $mimeType,
            'metadata' => $normalizedMetadata,
            'checksum_sha256' => $checksumSha256,
            'encryption_version' => $encryptionVersion,
            'status' => 'pending_upload',
            'media_event_uuid' => $mediaEventUuid,
        ]);

        $expiresAt = now()->addMinutes((int) config('media.presigned_upload_ttl_minutes', 15));
        $upload = $this->buildUploadTarget($diskName, $key, $expiresAt, $media->uuid);
        $chunkSize = $this->chunkedUploadService->chunkSize();

        $media->update(['chunk_size' => $chunkSize]);

        return array_merge($this->formatMedia($media->fresh()), $upload, [
            'upload_mode' => 'chunked',
            'chunk_size' => $chunkSize,
            'total_parts' => max(1, (int) ceil($sizeBytes / $chunkSize)),
        ]);
    }

    public function uploadContent(User $user, string $uuid, string $binary): array
    {
        $media = $this->accessService->requireMedia($uuid);
        $this->accessService->assertOwner($user, $media);

        if ($media->status !== 'pending_upload') {
            throw ValidationException::withMessages([
                'media' => ['Upload already completed or file is not pending.'],
            ]);
        }

        $disk = Storage::disk((string) config('media.disk'));
        $disk->put($media->s3_key, $binary);

        $media->update(['size_bytes' => strlen($binary)]);

        return ['message' => 'Content uploaded.', 'uuid' => $media->uuid];
    }

    public function complete(User $user, string $uuid): array
    {
        $media = $this->accessService->requireMedia($uuid);
        $this->accessService->assertOwner($user, $media);

        if ($media->status !== 'pending_upload') {
            throw ValidationException::withMessages([
                'media' => ['Upload is not pending.'],
            ]);
        }

        $disk = Storage::disk((string) config('media.disk'));
        $media = $media->fresh();

        if (! empty($media->uploaded_parts)) {
            $actualSize = $this->chunkedUploadService->finalizeChunkedUpload($media, $disk);
        } else {
            $actualSize = $this->resolveUploadedSizeBytes($disk, $media);
        }

        return DB::transaction(function () use ($user, $media, $actualSize) {
            $skipSenderQuota = $this->coOwnerService->isChatCoOwnerMedia($media);

            if (! $skipSenderQuota) {
                $this->quotaService->assertCanStore($user, $actualSize);
            }

            $media->update([
                'status' => 'active',
                'size_bytes' => $actualSize,
                'multipart_upload_id' => null,
                'uploaded_parts' => null,
            ]);

            if (! $skipSenderQuota) {
                $this->quotaService->addUsage($user->fresh(), $actualSize);
            }

            return $this->formatMedia($media->fresh(), $user);
        });
    }

    public function show(User $user, string $uuid): array
    {
        $media = $this->accessService->requireMedia($uuid);
        $this->accessService->assertCanView($user, $media);

        $payload = $this->formatMedia($media, $user);

        if ($media->status === 'active') {
            $payload['download'] = $this->buildDownloadTarget($media);
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    public function listForUser(
        User $user,
        ?string $scope = null,
        ?int $limit = null,
        ?string $cursor = null,
    ): array {
        $galleryScope = $scope === 'gallery';
        $pageSize = max(1, min($limit ?? (int) config('media.list_page_size', 20), 50));

        $removedUuids = \App\Models\MediaLibraryItem::query()
            ->where('user_id', $user->id)
            ->whereNotNull('removed_at')
            ->pluck('media_file_uuid');

        $primaryOwned = MediaFile::query()
            ->where('owner_user_id', $user->id)
            ->whereIn('status', ['pending_upload', 'active'])
            ->whereNotIn('uuid', $removedUuids)
            ->get();

        $coOwnedUuids = \App\Models\MediaPermission::query()
            ->where('user_id', $user->id)
            ->where('access', 'owner')
            ->pluck('media_file_uuid');

        $coOwned = MediaFile::query()
            ->whereIn('uuid', $coOwnedUuids)
            ->where('status', 'active')
            ->whereNotIn('uuid', $removedUuids)
            ->where('owner_user_id', '!=', $user->id)
            ->get();

        $owned = $primaryOwned
            ->merge($coOwned)
            ->unique('uuid')
            ->filter(fn (MediaFile $media) => $galleryScope
                ? $this->isGalleryMedia($user, $media)
                : $this->isPrivateMedia($user, $media))
            ->values();

        $shared = collect();

        $directViewUuids = \App\Models\MediaPermission::query()
            ->where('user_id', $user->id)
            ->where('access', 'view')
            ->pluck('media_file_uuid');

        $groupUuids = \App\Models\GroupMember::query()
            ->where('user_id', $user->id)
            ->pluck('group_uuid');

        $groupSharedUuids = $galleryScope
            ? \App\Models\MediaPermission::query()
                ->whereIn('group_uuid', $groupUuids)
                ->pluck('media_file_uuid')
            : collect();

        $sharedCandidateUuids = $directViewUuids
            ->merge($groupSharedUuids)
            ->unique();

        if ($sharedCandidateUuids->isNotEmpty()) {
            $shared = MediaFile::query()
                ->whereIn('uuid', $sharedCandidateUuids)
                ->where('status', 'active')
                ->where('owner_user_id', '!=', $user->id)
                ->whereNotIn('uuid', $coOwnedUuids)
                ->whereNotIn('uuid', $removedUuids)
                ->get()
                ->filter(fn (MediaFile $media) => $galleryScope
                    ? $this->isGalleryMedia($user, $media)
                    : $this->isPrivateMedia($user, $media))
                ->values();
        }

        $accessibleGalleryEventUuids = $galleryScope
            ? collect(
                app(MediaEventService::class)->accessibleEventUuidsForUser(
                    $user,
                    MediaEvent::SCOPE_GALLERY,
                )
            )
            : collect();

        $items = $owned
            ->map(fn (MediaFile $m) => ['media' => $m, 'is_owned' => true])
            ->concat($shared->map(fn (MediaFile $m) => ['media' => $m, 'is_owned' => false]))
            ->filter(function (array $row) use ($galleryScope, $accessibleGalleryEventUuids) {
                /** @var MediaFile $media */
                $media = $row['media'];

                if ($galleryScope) {
                    $eventUuid = $media->media_event_uuid;

                    return ! is_string($eventUuid)
                        || $eventUuid === ''
                        || ! $accessibleGalleryEventUuids->contains($eventUuid);
                }

                return $media->media_event_uuid === null || $media->media_event_uuid === '';
            })
            ->sortByDesc(function (array $row) {
                /** @var MediaFile $media */
                $media = $row['media'];

                return sprintf(
                    '%s|%s',
                    $media->created_at?->format('Y-m-d\TH:i:s.uP') ?? '',
                    $media->uuid,
                );
            })
            ->values();

        if ($cursor !== null) {
            [$cursorCreatedAt, $cursorUuid] = array_pad(explode('|', $cursor, 2), 2, null);
            $items = $items->filter(function (array $row) use ($cursorCreatedAt, $cursorUuid) {
                /** @var MediaFile $media */
                $media = $row['media'];
                $createdAt = $media->created_at?->toIso8601String() ?? '';

                if ($createdAt < $cursorCreatedAt) {
                    return true;
                }

                if ($createdAt > $cursorCreatedAt) {
                    return false;
                }

                return is_string($cursorUuid) && $media->uuid < $cursorUuid;
            })->values();
        }

        $page = $items->take($pageSize + 1);
        $hasMore = $page->count() > $pageSize;
        $pageItems = $page->take($pageSize)->values();

        $formatted = $pageItems->map(function (array $row) use ($user) {
            /** @var MediaFile $media */
            $media = $row['media'];

            return $this->formatMedia($media, $user, isOwned: (bool) $row['is_owned']);
        })->all();

        $nextCursor = null;
        if ($hasMore && $pageItems->isNotEmpty()) {
            /** @var MediaFile $last */
            $last = $pageItems->last()['media'];
            $nextCursor = ($last->created_at?->toIso8601String() ?? '').'|'.$last->uuid;
        }

        $eventScope = $galleryScope ? MediaEvent::SCOPE_GALLERY : MediaEvent::SCOPE_PRIVATE;
        $eventService = app(MediaEventService::class);
        $accessibleEventUuids = collect(
            $eventService->accessibleEventUuidsForUser($user, $eventScope)
        );

        $eventFiles = $owned
            ->filter(function (MediaFile $m) use ($accessibleEventUuids) {
                $eventUuid = $m->media_event_uuid;

                return is_string($eventUuid)
                    && $eventUuid !== ''
                    && $accessibleEventUuids->contains($eventUuid);
            })
            ->map(fn (MediaFile $m) => $this->formatMedia($m, $user))
            ->values()
            ->all();

        if ($galleryScope && $shared->isNotEmpty()) {
            $sharedEventFiles = $shared
                ->filter(function (MediaFile $m) use ($accessibleEventUuids) {
                    $eventUuid = $m->media_event_uuid;

                    return is_string($eventUuid)
                        && $eventUuid !== ''
                        && $accessibleEventUuids->contains($eventUuid);
                })
                ->map(fn (MediaFile $m) => $this->formatMedia($m, $user, isOwned: false))
                ->values();

            $existing = collect($eventFiles)->pluck('uuid');
            $eventFiles = collect($eventFiles)
                ->concat($sharedEventFiles->filter(fn (array $row) => ! $existing->contains($row['uuid'])))
                ->values()
                ->all();
        }

        return [
            'items' => $formatted,
            'event_files' => $eventFiles,
            'next_cursor' => $nextCursor,
            'has_more' => $hasMore,
            'limit' => $pageSize,
            'events' => $eventService->listForUser($user, $eventScope),
            'quota_bytes' => $this->quotaService->quotaBytes($user),
            'stored_bytes' => $this->quotaService->storedBytes($user),
            'read_bytes' => $this->quotaService->readBytes($user),
            'used_bytes' => $this->quotaService->usedBytes($user),
            'over_quota' => $this->quotaService->isOverQuota($user),
        ];
    }

    private function isPrivateMedia(User $user, MediaFile $media): bool
    {
        return $this->resolveMediaScope($user, $media) === 'private';
    }

    private function isGalleryMedia(User $user, MediaFile $media): bool
    {
        return $this->resolveMediaScope($user, $media) === 'gallery';
    }

    private function resolveMediaScope(User $user, MediaFile $media): string
    {
        $metadata = is_array($media->metadata) ? $media->metadata : [];
        $visibility = $metadata['visibility'] ?? null;

        if ($visibility === 'private') {
            return 'private';
        }

        if ($visibility === 'gallery' || ($metadata['source'] ?? null) === 'chat') {
            return 'gallery';
        }

        $hasEnvelope = MediaKeyEnvelope::query()
            ->where('media_file_uuid', $media->uuid)
            ->where('recipient_user_id', $user->id)
            ->exists();

        return $hasEnvelope ? 'private' : 'gallery';
    }

    public function delete(User $user, string $uuid): array
    {
        $media = $this->accessService->requireMedia($uuid);

        if ($this->coOwnerService->isChatCoOwnerMedia($media)) {
            return $this->coOwnerService->removeFromUserLibrary($user, $media);
        }

        $this->accessService->assertOwner($user, $media);

        return DB::transaction(function () use ($user, $media) {
            if ($media->status === 'active') {
                $disk = Storage::disk((string) config('media.disk'));
                $disk->delete($media->s3_key);
                if ($media->hasThumbnail()) {
                    $disk->delete($media->thumbnail_s3_key);
                }
                app(MediaStreamService::class)->deleteStreamPackage($media);
                $this->quotaService->removeUsage($user, (int) $media->size_bytes);
            } elseif ($media->status === 'pending_upload') {
                $this->chunkedUploadService->cleanupPartialUpload($media);
            }

            $media->update(['status' => 'deleted']);
            $media->delete();

            return ['message' => 'Media deleted.'];
        });
    }

    public function uploadThumbnail(
        User $user,
        string $uuid,
        string $binary,
        ?string $thumbNonce,
        string $thumbMime = 'image/jpeg',
    ): array {
        $media = $this->accessService->requireMedia($uuid);
        $this->accessService->assertOwner($user, $media);

        if ($media->status !== 'active' && $media->status !== 'pending_upload') {
            throw ValidationException::withMessages([
                'media' => ['Thumbnail can only be uploaded for pending or active files.'],
            ]);
        }

        if ($binary === '') {
            throw ValidationException::withMessages([
                'thumbnail' => ['Thumbnail payload is empty.'],
            ]);
        }

        // Keep thumbs tiny — reject anything over 256 KB ciphertext.
        if (strlen($binary) > 256 * 1024) {
            throw ValidationException::withMessages([
                'thumbnail' => ['Thumbnail is too large.'],
            ]);
        }

        $thumbKey = $media->s3_key.'.thumb';
        Storage::disk((string) config('media.disk'))->put($thumbKey, $binary);

        $metadata = is_array($media->metadata) ? $media->metadata : [];
        $metadata['has_thumbnail'] = true;
        $metadata['thumb_mime'] = $thumbMime;
        if (is_string($thumbNonce) && $thumbNonce !== '') {
            $metadata['thumb_nonce'] = $thumbNonce;
        }

        $media->update([
            'thumbnail_s3_key' => $thumbKey,
            'thumbnail_size_bytes' => strlen($binary),
            'metadata' => $metadata,
        ]);

        return $this->formatMedia($media->fresh(), $user);
    }

    public function downloadThumbnail(User $user, string $uuid): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $media = $this->accessService->requireMedia($uuid);
        $this->accessService->assertCanView($user, $media);
        $this->assertNonChatLibraryAccess($user, $media);

        if ($media->status !== 'active') {
            throw ValidationException::withMessages([
                'media' => ['File is not available for download.'],
            ]);
        }

        if (! $media->hasThumbnail()) {
            throw ValidationException::withMessages([
                'thumbnail' => ['No thumbnail is available for this file.'],
            ]);
        }

        $disk = Storage::disk((string) config('media.disk'));

        $thumbBytes = max(0, (int) $media->thumbnail_size_bytes);
        $this->quotaService->chargeReadTransfer(
            $user,
            $thumbBytes,
            $this->coOwnerService->isChatMedia($media),
        );

        return response()->streamDownload(function () use ($disk, $media) {
            echo $disk->get($media->thumbnail_s3_key);
        }, ($media->display_name ?? $media->uuid).'.thumb', [
            'Content-Type' => 'application/octet-stream',
            'X-Media-Has-Thumbnail' => '1',
        ]);
    }

    private function storageKey(User $user, string $uuid): string
    {
        return config('media.key_prefix').'/'.$user->uuid.'/'.$uuid;
    }

    /** @param array<string, mixed>|null $metadata */
    private function normalizeMetadata(?array $metadata): ?array
    {
        if ($metadata === null || $metadata === []) {
            return null;
        }

        $normalized = [];

        if (isset($metadata['duration_seconds'])) {
            $normalized['duration_seconds'] = max(0, (int) $metadata['duration_seconds']);
        }

        if (! empty($metadata['original_mime_type'])) {
            $normalized['original_mime_type'] = (string) $metadata['original_mime_type'];
        }

        if (isset($metadata['width'])) {
            $normalized['width'] = max(1, (int) $metadata['width']);
        }

        if (isset($metadata['height'])) {
            $normalized['height'] = max(1, (int) $metadata['height']);
        }

        if (! empty($metadata['visibility'])) {
            $visibility = (string) $metadata['visibility'];
            if (in_array($visibility, ['private', 'gallery'], true)) {
                $normalized['visibility'] = $visibility;
            }
        }

        if (! empty($metadata['source'])) {
            $source = (string) $metadata['source'];
            if (in_array($source, ['chat', 'gallery'], true)) {
                $normalized['source'] = $source;
            }
        }

        if (! empty($metadata['file_nonce'])) {
            $normalized['file_nonce'] = (string) $metadata['file_nonce'];
        }

        if (! empty($metadata['group_uuid'])) {
            $normalized['group_uuid'] = (string) $metadata['group_uuid'];
        }

        if (! empty($metadata['storage_mode'])) {
            $storageMode = (string) $metadata['storage_mode'];
            if (in_array($storageMode, ['sender', 'co_owner'], true)) {
                $normalized['storage_mode'] = $storageMode;
            }
        }

        if (! empty($metadata['thumb_nonce'])) {
            $normalized['thumb_nonce'] = (string) $metadata['thumb_nonce'];
        }

        if (array_key_exists('has_thumbnail', $metadata)) {
            $normalized['has_thumbnail'] = (bool) $metadata['has_thumbnail'];
        }

        if (! empty($metadata['thumb_mime'])) {
            $normalized['thumb_mime'] = (string) $metadata['thumb_mime'];
        }

        if (array_key_exists('has_stream', $metadata)) {
            $normalized['has_stream'] = (bool) $metadata['has_stream'];
        }

        if (isset($metadata['stream_chunk_count'])) {
            $normalized['stream_chunk_count'] = max(0, (int) $metadata['stream_chunk_count']);
        }

        if (isset($metadata['stream_chunk_size'])) {
            $normalized['stream_chunk_size'] = max(1, (int) $metadata['stream_chunk_size']);
        }

        if (isset($metadata['stream_total_bytes'])) {
            $normalized['stream_total_bytes'] = max(0, (int) $metadata['stream_total_bytes']);
        }

        if (isset($metadata['stream_version'])) {
            $normalized['stream_version'] = max(1, (int) $metadata['stream_version']);
        }

        return $normalized === [] ? null : $normalized;
    }

    /**
     * Resolve uploaded object size. Some IAM policies allow PutObject but deny
     * HeadObject; in that case we trust size_bytes after uploadContent ran.
     */
    private function resolveUploadedSizeBytes(\Illuminate\Contracts\Filesystem\Filesystem $disk, MediaFile $media): int
    {
        try {
            if ($disk->exists($media->s3_key)) {
                return (int) $disk->size($media->s3_key);
            }
        } catch (\Throwable) {
            // HeadObject/GetObject may be denied even when PutObject succeeded.
        }

        $contentStagedViaApi = $media->updated_at !== null
            && $media->created_at !== null
            && $media->updated_at->greaterThan($media->created_at);

        if ($contentStagedViaApi && $media->size_bytes > 0) {
            return (int) $media->size_bytes;
        }

        throw ValidationException::withMessages([
            'media' => ['Uploaded file not found in storage. Complete the PUT upload first.'],
        ]);
    }

    /** @return array<string, mixed> */
    private function buildUploadTarget(string $diskName, string $key, \Illuminate\Support\Carbon $expiresAt, string $mediaUuid): array
    {
        if ($diskName === 's3' && filled(config('filesystems.disks.s3.bucket'))) {
            /** @var \Illuminate\Filesystem\FilesystemAdapter $disk */
            $disk = Storage::disk('s3');
            $presigned = $disk->temporaryUploadUrl($key, $expiresAt, [
                'ContentType' => 'application/octet-stream',
            ]);

            return [
                'upload_method' => 'PUT',
                'upload_url' => $presigned['url'],
                'upload_headers' => $presigned['headers'] ?? [],
                'expires_at' => $expiresAt->toIso8601String(),
            ];
        }

        return [
            'upload_method' => 'PUT',
            'upload_url' => url('/api/v1/media/'.$mediaUuid.'/content'),
            'upload_headers' => [
                'Authorization' => 'Bearer {your_token}',
                'Content-Type' => 'application/octet-stream',
            ],
            'expires_at' => $expiresAt->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function buildDownloadTarget(MediaFile $media): array
    {
        // Always serve via API so every S3 egress is metered in StorageQuotaService.
        $expiresAt = now()->addMinutes((int) config('media.presigned_download_ttl_minutes', 60));

        return [
            'method' => 'GET',
            'url' => url('/api/v1/media/'.$media->uuid.'/content'),
            'expires_at' => $expiresAt->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public function formatMedia(MediaFile $media, ?User $viewer = null, bool $isOwned = true): array
    {
        $media->loadMissing(['owner:id,uuid,display_name', 'event:uuid,title']);

        $payload = [
            'uuid' => $media->uuid,
            'display_name' => $media->display_name,
            'size_bytes' => $media->size_bytes,
            'mime_type' => $media->mime_type,
            'metadata' => $media->metadata,
            'checksum_sha256' => $media->checksum_sha256,
            'encryption_version' => $media->encryption_version,
            'status' => $media->status,
            'owner_user_uuid' => $media->owner?->uuid,
            'owner_display_name' => $media->owner?->display_name,
            'media_event_uuid' => $media->media_event_uuid,
            'media_event_title' => $media->event?->title,
            'created_at' => $media->created_at?->toIso8601String(),
            'is_owned' => $isOwned,
            'has_thumbnail' => $media->hasThumbnail(),
            'thumbnail_size_bytes' => $media->thumbnail_size_bytes,
            'has_stream' => (bool) (($media->metadata['has_stream'] ?? false) === true),
        ];

        if ($viewer !== null) {
            $payload['is_co_owner'] = $isOwned && $this->accessService->isCoOwner($viewer, $media)
                && ! $this->accessService->isOwner($viewer, $media);
            $payload['is_unseen'] = app(MediaShareInboxService::class)
                ->isUnseenForUser($viewer, $media->uuid);
        } else {
            $payload['is_unseen'] = false;
        }

        return $payload;
    }

    public function downloadContent(User $user, string $uuid): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $media = $this->accessService->requireMedia($uuid);
        $this->accessService->assertCanView($user, $media);
        $this->assertNonChatLibraryAccess($user, $media);

        if ($media->status !== 'active') {
            throw ValidationException::withMessages([
                'media' => ['File is not available for download.'],
            ]);
        }

        $this->coOwnerService->chargeAccessQuotaIfNeeded($user, $media);

        $transferBytes = max(0, (int) $media->size_bytes);
        $this->quotaService->chargeReadTransfer(
            $user,
            $transferBytes,
            $this->coOwnerService->isChatMedia($media),
        );

        $disk = Storage::disk((string) config('media.disk'));

        return response()->streamDownload(function () use ($disk, $media) {
            echo $disk->get($media->s3_key);
        }, $media->display_name ?? $media->uuid, [
            'Content-Type' => $media->mime_type,
        ]);
    }

    private function assertNonChatLibraryAccess(User $user, MediaFile $media): void
    {
        if ($this->coOwnerService->isChatMedia($media)) {
            return;
        }

        $this->quotaService->assertCanAccessLibrary($user);
    }
}
