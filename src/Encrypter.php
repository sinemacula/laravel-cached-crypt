<?php

declare(strict_types = 1);

namespace SineMacula\CachedCrypt;

use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Encryption\Encrypter as LaravelEncrypter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Cached encrypter with memoization and optional plaintext persistence.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
class Encrypter extends LaravelEncrypter
{
    /** @var string */
    private const string CACHE_KEY_PREFIX = 'decrypted';

    /** @var string */
    private const string MEMO_KEY_PREFIX = 'memo';

    /** @var string */
    private const string VALUE_ENVELOPE_KEY = 'value';

    /** @var string */
    private const string METRIC_EVENT = 'cached-crypt.metric';

    /** @var array<string, mixed> */
    private array $memoCache = [];

    /** @var \SineMacula\CachedCrypt\CachedCryptConfiguration|null */
    private ?CachedCryptConfiguration $configuration = null;

    /**
     * Decrypt an encrypted payload with memoization and optional persistence.
     *
     * @param  mixed  $payload
     * @param  mixed  $unserialize
     * @return mixed
     */
    #[\Override]
    public function decrypt(mixed $payload, mixed $unserialize = true): mixed
    {
        $normalized_unserialize = (bool) $unserialize;

        if ($this->configuration()->enabled() && is_string($payload)) {
            $cache_context = $this->cacheContext($payload, $normalized_unserialize);
            $cached_value  = $this->resolveCachedValue($cache_context);

            if ($cached_value['hit']) {
                return $cached_value['value'];
            }

            return $this->decryptAndCache(
                $payload,
                $normalized_unserialize,
                $cache_context,
                $cached_value['cache_repository'],
            );
        }

        return parent::decrypt($payload, $normalized_unserialize);
    }

    /**
     * Resolve a cached value from memoization and optional persistent store.
     *
     * @param  array<string, mixed>  $cache_context
     * @return array{hit: bool, value: mixed, cache_repository: \Illuminate\Contracts\Cache\Repository|null}
     */
    private function resolveCachedValue(array $cache_context): array
    {
        $memoized_value        = $this->readMemoizedValue($cache_context['memo_key']);
        $cache_repository      = null;
        $resolved_value        = null;
        $has_hit               = false;
        $can_persist_plaintext = (bool) ($cache_context['can_persist_plaintext'] ?? false);

        if ($memoized_value['hit']) {
            $this->recordMetric('cache.hit', [
                'source'    => 'memo',
                'cache_key' => $cache_context['cache_key'],
            ]);
            $resolved_value = $memoized_value['value'];
            $has_hit        = true;
        }

        if (!$has_hit) {
            $this->recordMetric('cache.miss', [
                'source'    => 'memo',
                'cache_key' => $cache_context['cache_key'],
            ]);
        }

        if (!$has_hit && $can_persist_plaintext) {
            $cache_repository = $this->persistentRepository(
                $cache_context['store'],
                $cache_context['use_tags'],
                $cache_context['epoch'],
            );
            $persisted_value = $this->readRepositoryValue(
                $cache_repository,
                $cache_context['cache_key'],
            );

            if ($persisted_value['hit']) {
                $this->writeMemoizedValue(
                    $cache_context['memo_key'],
                    $persisted_value['value'],
                    $cache_context['ttl_seconds'],
                );
                $this->recordMetric('cache.hit', [
                    'source'    => 'persistent',
                    'cache_key' => $cache_context['cache_key'],
                ]);
                $resolved_value = $persisted_value['value'];
                $has_hit        = true;
            }

            if (!$has_hit) {
                $this->recordMetric('cache.miss', [
                    'source'    => 'persistent',
                    'cache_key' => $cache_context['cache_key'],
                ]);
            }
        }

        return [
            'hit'              => $has_hit,
            'value'            => $resolved_value,
            'cache_repository' => $cache_repository,
        ];
    }

    /**
     * Decrypt the payload and write eligible values to configured caches.
     *
     * @param  string  $payload
     * @param  bool  $unserialize
     * @param  array<string, mixed>  $context
     * @param  \Illuminate\Contracts\Cache\Repository|null  $repository
     * @return mixed
     */
    private function decryptAndCache(string $payload, bool $unserialize, array $context, ?CacheRepository $repository = null): mixed
    {
        $started_at          = microtime(true);
        $decrypted_value     = parent::decrypt($payload, $unserialize);
        $decrypt_duration_ms = (int) round((microtime(true) - $started_at) * 1000);

        $this->writeMemoizedValue(
            $context['memo_key'],
            $decrypted_value,
            $context['ttl_seconds'],
        );

        $this->recordMetric('decrypt.executed', [
            'duration_ms' => $decrypt_duration_ms,
            'cache_key'   => $context['cache_key'],
        ]);

        if ($repository !== null && $this->shouldPersistPlaintext($context, $decrypted_value)) {
            $this->persistPlaintextValue($repository, $context, $decrypted_value);
        }

        return $decrypted_value;
    }

