<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Message extends Model
{
    use SoftDeletes;

    public $incrementing = false;

    protected $primaryKey = 'uuid';

    protected $keyType = 'string';

    protected $fillable = [
        'uuid',
        'group_uuid',
        'sender_user_id',
        'encryption_generation',
        'ciphertext',
        'nonce',
        'encryption_version',
        'type',
        'media_file_uuid',
        'edited_at',
    ];

    protected function casts(): array
    {
        return [
            'encryption_generation' => 'integer',
            'encryption_version' => 'integer',
            'edited_at' => 'datetime',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class, 'group_uuid', 'uuid');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }
}
