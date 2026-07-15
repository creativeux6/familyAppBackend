<?php

namespace Database\Seeders;

use App\Models\StoragePlan;
use App\Models\User;
use App\Models\UserPlanAssignment;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class StoragePlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'name' => 'Free',
                'slug' => 'free',
                'description' => 'Default plan for every new account. 5 GB combined storage and read/egress quota. Upgrade when you need more space.',
                'quota_bytes' => 5 * 1024 * 1024 * 1024,
                'display_price_cents' => 0,
                'sort_order' => 10,
            ],
            [
                'name' => 'Family',
                'slug' => 'family',
                'description' => 'Shared family media with 10 GB combined upload and read quota. Assigned by an admin until paid billing ships.',
                'quota_bytes' => 10 * 1024 * 1024 * 1024,
                'display_price_cents' => 0,
                'sort_order' => 20,
            ],
            [
                'name' => 'Premium',
                'slug' => 'premium',
                'description' => 'High-capacity plan with 50 GB combined upload and read quota for frequent gallery and video use.',
                'quota_bytes' => 50 * 1024 * 1024 * 1024,
                'display_price_cents' => 999,
                'sort_order' => 30,
            ],
        ];

        $freePlan = null;

        foreach ($plans as $data) {
            $plan = StoragePlan::query()->updateOrCreate(
                ['slug' => $data['slug']],
                [
                    'uuid' => StoragePlan::query()->where('slug', $data['slug'])->value('uuid') ?? (string) Str::uuid(),
                    'name' => $data['name'],
                    'description' => $data['description'],
                    'quota_bytes' => $data['quota_bytes'],
                    'display_price_cents' => $data['display_price_cents'],
                    'currency' => 'USD',
                    'is_active' => true,
                    'sort_order' => $data['sort_order'],
                ]
            );

            if ($data['slug'] === 'free') {
                $freePlan = $plan;
            }
        }

        // Default Free tier for every user without an active plan (5 GB).
        if ($freePlan) {
            User::query()->each(function (User $user) use ($freePlan) {
                if (UserPlanAssignment::query()->where('user_id', $user->id)->where('is_active', true)->exists()) {
                    return;
                }

                UserPlanAssignment::create([
                    'user_id' => $user->id,
                    'storage_plan_uuid' => $freePlan->uuid,
                    'source' => 'system_default',
                    'starts_at' => now(),
                    'is_active' => true,
                ]);
            });
        }

        $this->command?->info('Seeded storage plans: Free (5 GB), Family (10 GB), Premium (50 GB)');
    }
}
