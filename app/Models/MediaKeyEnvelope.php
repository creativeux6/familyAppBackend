<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MediaKeyEnvelope extends Model
{
    protected $fillable = [
        'media_file_uuid',
        'recipient_user_id',
        'wrapped_content_key',
        'encryption_version',
    ];

    protected function casts(): array
    {
        return [
            'encryption_version' => 'integer',
        ];
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }
}
