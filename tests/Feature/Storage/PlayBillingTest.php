<?php

namespace Tests\Feature\Storage;

use App\Models\StoragePlan;
use App\Models\UserPlanAssignment;
use App\Modules\StoragePlans\Contracts\GooglePlayClientInterface;
use App\Modules\StoragePlans\Support\StorageBytes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesTestUsers;
use Tests\Support\FakeGooglePlayClient;
use Tests\TestCase;

class PlayBillingTest extends TestCase
{
    use CreatesTestUsers;
    use RefreshDatabase;

    private FakeGooglePlayClient $play;

    protected function setUp(): void
    {
        parent::setUp();
        $this->play = new FakeGooglePlayClient;
        $this->app->instance(GooglePlayClientInterface::class, $this->play);
    }

    public function test_plan_change_rejects_paid_sku_without_play_purchase(): void
    {
        $user = $this->createUserWithFamily();
        $this->actingAsUser($user);
        $plan = $this->makePaidPlan();

        $this->postJson('/api/v1/storage/plan-change', [
            'storage_plan_uuid' => $plan->uuid,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['payment']);
    }

    public function test_plan_change_still_allows_free(): void
    {
        $user = $this->createUserWithFamily();
        $this->actingAsUser($user);

        $free = StoragePlan::query()->create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Free',
            'slug' => 'free-play-test',
            'quota_bytes' => StorageBytes::fromGib(6),
            'storage_limit_bytes' => StorageBytes::fromGib(6),
            'monthly_access_limit_bytes' => StorageBytes::fromGib(15),
            'display_price_cents' => 0,
            'currency' => 'USD',
            'billing_period' => 'yearly',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $this->postJson('/api/v1/storage/plan-change', [
            'storage_plan_uuid' => $free->uuid,
        ])->assertOk()
            ->assertJsonPath('plan.slug', 'free-play-test');
    }

    public function test_play_verify_applies_paid_plan(): void
    {
        $user = $this->createUserWithFamily();
        $this->actingAsUser($user);
        $plan = $this->makePaidPlan();
        $this->play->purchase['obfuscated_external_account_id'] = $user->uuid;

        $this->postJson('/api/v1/storage/play/verify', [
            'purchase_token' => 'token-abc',
            'product_id' => 'tijori_personal',
            'storage_plan_uuid' => $plan->uuid,
        ])->assertOk()
            ->assertJsonPath('source', 'google_play')
            ->assertJsonPath('plan.slug', 'personal-play');

        $assignment = UserPlanAssignment::query()->where('user_id', $user->id)->where('is_active', true)->first();
        $this->assertSame('token-abc', $assignment?->play_purchase_token);
        $this->assertTrue($this->play->acknowledged);
    }

    public function test_rtdn_expired_drops_to_free(): void
    {
        $user = $this->createUserWithFamily();
        $plan = $this->makePaidPlan();
        $this->play->purchase['obfuscated_external_account_id'] = $user->uuid;

        $this->actingAsUser($user);
        $this->postJson('/api/v1/storage/play/verify', [
            'purchase_token' => 'token-exp',
            'product_id' => 'tijori_personal',
            'storage_plan_uuid' => $plan->uuid,
        ])->assertOk();

        $inner = [
            'subscriptionNotification' => [
                'notificationType' => 13,
                'purchaseToken' => 'token-exp',
                'subscriptionId' => 'tijori_personal',
            ],
        ];

        $this->postJson('/api/v1/webhooks/google-play', [
            'message' => [
                'data' => base64_encode(json_encode($inner)),
            ],
        ])->assertOk();

        $assignment = UserPlanAssignment::query()->where('user_id', $user->id)->where('is_active', true)->first();
        $this->assertSame('system_default', $assignment?->source);
        $this->assertSame(0, (int) $assignment?->plan?->display_price_cents);
    }

    private function makePaidPlan(): StoragePlan
    {
        return StoragePlan::query()->create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Personal',
            'slug' => 'personal-play',
            'play_product_id' => 'tijori_personal',
            'quota_bytes' => StorageBytes::fromGib(100),
            'storage_limit_bytes' => StorageBytes::fromGib(100),
            'monthly_access_limit_bytes' => StorageBytes::fromGib(300),
            'display_price_cents' => 299,
            'currency' => 'USD',
            'billing_period' => 'monthly',
            'is_active' => true,
            'sort_order' => 20,
        ]);
    }
}
