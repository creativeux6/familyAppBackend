<?php

return [
    'disk' => env('MEDIA_DISK', env('FILESYSTEM_DISK', 'local')),
    // 0 (or negative) = unlimited user storage (S3). Plans still apply when assigned.
    'default_quota_bytes' => (int) env('MEDIA_DEFAULT_QUOTA_BYTES', 0),
    'presigned_upload_ttl_minutes' => (int) env('MEDIA_PRESIGNED_UPLOAD_TTL', 15),
    'presigned_download_ttl_minutes' => (int) env('MEDIA_PRESIGNED_DOWNLOAD_TTL', 60),
    'key_prefix' => env('MEDIA_KEY_PREFIX', 'famlyApp/media'),
    // Chunk body size — keep below reverse-proxy limit unless nginx client_max_body_size is raised/disabled.
    'chunk_size_bytes' => (int) env('MEDIA_CHUNK_SIZE_BYTES', 5 * 1024 * 1024),
    'list_page_size' => (int) env('MEDIA_LIST_PAGE_SIZE', 20),
    'thumbnail_max_edge' => (int) env('MEDIA_THUMBNAIL_MAX_EDGE', 96),
    'stream_chunk_size_bytes' => (int) env('MEDIA_STREAM_CHUNK_SIZE_BYTES', 256 * 1024),
];