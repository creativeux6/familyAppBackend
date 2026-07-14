<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MediaLibraryItem extends Model
{
    protected $fillable = [
        'media_file_uuid',
        'user_id',
        'quota_charged_at',
        'stream_bytes_charged',
        'removed_at',
    ];

    protected function casts(): array
    {
        return [
            'quota_charged_at' => 'datetime',
            'removed_at' => 'datetime',
            'stream_bytes_charged' => 'integer',
        ];
    }

    public function mediaFile(): BelongsTo
    {
        return $this->belongsTo(MediaFile::class, 'media_file_uuid', 'uuid');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
