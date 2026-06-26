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
final class Encrypter extends LaravelEncrypter
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
        $normalizedUnserialize = (bool) $unserialize;

        if ($this->configuration()->enabled() && is_string($payload)) {
            $cacheContext = $this->cacheContext($payload, $normalizedUnserialize);
            $cachedValue  = $this->resolveCachedValue($cacheContext);

            if ($cachedValue['hit']) {
                return $cachedValue['value'];
            }

            return $this->decryptAndCache(
                $payload,
                $normalizedUnserialize,
                $cacheContext,
                $cachedValue['cache_repository'],
            );
        }

        return parent::decrypt($payload, $normalizedUnserialize);
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
        $cacheKey      = $this->cacheKey($payload, $unserialize, $epoch);

        return [
            'cache_key'             => $cacheKey,
            'memo_key'              => sprintf('%s:%s', self::MEMO_KEY_PREFIX, $cacheKey),
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
     * Resolve a cached value from memoization and optional persistent store.
     *
     * @param  array<string, mixed>  $cacheContext
     * @return array{hit: bool, value: mixed, cache_repository: \Illuminate\Contracts\Cache\Repository|null}
     */
    private function resolveCachedValue(array $cacheContext): array
    {
        $memoizedValue = $this->readMemoizedValue($cacheContext['memo_key']);

        if ($memoizedValue['hit']) {
            $this->recordMetric('cache.hit', [
                'source'    => 'memo',
                'cache_key' => $cacheContext['cache_key'],
            ]);

            return [
                'hit'              => true,
                'value'            => $memoizedValue['value'],
                'cache_repository' => null,
            ];
        }

        $this->recordMetric('cache.miss', [
            'source'    => 'memo',
            'cache_key' => $cacheContext['cache_key'],
        ]);

        if (!(bool) ($cacheContext['can_persist_plaintext'] ?? false)) {
            return [
                'hit'              => false,
                'value'            => null,
                'cache_repository' => null,
            ];
        }

        return $this->resolvePersistedValue($cacheContext);
    }

    /**
     * Resolve a cached value from the persistent store, memoizing any hit.
     *
     * @param  array<string, mixed>  $cacheContext
     * @return array{hit: bool, value: mixed, cache_repository: \Illuminate\Contracts\Cache\Repository|null}
     */
    private function resolvePersistedValue(array $cacheContext): array
    {
        try {
            $cacheRepository = $this->persistentRepository(
                $cacheContext['store'],
                $cacheContext['use_tags'],
                $cacheContext['epoch'],
            );
            $persistedValue = $this->readRepositoryValue(
                $cacheRepository,
                $cacheContext['cache_key'],
            );
        } catch (\Throwable $exception) {
            $this->recordMetric('cache.error', [
                'source'    => 'persistent',
                'operation' => 'read',
                'exception' => $exception::class,
            ]);
            $this->recordMetric('cache.miss', [
                'source'    => 'persistent',
                'cache_key' => $cacheContext['cache_key'],
            ]);

            return ['hit' => false, 'value' => null, 'cache_repository' => null];
        }

        if (!$persistedValue['hit']) {
            $this->recordMetric('cache.miss', [
                'source'    => 'persistent',
                'cache_key' => $cacheContext['cache_key'],
            ]);

            return ['hit' => false, 'value' => null, 'cache_repository' => $cacheRepository];
        }

        $this->writeMemoizedValue(
            $cacheContext['memo_key'],
            $persistedValue['value'],
            $cacheContext['ttl_seconds'],
        );
        $this->recordMetric('cache.hit', [
            'source'    => 'persistent',
            'cache_key' => $cacheContext['cache_key'],
        ]);

        return [
            'hit'              => true,
            'value'            => $persistedValue['value'],
            'cache_repository' => $cacheRepository,
        ];
    }

    /**
     * Read a value from the memoization cache.
     *
     * @param  string  $memoKey
     * @return array{hit: bool, value: mixed}
     */
    private function readMemoizedValue(string $memoKey): array
    {
        $memoRepository = $this->memoRepository();

        if ($memoRepository !== null) {
            return $this->readRepositoryValue($memoRepository, $memoKey);
        }

        if (array_key_exists($memoKey, $this->memoCache)) {
            return ['hit' => true, 'value' => $this->memoCache[$memoKey]];
        }

        return ['hit' => false, 'value' => null];
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
     * Read a value from a cache repository.
     *
     * @param  \Illuminate\Contracts\Cache\Repository  $cacheRepository
     * @param  string  $cacheKey
     * @return array{hit: bool, value: mixed}
     */
    private function readRepositoryValue(CacheRepository $cacheRepository, string $cacheKey): array
    {
        $cacheValue = $cacheRepository->get($cacheKey);

        if ($cacheValue === null) {
            return ['hit' => false, 'value' => null];
        }

        if (is_array($cacheValue) && array_key_exists(self::VALUE_ENVELOPE_KEY, $cacheValue)) {
            return ['hit' => true, 'value' => $cacheValue[self::VALUE_ENVELOPE_KEY]];
        }

        return ['hit' => true, 'value' => $cacheValue];
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
        $sampleRate = $this->configuration()->metricSampleRate();

        if ($sampleRate <= 0.0) {
            return false;
        }

        $sampleThreshold = $sampleRate >= 1.0
            ? 10000
            : (int) round($sampleRate * 10000);

        if ($sampleThreshold < 1) {
            return false;
        }

        return random_int(1, 10000) <= $sampleThreshold;
    }

    /**
     * Resolve the persistent cache repository.
     *
     * @param  string|null  $storeName
     * @param  bool  $useTags
     * @param  string  $epoch
     * @return \Illuminate\Contracts\Cache\Repository
     */
    private function persistentRepository(?string $storeName, bool $useTags, string $epoch): CacheRepository
    {
        $cacheRepository = $storeName === null ? Cache::store() : Cache::store($storeName);

        if (!$useTags || !$cacheRepository instanceof Repository) {
            return $cacheRepository;
        }

        if (!$cacheRepository->supportsTags()) {
            $this->recordMetric('cache.tags.unsupported', [
                'store' => $storeName ?? 'default',
            ]);

            return $cacheRepository;
        }

        return $cacheRepository->tags([
            'cached-crypt',
            sprintf('cached-crypt:%s', $epoch),
        ]);
    }

    /**
     * Write a value to the memoization cache.
     *
     * @param  string  $memoKey
     * @param  mixed  $value
     * @param  int  $ttlSeconds
     * @return void
     */
    private function writeMemoizedValue(string $memoKey, mixed $value, int $ttlSeconds): void
    {
        if (!$this->isWithinMaxMemoSize($value)) {
            return;
        }

        $memoRepository = $this->memoRepository();

        if ($memoRepository !== null) {
            $this->writeRepositoryValue($memoRepository, $memoKey, $value, $ttlSeconds);

            return;
        }

        $this->memoCache[$memoKey] = $value;
    }

    /**
     * Determine if decrypted data is within configured memoization size limits.
     *
     * @param  mixed  $decryptedValue
     * @return bool
     */
    private function isWithinMaxMemoSize(mixed $decryptedValue): bool
    {
        $maxMemoBytes = $this->configuration()->maxMemoBytes();

        if ($maxMemoBytes === null) {
            return true;
        }

        $estimatedBytes = $this->estimatedBytes($decryptedValue);
        $isWithinLimit  = $estimatedBytes <= $maxMemoBytes;

        if (!$isWithinLimit) {
            $this->recordMetric('cache.skip.max_memo_bytes', [
                'memo_bytes'     => $estimatedBytes,
                'max_memo_bytes' => $maxMemoBytes,
            ]);
        }

        return $isWithinLimit;
    }

    /**
     * Estimate payload size in bytes for metric and guardrail decisions.
     *
     * @param  mixed  $value
     * @return int
     */
    private function estimatedBytes(mixed $value): int
    {
        $estimatedBytes = 0;

        if ($value !== null) {
            if (is_string($value)) {
                $estimatedBytes = strlen($value);
            } elseif (is_scalar($value)) {
                $estimatedBytes = strlen((string) $value);
            } else {
                try {
                    $estimatedBytes = strlen(serialize($value));
                } catch (\Throwable) {
                    $estimatedBytes = strlen(get_debug_type($value));
                }
            }
        }

        return $estimatedBytes;
    }

    /**
     * Write a value to a cache repository.
     *
     * @param  \Illuminate\Contracts\Cache\Repository  $cacheRepository
     * @param  string  $cacheKey
     * @param  mixed  $value
     * @param  int  $ttlSeconds
     * @return void
     */
    private function writeRepositoryValue(CacheRepository $cacheRepository, string $cacheKey, mixed $value, int $ttlSeconds): void
    {
        $cacheRepository->put(
            $cacheKey,
            [self::VALUE_ENVELOPE_KEY => $value],
            $ttlSeconds,
        );
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
        $startedAt         = microtime(true);
        $decryptedValue    = parent::decrypt($payload, $unserialize);
        $decryptDurationMs = (int) round((microtime(true) - $startedAt) * 1000);

        $this->writeMemoizedValue(
            $context['memo_key'],
            $decryptedValue,
            $context['ttl_seconds'],
        );

        $this->recordMetric('decrypt.executed', [
            'duration_ms' => $decryptDurationMs,
            'cache_key'   => $context['cache_key'],
        ]);

        if ($repository !== null && $this->shouldPersistPlaintext($context, $decryptedValue)) {
            try {
                $this->persistPlaintextValue($repository, $context, $decryptedValue);
            } catch (\Throwable $exception) {
                $this->recordMetric('cache.error', [
                    'source'    => 'persistent',
                    'operation' => 'write',
                    'exception' => $exception::class,
                ]);
            }
        }

        return $decryptedValue;
    }

    /**
     * Determine whether decrypted plaintext should be persisted to cache.
     *
     * @param  array<string, mixed>  $context
     * @param  mixed  $decryptedValue
     * @return bool
     */
    private function shouldPersistPlaintext(array $context, mixed $decryptedValue): bool
    {
        $canPersistPlaintext = (bool) ($context['can_persist_plaintext'] ?? false);

        return $canPersistPlaintext
            && $this->isWithinMaxPlaintextSize($decryptedValue);
    }

    /**
     * Determine if decrypted data is within configured size limits.
     *
     * @param  mixed  $decryptedValue
     * @return bool
     */
    private function isWithinMaxPlaintextSize(mixed $decryptedValue): bool
    {
        $maxPlaintextBytes = $this->configuration()->maxBytesToCache();

        if ($maxPlaintextBytes === null) {
            return true;
        }

        $estimatedBytes = $this->estimatedBytes($decryptedValue);
        $isWithinLimit  = $estimatedBytes <= $maxPlaintextBytes;

        if (!$isWithinLimit) {
            $this->recordMetric('cache.skip.max_plaintext_bytes', [
                'plaintext_bytes'     => $estimatedBytes,
                'max_plaintext_bytes' => $maxPlaintextBytes,
            ]);
        }

        return $isWithinLimit;
    }

    /**
     * Persist decrypted plaintext and emit write metrics.
     *
     * @param  \Illuminate\Contracts\Cache\Repository  $repository
     * @param  array<string, mixed>  $context
     * @param  mixed  $decryptedValue
     * @return void
     */
    private function persistPlaintextValue(CacheRepository $repository, array $context, mixed $decryptedValue): void
    {
        $this->writeRepositoryValue(
            $repository,
            $context['cache_key'],
            $decryptedValue,
            $context['ttl_seconds'],
        );

        $this->recordMetric('cache.write', [
            'source'    => 'persistent',
            'cache_key' => $context['cache_key'],
            'bytes'     => $this->estimatedBytes($decryptedValue),
        ]);
    }
}
