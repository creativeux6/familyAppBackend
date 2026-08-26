<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UserPlanAssignment extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_PAST_DUE = 'past_due';

    public const STATUS_MEDIA_LOCKED = 'media_locked';

    protected $fillable = [
        'user_id',
        'storage_plan_uuid',
        'pending_storage_plan_uuid',
        'pending_change_at',
        'source',
        'assigned_by_user_id',
        'starts_at',
        'ends_at',
        'is_active',
        'billing_status',
        'last_payment_failed_at',
        'payment_retry_count',
        'next_retry_at',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'pending_change_at' => 'datetime',
            'last_payment_failed_at' => 'datetime',
            'next_retry_at' => 'datetime',
            'is_active' => 'boolean',
            'payment_retry_count' => 'integer',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(StoragePlan::class, 'storage_plan_uuid', 'uuid');
    }

    public function pendingPlan(): BelongsTo
    {
        return $this->belongsTo(StoragePlan::class, 'pending_storage_plan_uuid', 'uuid');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function members(): HasMany
    {
        return $this->hasMany(PlanAssignmentMember::class, 'assignment_id');
    }

    public function usagePeriods(): HasMany
    {
        return $this->hasMany(UserStorageUsage::class, 'assignment_id');
    }

    public function isMediaLocked(): bool
    {
        return $this->billing_status === self::STATUS_MEDIA_LOCKED;
    }

    public function isPastDue(): bool
    {
        return $this->billing_status === self::STATUS_PAST_DUE;
    }
}
