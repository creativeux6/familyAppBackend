<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GroupKeyEnvelope extends Model
{
    protected $fillable = [
        'group_uuid',
        'generation',
        'recipient_user_id',
        'wrapped_group_key',
        'encryption_version',
    ];

    protected function casts(): array
    {
        return [
            'generation' => 'integer',
            'encryption_version' => 'integer',
        ];
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }
}
