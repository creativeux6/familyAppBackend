<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserIdentityKeyVault extends Model
{
    protected $fillable = [
        'user_id',
        'sealed_private_key',
        'encryption_version',
    ];

    protected function casts(): array
    {
        return [
            'encryption_version' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
