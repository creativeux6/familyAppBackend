<?php

namespace Tests\Feature\Storage;

use App\Models\FamilyMember;
use App\Models\MediaFile;
use App\Models\MediaLibraryItem;
use App\Models\UserStorageUsage;
use App\Modules\StoragePlans\Services\StorageQuotaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesTestUsers;
use Tests\TestCase;

class StoredUsageInventoryTest extends TestCase
{
    use CreatesTestUsers;
    use RefreshDatabase;

    public function test_quota_counts_active_media_thumbnails_and_avatars(): void
    {
        $user = $this->actingAsUser($this->createUserWithFamily());
        $user->forceFill([
            'avatar_master_bytes' => 1200,
            'avatar_thumb_bytes' => 300,
        ])->save();

        MediaFile::query()->create([
            'uuid' => (string) Str::uuid(),
            'owner_user_id' => $user->id,
            'uploaded_by_user_id' => $user->id,
            's3_bucket' => 'local',
            's3_key' => 'test/media-a',
            'display_name' => 'a.bin',
            'size_bytes' => 4000,
            'thumbnail_size_bytes' => 250,
            'mime_type' => 'application/octet-stream',
            'checksum_sha256' => hash('sha256', 'a'),
            'encryption_version' => 1,
            'status' => 'active',
        ]);

        MediaFile::query()->create([
            'uuid' => (string) Str::uuid(),
            'owner_user_id' => $user->id,
            'uploaded_by_user_id' => $user->id,
            's3_bucket' => 'local',
            's3_key' => 'test/media-pending',
            'display_name' => 'pending.bin',
            'size_bytes' => 99999,
            'mime_type' => 'application/octet-stream',
            'checksum_sha256' => hash('sha256', 'p'),
            'encryption_version' => 1,
            'status' => 'pending_upload',
        ]);

        $this->getJson('/api/v1/storage/quota')
            ->assertSuccessful()
            ->assertJsonPath('stored_bytes', 5750)
            ->assertJsonPath('used_bytes', 5750);
    }

    public function test_quota_corrects_stale_counters(): void
    {
        $user = $this->actingAsUser($this->createUserWithFamily());
        app(StorageQuotaService::class)->ensureAccessPeriod($user);
        $user->update(['storage_used_bytes' => 50_000_000]);
        $usage = UserStorageUsage::query()->whereNull('closed_at')->first();
        $this->assertNotNull($usage);
        $usage->update(['storage_used_bytes' => 50_000_000]);

        MediaFile::query()->create([
            'uuid' => (string) Str::uuid(),
            'owner_user_id' => $user->id,
            'uploaded_by_user_id' => $user->id,
            's3_bucket' => 'local',
            's3_key' => 'test/media-real',
            'display_name' => 'real.bin',
            'size_bytes' => 2048,
            'mime_type' => 'application/octet-stream',
            'checksum_sha256' => hash('sha256', 'r'),
            'encryption_version' => 1,
            'status' => 'active',
        ]);

        $this->getJson('/api/v1/storage/quota')
            ->assertSuccessful()
            ->assertJsonPath('stored_bytes', 2048)
            ->assertJsonPath('used_bytes', 2048);
    }

    public function test_quota_skips_chat_co_owner_files_for_uploader(): void
    {
        $uploader = $this->actingAsUser($this->createUserWithFamily());
        $recipient = $this->createUserWithFamily();

        $media = MediaFile::query()->create([
            'uuid' => (string) Str::uuid(),
            'owner_user_id' => $uploader->id,
            'uploaded_by_user_id' => $uploader->id,
            's3_bucket' => 'local',
            's3_key' => 'test/chat-co',
            'display_name' => 'chat.bin',
            'size_bytes' => 8000,
            'mime_type' => 'application/octet-stream',
            'checksum_sha256' => hash('sha256', 'c'),
            'encryption_version' => 1,
            'status' => 'active',
            'metadata' => [
                'source' => 'chat',
                'storage_mode' => 'co_owner',
            ],
        ]);

        MediaLibraryItem::query()->create([
            'media_file_uuid' => $media->uuid,
            'user_id' => $recipient->id,
            'quota_charged_at' => now(),
            'stream_bytes_charged' => 8000,
        ]);

        $this->getJson('/api/v1/storage/quota')
            ->assertSuccessful()
            ->assertJsonPath('stored_bytes', 0);

        $this->actingAsUser($recipient);
        $this->getJson('/api/v1/storage/quota')
            ->assertSuccessful()
            ->assertJsonPath('stored_bytes', 8000);
    }

    public function test_quota_counts_unregistered_member_avatars_for_uploader(): void
    {
        $user = $this->actingAsUser($this->createUserWithFamily());
        $familyUuid = FamilyMember::query()->where('user_id', $user->id)->value('family_uuid');

        FamilyMember::query()->create([
            'uuid' => (string) Str::uuid(),
            'family_uuid' => $familyUuid,
            'user_id' => null,
            'first_name' => 'Aunt',
            'last_name' => 'Member',
            'gender' => 'female',
            'avatar_master_bytes' => 900,
            'avatar_thumb_bytes' => 100,
            'avatar_updated_by_user_id' => $user->id,
        ]);

        $this->getJson('/api/v1/storage/quota')
            ->assertSuccessful()
            ->assertJsonPath('stored_bytes', 1000);
    }
}
