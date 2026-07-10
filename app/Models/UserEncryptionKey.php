<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserEncryptionKey extends Model
{
    public $incrementing = false;

    protected $primaryKey = 'user_id';

    protected $fillable = [
        'user_id',
        'public_identity_key',
        'encryption_version',
        'rotated_at',
    ];

    protected function casts(): array
    {
        return [
            'encryption_version' => 'integer',
            'rotated_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
