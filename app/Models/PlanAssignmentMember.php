<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlanAssignmentMember extends Model
{
    public const ROLE_OWNER = 'owner';

    public const ROLE_MEMBER = 'member';

    protected $fillable = [
        'assignment_id',
        'user_id',
        'role',
        'period_start',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'datetime',
        ];
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(UserPlanAssignment::class, 'assignment_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
