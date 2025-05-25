# Laravel Cached Crypt

[![Latest Stable Version](https://img.shields.io/packagist/v/sinemacula/laravel-cached-crypt.svg)](https://packagist.org/packages/sinemacula/laravel-cached-crypt)
[![Build Status](https://github.com/sinemacula/laravel-cached-crypt/actions/workflows/tests.yml/badge.svg?branch=master)](https://github.com/sinemacula/laravel-cached-crypt/actions/workflows/tests.yml)
[![StyleCI](https://github.styleci.io/repos/989824280/shield?style=flat&branch=master)](https://github.styleci.io/repos/989824280)
[![Maintainability](https://qlty.sh/badges/38be203a-933b-4ae8-9e80-f6e8f924ecb9/maintainability.svg)](https://qlty.sh/gh/sinemacula/projects/laravel-cached-crypt)
[![Code Coverage](https://qlty.sh/badges/38be203a-933b-4ae8-9e80-f6e8f924ecb9/test_coverage.svg)](https://qlty.sh/gh/sinemacula/projects/laravel-cached-crypt)
[![Total Downloads](https://img.shields.io/packagist/dt/sinemacula/laravel-cached-crypt.svg)](https://packagist.org/packages/sinemacula/laravel-cached-crypt)

> ⚠️ **Security Warning**  
> This package caches decrypted values using Laravel's configured cache driver. You should **only use this package with
a cache store that provides encryption at rest**, such as Redis or Memcached in a secured environment. **Do not use this
with insecure cache drivers like `file` or `array`**, as decrypted data will be stored in plaintext on disk or in
> memory.

Laravel Cached Crypt is a zero-configuration package that transparently adds caching to Laravel’s Crypt facade, reducing
CPU load by avoiding repeated decryption of encrypted values across your application.

## Features

- **Transparent Integration**: Automatically replaces Laravel's default `Crypt` implementation—no configuration or code
  changes required.
- **Cached Decryption**: Caches decrypted values using Laravel's default cache driver (e.g. Redis) to improve
  performance on read-heavy encrypted fields.
- **Deployment-Friendly**: Works seamlessly with deploy-based cache flushing strategies to keep cache fresh and
  consistent.

## Installation

To install Laravel Cached Crypt, run the following command in your project directory:

```bash
composer require sinemacula/laravel-cached-crypt
```

Laravel will automatically register the service provider via package discovery.

## Configuration

This package requires no configuration. Once installed, it will automatically override Laravel’s default `Crypt` binding
and begin caching decrypted values across your application.

## How It Works

Laravel Cached Crypt intercepts calls to `Crypt::decryptString()` and caches the decrypted output using a hash of the
encrypted payload. On subsequent calls, it retrieves the decrypted value from cache rather than reprocessing the
decryption.

This approach is especially effective for:

- APIs that return encrypted model attributes in large datasets
- Applications with frequent reads from encrypted fields
- Reducing CPU load and PHP-FPM worker saturation under high concurrency

## Security Considerations

Decrypted values are stored in your application’s configured cache store. Ensure that your cache backend (e.g. Redis) is
properly secured, resides within a private network or VPC, and uses encryption in transit. This package does not alter
Laravel’s encryption algorithms or key handling in any way.

## Contributing

Contributions are welcome and will be fully credited. We accept contributions via pull requests on GitHub.

## Security

If you discover any security-related issues, please email instead of using the issue tracker.

## License

Laravel Cached Crypt is open-sourced software licensed under
the [Apache License, Version 2.0](https://www.apache.org/licenses/LICENSE-2.0).
