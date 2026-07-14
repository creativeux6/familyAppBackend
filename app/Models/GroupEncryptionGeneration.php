<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GroupEncryptionGeneration extends Model
{
    protected $fillable = [
        'group_uuid',
        'generation',
        'created_by_user_id',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'generation' => 'integer',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class, 'group_uuid', 'uuid');
    }
}
