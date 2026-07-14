<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class MediaEvent extends Model
{
    use SoftDeletes;

    public $incrementing = false;

    protected $primaryKey = 'uuid';

    protected $keyType = 'string';

    public const TYPE_GENERAL = 'general';

    public const TYPE_WEDDING = 'wedding';

    public const TYPE_TOUR = 'tour';

    public const TYPE_PARTY = 'party';

    public const TYPE_RELIGIOUS = 'religious';

    public const TYPE_OTHER = 'other';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PLANNED = 'planned';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_ARCHIVED = 'archived';

    public const SCOPE_PRIVATE = 'private';

    public const SCOPE_GALLERY = 'gallery';

    protected $fillable = [
        'uuid',
        'owner_user_id',
        'scope',
        'title',
        'description',
        'event_date',
        'location',
        'event_type',
        'status',
        'starts_at',
        'ends_at',
        'timezone',
        'currency',
        'management_enabled',
        'management_meta',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'event_date' => 'date',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'management_enabled' => 'boolean',
            'management_meta' => 'array',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(MediaFile::class, 'media_event_uuid', 'uuid');
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(EventExpense::class, 'media_event_uuid', 'uuid');
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(EventBooking::class, 'media_event_uuid', 'uuid');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(EventTask::class, 'media_event_uuid', 'uuid');
    }

    public function collaborators(): HasMany
    {
        return $this->hasMany(EventCollaborator::class, 'media_event_uuid', 'uuid');
    }

    /** Whether this event can hold v2 management records (flag on; feature may still be off). */
    public function isManagementReady(): bool
    {
        return (bool) $this->management_enabled
            && (bool) config('features.event_management_enabled', false);
    }
}
