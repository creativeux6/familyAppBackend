<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoragePlan extends Model
{
    public $incrementing = false;

    protected $primaryKey = 'uuid';

    protected $keyType = 'string';

    protected $fillable = [
        'uuid', 'name', 'slug', 'quota_bytes', 'display_price_cents', 'currency', 'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'quota_bytes' => 'integer',
            'display_price_cents' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
