<?php

namespace App\Modules\Admin\Services;

use App\Models\AbuseReport;
use App\Models\Family;
use App\Models\Group;
use App\Models\MediaFile;
use App\Models\User;
use App\Models\UserPlanAssignment;
use App\Models\UserStorageUsage;
use App\Modules\StoragePlans\Support\B2CostEstimator;

class AdminDashboardService
{
    public function __construct(
        private readonly B2CostEstimator $costEstimator,
    ) {}

    public function stats(): array
    {
        $openUsage = UserStorageUsage::query()->whereNull('closed_at');

        $stored = (int) (clone $openUsage)->sum('storage_used_bytes');
        $streamed = (int) (clone $openUsage)->sum('streamed_bytes');
        $downloaded = (int) (clone $openUsage)->sum('downloaded_bytes');
        $viewed = (int) (clone $openUsage)->sum('file_viewed_bytes');
        $egress = $streamed + $downloaded + $viewed;
        $costs = $this->costEstimator->estimateFromTotals($stored, $egress);

        $paidActive = UserPlanAssignment::query()
            ->where('user_plan_assignments.is_active', true)
            ->where('user_plan_assignments.billing_status', UserPlanAssignment::STATUS_ACTIVE)
            ->whereHas('plan', fn ($q) => $q->where('display_price_cents', '>', 0));

        $revenueCents = (int) UserPlanAssignment::query()
            ->where('user_plan_assignments.is_active', true)
            ->where('user_plan_assignments.billing_status', UserPlanAssignment::STATUS_ACTIVE)
            ->join('storage_plans', 'storage_plans.uuid', '=', 'user_plan_assignments.storage_plan_uuid')
            ->where('storage_plans.display_price_cents', '>', 0)
            ->sum('storage_plans.display_price_cents');
        $revenueUsd = $revenueCents / 100;

        return [
            'users_total' => User::query()->count(),
            'users_new_7d' => User::query()->where('created_at', '>=', now()->subDays(7))->count(),
            'families_total' => Family::query()->count(),
            'groups_total' => Group::query()->count(),
            'media_files_active' => MediaFile::query()->where('status', 'active')->count(),
            'abuse_reports_open' => AbuseReport::query()->where('status', 'open')->count(),
            'active_subscribers' => (clone $paidActive)->count(),
            'assignments_past_due' => UserPlanAssignment::query()
                ->where('is_active', true)
                ->where('billing_status', UserPlanAssignment::STATUS_PAST_DUE)
                ->count(),
            'assignments_media_locked' => UserPlanAssignment::query()
                ->where('is_active', true)
                ->where('billing_status', UserPlanAssignment::STATUS_MEDIA_LOCKED)
                ->count(),
            'storage_used_bytes' => $stored,
            'streamed_bytes' => $streamed,
            'downloaded_bytes' => $downloaded,
            'file_viewed_bytes' => $viewed,
            'estimated_b2_storage_usd' => $costs['storage_usd'],
            'estimated_b2_egress_usd' => $costs['egress_usd'],
            'estimated_b2_total_usd' => $costs['estimated_usd'],
            'estimated_revenue_usd' => round($revenueUsd, 4),
            'estimated_gross_margin_usd' => round($revenueUsd - $costs['estimated_usd'], 4),
        ];
    }
}
