<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Family extends Model
{
    public $incrementing = false;

    protected $primaryKey = 'uuid';

    protected $keyType = 'string';

    protected $fillable = ['uuid', 'name', 'slug', 'member_count'];

    public function members(): HasMany
    {
        return $this->hasMany(FamilyMember::class, 'family_uuid', 'uuid');
    }
}
