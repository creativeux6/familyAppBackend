<?php

namespace Database\Seeders;

use App\Models\StoragePlan;
use App\Models\User;
use App\Models\UserPlanAssignment;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

class StoragePlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'name' => 'Free',
                'slug' => 'free',
                'quota_bytes' => 1 * 1024 * 1024 * 1024,
                'display_price_cents' => 0,
                'sort_order' => 10,
            ],
            [
                'name' => 'Family',
                'slug' => 'family',
                'quota_bytes' => 10 * 1024 * 1024 * 1024,
                'display_price_cents' => 0,
                'sort_order' => 20,
            ],
            [
                'name' => 'Premium',
                'slug' => 'premium',
                'quota_bytes' => 50 * 1024 * 1024 * 1024,
                'display_price_cents' => 999,
                'sort_order' => 30,
            ],
        ];

        $familyPlan = null;

        foreach ($plans as $data) {
            $plan = StoragePlan::query()->updateOrCreate(
                ['slug' => $data['slug']],
                [
                    'uuid' => StoragePlan::query()->where('slug', $data['slug'])->value('uuid') ?? (string) Str::uuid(),
                    'name' => $data['name'],
                    'quota_bytes' => $data['quota_bytes'],
                    'display_price_cents' => $data['display_price_cents'],
                    'currency' => 'USD',
                    'is_active' => true,
                    'sort_order' => $data['sort_order'],
                ]
            );

            if ($data['slug'] === 'family') {
                $familyPlan = $plan;
            }
        }

        if ($familyPlan) {
            User::query()->each(function (User $user) use ($familyPlan) {
                if (UserPlanAssignment::query()->where('user_id', $user->id)->where('is_active', true)->exists()) {
                    return;
                }

                UserPlanAssignment::create([
                    'user_id' => $user->id,
                    'storage_plan_uuid' => $familyPlan->uuid,
                    'source' => 'admin_manual',
                    'starts_at' => now(),
                    'is_active' => true,
                ]);
            });
        }

        $this->command?->info('Seeded storage plans: free, family, premium');
    }
}
