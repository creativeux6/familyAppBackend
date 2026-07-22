<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Auth abuse protection
    |--------------------------------------------------------------------------
    |
    | Limits are enforced per IP and (where useful) per phone fingerprint so
    | bulk register/login/credential stuffing from one IP is blocked with 429.
    |
    */
    'login_per_minute_ip' => (int) env('AUTH_LOGIN_PER_MINUTE_IP', 10),
    'login_per_minute_phone' => (int) env('AUTH_LOGIN_PER_MINUTE_PHONE', 5),
    'login_per_hour_ip' => (int) env('AUTH_LOGIN_PER_HOUR_IP', 40),

    'register_per_minute_ip' => (int) env('AUTH_REGISTER_PER_MINUTE_IP', 3),
    'register_per_hour_ip' => (int) env('AUTH_REGISTER_PER_HOUR_IP', 10),

    'password_per_minute_ip' => (int) env('AUTH_PASSWORD_PER_MINUTE_IP', 5),
    'password_per_hour_ip' => (int) env('AUTH_PASSWORD_PER_HOUR_IP', 15),

    /*
    | General authenticated API ceiling (per user or IP).
    */
    'api_per_minute' => (int) env('API_RATE_LIMIT_PER_MINUTE', 120),
];
