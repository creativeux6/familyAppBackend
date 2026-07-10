<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserDeclaredRelative extends Model
{
    public $incrementing = false;

    protected $primaryKey = 'uuid';

    protected $keyType = 'string';

    protected $fillable = [
        'uuid',
        'user_id',
        'relation_type',
        'relation_index',
        'first_name',
        'last_name',
        'maiden_name',
        'date_of_birth',
        'date_of_death',
        'birthplace',
        'gender',
        'is_living',
        'member_uuid',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'date_of_death' => 'date',
            'is_living' => 'boolean',
            'relation_index' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(FamilyMember::class, 'member_uuid', 'uuid');
    }
}
