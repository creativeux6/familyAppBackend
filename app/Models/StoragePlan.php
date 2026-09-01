<?php

namespace App\Models;

use App\Modules\StoragePlans\Support\StorageBytes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StoragePlan extends Model
{
    public $incrementing = false;

    protected $primaryKey = 'uuid';

    protected $keyType = 'string';

    protected $fillable = [
        'uuid',
        'name',
        'description',
        'slug',
        'play_product_id',
        'quota_bytes',
        'storage_limit_bytes',
        'monthly_access_limit_bytes',
        'streaming_limit_bytes',
        'download_limit_bytes',
        'file_view_limit_bytes',
        'max_users',
        'is_shared',
        'max_shared_members',
        'warning_percentage',
        'soft_limit_percentage',
        'hard_limit_percentage',
        'display_price_cents',
        'currency',
        'billing_period',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'quota_bytes' => 'integer',
            'storage_limit_bytes' => 'integer',
            'monthly_access_limit_bytes' => 'integer',
            'streaming_limit_bytes' => 'integer',
            'download_limit_bytes' => 'integer',
            'file_view_limit_bytes' => 'integer',
            'max_users' => 'integer',
            'is_shared' => 'boolean',
            'max_shared_members' => 'integer',
            'warning_percentage' => 'integer',
            'soft_limit_percentage' => 'integer',
            'hard_limit_percentage' => 'integer',
            'display_price_cents' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function isYearly(): bool
    {
        return strtolower((string) $this->billing_period) === 'yearly'
            || $this->slug === 'free';
    }

    public function isPaid(): bool
    {
        return (int) $this->display_price_cents > 0;
    }

    public function storageLimitBytes(): int
    {
        $bytes = (int) ($this->storage_limit_bytes ?: $this->quota_bytes);

        return max($bytes, 1);
    }

    public function monthlyAccessLimitBytes(): int
    {
        $bytes = (int) $this->monthly_access_limit_bytes;

        return $bytes > 0 ? $bytes : $this->storageLimitBytes();
    }

    public function streamingLimitBytes(): int
    {
        $bytes = (int) $this->streaming_limit_bytes;

        return $bytes > 0 ? $bytes : $this->monthlyAccessLimitBytes();
    }

    public function downloadLimitBytes(): int
    {
        $bytes = (int) $this->download_limit_bytes;

        return $bytes > 0 ? $bytes : $this->storageLimitBytes();
    }

    public function fileViewLimitBytes(): int
    {
        $bytes = (int) $this->file_view_limit_bytes;

        return $bytes > 0 ? $bytes : $this->monthlyAccessLimitBytes();
    }

    public function maxSharedMembers(): int
    {
        if (! $this->is_shared) {
            return 0;
        }

        return max(0, (int) $this->max_shared_members);
    }

    /** @return array<string, mixed> */
    public function limitSnapshot(): array
    {
        $access = $this->monthlyAccessLimitBytes();

        return [
            'plan_uuid' => $this->uuid,
            'storage_limit_bytes' => $this->storageLimitBytes(),
            'monthly_access_limit_bytes' => $access,
            'streaming_limit_bytes' => $this->streamingLimitBytes(),
            'download_limit_bytes' => $this->downloadLimitBytes(),
            'file_view_limit_bytes' => $this->fileViewLimitBytes(),
            'max_shared_members' => $this->maxSharedMembers(),
            'is_shared' => (bool) $this->is_shared,
        ];
    }

    public static function gb(float $gib): int
    {
        return StorageBytes::fromGib($gib);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(UserPlanAssignment::class, 'storage_plan_uuid', 'uuid');
    }
}
