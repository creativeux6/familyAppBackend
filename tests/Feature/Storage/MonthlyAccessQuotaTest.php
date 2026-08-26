<?php

namespace Tests\Feature\Storage;

use App\Models\MediaFile;
use App\Models\UserStorageUsage;
use App\Modules\StoragePlans\Services\StorageQuotaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesTestUsers;
use Tests\TestCase;

class MonthlyAccessQuotaTest extends TestCase
{
    use CreatesTestUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config([
            'media.disk' => 'local',
            'media.access_soft_remaining_bytes' => 512 * 1024 * 1024,
            'media.large_file_bytes' => 100 * 1024 * 1024,
            'media.access_upgrade_message' => 'Too many requests. Please upgrade your subscription.',
        ]);
    }

    public function test_user_quota_summary_hides_access_meters(): void
    {
        $user = $this->actingAsUser($this->createUserWithFamily());
        $quota = app(StorageQuotaService::class);
        $quota->ensureAccessPeriod($user);
        $usage = UserStorageUsage::query()->whereNull('closed_at')->first();
        $usage->storage_used_bytes = 1_000_000;
        $usage->save();
        $user->update([
            'storage_used_bytes' => 1_000_000,
            'storage_read_period_bytes' => 9_000_000_000,
        ]);

        $response = $this->getJson('/api/v1/storage/quota');
        $response->assertSuccessful()
            ->assertJsonPath('stored_bytes', 1_000_000)
            ->assertJsonPath('used_bytes', 1_000_000)
            ->assertJsonMissing(['access_used_bytes'])
            ->assertJsonMissing(['read_bytes']);
    }

    public function test_soft_gate_blocks_large_gallery_media_only(): void
    {
        $user = $this->createUserWithFamily();
        $quota = app(StorageQuotaService::class);
        $quota->ensureAccessPeriod($user);
        $accessQuota = $quota->accessQuotaBytes($user);

        $usage = UserStorageUsage::query()->whereNull('closed_at')->first();
        $this->assertNotNull($usage);
        $usage->downloaded_bytes = max(0, $accessQuota - (400 * 1024 * 1024));
        $usage->syncMonthlyAccess();
        $usage->save();
        $user = $user->fresh();

        $this->assertTrue($quota->isAccessSoftGated($user));

        $large = new MediaFile([
            'size_bytes' => 150 * 1024 * 1024,
            'metadata' => ['source' => 'gallery'],
        ]);
        $small = new MediaFile([
            'size_bytes' => 50 * 1024 * 1024,
            'metadata' => ['source' => 'gallery'],
        ]);

        try {
            $quota->assertCanOpenMedia($user, $large);
            $this->fail('Expected large media to be blocked when soft-gated.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString(
                'Too many requests',
                $e->errors()['storage'][0] ?? '',
            );
        }

        $quota->assertCanOpenMedia($user, $small);
    }

    public function test_admin_can_reset_access_usage(): void
    {
        \Spatie\Permission\Models\Role::findOrCreate('admin', 'web');

        $admin = $this->createUserWithFamily(['display_name' => 'Admin']);
        $admin->assignRole('admin');
        $target = $this->createUserWithFamily(['display_name' => 'Member']);
        $quota = app(StorageQuotaService::class);
        $quota->ensureAccessPeriod($target);
        $usage = UserStorageUsage::query()
            ->where('assignment_id', app(\App\Modules\StoragePlans\Services\PlanAssignmentService::class)->activeAssignment($target)->id)
            ->whereNull('closed_at')
            ->first();
        $usage->downloaded_bytes = 5_000_000_000;
        $usage->syncMonthlyAccess();
        $usage->save();
        $target->update([
            'storage_access_warn_level' => 2,
        ]);

        $this->actingAsUser($admin);
        $response = $this->postJson("/api/v1/admin/users/{$target->uuid}/access-usage/reset");
        $response->assertSuccessful()
            ->assertJsonPath('storage.access_used_bytes', 0);

        $this->assertSame(0, (int) $target->fresh()->storage_read_period_bytes);
        $this->assertSame(0, (int) $target->fresh()->storage_access_warn_level);
    }

    public function test_assert_can_store_ignores_access_usage(): void
    {
        $user = $this->createUserWithFamily();
        $quota = app(StorageQuotaService::class);
        $quota->ensureAccessPeriod($user);
        $usage = UserStorageUsage::query()->whereNull('closed_at')->first();
        $usage->downloaded_bytes = $quota->accessQuotaBytes($user);
        $usage->syncMonthlyAccess();
        $usage->save();

        $quota->assertCanStore($user->fresh(), 1024);
        $this->assertFalse($quota->isOverQuota($user->fresh()));
    }
}
