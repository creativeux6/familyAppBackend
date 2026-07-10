<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserKeyBackup extends Model
{
    protected $fillable = [
        'user_id',
        'encrypted_private_key_blob',
        'salt',
        'encryption_version',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'encryption_version' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
