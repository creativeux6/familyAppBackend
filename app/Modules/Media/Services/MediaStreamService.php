<?php

namespace App\Modules\Media\Services;

use App\Models\MediaFile;
use App\Models\User;
use App\Modules\StoragePlans\Services\StorageQuotaService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MediaStreamService
{
    public function __construct(
        private readonly MediaAccessService $accessService,
        private readonly MediaCoOwnerService $coOwnerService,
        private readonly StorageQuotaService $quotaService,
    ) {}

    public function streamPrefix(MediaFile $media): string
    {
        return $media->s3_key.'.stream';
    }

    public function manifestKey(MediaFile $media): string
    {
        return $this->streamPrefix($media).'/manifest.json';
    }

    public function chunkKey(MediaFile $media, int $index): string
    {
        return $this->streamPrefix($media).'/c'.str_pad((string) $index, 6, '0', STR_PAD_LEFT).'.bin';
    }

    /** @return array<string, mixed> */
    public function getManifest(User $user, string $uuid): array
    {
        $media = $this->accessService->requireMedia($uuid);
        $this->accessService->assertCanView($user, $media);
        $this->assertNonChatLibraryAccess($user, $media);

        if ($media->status !== 'active') {
            throw ValidationException::withMessages([
                'media' => ['File is not available.'],
            ]);
        }

        $disk = Storage::disk((string) config('media.disk'));
        $key = $this->manifestKey($media);

        if (! $disk->exists($key)) {
            throw ValidationException::withMessages([
                'stream' => ['No stream manifest is available for this file.'],
            ]);
        }

        $raw = $disk->get($key);
        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            throw ValidationException::withMessages([
                'stream' => ['Stream manifest is invalid.'],
            ]);
        }

        return $decoded;
    }

    public function downloadChunk(User $user, string $uuid, int $index): StreamedResponse
    {
        $media = $this->accessService->requireMedia($uuid);
        $this->accessService->assertCanView($user, $media);
        $this->assertNonChatLibraryAccess($user, $media);

        if ($media->status !== 'active') {
            throw ValidationException::withMessages([
                'media' => ['File is not available.'],
            ]);
        }

        if ($index < 0) {
            throw ValidationException::withMessages([
                'chunk' => ['Invalid chunk index.'],
            ]);
        }

        $disk = Storage::disk((string) config('media.disk'));
        $key = $this->chunkKey($media, $index);

        if (! $disk->exists($key)) {
            throw ValidationException::withMessages([
                'chunk' => ['Stream chunk not found.'],
            ]);
        }

        $size = (int) $disk->size($key);
        $this->coOwnerService->chargeStreamBytesIfNeeded($user, $media, $size);
        $this->quotaService->chargeReadTransfer(
            $user,
            $size,
            $this->coOwnerService->isChatMedia($media),
        );

        return response()->streamDownload(function () use ($disk, $key) {
            echo $disk->get($key);
        }, 'chunk-'.$index.'.bin', [
            'Content-Type' => 'application/octet-stream',
            'Content-Length' => (string) $size,
            'X-Media-Stream-Chunk' => (string) $index,
            'X-Media-Stream-Bytes' => (string) $size,
        ]);
    }

    public function storeManifest(User $user, string $uuid, array $manifest): array
    {
        $media = $this->accessService->requireMedia($uuid);
        $this->accessService->assertOwner($user, $media);

        if ($media->status !== 'active' && $media->status !== 'pending_upload') {
            throw ValidationException::withMessages([
                'media' => ['Stream can only be attached to pending or active files.'],
            ]);
        }

        $disk = Storage::disk((string) config('media.disk'));
        $disk->put(
            $this->manifestKey($media),
            json_encode($manifest, JSON_THROW_ON_ERROR),
        );

        $metadata = is_array($media->metadata) ? $media->metadata : [];
        $metadata['has_stream'] = true;
        $metadata['stream_version'] = (int) ($manifest['version'] ?? 1);
        $metadata['stream_chunk_count'] = (int) ($manifest['chunk_count'] ?? 0);
        $metadata['stream_chunk_size'] = (int) ($manifest['chunk_size'] ?? config('media.stream_chunk_size_bytes'));
        $metadata['stream_total_bytes'] = (int) ($manifest['total_bytes'] ?? 0);
        if (! empty($manifest['duration_seconds'])) {
            $metadata['duration_seconds'] = (int) $manifest['duration_seconds'];
        }

        $media->update(['metadata' => $metadata]);

        return [
            'message' => 'Stream manifest stored.',
            'has_stream' => true,
            'chunk_count' => $metadata['stream_chunk_count'],
        ];
    }

    public function storeChunk(User $user, string $uuid, int $index, string $binary): array
    {
        $media = $this->accessService->requireMedia($uuid);
        $this->accessService->assertOwner($user, $media);

        if ($media->status !== 'active' && $media->status !== 'pending_upload') {
            throw ValidationException::withMessages([
                'media' => ['Stream can only be attached to pending or active files.'],
            ]);
        }

        if ($index < 0) {
            throw ValidationException::withMessages([
                'chunk' => ['Invalid chunk index.'],
            ]);
        }

        if ($binary === '' || strlen($binary) > 2 * 1024 * 1024) {
            throw ValidationException::withMessages([
                'chunk' => ['Invalid chunk payload.'],
            ]);
        }

        Storage::disk((string) config('media.disk'))->put(
            $this->chunkKey($media, $index),
            $binary,
        );

        return [
            'message' => 'Chunk stored.',
            'index' => $index,
            'size_bytes' => strlen($binary),
        ];
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  array<int, string>  $chunkBinaries  index => binary ciphertext
     * @return array<string, mixed>
     */
    public function storeStreamPackage(
        User $user,
        string $uuid,
        array $manifest,
        array $chunkBinaries,
    ): array {
        foreach ($chunkBinaries as $index => $binary) {
            if (! is_string($binary) || $binary === '') {
                continue;
            }
            $this->storeChunk($user, $uuid, (int) $index, $binary);
        }

        return $this->storeManifest($user, $uuid, $manifest);
    }

    public function deleteStreamPackage(MediaFile $media): void
    {
        $disk = Storage::disk((string) config('media.disk'));
        $prefix = $this->streamPrefix($media);
        // Best-effort cleanup of known keys from metadata.
        $metadata = is_array($media->metadata) ? $media->metadata : [];
        $count = (int) ($metadata['stream_chunk_count'] ?? 0);
        for ($i = 0; $i < $count; $i++) {
            $disk->delete($this->chunkKey($media, $i));
        }
        $disk->delete($this->manifestKey($media));
        // Also try deleting directory marker if local disk supports it.
        try {
            $disk->deleteDirectory($prefix);
        } catch (\Throwable) {
            // ignore
        }
    }

    private function assertNonChatLibraryAccess(User $user, MediaFile $media): void
    {
        if ($this->coOwnerService->isChatMedia($media)) {
            return;
        }

        $this->quotaService->assertCanAccessLibrary($user);
    }
}
