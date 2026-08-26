<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UserStorageUsage extends Model
{
    protected $table = 'user_storage_usage';

    protected $fillable = [
        'assignment_id',
        'plan_uuid',
        'storage_used_bytes',
        'monthly_access_bytes',
        'streamed_bytes',
        'downloaded_bytes',
        'file_viewed_bytes',
        'upload_requests',
        'download_requests',
        'stream_requests',
        'file_view_requests',
        'storage_limit_bytes',
        'monthly_access_limit_bytes',
        'streaming_limit_bytes',
        'download_limit_bytes',
        'file_view_limit_bytes',
        'max_shared_members',
        'is_shared',
        'period_start',
        'period_end',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'storage_used_bytes' => 'integer',
            'monthly_access_bytes' => 'integer',
            'streamed_bytes' => 'integer',
            'downloaded_bytes' => 'integer',
            'file_viewed_bytes' => 'integer',
            'upload_requests' => 'integer',
            'download_requests' => 'integer',
            'stream_requests' => 'integer',
            'file_view_requests' => 'integer',
            'storage_limit_bytes' => 'integer',
            'monthly_access_limit_bytes' => 'integer',
            'streaming_limit_bytes' => 'integer',
            'download_limit_bytes' => 'integer',
            'file_view_limit_bytes' => 'integer',
            'max_shared_members' => 'integer',
            'is_shared' => 'boolean',
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(UserPlanAssignment::class, 'assignment_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(StoragePlan::class, 'plan_uuid', 'uuid');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(StorageUsageLog::class, 'user_storage_usage_id');
    }

    public function syncMonthlyAccess(): void
    {
        $this->monthly_access_bytes = (int) $this->streamed_bytes
            + (int) $this->downloaded_bytes
            + (int) $this->file_viewed_bytes;
    }

    public function applyPlanSnapshot(StoragePlan $plan): void
    {
        $snapshot = $plan->limitSnapshot();
        $this->plan_uuid = $snapshot['plan_uuid'];
        $this->storage_limit_bytes = $snapshot['storage_limit_bytes'];
        $this->monthly_access_limit_bytes = $snapshot['monthly_access_limit_bytes'];
        $this->streaming_limit_bytes = $snapshot['streaming_limit_bytes'];
        $this->download_limit_bytes = $snapshot['download_limit_bytes'];
        $this->file_view_limit_bytes = $snapshot['file_view_limit_bytes'];
        $this->max_shared_members = $snapshot['max_shared_members'];
        $this->is_shared = $snapshot['is_shared'];
    }
}
