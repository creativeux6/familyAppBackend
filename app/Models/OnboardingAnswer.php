<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OnboardingAnswer extends Model
{
    protected $fillable = [
        'onboarding_session_uuid',
        'relative_slot',
        'relation_index',
        'relation_hint',
        'first_name',
        'last_name',
        'maiden_name',
        'date_of_birth',
        'date_of_death',
        'birthplace',
        'gender',
        'is_living',
    ];

    protected function casts(): array
    {
        return [
            'relation_hint' => 'array',
            'relation_index' => 'integer',
            'date_of_birth' => 'date',
            'date_of_death' => 'date',
            'is_living' => 'boolean',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(OnboardingSession::class, 'onboarding_session_uuid', 'uuid');
    }
}
