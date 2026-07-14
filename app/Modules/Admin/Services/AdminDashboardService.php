<?php

namespace App\Modules\Admin\Services;

use App\Models\AbuseReport;
use App\Models\Family;
use App\Models\Group;
use App\Models\MediaFile;
use App\Models\User;

class AdminDashboardService
{
    public function stats(): array
    {
        return [
            'users_total' => User::query()->count(),
            'users_new_7d' => User::query()->where('created_at', '>=', now()->subDays(7))->count(),
            'families_total' => Family::query()->count(),
            'groups_total' => Group::query()->count(),
            'media_files_active' => MediaFile::query()->where('status', 'active')->count(),
            'abuse_reports_open' => AbuseReport::query()->where('status', 'open')->count(),
        ];
    }
}
