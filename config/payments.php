<?php

return [
    // v1 stub gateway. Set false in tests to exercise past_due / media_locked.
    'stub_succeed' => filter_var(env('PAYMENTS_STUB_SUCCEED', true), FILTER_VALIDATE_BOOLEAN),
    'grace_days' => (int) env('PAYMENTS_GRACE_DAYS', 3),
    'retry_interval_hours' => (int) env('PAYMENTS_RETRY_INTERVAL_HOURS', 24),
    'max_auto_retries' => (int) env('PAYMENTS_MAX_AUTO_RETRIES', 3),
];
