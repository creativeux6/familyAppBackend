<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CalendarReminder extends Model
{
    public const VISIBILITY_PERSONAL = 'personal';

    public const VISIBILITY_CONNECTED_ONLY = 'connected_only';

    public const VISIBILITY_ALL_TREE = 'all_tree';

    public const TYPE_PERSONAL_REMINDER = 'personal_reminder';

    public const TYPE_CUSTOM_EVENT = 'custom_event';

    public $incrementing = false;

    protected $primaryKey = 'uuid';

    protected $keyType = 'string';

    protected $fillable = [
        'uuid',
        'user_id',
        'family_member_uuid',
        'title',
        'notes',
        'event_date',
        'event_type',
        'visibility',
        'notify_days_before',
        'recurring_yearly',
        'is_enabled',
    ];

    protected function casts(): array
    {
        return [
            'event_date' => 'date',
            'recurring_yearly' => 'boolean',
            'is_enabled' => 'boolean',
            'notify_days_before' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function familyMember(): BelongsTo
    {
        return $this->belongsTo(FamilyMember::class, 'family_member_uuid', 'uuid');
    }
}