    /**
     * Build cache context for the current decrypt request.
     *
     * @param  string  $payload
     * @param  bool  $unserialize
     * @return array{
     *     cache_key: string,
     *     memo_key: string,
     *     ttl_seconds: int,
     *     store: string|null,
     *     epoch: string,
     *     use_tags: bool,
     *     can_persist_plaintext: bool
     * }
     */
    private function cacheContext(string $payload, bool $unserialize): array
    {
        $configuration = $this->configuration();
        $epoch         = $configuration->epoch();
        $cache_key     = $this->cacheKey($payload, $unserialize, $epoch);

        return [
            'cache_key'             => $cache_key,
            'memo_key'              => sprintf('%s:%s', self::MEMO_KEY_PREFIX, $cache_key),
            'ttl_seconds'           => $configuration->ttlSeconds(),
            'store'                 => $configuration->storeName(),
            'epoch'                 => $epoch,
            'use_tags'              => $configuration->shouldUseTags(),
            'can_persist_plaintext' => $configuration->canPersistPlaintext(
                $payload,
                $unserialize,
            ),
        ];
    }

    /**
     * Create a cache key for the encrypted payload.
     *
     * @param  string  $payload
     * @param  bool  $unserialize
     * @param  string  $epoch
     * @return string
     */
    private function cacheKey(string $payload, bool $unserialize, string $epoch): string
    {
        $segments = [
            self::CACHE_KEY_PREFIX,
            $epoch,
        ];

        $fingerprint = $this->configuration()->keyFingerprint();

        if ($fingerprint !== null) {
            $segments[] = $fingerprint;
        }

        $segments[] = hash('sha256', $payload);
        $segments[] = $unserialize ? '1' : '0';

        return implode(':', $segments);
    }

    /**
     * Resolve the persistent cache repository.
     *
     * @param  string|null  $store_name
     * @param  bool  $use_tags
     * @param  string  $epoch
     * @return \Illuminate\Contracts\Cache\Repository
     */
    private function persistentRepository(?string $store_name, bool $use_tags, string $epoch): CacheRepository
    {
        $cache_repository = $store_name === null ? Cache::store() : Cache::store($store_name);

        if (!$use_tags || !$cache_repository instanceof Repository) {
            return $cache_repository;
        }

        if (!$cache_repository->supportsTags()) {
            $this->recordMetric('cache.tags.unsupported', [
                'store' => $store_name ?? 'default',
            ]);

            return $cache_repository;
        }

        return $cache_repository->tags([
            'cached-crypt',
            sprintf('cached-crypt:%s', $epoch),
        ]);
    }

    /**
     * Resolve the memoized cache repository.
     *
     * @return \Illuminate\Contracts\Cache\Repository|null
     */
    private function memoRepository(): ?CacheRepository
    {
        if (!config()->has('cache.stores.array')) {
            return null;
        }

        return Cache::memo('array');
    }

    /**
     * Determine if decrypted data is within configured size limits.
     *
     * @param  mixed  $decrypted_value
     * @return bool
     */
    private function withinMaxPlaintextSize(mixed $decrypted_value): bool
    {
        $max_plaintext_bytes = $this->configuration()->maxBytesToCache();

        if ($max_plaintext_bytes === null) {
            return true;
        }

        $estimated_bytes = $this->estimatedBytes($decrypted_value);
        $is_within_limit = $estimated_bytes <= $max_plaintext_bytes;

        if (!$is_within_limit) {
            $this->recordMetric('cache.skip.max_plaintext_bytes', [
                'plaintext_bytes'     => $estimated_bytes,
                'max_plaintext_bytes' => $max_plaintext_bytes,
            ]);
        }

        return $is_within_limit;
    }

    /**
     * Estimate payload size in bytes for metric and guardrail decisions.
     *
     * @param  mixed  $value
     * @return int
     */
    private function estimatedBytes(mixed $value): int
    {
        $estimated_bytes = 0;

        if ($value !== null) {
            if (is_string($value)) {
                $estimated_bytes = strlen($value);
            } elseif (is_scalar($value)) {
                $estimated_bytes = strlen((string) $value);
            } else {
                try {
                    $estimated_bytes = strlen(serialize($value));
                } catch (\Throwable) {
                    $estimated_bytes = strlen(get_debug_type($value));
                }
            }
        }

        return $estimated_bytes;
    }

    /**
     * Read a value from the memoization cache.
     *
     * @param  string  $memo_key
     * @return array{hit: bool, value: mixed}
     */
    private function readMemoizedValue(string $memo_key): array
    {
        $memo_repository = $this->memoRepository();

        if ($memo_repository !== null) {
            return $this->readRepositoryValue($memo_repository, $memo_key);
        }

        if (array_key_exists($memo_key, $this->memoCache)) {
            return ['hit' => true, 'value' => $this->memoCache[$memo_key]];
        }

        return ['hit' => false, 'value' => null];
    }

    /**
     * Write a value to the memoization cache.
     *
     * @param  string  $memo_key
     * @param  mixed  $value
     * @param  int  $ttl_seconds
     * @return void
     */
    private function writeMemoizedValue(string $memo_key, mixed $value, int $ttl_seconds): void
    {
        if (!$this->withinMaxMemoSize($value)) {
            return;
        }

        $memo_repository = $this->memoRepository();

        if ($memo_repository !== null) {
            $this->writeRepositoryValue($memo_repository, $memo_key, $value, $ttl_seconds);

            return;
        }

        $this->memoCache[$memo_key] = $value;
    }

