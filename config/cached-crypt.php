<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cached Crypt Configuration
    |--------------------------------------------------------------------------
    |
    | This option determines whether the cached encrypter functionality should
    | be enabled. When disabled, Laravel's default Crypt behavior will be used
    | without caching decrypted values. This is useful for testing or debugging.
    |
    */

    'enabled' => env('CACHED_CRYPT_ENABLED', true)

];
