<?php

return [
    'disk' => env('MEDIA_DISK', env('FILESYSTEM_DISK', 'local')),
    // Free-tier quota comes from the seeded Free storage plan (5 GB), not env.
    // See docs/00-overview/permanent-product-rules.md
    'presigned_upload_ttl_minutes' => (int) env('MEDIA_PRESIGNED_UPLOAD_TTL', 15),
    'presigned_download_ttl_minutes' => (int) env('MEDIA_PRESIGNED_DOWNLOAD_TTL', 60),
    'key_prefix' => env('MEDIA_KEY_PREFIX', 'tagori/media'), // never store at bucket root; env can change
    // Chunk body size — keep below reverse-proxy limit unless nginx client_max_body_size is raised/disabled.
    'chunk_size_bytes' => (int) env('MEDIA_CHUNK_SIZE_BYTES', 5 * 1024 * 1024),
    'list_page_size' => (int) env('MEDIA_LIST_PAGE_SIZE', 20),
    'thumbnail_max_edge' => (int) env('MEDIA_THUMBNAIL_MAX_EDGE', 96),
    'stream_chunk_size_bytes' => (int) env('MEDIA_STREAM_CHUNK_SIZE_BYTES', 256 * 1024),
    'pending_upload_ttl_hours' => (int) env('MEDIA_PENDING_UPLOAD_TTL_HOURS', 24),
    'acl_cache_seconds' => (int) env('MEDIA_ACL_CACHE_SECONDS', 45),
    'read_meter_window_seconds' => (int) env('MEDIA_READ_METER_WINDOW_SECONDS', 3600),
    // Unused for caps. Monthly access lives on storage_plans.monthly_access_limit_bytes.
    'access_quota_multiplier' => (int) env('MEDIA_ACCESS_QUOTA_MULTIPLIER', 3),
    // When monthly access is within this remaining window, block media larger than large_file_bytes.
    'access_soft_remaining_bytes' => (int) env('MEDIA_ACCESS_SOFT_REMAINING_BYTES', 512 * 1024 * 1024),
    'access_warn_remaining_bytes' => [
        (int) env('MEDIA_ACCESS_WARN_2GB_BYTES', 2 * 1024 * 1024 * 1024),
        (int) env('MEDIA_ACCESS_WARN_1GB_BYTES', 1 * 1024 * 1024 * 1024),
    ],
    'large_file_bytes' => (int) env('MEDIA_LARGE_FILE_BYTES', 100 * 1024 * 1024),
    'access_upgrade_message' => env(
        'MEDIA_ACCESS_UPGRADE_MESSAGE',
        'Too many requests. Please upgrade your subscription.',
    ),
    'payment_lock_message' => env(
        'MEDIA_PAYMENT_LOCK_MESSAGE',
        'Media is locked because payment failed. Retry payment to continue.',
    ),
    'b2_storage_usd_per_gb_month' => (float) env('B2_STORAGE_USD_PER_GB_MONTH', 0.006),
    'b2_egress_usd_per_gb' => (float) env('B2_EGRESS_USD_PER_GB', 0.01),
];