    /**
     * Determine if decrypted data is within configured memoization size limits.
     *
     * @param  mixed  $decrypted_value
     * @return bool
     */
    private function withinMaxMemoSize(mixed $decrypted_value): bool
    {
        $max_memo_bytes = $this->configuration()->maxMemoBytes();

        if ($max_memo_bytes === null) {
            return true;
        }

        $estimated_bytes = $this->estimatedBytes($decrypted_value);
        $is_within_limit = $estimated_bytes <= $max_memo_bytes;

        if (!$is_within_limit) {
            $this->recordMetric('cache.skip.max_memo_bytes', [
                'memo_bytes'     => $estimated_bytes,
                'max_memo_bytes' => $max_memo_bytes,
            ]);
        }

        return $is_within_limit;
    }

    /**
     * Read a value from a cache repository.
     *
     * @param  \Illuminate\Contracts\Cache\Repository  $cache_repository
     * @param  string  $cache_key
     * @return array{hit: bool, value: mixed}
     */
    private function readRepositoryValue(CacheRepository $cache_repository, string $cache_key): array
    {
        $cache_value = $cache_repository->get($cache_key);

        if ($cache_value === null) {
            return ['hit' => false, 'value' => null];
        }

        if ($this->isValueEnvelope($cache_value)) {
            return ['hit' => true, 'value' => $cache_value[self::VALUE_ENVELOPE_KEY]];
        }

        return ['hit' => true, 'value' => $cache_value];
    }

    /**
     * Write a value to a cache repository.
     *
     * @param  \Illuminate\Contracts\Cache\Repository  $cache_repository
     * @param  string  $cache_key
     * @param  mixed  $value
     * @param  int  $ttl_seconds
     * @return void
     */
    private function writeRepositoryValue(CacheRepository $cache_repository, string $cache_key, mixed $value, int $ttl_seconds): void
    {
        $cache_repository->put(
            $cache_key,
            [self::VALUE_ENVELOPE_KEY => $value],
            $ttl_seconds,
        );
    }

    /**
     * Determine whether decrypted plaintext should be persisted to cache.
     *
     * @param  array<string, mixed>  $context
     * @param  mixed  $decrypted_value
     * @return bool
     */
    private function shouldPersistPlaintext(array $context, mixed $decrypted_value): bool
    {
        $can_persist_plaintext = (bool) ($context['can_persist_plaintext'] ?? false);

        return $can_persist_plaintext
            && $this->withinMaxPlaintextSize($decrypted_value);
    }

    /**
     * Persist decrypted plaintext and emit write metrics.
     *
     * @param  \Illuminate\Contracts\Cache\Repository  $repository
     * @param  array<string, mixed>  $context
     * @param  mixed  $decrypted_value
     * @return void
     */
    private function persistPlaintextValue(CacheRepository $repository, array $context, mixed $decrypted_value): void
    {
        $this->writeRepositoryValue(
            $repository,
            $context['cache_key'],
            $decrypted_value,
            $context['ttl_seconds'],
        );

        $this->recordMetric('cache.write', [
            'source'    => 'persistent',
            'cache_key' => $context['cache_key'],
            'bytes'     => $this->estimatedBytes($decrypted_value),
        ]);
    }

    /**
     * Determine if a cache value uses the expected envelope shape.
     *
     * @param  mixed  $cache_value
     * @return bool
     */
    private function isValueEnvelope(mixed $cache_value): bool
    {
        return is_array($cache_value) && array_key_exists(self::VALUE_ENVELOPE_KEY, $cache_value);
    }

    /**
     * Resolve cached-crypt runtime configuration.
     *
     * @return \SineMacula\CachedCrypt\CachedCryptConfiguration
     */
    private function configuration(): CachedCryptConfiguration
    {
        if ($this->configuration === null) {
            $this->configuration = new CachedCryptConfiguration;
        }

        return $this->configuration;
    }

    /**
     * Record a sampled metric event when metrics are enabled.
     *
     * @param  string  $metric
     * @param  array<string, mixed>  $context
     * @return void
     */
    private function recordMetric(string $metric, array $context = []): void
    {
        if (!$this->configuration()->metricsEnabled() || !$this->shouldSampleMetric()) {
            return;
        }

        Log::debug(self::METRIC_EVENT, [
            'metric'  => $metric,
            'context' => $context,
        ]);
    }

    /**
     * Determine if a metric event should be sampled.
     *
     * @return bool
     */
    private function shouldSampleMetric(): bool
    {
        $sample_rate = $this->configuration()->metricSampleRate();

        if ($sample_rate <= 0.0) {
            return false;
        }

        $sample_threshold = $sample_rate >= 1.0
            ? 10000
            : (int) round($sample_rate * 10000);

        if ($sample_threshold < 1) {
            return false;
        }

        return random_int(1, 10000) <= $sample_threshold;
    }
}
