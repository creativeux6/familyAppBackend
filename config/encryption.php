<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Identity key vault master key
    |--------------------------------------------------------------------------
    |
    | Seals each user's identity private key so authenticated clients can
    | restore history without a recovery passphrase. Prefer a dedicated
    | 32-byte key (base64). Falls back to APP_KEY when unset (local/dev).
    |
    */

    'vault_master_key' => env('ENCRYPTION_VAULT_MASTER_KEY'),

];
