<?php

namespace Tests\Feature\Media;

use App\Models\MediaFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesTestUsers;
use Tests\TestCase;

class MediaUploadDurabilityTest extends TestCase
{
    use CreatesTestUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['media.disk' => 'local']);
    }

    public function test_complete_is_idempotent(): void
    {
        $this->actingAsUser($this->createUserWithFamily());
        $payload = str_repeat('a', 1024);

        $init = $this->postJson('/api/v1/media/uploads/initiate', [
            'display_name' => 'photo.bin',
            'size_bytes' => strlen($payload),
            'mime_type' => 'application/octet-stream',
            'checksum_sha256' => hash('sha256', $payload),
        ]);
        $init->assertSuccessful();
        $uuid = $init->json('uuid');

        $this->call(
            'PUT',
            "/api/v1/media/{$uuid}/content",
            server: $this->transformHeadersToServerVars([
                'CONTENT_TYPE' => 'application/octet-stream',
                'Accept' => 'application/json',
            ]),
            content: $payload,
        )->assertSuccessful();

        $first = $this->postJson("/api/v1/media/{$uuid}/complete");
        $first->assertSuccessful()->assertJsonPath('status', 'active');

        $second = $this->postJson("/api/v1/media/{$uuid}/complete");
        $second->assertSuccessful()->assertJsonPath('status', 'active');
        $this->assertSame($first->json('uuid'), $second->json('uuid'));

        $this->assertSame(1, MediaFile::query()->where('status', 'active')->count());
    }

    public function test_concurrent_chunk_parts_are_not_lost(): void
    {
        $this->actingAsUser($this->createUserWithFamily());
        $part1 = str_repeat('1', 512);
        $part2 = str_repeat('2', 512);
        $total = $part1.$part2;

        $init = $this->postJson('/api/v1/media/uploads/initiate', [
            'display_name' => 'chunked.bin',
            'size_bytes' => strlen($total),
            'mime_type' => 'application/octet-stream',
            'checksum_sha256' => hash('sha256', $total),
        ]);
        $init->assertSuccessful();
        $uuid = $init->json('uuid');

        MediaFile::query()->where('uuid', $uuid)->update(['chunk_size' => 512]);

        $this->call(
            'PUT',
            "/api/v1/media/{$uuid}/chunks/1",
            server: $this->transformHeadersToServerVars([
                'CONTENT_TYPE' => 'application/octet-stream',
                'Accept' => 'application/json',
            ]),
            content: $part1,
        )->assertSuccessful();

        $this->call(
            'PUT',
            "/api/v1/media/{$uuid}/chunks/2",
            server: $this->transformHeadersToServerVars([
                'CONTENT_TYPE' => 'application/octet-stream',
                'Accept' => 'application/json',
            ]),
            content: $part2,
        )->assertSuccessful();

        $status = $this->getJson("/api/v1/media/{$uuid}/upload/status")->assertSuccessful();
        $this->assertSame([1, 2], $status->json('uploaded_parts'));
        $this->assertSame(strlen($total), $status->json('uploaded_bytes'));

        $this->postJson("/api/v1/media/{$uuid}/complete")
            ->assertSuccessful()
            ->assertJsonPath('status', 'active');
    }

    public function test_sweeper_aborts_stale_pending_uploads(): void
    {
        $this->actingAsUser($this->createUserWithFamily());

        $init = $this->postJson('/api/v1/media/uploads/initiate', [
            'display_name' => 'stale.bin',
            'size_bytes' => 100,
            'mime_type' => 'application/octet-stream',
            'checksum_sha256' => hash('sha256', 'stale'),
        ]);
        $init->assertSuccessful();
        $uuid = $init->json('uuid');

        MediaFile::query()->where('uuid', $uuid)->update([
            'created_at' => now()->subHours(48),
            'updated_at' => now()->subHours(48),
        ]);

        Artisan::call('media:sweep-pending-uploads', ['--hours' => 24]);

        $this->assertNull(MediaFile::query()->where('uuid', $uuid)->first());
        $this->assertNotNull(
            MediaFile::withTrashed()->where('uuid', $uuid)->where('status', 'deleted')->first()
        );
    }

    public function test_object_keys_live_under_media_folder_not_bucket_root(): void
    {
        config(['media.key_prefix' => '']);
        $user = $this->actingAsUser($this->createUserWithFamily());

        $init = $this->postJson('/api/v1/media/uploads/initiate', [
            'display_name' => 'photo.bin',
            'size_bytes' => 32,
            'mime_type' => 'application/octet-stream',
            'checksum_sha256' => hash('sha256', 'x'),
        ]);
        $init->assertSuccessful();

        $key = MediaFile::query()->where('uuid', $init->json('uuid'))->value('s3_key');
        $this->assertNotNull($key);
        $this->assertStringStartsWith('tagori/media/'.$user->uuid.'/', $key);
        $this->assertStringNotContainsString('//', $key);
    }
}
