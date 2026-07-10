<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MediaOwnershipTransfer extends Model
{
    public $incrementing = false;

    protected $primaryKey = 'uuid';

    protected $keyType = 'string';

    protected $fillable = [
        'uuid',
        'media_file_uuid',
        'from_user_id',
        'to_user_id',
        'status',
        'size_bytes',
        'responded_at',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'responded_at' => 'datetime',
        ];
    }

    public function mediaFile(): BelongsTo
    {
        return $this->belongsTo(MediaFile::class, 'media_file_uuid', 'uuid');
    }
}
