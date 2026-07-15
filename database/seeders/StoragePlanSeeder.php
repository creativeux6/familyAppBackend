<?php

namespace Database\Seeders;

use App\Models\StoragePlan;
use App\Models\User;
use App\Models\UserPlanAssignment;
use App\Modules\StoragePlans\Services\PlanAssignmentService;
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
                'description' => 'Default plan for every new account. 5 GB combined storage and read/egress. Renews yearly.',
                'quota_bytes' => 5 * 1024 * 1024 * 1024,
                'display_price_cents' => 0,
                'billing_period' => PlanAssignmentService::PERIOD_YEARLY,
                'sort_order' => 10,
            ],
            [
                'name' => 'Family',
                'slug' => 'family',
                'description' => 'Shared family media with 10 GB combined upload and read quota. Renews monthly.',
                'quota_bytes' => 10 * 1024 * 1024 * 1024,
                'display_price_cents' => 0,
                'billing_period' => PlanAssignmentService::PERIOD_MONTHLY,
                'sort_order' => 20,
            ],
            [
                'name' => 'Premium',
                'slug' => 'premium',
                'description' => 'High-capacity plan with 50 GB combined upload and read quota. Renews monthly.',
                'quota_bytes' => 50 * 1024 * 1024 * 1024,
                'display_price_cents' => 999,
                'billing_period' => PlanAssignmentService::PERIOD_MONTHLY,
                'sort_order' => 30,
            ],
        ];

        $freePlan = null;
        $assignmentService = app(PlanAssignmentService::class);

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
                    'billing_period' => $data['billing_period'],
                    'is_active' => true,
                    'sort_order' => $data['sort_order'],
                ]
            );

            if ($data['slug'] === 'free') {
                $freePlan = $plan;
            }
        }

        // Default Free tier for every user without an active plan (5 GB, yearly renewal).
        if ($freePlan) {
            User::query()->each(function (User $user) use ($freePlan, $assignmentService) {
                if (UserPlanAssignment::query()->where('user_id', $user->id)->where('is_active', true)->exists()) {
                    return;
                }

                $assignmentService->assign($user, $freePlan, null, 'system_default');
            });
        }

        $this->command?->info('Seeded storage plans: Free (5 GB / yearly), Family (10 GB / monthly), Premium (50 GB / monthly)');
    }
}
