<?php

return [
    'disk' => env('AVATAR_DISK', env('MEDIA_DISK', env('FILESYSTEM_DISK', 'local'))),
    'key_prefix' => env('AVATAR_KEY_PREFIX', 'famlyApp/avatars'),
    'signed_url_ttl_minutes' => (int) env('AVATAR_SIGNED_URL_TTL', 60),
    'max_master_bytes' => (int) env('AVATAR_MAX_MASTER_BYTES', 2 * 1024 * 1024),
    'max_thumb_bytes' => (int) env('AVATAR_MAX_THUMB_BYTES', 256 * 1024),
];
