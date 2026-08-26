<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class PaymentTransaction extends Model
{
    public $incrementing = false;

    protected $primaryKey = 'uuid';

    protected $keyType = 'string';

    protected $fillable = [
        'uuid',
        'user_id',
        'storage_plan_uuid',
        'amount_cents',
        'currency',
        'provider',
        'provider_reference',
        'status',
        'provider_payload',
    ];

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'provider_payload' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (PaymentTransaction $tx): void {
            if (empty($tx->uuid)) {
                $tx->uuid = (string) Str::uuid();
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(StoragePlan::class, 'storage_plan_uuid', 'uuid');
    }
}
