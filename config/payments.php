<?php

return [
    // Local stub only. Production Android paid plans use Google Play Billing.
    'stub_succeed' => filter_var(env('PAYMENTS_STUB_SUCCEED', true), FILTER_VALIDATE_BOOLEAN),
    // When false (default), POST /storage/plan-change cannot apply a paid SKU.
    // Android clients must verify a Play purchase instead. Set true only for local web stub tests.
    'allow_client_paid_change' => filter_var(env('PAYMENTS_ALLOW_CLIENT_PAID_CHANGE', false), FILTER_VALIDATE_BOOLEAN),
    'grace_days' => (int) env('PAYMENTS_GRACE_DAYS', 3),
    'retry_interval_hours' => (int) env('PAYMENTS_RETRY_INTERVAL_HOURS', 24),
    'max_auto_retries' => (int) env('PAYMENTS_MAX_AUTO_RETRIES', 3),
];
