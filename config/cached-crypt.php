<?php

declare(strict_types = 1);

return [

    /*
    |--------------------------------------------------------------------------
    | Cached Crypt Configuration
    |--------------------------------------------------------------------------
    |
    | Enable or disable cached-crypt integration.
    | Default is enabled for drop-in optimization without extra setup.
    |
    */

    'enabled' => env('CACHED_CRYPT_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Plaintext Caching Mode
    |--------------------------------------------------------------------------
    |
    | cache_plaintext controls cross-request cache persistence. memo_only keeps
    | decrypt caching in-process only and never persists plaintext values.
    |
    */

    'cache_plaintext' => env('CACHED_CRYPT_CACHE_PLAINTEXT', false),
    'memo_only'       => env('CACHED_CRYPT_MEMO_ONLY', true),

    /*
    |--------------------------------------------------------------------------
    | Persistence Controls
    |--------------------------------------------------------------------------
    |
    | ttl_seconds: bounded lifetime for persisted plaintext values.
    | epoch: cache namespace segment for instant global invalidation.
    | key_fingerprint: optional non-reversible namespace marker.
    | store: optional dedicated cache store name.
    |
    | When app key, cipher, or previous_keys change, bump epoch.
    |
    */

    'ttl_seconds'     => env('CACHED_CRYPT_TTL_SECONDS', 120),
    'epoch'           => env('CACHED_CRYPT_EPOCH', 'v1'),
    'key_fingerprint' => env('CACHED_CRYPT_KEY_FINGERPRINT'),
    'store'           => env('CACHED_CRYPT_STORE'),

    /*
    |--------------------------------------------------------------------------
    | Size Guardrails
    |--------------------------------------------------------------------------
    |
    | min_bytes_to_cache prevents cache churn for tiny encrypted payloads.
    | max_memo_bytes prevents large values from being retained in-process.
    | max_bytes_to_cache prevents large plaintext values from being persisted.
    |
    */

    'min_bytes_to_cache' => env('CACHED_CRYPT_MIN_BYTES_TO_CACHE', 1024),
    'max_memo_bytes'     => env('CACHED_CRYPT_MAX_MEMO_BYTES', 262144),
    'max_bytes_to_cache' => env('CACHED_CRYPT_MAX_BYTES_TO_CACHE', 262144),

    /*
    |--------------------------------------------------------------------------
    | Cache Tagging
    |--------------------------------------------------------------------------
    |
    | When enabled and supported by the selected cache store, cached plaintext
    | entries will be tagged as:
    | - cached-crypt
    | - cached-crypt:{epoch}
    |
    */

    'use_tags' => env('CACHED_CRYPT_USE_TAGS', false),

    /*
    |--------------------------------------------------------------------------
    | Eligibility Resolver
    |--------------------------------------------------------------------------
    |
    | Optional callable/Closure/class-string invokable that receives:
    | (string $payload, bool $unserialize): bool
    | Return false to skip persistent plaintext caching for that payload.
    |
    */

    'eligibility_resolver' => null,

    /*
    |--------------------------------------------------------------------------
    | Metrics
    |--------------------------------------------------------------------------
    |
    | Sampled metric logging for cache hit/miss and decrypt timing.
    | sample_rate must be between 0 and 1.
    |
    */

    'metrics' => [
        'enabled'     => env('CACHED_CRYPT_METRICS_ENABLED', false),
        'sample_rate' => env('CACHED_CRYPT_METRICS_SAMPLE_RATE', 0.10),
    ],

];
