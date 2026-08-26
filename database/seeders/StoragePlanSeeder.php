<?php

namespace Database\Seeders;

use App\Models\StoragePlan;
use App\Models\User;
use App\Models\UserPlanAssignment;
use App\Modules\StoragePlans\Services\PlanAssignmentService;
use App\Modules\StoragePlans\Support\StorageBytes;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class StoragePlanSeeder extends Seeder
{
    public function run(): void
    {
        $gb = fn (float $n) => StorageBytes::fromGib($n);

        $plans = [
            [
                'name' => 'Free',
                'slug' => 'free',
                'description' => 'Default plan. 5 GB stored, 15 GB monthly access. Owner only. Renews yearly.',
                'storage_limit_bytes' => $gb(5),
                'monthly_access_limit_bytes' => $gb(15),
                'streaming_limit_bytes' => $gb(10),
                'download_limit_bytes' => $gb(5),
                'file_view_limit_bytes' => $gb(15),
                'display_price_cents' => 0,
                'billing_period' => PlanAssignmentService::PERIOD_YEARLY,
                'is_shared' => false,
                'max_shared_members' => 0,
                'sort_order' => 10,
            ],
            [
                'name' => 'Personal',
                'slug' => 'personal',
                'description' => '100 GB stored, 300 GB monthly access. Single seat. $2.99 / month.',
                'storage_limit_bytes' => $gb(100),
                'monthly_access_limit_bytes' => $gb(300),
                'streaming_limit_bytes' => $gb(250),
                'download_limit_bytes' => $gb(100),
                'file_view_limit_bytes' => $gb(300),
                'display_price_cents' => 299,
                'billing_period' => PlanAssignmentService::PERIOD_MONTHLY,
                'is_shared' => false,
                'max_shared_members' => 0,
                'sort_order' => 20,
            ],
            [
                'name' => 'Plus',
                'slug' => 'plus',
                'description' => '200 GB stored, 600 GB monthly access. Shared, 4 members + owner. $4.99 / month.',
                'storage_limit_bytes' => $gb(200),
                'monthly_access_limit_bytes' => $gb(600),
                'streaming_limit_bytes' => $gb(500),
                'download_limit_bytes' => $gb(200),
                'file_view_limit_bytes' => $gb(600),
                'display_price_cents' => 499,
                'billing_period' => PlanAssignmentService::PERIOD_MONTHLY,
                'is_shared' => true,
                'max_shared_members' => 4,
                'sort_order' => 30,
            ],
            [
                'name' => 'Pro',
                'slug' => 'pro',
                'description' => '500 GB stored, 1.5 TB monthly access. Shared, 8 members + owner. $9.99 / month.',
                'storage_limit_bytes' => $gb(500),
                'monthly_access_limit_bytes' => $gb(1536),
                'streaming_limit_bytes' => $gb(1024),
                'download_limit_bytes' => $gb(500),
                'file_view_limit_bytes' => $gb(1536),
                'display_price_cents' => 999,
                'billing_period' => PlanAssignmentService::PERIOD_MONTHLY,
                'is_shared' => true,
                'max_shared_members' => 8,
                'sort_order' => 40,
            ],
        ];

        $freePlan = null;
        $assignmentService = app(PlanAssignmentService::class);

        foreach ($plans as $data) {
            $shared = (bool) $data['is_shared'];
            $maxMembers = (int) $data['max_shared_members'];
            $storage = (int) $data['storage_limit_bytes'];

            $plan = StoragePlan::query()->updateOrCreate(
                ['slug' => $data['slug']],
                [
                    'uuid' => StoragePlan::query()->where('slug', $data['slug'])->value('uuid') ?? (string) Str::uuid(),
                    'name' => $data['name'],
                    'description' => $data['description'],
                    'quota_bytes' => $storage,
                    'storage_limit_bytes' => $storage,
                    'monthly_access_limit_bytes' => $data['monthly_access_limit_bytes'],
                    'streaming_limit_bytes' => $data['streaming_limit_bytes'],
                    'download_limit_bytes' => $data['download_limit_bytes'],
                    'file_view_limit_bytes' => $data['file_view_limit_bytes'],
                    'display_price_cents' => $data['display_price_cents'],
                    'currency' => 'USD',
                    'billing_period' => $data['billing_period'],
                    'is_shared' => $shared,
                    'max_shared_members' => $maxMembers,
                    'max_users' => $shared ? 1 + $maxMembers : 1,
                    'warning_percentage' => 80,
                    'soft_limit_percentage' => 90,
                    'hard_limit_percentage' => 100,
                    'is_active' => true,
                    'sort_order' => $data['sort_order'],
                ]
            );

            if ($data['slug'] === 'free') {
                $freePlan = $plan;
            }
        }

        if ($freePlan) {
            User::query()->each(function (User $user) use ($assignmentService) {
                if (UserPlanAssignment::query()->where('user_id', $user->id)->where('is_active', true)->exists()) {
                    $assignmentService->ensureDefaultFreePlan($user);

                    return;
                }

                $assignmentService->ensureDefaultFreePlan($user);
            });
        }

        $this->command?->info('Seeded storage plans: Free, Personal, Plus, Pro');
    }
}
