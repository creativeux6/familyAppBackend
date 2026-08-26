<?php

namespace Tests\Feature\Storage;

use App\Models\MediaFile;
use App\Models\PlanAssignmentMember;
use App\Models\StoragePlan;
use App\Models\UserPlanAssignment;
use App\Models\UserStorageUsage;
use App\Modules\StoragePlans\Services\PlanAssignmentService;
use App\Modules\StoragePlans\Services\PlanBillingService;
use App\Modules\StoragePlans\Services\StoragePoolService;
use App\Modules\StoragePlans\Services\StorageQuotaService;
use App\Modules\StoragePlans\Support\StorageBytes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesTestUsers;
use Tests\TestCase;

class PlanUsageAndMembershipTest extends TestCase
{
    use CreatesTestUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config([
            'media.disk' => 'local',
            'payments.stub_succeed' => true,
            'media.access_soft_remaining_bytes' => 512 * 1024 * 1024,
            'media.large_file_bytes' => 100 * 1024 * 1024,
        ]);
    }

    public function test_cycle_starts_owner_only_and_members_lock(): void
    {
        $owner = $this->createUserWithFamily(['display_name' => 'Owner']);
        $member = $this->createUserWithFamily(['display_name' => 'Member']);
        $this->connectUsers($owner, $member);

        $plus = $this->makeSharedPlan(4);
        $assignments = app(PlanAssignmentService::class);
        $assignments->changePlan($owner, $plus, $owner, 'admin_manual');

        $pool = app(StoragePoolService::class);
        $payload = $pool->membershipPayload($owner);
        $this->assertTrue($payload['is_owner']);
        $this->assertSame(0, $payload['member_count']);
        $this->assertSame(4, $payload['empty_seats']);

        $pool->addMember($owner, $member);
        $payload = $pool->membershipPayload($owner);
        $this->assertSame(1, $payload['member_count']);
        $this->assertSame(3, $payload['empty_seats']);
        $this->assertTrue(collect($payload['members'])->every(fn ($row) => $row['locked'] === true));

        try {
            $pool->addMember($owner, $member);
            $this->fail('Expected duplicate member to fail.');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors()['user_uuid'] ?? []);
        }
    }

    public function test_upgrade_opens_extra_empty_seats_without_resetting_roster(): void
    {
        $owner = $this->createUserWithFamily();
        $member = $this->createUserWithFamily();
        $this->connectUsers($owner, $member);
        $plus = $this->makeSharedPlan(1);
        $pro = $this->makeSharedPlan(3, 'pro-test', 'Pro Test', 999);

        $assignments = app(PlanAssignmentService::class);
        $assignments->changePlan($owner, $plus, $owner, 'admin_manual');
        app(StoragePoolService::class)->addMember($owner, $member);

        $assignments->changePlan($owner, $pro, $owner, 'admin_manual');
        $payload = app(StoragePoolService::class)->membershipPayload($owner);

        $this->assertSame(1, $payload['member_count']);
        $this->assertSame(2, $payload['empty_seats']);
        $this->assertSame('pro-test', $assignments->activeAssignment($owner)?->plan?->slug);
    }

    public function test_downgrade_waits_until_next_cycle_then_resets_roster(): void
    {
        $owner = $this->createUserWithFamily();
        $member = $this->createUserWithFamily();
        $this->connectUsers($owner, $member);
        $plus = $this->makeSharedPlan(4);
        $personal = $this->makeSinglePlan();

        $assignments = app(PlanAssignmentService::class);
        $assignments->changePlan($owner, $plus, $owner, 'admin_manual');
        app(StoragePoolService::class)->addMember($owner, $member);

        $assignment = $assignments->changePlan($owner, $personal, $owner, 'admin_manual');
        $this->assertSame($plus->uuid, $assignment->storage_plan_uuid);
        $this->assertSame($personal->uuid, $assignment->pending_storage_plan_uuid);
        $this->assertSame(1, app(StoragePoolService::class)->membershipPayload($owner)['member_count']);

        $assignment->update(['ends_at' => now()->subMinute()]);
        $assignments->renewAssignment($assignment->fresh(['plan', 'pendingPlan', 'user']));

        $renewed = $assignments->activeAssignment($owner);
        $this->assertSame($personal->uuid, $renewed?->storage_plan_uuid);
        $this->assertNull($renewed?->pending_storage_plan_uuid);
        $this->assertSame(0, app(StoragePoolService::class)->membershipPayload($owner)['member_count']);
    }

    public function test_payment_lock_blocks_open_but_allows_delete(): void
    {
        $user = $this->createUserWithFamily();
        $quota = app(StorageQuotaService::class);
        $quota->ensureAccessPeriod($user);

        $assignment = app(PlanAssignmentService::class)->activeAssignment($user);
        $assignment->update(['billing_status' => UserPlanAssignment::STATUS_MEDIA_LOCKED]);

        $media = new MediaFile([
            'size_bytes' => 1024,
            'metadata' => ['source' => 'gallery'],
        ]);

        try {
            $quota->assertCanOpenMedia($user->fresh(), $media);
            $this->fail('Expected payment lock.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('locked', strtolower($e->errors()['storage'][0] ?? ''));
        }

        try {
            $quota->assertCanStore($user->fresh(), 100);
            $this->fail('Expected payment lock on upload.');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors()['storage'] ?? $e->errors()['size_bytes'] ?? []);
        }

        $quota->removeStoredUsage($user->fresh(), 1);
    }

    public function test_failed_retry_then_grace_lock(): void
    {
        config(['payments.stub_succeed' => false, 'payments.grace_days' => 3, 'payments.max_auto_retries' => 3]);

        $user = $this->createUserWithFamily();
        $paid = $this->makeSinglePlan();
        $assignments = app(PlanAssignmentService::class);

        try {
            $assignments->changePlan($user, $paid, $user, 'payment');
            $this->fail('Upgrade should fail when stub payment fails.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('payment', $e->errors());
        }

        $assignment = $assignments->activeAssignment($user);
        $assignment->update([
            'storage_plan_uuid' => $paid->uuid,
            'billing_status' => UserPlanAssignment::STATUS_PAST_DUE,
            'last_payment_failed_at' => now()->subDays(4),
            'payment_retry_count' => 3,
        ]);

        app(PlanBillingService::class)->lockExpiredGrace();
        $this->assertSame(
            UserPlanAssignment::STATUS_MEDIA_LOCKED,
            $assignment->fresh()->billing_status,
        );
    }

    public function test_split_meters_and_logs(): void
    {
        $user = $this->createUserWithFamily();
        $quota = app(StorageQuotaService::class);
        $quota->addStoredUsage($user, 1000);
        $quota->chargeReadTransfer($user, 200, false, StorageQuotaService::ACTION_STREAM, 'media-a');
        $quota->chargeReadTransfer($user, 300, false, StorageQuotaService::ACTION_DOWNLOAD, 'media-a');
        $quota->chargeReadTransfer($user, 50, false, StorageQuotaService::ACTION_FILE_VIEW, 'media-a');
        $quota->chargeReadTransfer($user, 50, false, StorageQuotaService::ACTION_FILE_VIEW, 'media-a');

        $usage = UserStorageUsage::query()->whereNull('closed_at')->first();
        $this->assertNotNull($usage);
        $this->assertSame(1000, (int) $usage->storage_used_bytes);
        $this->assertSame(200, (int) $usage->streamed_bytes);
        $this->assertSame(300, (int) $usage->downloaded_bytes);
        $this->assertSame(50, (int) $usage->file_viewed_bytes);
        $this->assertSame(550, (int) $usage->monthly_access_bytes);
        $this->assertSame(3, \App\Models\StorageUsageLog::query()->whereIn('action', ['stream', 'download', 'file_view'])->count());
    }

    public function test_admin_dashboard_includes_b2_totals(): void
    {
        \Spatie\Permission\Models\Role::findOrCreate('admin', 'web');
        $admin = $this->createUserWithFamily(['display_name' => 'Admin']);
        $admin->assignRole('admin');
        $this->actingAsUser($admin);

        $this->getJson('/api/v1/admin/dashboard')
            ->assertSuccessful()
            ->assertJsonStructure([
                'users_total',
                'active_subscribers',
                'storage_used_bytes',
                'streamed_bytes',
                'downloaded_bytes',
                'estimated_b2_storage_usd',
                'estimated_revenue_usd',
                'estimated_gross_margin_usd',
            ]);
    }

    private function makeSharedPlan(int $seats, string $slug = 'plus-test', string $name = 'Plus Test', int $price = 499): StoragePlan
    {
        $gb = fn (float $n) => StorageBytes::fromGib($n);

        return StoragePlan::query()->create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'name' => $name,
            'slug' => $slug,
            'quota_bytes' => $gb(200),
            'storage_limit_bytes' => $gb(200),
            'monthly_access_limit_bytes' => $gb(600),
            'streaming_limit_bytes' => $gb(500),
            'download_limit_bytes' => $gb(200),
            'file_view_limit_bytes' => $gb(600),
            'display_price_cents' => $price,
            'currency' => 'USD',
            'billing_period' => 'monthly',
            'is_shared' => true,
            'max_shared_members' => $seats,
            'max_users' => 1 + $seats,
            'is_active' => true,
            'sort_order' => 30,
        ]);
    }

    private function makeSinglePlan(): StoragePlan
    {
        $gb = fn (float $n) => StorageBytes::fromGib($n);

        return StoragePlan::query()->create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'name' => 'Personal Test',
            'slug' => 'personal-test',
            'quota_bytes' => $gb(100),
            'storage_limit_bytes' => $gb(100),
            'monthly_access_limit_bytes' => $gb(300),
            'streaming_limit_bytes' => $gb(250),
            'download_limit_bytes' => $gb(100),
            'file_view_limit_bytes' => $gb(300),
            'display_price_cents' => 299,
            'currency' => 'USD',
            'billing_period' => 'monthly',
            'is_shared' => false,
            'max_shared_members' => 0,
            'max_users' => 1,
            'is_active' => true,
            'sort_order' => 20,
        ]);
    }
}
