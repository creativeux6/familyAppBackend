<?php

namespace App\Modules\Media\Services;

use App\Models\MediaFile;
use App\Models\User;
use Aws\S3\S3Client;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class MediaChunkedUploadService
{
    public function __construct(
        private readonly MediaAccessService $accessService,
    ) {}

    public function chunkSize(): int
    {
        // Floor at 512 KiB for tiny environments; production default is 5 MiB.
        return max(512 * 1024, (int) config('media.chunk_size_bytes', 5 * 1024 * 1024));
    }

    /** @return array<string, mixed> */
    public function uploadStatus(User $user, string $uuid): array
    {
        $media = $this->accessService->requireMedia($uuid);
        $this->accessService->assertOwner($user, $media);

        return $this->formatUploadStatus($media);
    }

    /** @return array<string, mixed> */
    public function uploadChunk(User $user, string $uuid, int $partNumber, string $binary): array
    {
        if ($partNumber < 1) {
            throw ValidationException::withMessages([
                'part_number' => ['Part number must be at least 1.'],
            ]);
        }

        $chunkSize = null;
        $bodySize = strlen($binary);
        $diskName = (string) config('media.disk');

        // Validate ownership/status and claim the part slot under a row lock so
        // concurrent chunk uploads cannot clobber uploaded_parts JSON.
        $media = DB::transaction(function () use ($user, $uuid, $partNumber, $bodySize, &$chunkSize) {
            $media = MediaFile::query()->where('uuid', $uuid)->lockForUpdate()->first();
            if (! $media) {
                $media = $this->accessService->requireMedia($uuid);
            }
            $this->accessService->assertOwner($user, $media);

            if ($media->status !== 'pending_upload') {
                throw ValidationException::withMessages([
                    'media' => ['Upload already completed or file is not pending.'],
                ]);
            }

            $chunkSize = (int) ($media->chunk_size ?: $this->chunkSize());

            if ($bodySize < 1 || $bodySize > $chunkSize) {
                throw ValidationException::withMessages([
                    'chunk' => ["Chunk size must be between 1 and {$chunkSize} bytes."],
                ]);
            }

            $parts = collect($media->uploaded_parts ?? []);
            if ($parts->contains(fn (array $part) => (int) ($part['part_number'] ?? 0) === $partNumber)) {
                return $media;
            }

            // Reserve the part number before uploading bytes so races serialize.
            $parts->push([
                'part_number' => $partNumber,
                'size_bytes' => $bodySize,
                'pending' => true,
            ]);
            $media->update([
                'uploaded_parts' => $parts->sortBy('part_number')->values()->all(),
                'chunk_size' => $chunkSize,
            ]);

            return $media->fresh();
        });

        $parts = collect($media->uploaded_parts ?? []);
        $existing = $parts->first(
            fn (array $part) => (int) ($part['part_number'] ?? 0) === $partNumber
        );

        if (is_array($existing) && empty($existing['pending'])) {
            return array_merge(
                ['message' => 'Chunk already uploaded.', 'uuid' => $media->uuid],
                $this->formatUploadStatus($media)
            );
        }

        try {
            if ($this->usesObjectStoreMultipart($diskName)) {
                $etag = $this->uploadS3Part($media, $partNumber, $binary);
                $this->finalizePartMetadata($media->uuid, $partNumber, $bodySize, $etag);
            } else {
                $this->uploadLocalPart($media, $partNumber, $binary);
                $this->finalizePartMetadata($media->uuid, $partNumber, $bodySize);
            }
        } catch (\Throwable $e) {
            $this->releasePendingPart($media->uuid, $partNumber);
            throw $e;
        }

        return array_merge(
            ['message' => 'Chunk uploaded.', 'uuid' => $media->uuid],
            $this->formatUploadStatus($media->fresh())
        );
    }

    private function finalizePartMetadata(
        string $uuid,
        int $partNumber,
        int $bodySize,
        ?string $etag = null,
    ): void {
        DB::transaction(function () use ($uuid, $partNumber, $bodySize, $etag) {
            $media = MediaFile::query()->where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            $parts = collect($media->uploaded_parts ?? [])
                ->reject(fn (array $part) => (int) ($part['part_number'] ?? 0) === $partNumber)
                ->values();

            $entry = [
                'part_number' => $partNumber,
                'size_bytes' => $bodySize,
            ];
            if ($etag !== null) {
                $entry['etag'] = $etag;
            }
            $parts->push($entry);

            $media->update([
                'uploaded_parts' => $parts->sortBy('part_number')->values()->all(),
            ]);
        });
    }

    private function releasePendingPart(string $uuid, int $partNumber): void
    {
        DB::transaction(function () use ($uuid, $partNumber) {
            $media = MediaFile::query()->where('uuid', $uuid)->lockForUpdate()->first();
            if (! $media) {
                return;
            }

            $parts = collect($media->uploaded_parts ?? [])
                ->reject(function (array $part) use ($partNumber) {
                    return (int) ($part['part_number'] ?? 0) === $partNumber
                        && ! empty($part['pending']);
                })
                ->values()
                ->all();

            $media->update(['uploaded_parts' => $parts]);
        });
    }

    /** @return array<string, mixed> */
    public function abortUpload(User $user, string $uuid): array
    {
        $media = $this->accessService->requireMedia($uuid);
        $this->accessService->assertOwner($user, $media);

        if ($media->status !== 'pending_upload' && $media->status !== 'finalizing') {
            throw ValidationException::withMessages([
                'media' => ['Only pending uploads can be aborted.'],
            ]);
        }

        $this->cleanupPartialUpload($media);

        $media->update([
            'status' => 'deleted',
            'multipart_upload_id' => null,
            'uploaded_parts' => null,
        ]);
        $media->delete();

        return ['message' => 'Upload aborted.', 'uuid' => $uuid];
    }

    public function finalizeChunkedUpload(MediaFile $media, Filesystem $disk): int
    {
        $parts = collect($media->uploaded_parts ?? [])
            ->reject(fn (array $part) => ! empty($part['pending']))
            ->sortBy('part_number')
            ->values();

        if ($parts->isEmpty()) {
            throw ValidationException::withMessages([
                'media' => ['No upload chunks found. Upload file parts first.'],
            ]);
        }

        $expectedSize = (int) $media->size_bytes;
        $uploadedSize = (int) $parts->sum('size_bytes');

        if ($uploadedSize !== $expectedSize) {
            throw ValidationException::withMessages([
                'media' => ["Uploaded bytes ({$uploadedSize}) do not match expected size ({$expectedSize})."],
            ]);
        }

        $diskName = (string) config('media.disk');

        if ($this->usesObjectStoreMultipart($diskName) && filled($media->multipart_upload_id)) {
            $this->completeS3Multipart($media, $parts->all());
        } else {
            $this->assembleLocalParts($media, $disk, $parts->count());
        }

        $this->cleanupPartArtifacts($media, keepFinalObject: true);

        return $uploadedSize;
    }

    /** @return array<string, mixed> */
    public function formatUploadStatus(MediaFile $media): array
    {
        $chunkSize = (int) ($media->chunk_size ?: $this->chunkSize());
        $totalBytes = (int) $media->size_bytes;
        $totalParts = max(1, (int) ceil($totalBytes / $chunkSize));
        $uploadedParts = collect($media->uploaded_parts ?? [])
            ->reject(fn (array $part) => ! empty($part['pending']))
            ->pluck('part_number')
            ->map(fn ($value) => (int) $value)
            ->sort()
            ->values()
            ->all();
        $uploadedBytes = (int) collect($media->uploaded_parts ?? [])
            ->reject(fn (array $part) => ! empty($part['pending']))
            ->sum('size_bytes');

        return [
            'uuid' => $media->uuid,
            'status' => $media->status,
            'upload_mode' => 'chunked',
            'chunk_size' => $chunkSize,
            'size_bytes' => $totalBytes,
            'total_parts' => $totalParts,
            'uploaded_parts' => $uploadedParts,
            'uploaded_bytes' => $uploadedBytes,
            'progress_percent' => $totalBytes > 0
                ? min(100, (int) round(($uploadedBytes / $totalBytes) * 100))
                : 0,
        ];
    }

    public function cleanupPartialUpload(MediaFile $media): void
    {
        $diskName = (string) config('media.disk');

        if ($this->usesObjectStoreMultipart($diskName) && filled($media->multipart_upload_id)) {
            try {
                $this->s3Client()->abortMultipartUpload([
                    'Bucket' => $this->objectStoreBucket($diskName),
                    'Key' => $media->s3_key,
                    'UploadId' => $media->multipart_upload_id,
                ]);
            } catch (\Throwable) {
                // Best-effort cleanup.
            }
        }

        $this->cleanupPartArtifacts($media, keepFinalObject: false);
    }

    private function uploadS3Part(MediaFile $media, int $partNumber, string $binary): string
    {
        $bucket = $this->objectStoreBucket();

        if (! filled($media->multipart_upload_id)) {
            $result = $this->s3Client()->createMultipartUpload([
                'Bucket' => $bucket,
                'Key' => $media->s3_key,
                'ContentType' => 'application/octet-stream',
            ]);

            $media->update(['multipart_upload_id' => $result['UploadId']]);
            $media = $media->fresh();
        }

        $result = $this->s3Client()->uploadPart([
            'Bucket' => $bucket,
            'Key' => $media->s3_key,
            'UploadId' => (string) $media->multipart_upload_id,
            'PartNumber' => $partNumber,
            'Body' => $binary,
        ]);

        return (string) $result['ETag'];
    }

    private function uploadLocalPart(MediaFile $media, int $partNumber, string $binary): void
    {
        Storage::disk((string) config('media.disk'))->put(
            $this->localPartKey($media, $partNumber),
            $binary
        );
    }

    /** @param array<int, array<string, mixed>> $parts */
    private function completeS3Multipart(MediaFile $media, array $parts): void
    {
        try {
            $this->s3Client()->completeMultipartUpload([
                'Bucket' => $this->objectStoreBucket(),
                'Key' => $media->s3_key,
                'UploadId' => (string) $media->multipart_upload_id,
                'MultipartUpload' => [
                    'Parts' => collect($parts)
                        ->sortBy('part_number')
                        ->map(fn (array $part) => [
                            'ETag' => $part['etag'],
                            'PartNumber' => (int) $part['part_number'],
                        ])
                        ->values()
                        ->all(),
                ],
            ]);
        } catch (\Throwable $e) {
            // Idempotent retry: multipart may already be completed.
            $disk = Storage::disk((string) config('media.disk'));
            try {
                if ($disk->exists($media->s3_key)) {
                    return;
                }
            } catch (\Throwable) {
                // fall through
            }

            throw $e;
        }
    }

    private function assembleLocalParts(MediaFile $media, Filesystem $disk, int $partCount): void
    {
        $finalKey = $media->s3_key;
        $tempKey = $finalKey.'.assembling';

        $disk->delete($tempKey);

        for ($part = 1; $part <= $partCount; $part++) {
            $partKey = $this->localPartKey($media, $part);
            $chunk = $disk->get($partKey);

            if ($chunk === null) {
                throw ValidationException::withMessages([
                    'media' => ["Missing local chunk {$part}."],
                ]);
            }

            $disk->append($tempKey, $chunk);
        }

        $disk->move($tempKey, $finalKey);
    }

    private function cleanupPartArtifacts(MediaFile $media, bool $keepFinalObject): void
    {
        $disk = Storage::disk((string) config('media.disk'));
        $partCount = count($media->uploaded_parts ?? []);

        for ($part = 1; $part <= max($partCount, 1); $part++) {
            $partKey = $this->localPartKey($media, $part);
            if ($disk->exists($partKey)) {
                $disk->delete($partKey);
            }
        }

        $assemblingKey = $media->s3_key.'.assembling';
        if ($disk->exists($assemblingKey)) {
            $disk->delete($assemblingKey);
        }

        if (! $keepFinalObject && $disk->exists($media->s3_key)) {
            $disk->delete($media->s3_key);
        }
    }

    private function localPartKey(MediaFile $media, int $partNumber): string
    {
        return $media->s3_key.'.parts/'.$partNumber;
    }

    private function usesObjectStoreMultipart(?string $diskName = null): bool
    {
        $diskName ??= (string) config('media.disk');

        return in_array($diskName, ['s3', 'b2'], true)
            && filled(config("filesystems.disks.{$diskName}.bucket"));
    }

    private function objectStoreBucket(?string $diskName = null): string
    {
        $diskName ??= (string) config('media.disk');

        return (string) config("filesystems.disks.{$diskName}.bucket");
    }

    private function s3Client(): S3Client
    {
        $diskName = (string) config('media.disk', 'b2');
        $config = config('filesystems.disks.'.$diskName) ?? config('filesystems.disks.b2');

        $clientConfig = [
            'version' => 'latest',
            'region' => $config['region'] ?? 'us-west-002',
            'credentials' => [
                'key' => $config['key'] ?? '',
                'secret' => $config['secret'] ?? '',
            ],
        ];

        if (! empty($config['endpoint'])) {
            $clientConfig['endpoint'] = $config['endpoint'];
        }

        $clientConfig['use_path_style_endpoint'] = (bool) ($config['use_path_style_endpoint'] ?? true);

        return new S3Client($clientConfig);
    }
}
