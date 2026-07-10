<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RelationshipEdgeType extends Model
{
    protected $fillable = ['code', 'inverse_code', 'is_symmetric', 'label'];

    protected function casts(): array
    {
        return ['is_symmetric' => 'boolean'];
    }
}
