<?php

namespace App\Console\Commands;

use App\Models\MediaFile;
use App\Models\User;
use App\Modules\Media\Services\MediaUploadService;
use App\Modules\StoragePlans\Services\StorageQuotaService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MediaB2SmokeCommand extends Command
{
    protected $signature = 'media:b2-smoke {--user= : User uuid to upload through the media API}';

    protected $description = 'Write a tiny probe object to B2 under MEDIA_KEY_PREFIX and upload via the media API.';

    public function handle(MediaUploadService $uploads, StorageQuotaService $quota): int
    {
        $diskName = (string) config('media.disk');
        $keyId = (string) config('filesystems.disks.b2.key');
        $appKey = (string) config('filesystems.disks.b2.secret');

        if ($diskName !== 'b2' || $keyId === '' || $appKey === '') {
            $this->warn('MEDIA_DISK is not b2 or B2 credentials are missing; skipping live B2 smoke.');

            return self::SUCCESS;
        }

        $prefix = trim((string) config('media.key_prefix', 'tagori/media'), '/') ?: 'tagori/media';
        $bucket = (string) config('filesystems.disks.b2.bucket');
        $probeKey = $prefix.'/smoke/'.Str::uuid().'.txt';
        $body = 'tijori-b2-smoke '.now()->toIso8601String();

        $disk = Storage::disk('b2');
        $disk->put($probeKey, $body);

        if (! $disk->exists($probeKey)) {
            $this->error("Probe object missing after put: {$probeKey}");

            return self::FAILURE;
        }

        $this->info("Wrote probe {$probeKey} in bucket {$bucket}.");
        $disk->delete($probeKey);
        $this->info('Deleted probe object.');

        $layoutKey = $prefix.'/'.Str::uuid().'/'.Str::uuid();
        $disk->put($layoutKey, $body);
        if (! $disk->exists($layoutKey) || ! str_starts_with($layoutKey, $prefix.'/')) {
            $this->error("Layout object missing under MEDIA_KEY_PREFIX: {$layoutKey}");

            return self::FAILURE;
        }
        $this->info("Wrote layout object {$layoutKey}.");
        $disk->delete($layoutKey);
        $this->info('Deleted layout object.');

        try {
            $userUuid = $this->option('user');
            $user = $userUuid
                ? User::query()->where('uuid', $userUuid)->first()
                : User::query()->orderBy('id')->first();
        } catch (\Throwable $e) {
            $this->warn('Database is not reachable; skipped media API metering smoke. B2 prefix writes succeeded.');

            return self::SUCCESS;
        }

        if (! $user) {
            $this->warn('No user available for media API smoke; prefix writes finished.');

            return self::SUCCESS;
        }

        $payload = 'smoke-api-'.Str::random(8);
        $init = $uploads->initiate(
            $user,
            'b2-smoke.bin',
            strlen($payload),
            'application/octet-stream',
            hash('sha256', $payload),
            1,
            null,
            ['source' => 'gallery', 'visibility' => 'private'],
        );
        $uuid = (string) ($init['uuid'] ?? '');
        $media = MediaFile::query()->where('uuid', $uuid)->first();
        $objectKey = (string) ($media?->s3_key ?? '');

        if ($uuid === '' || $objectKey === '' || ! str_starts_with($objectKey, $prefix.'/')) {
            $this->error('Initiate did not return a key under MEDIA_KEY_PREFIX.');

            return self::FAILURE;
        }

        $this->info("Initiated media {$uuid} key={$objectKey}");

        $uploads->uploadContent($user, $uuid, $payload);
        $uploads->complete($user, $uuid);

        if (! $disk->exists($objectKey)) {
            $this->error("Media object missing after complete: {$objectKey}");

            return self::FAILURE;
        }

        $quota->chargeReadTransfer(
            $user->fresh(),
            strlen($payload),
            false,
            StorageQuotaService::ACTION_DOWNLOAD,
            $uuid,
        );

        $this->info('Completed media API upload and metered one download.');

        $uploads->delete($user->fresh(), $uuid);

        if ($disk->exists($objectKey)) {
            $this->error("Media object still present after delete: {$objectKey}");

            return self::FAILURE;
        }

        $this->info('Deleted smoke media object.');

        return self::SUCCESS;
    }
}
