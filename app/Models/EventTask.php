<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class EventTask extends Model
{
    use SoftDeletes;

    public $incrementing = false;

    protected $primaryKey = 'uuid';

    protected $keyType = 'string';

    protected $fillable = [
        'uuid',
        'media_event_uuid',
        'created_by_user_id',
        'assignee_user_id',
        'title',
        'description',
        'status',
        'priority',
        'due_at',
        'completed_at',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'priority' => 'integer',
            'due_at' => 'datetime',
            'completed_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(MediaEvent::class, 'media_event_uuid', 'uuid');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_user_id');
    }
}
