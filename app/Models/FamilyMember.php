<?php

namespace App\Models;

use App\Modules\FamilyTree\Services\MemberCodeService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class FamilyMember extends Model
{
    use SoftDeletes;

    public $incrementing = false;

    protected $primaryKey = 'uuid';

    protected $keyType = 'string';

    protected $fillable = [
        'uuid', 'member_code', 'family_uuid', 'user_id', 'first_name', 'middle_name', 'last_name',
        'maiden_name', 'date_of_birth', 'date_of_death', 'birthplace', 'gender',
        'is_living', 'is_anonymous', 'match_confidence',
        'avatar_master_key', 'avatar_thumb_key', 'avatar_master_bytes', 'avatar_thumb_bytes',
        'avatar_updated_at', 'avatar_updated_by_user_id',
    ];

    protected static function booted(): void
    {
        static::creating(function (FamilyMember $member) {
            if (empty($member->member_code)) {
                $member->member_code = app(MemberCodeService::class)->generateUnique();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'date_of_death' => 'date',
            'is_living' => 'boolean',
            'is_anonymous' => 'boolean',
            'match_confidence' => 'decimal:4',
            'avatar_master_bytes' => 'integer',
            'avatar_thumb_bytes' => 'integer',
            'avatar_updated_at' => 'datetime',
        ];
    }

    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class, 'family_uuid', 'uuid');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function outgoingEdges(): HasMany
    {
        return $this->hasMany(RelationshipEdge::class, 'from_member_uuid', 'uuid');
    }
}
