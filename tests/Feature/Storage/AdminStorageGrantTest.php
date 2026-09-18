<?php

namespace Tests\Feature\Storage;

use App\Models\StoragePlan;
use App\Models\UserStorageUsage;
use App\Modules\StoragePlans\Services\PlanAssignmentService;
use App\Modules\StoragePlans\Services\StorageQuotaService;
use App\Modules\StoragePlans\Support\StorageBytes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTestUsers;
use Tests\TestCase;

class AdminStorageGrantTest extends TestCase
{
    use CreatesTestUsers;
    use RefreshDatabase;

    public function test_admin_can_set_free_plan_storage_limit_and_access_scales(): void
    {
        \Spatie\Permission\Models\Role::findOrCreate('admin', 'web');
        $admin = $this->createUserWithFamily(['display_name' => 'Admin']);
        $admin->assignRole('admin');
        $target = $this->createUserWithFamily(['display_name' => 'Free user']);

        $quota = app(StorageQuotaService::class);
        $quota->ensureAccessPeriod($target);

        $this->actingAsUser($admin);
        $response = $this->patchJson("/api/v1/admin/users/{$target->uuid}/storage-grant", [
            'storage_limit_gb' => 50,
        ]);

        $expectedStorage = StorageBytes::fromGib(50);
        $expectedAccess = $expectedStorage * 3;

        $response->assertSuccessful()
            ->assertJsonPath('storage.quota_bytes', $expectedStorage)
            ->assertJsonPath('storage.access_quota_bytes', $expectedAccess);

        $this->assertSame($expectedStorage, $quota->quotaBytes($target->fresh()));
        $this->assertSame($expectedAccess, $quota->accessQuotaBytes($target->fresh()));
        $quota->assertCanStore($target->fresh(), StorageBytes::fromGib(40));
    }

    public function test_storage_grant_rejected_for_paid_plan_until_make_free(): void
    {
        \Spatie\Permission\Models\Role::findOrCreate('admin', 'web');
        $admin = $this->createUserWithFamily(['display_name' => 'Admin']);
        $admin->assignRole('admin');
        $target = $this->createUserWithFamily(['display_name' => 'Paid user']);

        $personal = StoragePlan::query()->where('slug', 'personal')->first()
            ?? StoragePlan::query()->create([
                'uuid' => (string) \Illuminate\Support\Str::uuid(),
                'name' => 'Personal',
                'slug' => 'personal',
                'quota_bytes' => StorageBytes::fromGib(100),
                'storage_limit_bytes' => StorageBytes::fromGib(100),
                'monthly_access_limit_bytes' => StorageBytes::fromGib(300),
                'streaming_limit_bytes' => StorageBytes::fromGib(250),
                'download_limit_bytes' => StorageBytes::fromGib(100),
                'file_view_limit_bytes' => StorageBytes::fromGib(300),
                'display_price_cents' => 299,
                'billing_period' => PlanAssignmentService::PERIOD_MONTHLY,
                'is_active' => true,
                'is_shared' => false,
                'max_shared_members' => 0,
                'sort_order' => 20,
            ]);

        app(PlanAssignmentService::class)->applyImmediatePlan($target, $personal, 'admin_manual');

        $this->actingAsUser($admin);
        $this->patchJson("/api/v1/admin/users/{$target->uuid}/storage-grant", [
            'storage_limit_gb' => 50,
        ])->assertStatus(422);

        $this->postJson("/api/v1/admin/users/{$target->uuid}/make-free")
            ->assertSuccessful()
            ->assertJsonPath('storage.plan.slug', 'free');

        $this->patchJson("/api/v1/admin/users/{$target->uuid}/storage-grant", [
            'storage_limit_gb' => 50,
        ])->assertSuccessful()
            ->assertJsonPath('storage.quota_bytes', StorageBytes::fromGib(50));
    }

    public function test_clear_storage_grant_restores_free_plan_limits(): void
    {
        \Spatie\Permission\Models\Role::findOrCreate('admin', 'web');
        $admin = $this->createUserWithFamily(['display_name' => 'Admin']);
        $admin->assignRole('admin');
        $target = $this->createUserWithFamily(['display_name' => 'Free user']);
        app(StorageQuotaService::class)->ensureAccessPeriod($target);

        $this->actingAsUser($admin);
        $this->patchJson("/api/v1/admin/users/{$target->uuid}/storage-grant", [
            'storage_limit_gb' => 80,
        ])->assertSuccessful();

        $this->patchJson("/api/v1/admin/users/{$target->uuid}/storage-grant", [
            'clear' => true,
        ])->assertSuccessful();

        $quota = app(StorageQuotaService::class);
        $planLimit = (int) app(PlanAssignmentService::class)
            ->activeAssignment($target)
            ?->plan
            ?->storageLimitBytes();

        $this->assertSame($planLimit, $quota->quotaBytes($target->fresh()));
    }

    public function test_free_plan_renew_keeps_previous_storage_limits(): void
    {
        $user = $this->createUserWithFamily(['display_name' => 'Free renew']);
        $assignments = app(PlanAssignmentService::class);
        $quota = app(StorageQuotaService::class);
        $quota->ensureAccessPeriod($user);

        $assignment = $assignments->activeAssignment($user);
        $this->assertNotNull($assignment);
        $this->assertSame('free', $assignment->plan?->slug);

        $customStorage = StorageBytes::fromGib(40);
        $usage = UserStorageUsage::query()
            ->where('assignment_id', $assignment->id)
            ->whereNull('closed_at')
            ->firstOrFail();
        $usage->storage_limit_bytes = $customStorage;
        $usage->monthly_access_limit_bytes = $customStorage * 3;
        $usage->streaming_limit_bytes = $customStorage * 2;
        $usage->download_limit_bytes = $customStorage;
        $usage->file_view_limit_bytes = $customStorage * 3;
        $usage->downloaded_bytes = StorageBytes::fromGib(2);
        $usage->syncMonthlyAccess();
        $usage->save();

        $assignment->update(['ends_at' => now()->subMinute()]);
        $assignments->renewAssignment($assignment->fresh(['plan', 'user', 'pendingPlan']));

        $open = UserStorageUsage::query()
            ->where('assignment_id', $assignment->id)
            ->whereNull('closed_at')
            ->firstOrFail();

        $this->assertSame($customStorage, (int) $open->storage_limit_bytes);
        $this->assertSame($customStorage * 3, (int) $open->monthly_access_limit_bytes);
        $this->assertSame(0, (int) $open->monthly_access_bytes);
        $this->assertSame(0, (int) $open->downloaded_bytes);
        $this->assertSame($customStorage, $quota->quotaBytes($user->fresh()));
        $this->assertSame($customStorage * 3, $quota->accessQuotaBytes($user->fresh()));
    }
}
