<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Group extends Model
{
    use SoftDeletes;

    public $incrementing = false;

    protected $primaryKey = 'uuid';

    protected $keyType = 'string';

    protected $fillable = [
        'uuid',
        'type',
        'direct_key',
        'name',
        'description',
        'created_by_user_id',
        'member_count',
    ];

    protected function casts(): array
    {
        return [
            'member_count' => 'integer',
            'type' => 'string',
        ];
    }

    public function isDirect(): bool
    {
        return $this->type === 'direct';
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(GroupMember::class, 'group_uuid', 'uuid');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'group_uuid', 'uuid');
    }

    public function encryptionGenerations(): HasMany
    {
        return $this->hasMany(GroupEncryptionGeneration::class, 'group_uuid', 'uuid');
    }
}
