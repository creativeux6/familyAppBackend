<?php

namespace App\Console\Commands;

use App\Models\MediaFile;
use App\Modules\Media\Services\MediaChunkedUploadService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SweepPendingMediaUploadsCommand extends Command
{
    protected $signature = 'media:sweep-pending-uploads
                            {--hours= : Override stale threshold in hours}';

    protected $description = 'Abort and soft-delete abandoned pending media uploads older than the configured threshold.';

    public function handle(MediaChunkedUploadService $chunkedUploadService): int
    {
        $hours = (int) ($this->option('hours') ?: config('media.pending_upload_ttl_hours', 24));
        $cutoff = now()->subHours(max(1, $hours));

        $query = MediaFile::query()
            ->whereIn('status', ['pending_upload', 'finalizing'])
            ->where('created_at', '<', $cutoff);

        $count = 0;

        $query->orderBy('created_at')->chunkById(50, function ($files) use ($chunkedUploadService, &$count) {
            foreach ($files as $media) {
                try {
                    $chunkedUploadService->cleanupPartialUpload($media);
                    $media->update([
                        'status' => 'deleted',
                        'multipart_upload_id' => null,
                        'uploaded_parts' => null,
                    ]);
                    $media->delete();
                    $count++;
                } catch (\Throwable $e) {
                    Log::warning('Failed to sweep pending media upload', [
                        'media_uuid' => $media->uuid,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }, 'uuid');

        $this->info("Swept {$count} stale pending upload(s).");

        return self::SUCCESS;
    }
}
