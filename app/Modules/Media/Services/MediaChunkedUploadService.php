<?php

namespace App\Modules\Media\Services;

use App\Models\MediaFile;
use App\Models\User;
use Aws\S3\S3Client;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class MediaChunkedUploadService
{
    public function __construct(
        private readonly MediaAccessService $accessService,
    ) {}

    public function chunkSize(): int
    {
        return max(1024 * 1024, (int) config('media.chunk_size_bytes', 5 * 1024 * 1024));
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

        $media = $this->accessService->requireMedia($uuid);
        $this->accessService->assertOwner($user, $media);

        if ($media->status !== 'pending_upload') {
            throw ValidationException::withMessages([
                'media' => ['Upload already completed or file is not pending.'],
            ]);
        }

        $chunkSize = (int) ($media->chunk_size ?: $this->chunkSize());
        $bodySize = strlen($binary);

        if ($bodySize < 1 || $bodySize > $chunkSize) {
            throw ValidationException::withMessages([
                'chunk' => ["Chunk size must be between 1 and {$chunkSize} bytes."],
            ]);
        }

        $diskName = (string) config('media.disk');
        $parts = collect($media->uploaded_parts ?? []);

        if ($parts->contains(fn (array $part) => (int) ($part['part_number'] ?? 0) === $partNumber)) {
            return array_merge(
                ['message' => 'Chunk already uploaded.', 'uuid' => $media->uuid],
                $this->formatUploadStatus($media->fresh())
            );
        }

        if ($diskName === 's3' && filled(config('filesystems.disks.s3.bucket'))) {
            $etag = $this->uploadS3Part($media, $partNumber, $binary);
            $parts->push([
                'part_number' => $partNumber,
                'etag' => $etag,
                'size_bytes' => $bodySize,
            ]);
        } else {
            $this->uploadLocalPart($media, $partNumber, $binary);
            $parts->push([
                'part_number' => $partNumber,
                'size_bytes' => $bodySize,
            ]);
        }

        $media->update([
            'uploaded_parts' => $parts->sortBy('part_number')->values()->all(),
            'chunk_size' => $chunkSize,
        ]);

        return array_merge(
            ['message' => 'Chunk uploaded.', 'uuid' => $media->uuid],
            $this->formatUploadStatus($media->fresh())
        );
    }

    /** @return array<string, mixed> */
    public function abortUpload(User $user, string $uuid): array
    {
        $media = $this->accessService->requireMedia($uuid);
        $this->accessService->assertOwner($user, $media);

        if ($media->status !== 'pending_upload') {
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
        $parts = collect($media->uploaded_parts ?? [])->sortBy('part_number')->values();

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

        if ($diskName === 's3' && filled($media->multipart_upload_id)) {
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
            ->pluck('part_number')
            ->map(fn ($value) => (int) $value)
            ->sort()
            ->values()
            ->all();
        $uploadedBytes = (int) collect($media->uploaded_parts ?? [])->sum('size_bytes');

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

        if ($diskName === 's3' && filled($media->multipart_upload_id)) {
            try {
                $this->s3Client()->abortMultipartUpload([
                    'Bucket' => (string) config('filesystems.disks.s3.bucket'),
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
        if (! filled($media->multipart_upload_id)) {
            $result = $this->s3Client()->createMultipartUpload([
                'Bucket' => (string) config('filesystems.disks.s3.bucket'),
                'Key' => $media->s3_key,
                'ContentType' => 'application/octet-stream',
            ]);

            $media->update(['multipart_upload_id' => $result['UploadId']]);
            $media = $media->fresh();
        }

        $result = $this->s3Client()->uploadPart([
            'Bucket' => (string) config('filesystems.disks.s3.bucket'),
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
        $this->s3Client()->completeMultipartUpload([
            'Bucket' => (string) config('filesystems.disks.s3.bucket'),
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

    private function s3Client(): S3Client
    {
        $config = config('filesystems.disks.s3');

        return new S3Client([
            'version' => 'latest',
            'region' => $config['region'],
            'credentials' => [
                'key' => $config['key'],
                'secret' => $config['secret'],
            ],
        ]);
    }
}
