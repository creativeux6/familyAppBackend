<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CalendarNotificationDelivery extends Model
{
    public $incrementing = false;

    protected $primaryKey = 'uuid';

    protected $keyType = 'string';

    protected $fillable = [
        'uuid',
        'recipient_user_id',
        'source_member_uuid',
        'calendar_reminder_uuid',
        'event_type',
        'notification_kind',
        'occurrence_date',
        'notified_at',
    ];

    protected function casts(): array
    {
        return [
            'occurrence_date' => 'date',
            'notified_at' => 'datetime',
        ];
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }
}
