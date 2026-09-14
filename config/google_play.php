<?php

return [
    'package_name' => env('GOOGLE_PLAY_PACKAGE', 'com.prolampx.tijori'),
    'client_email' => env('GOOGLE_PLAY_CLIENT_EMAIL'),
    'private_key' => env('GOOGLE_PLAY_PRIVATE_KEY'),
    'credentials_path' => env('GOOGLE_PLAY_CREDENTIALS_PATH'),
    'rtdn_token' => env('GOOGLE_PLAY_RTDN_TOKEN'),
    'product_ids' => [
        'personal' => env('GOOGLE_PLAY_SKU_PERSONAL', 'tijori_personal'),
        'plus' => env('GOOGLE_PLAY_SKU_PLUS', 'tijori_plus'),
        'pro' => env('GOOGLE_PLAY_SKU_PRO', 'tijori_pro'),
    ],
];
