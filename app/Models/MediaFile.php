<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class MediaFile extends Model
{
    use SoftDeletes;

    public $incrementing = false;

    protected $primaryKey = 'uuid';

    protected $keyType = 'string';

    protected $fillable = [
        'uuid',
        'owner_user_id',
        'uploaded_by_user_id',
        's3_bucket',
        's3_key',
        'display_name',
        'size_bytes',
        'mime_type',
        'metadata',
        'checksum_sha256',
        'encryption_version',
        'status',
        'media_event_uuid',
        'multipart_upload_id',
        'uploaded_parts',
        'chunk_size',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'encryption_version' => 'integer',
            'uploaded_parts' => 'array',
            'chunk_size' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    public function permissions(): HasMany
    {
        return $this->hasMany(MediaPermission::class, 'media_file_uuid', 'uuid');
    }

    public function keyEnvelopes(): HasMany
    {
        return $this->hasMany(MediaKeyEnvelope::class, 'media_file_uuid', 'uuid');
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(MediaEvent::class, 'media_event_uuid', 'uuid');
    }
}
