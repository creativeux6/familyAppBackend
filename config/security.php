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

    'friends_sync_per_minute_user' => (int) env('FRIENDS_SYNC_PER_MINUTE_USER', 10),
    'friends_sync_per_minute_ip' => (int) env('FRIENDS_SYNC_PER_MINUTE_IP', 20),
];
