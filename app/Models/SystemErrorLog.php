<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SystemErrorLog extends Model
{
    protected $fillable = [
        'uuid',
        'user_id',
        'method',
        'path',
        'status_code',
        'exception_class',
        'message',
        'trace',
        'request_id',
        'ip_address',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'status_code' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
