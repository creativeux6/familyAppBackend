<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OnboardingSession extends Model
{
    public $incrementing = false;

    protected $primaryKey = 'uuid';

    protected $keyType = 'string';

    protected $fillable = [
        'uuid', 'user_id', 'status', 'matched_family_uuid',
        'top_match_score', 'match_candidates', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'top_match_score' => 'decimal:4',
            'match_candidates' => 'array',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function matchedFamily(): BelongsTo
    {
        return $this->belongsTo(Family::class, 'matched_family_uuid', 'uuid');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(OnboardingAnswer::class, 'onboarding_session_uuid', 'uuid');
    }
}
