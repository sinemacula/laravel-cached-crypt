# Laravel Cached Crypt

[![Latest Stable Version](https://img.shields.io/packagist/v/sinemacula/laravel-cached-crypt.svg)](https://packagist.org/packages/sinemacula/laravel-cached-crypt)
[![Build Status](https://github.com/sinemacula/laravel-cached-crypt/actions/workflows/tests.yml/badge.svg?branch=master)](https://github.com/sinemacula/laravel-cached-crypt/actions/workflows/tests.yml)
[![StyleCI](https://github.styleci.io/repos/989824280/shield?style=flat&branch=master)](https://github.styleci.io/repos/989824280)
[![Maintainability](https://qlty.sh/badges/38be203a-933b-4ae8-9e80-f6e8f924ecb9/maintainability.svg)](https://qlty.sh/gh/sinemacula/projects/laravel-cached-crypt)
[![Code Coverage](https://qlty.sh/badges/38be203a-933b-4ae8-9e80-f6e8f924ecb9/test_coverage.svg)](https://qlty.sh/gh/sinemacula/projects/laravel-cached-crypt)
[![Total Downloads](https://img.shields.io/packagist/dt/sinemacula/laravel-cached-crypt.svg)](https://packagist.org/packages/sinemacula/laravel-cached-crypt)

Laravel Cached Crypt transparently wraps Laravel's encrypter to reduce repeated decrypt overhead in hot paths.
It memoizes decrypt results in-process and can optionally persist plaintext in cache with bounded TTL, namespace
versioning, and size guardrails.

> ⚠️ **Security Warning**
> Persisted plaintext caching should only be enabled when operational controls are in place.
> Use secured Redis/Memcached with encryption in transit and at rest, private networking, and short TTLs.
> Prefer memo-only mode unless cross-request reuse is required.

## Features

- Transparent `Crypt` integration through a custom encrypter binding
- In-process memoization for repeated decrypts in the same request/job lifecycle
- Optional cross-request plaintext persistence with TTL (no `rememberForever`)
- Epoch-based namespace invalidation (`decrypted:{epoch}:{hash}:{flag}`)
- SHA-256 payload hashing for cache keys
- Optional dedicated cache store and cache tagging support
- Size guardrails (`min_bytes_to_cache`, `max_memo_bytes`, `max_bytes_to_cache`)
- Optional sampled metric logging for hit/miss, decrypt time, and cache write size
- Optional resolver hook for application-specific caching eligibility decisions

## Installation

```bash
composer require sinemacula/laravel-cached-crypt
```

Laravel will automatically register the service provider via package discovery.

## Configuration

Publish the config file:

```bash
php artisan vendor:publish --tag=config
```

Default configuration in `config/cached-crypt.php`:

```php
return [
    'enabled' => false,
    'cache_plaintext' => false,
    'memo_only' => true,
    'ttl_seconds' => 120,
    'epoch' => 'v1',
    'key_fingerprint' => null,
    'store' => null,
    'min_bytes_to_cache' => 1024,
    'max_memo_bytes' => 262144,
    'max_bytes_to_cache' => 262144,
    'use_tags' => false,
    'eligibility_resolver' => null,
    'metrics' => [
        'enabled' => false,
        'sample_rate' => 0.10,
    ],
];
```

Safe defaults:

- Package disabled by default
- Memoization available for in-process reuse
- Cross-request plaintext persistence disabled unless explicitly enabled

## How It Works

For each decrypt call:

1. Build a namespaced key with epoch and SHA-256 payload hash.
2. Read from in-process memoization cache first.
3. Optionally read/write persistent plaintext cache when enabled and eligible.
4. Fallback to Laravel decrypt and memoize result.

Persistent writes are bounded by:

- `ttl_seconds`
- `min_bytes_to_cache` (encrypted payload size)
- `max_memo_bytes` (in-process memo value size estimate)
- `max_bytes_to_cache` (decrypted value size estimate)
- Optional resolver callback (`eligibility_resolver`)

## Operating Modes

- `memo_only = true`: in-process reuse only, no cross-request plaintext persistence.
- `cache_plaintext = true` and `memo_only = false`: allows persistent plaintext caching with guardrails.

## Key Rotation and Invalidation

When encryption settings change (for example `APP_KEY`, cipher, or `previous_keys`), bump `epoch`.
This immediately cold-starts cached plaintext keys without requiring global cache flushes.

If `use_tags` is enabled and the store supports tags, entries are grouped under:

- `cached-crypt`
- `cached-crypt:{epoch}`

## Metrics

When `metrics.enabled` is true, sampled events are logged with:

- cache hit/miss source (`memo` or `persistent`)
- decrypt duration in milliseconds
- approximate bytes persisted for cache writes

## Testing

```bash
composer test
composer test-coverage
composer check
```

## Contributing

Contributions are welcome via GitHub pull requests.

## Security

If you discover a security issue, please contact Sine Macula directly rather than opening a public issue.

## License

Licensed under the [Apache License, Version 2.0](https://www.apache.org/licenses/LICENSE-2.0).
