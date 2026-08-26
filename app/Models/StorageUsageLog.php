<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StorageUsageLog extends Model
{
    protected $fillable = [
        'user_id',
        'media_file_uuid',
        'assignment_id',
        'user_storage_usage_id',
        'action',
        'bytes_used',
        'provider',
        'operation_type',
    ];

    protected function casts(): array
    {
        return [
            'bytes_used' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(UserPlanAssignment::class, 'assignment_id');
    }

    public function usage(): BelongsTo
    {
        return $this->belongsTo(UserStorageUsage::class, 'user_storage_usage_id');
    }
}
