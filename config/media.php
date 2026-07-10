<?php

return [
    'disk' => env('MEDIA_DISK', env('FILESYSTEM_DISK', 'local')),
    'default_quota_bytes' => (int) env('MEDIA_DEFAULT_QUOTA_BYTES', 5 * 1024 * 1024 * 1024),
    'presigned_upload_ttl_minutes' => (int) env('MEDIA_PRESIGNED_UPLOAD_TTL', 15),
    'presigned_download_ttl_minutes' => (int) env('MEDIA_PRESIGNED_DOWNLOAD_TTL', 60),
    'key_prefix' => env('MEDIA_KEY_PREFIX', 'famlyApp/media'),
    'chunk_size_bytes' => (int) env('MEDIA_CHUNK_SIZE_BYTES', 5 * 1024 * 1024),
];
