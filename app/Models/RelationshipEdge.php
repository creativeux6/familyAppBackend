<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RelationshipEdge extends Model
{
    public $incrementing = false;

    protected $primaryKey = 'uuid';

    protected $keyType = 'string';

    protected $fillable = [
        'uuid', 'from_member_uuid', 'to_member_uuid', 'edge_type_id', 'confidence', 'marriage_date', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'confidence' => 'decimal:4',
            'marriage_date' => 'date',
        ];
    }

    public function edgeType(): BelongsTo
    {
        return $this->belongsTo(RelationshipEdgeType::class, 'edge_type_id');
    }
}
