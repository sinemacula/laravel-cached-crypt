<?php

declare(strict_types = 1);

namespace SineMacula\CachedCrypt;

/**
 * Configuration accessor for cached-crypt runtime behavior.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class CachedCryptConfiguration
{
    /** @var string */
    private const string DEFAULT_EPOCH = 'v1';

    /** @var int */
    private const int DEFAULT_TTL_SECONDS = 120;

    /** @var float */
    private const float DEFAULT_METRIC_SAMPLE_RATE = 0.10;

    /**
     * Determine whether cached-crypt integration is enabled.
     *
     * @return bool
     */
    public function enabled(): bool
    {
        return $this->boolean('enabled', true);
    }

    /**
     * Determine whether plaintext can be persisted for this payload.
     *
     * @param  string  $payload
     * @param  bool  $unserialize
     * @return bool
     */
    public function canPersistPlaintext(string $payload, bool $unserialize): bool
    {
        $shouldPersistPlaintext = $this->boolean('cache_plaintext', false)
            && !$this->boolean('memo_only', true)
            && $this->passesEligibilityResolver($payload, $unserialize);

        if (!$shouldPersistPlaintext) {
            return false;
        }

        $minimumPayloadBytes = $this->nullableInteger('min_bytes_to_cache');

        if ($minimumPayloadBytes === null) {
            return true;
        }

        return strlen($payload) >= $minimumPayloadBytes;
    }

    /**
     * Determine whether cache tags should be used when supported.
     *
     * @return bool
     */
    public function shouldUseTags(): bool
    {
        return $this->boolean('use_tags', false);
    }

    /**
     * Resolve the configured cache namespace epoch.
     *
     * @return string
     */
    public function epoch(): string
    {
        return $this->string('epoch') ?? self::DEFAULT_EPOCH;
    }

    /**
     * Resolve the configured key fingerprint namespace segment.
     *
     * @return string|null
     */
    public function keyFingerprint(): ?string
    {
        return $this->string('key_fingerprint');
    }

    /**
     * Resolve the configured cache store name.
     *
     * @return string|null
     */
    public function storeName(): ?string
    {
        $storeName = $this->string('store');

        if ($storeName === null) {
            return null;
        }

        if (config()->has(sprintf('cache.stores.%s', $storeName))) {
            return $storeName;
        }

        return null;
    }

    /**
     * Resolve configured cache TTL in seconds.
     *
     * @return int
     */
    public function ttlSeconds(): int
    {
        $ttlSeconds = $this->integer('ttl_seconds', self::DEFAULT_TTL_SECONDS);

        if ($ttlSeconds > 0) {
            return $ttlSeconds;
        }

        return self::DEFAULT_TTL_SECONDS;
    }

    /**
     * Resolve configured max plaintext bytes for persistence.
     *
     * @return int|null
     */
    public function maxBytesToCache(): ?int
    {
        return $this->nullableInteger('max_bytes_to_cache');
    }

    /**
     * Resolve configured max memoized plaintext bytes for in-process cache.
     *
     * @return int|null
     */
    public function maxMemoBytes(): ?int
    {
        return $this->nullableInteger('max_memo_bytes');
    }

    /**
     * Resolve metric sample rate with bounds.
     *
     * @return float
     */
    public function metricSampleRate(): float
    {
        $sampleRate = $this->value('metrics.sample_rate', self::DEFAULT_METRIC_SAMPLE_RATE);

        if (!is_numeric($sampleRate)) {
            return self::DEFAULT_METRIC_SAMPLE_RATE;
        }

        return max(0.0, min(1.0, (float) $sampleRate));
    }

    /**
     * Determine if metric logging is enabled.
     *
     * @return bool
     */
    public function metricsEnabled(): bool
    {
        return $this->boolean('metrics.enabled', false);
    }

    /**
     * Resolve a value from cached-crypt config namespace.
     *
     * @param  string  $key
     * @param  mixed  $default
     * @return mixed
     */
    public function value(string $key, mixed $default = null): mixed
    {
        $underscoredPath = sprintf('cached_crypt.%s', $key);

        if (config()->has($underscoredPath)) {
            return config($underscoredPath);
        }

        $hyphenatedPath = sprintf('cached-crypt.%s', $key);

        if (config()->has($hyphenatedPath)) {
            return config($hyphenatedPath);
        }

        return $default;
    }

    /**
     * Resolve a boolean config value.
     *
     * Named after the type it returns, like the sibling string()/integer()
     * accessors, so it opts out of predicate naming rather than asking a
     * yes/no question.
     *
     * @imperative
     *
     * @param  string  $key
     * @param  bool  $default
     * @return bool
     */
    private function boolean(string $key, bool $default): bool
    {
        $configValue = $this->value($key, $default);

        if (is_bool($configValue)) {
            return $configValue;
        }

        return (bool) $configValue;
    }

    /**
     * Resolve a string config value.
     *
     * @param  string  $key
     * @return string|null
     */
    private function string(string $key): ?string
    {
        $configValue = $this->value($key);

        if (!is_string($configValue)) {
            return null;
        }

        $trimmedValue = trim($configValue);

        if ($trimmedValue === '') {
            return null;
        }

        return $trimmedValue;
    }

    /**
     * Resolve an integer config value.
     *
     * @param  string  $key
     * @param  int  $default
     * @return int
     */
    private function integer(string $key, int $default): int
    {
        $configValue = $this->value($key, $default);

        if (!is_numeric($configValue)) {
            return $default;
        }

        return (int) $configValue;
    }

    /**
     * Resolve a nullable integer config value.
     *
     * @param  string  $key
     * @return int|null
     */
    private function nullableInteger(string $key): ?int
    {
        $configValue = $this->value($key);

        if ($configValue === null || $configValue === '') {
            return null;
        }

        if (!is_numeric($configValue)) {
            return null;
        }

        return (int) $configValue;
    }

    /**
     * Determine if configured resolver allows plaintext caching.
     *
     * @param  string  $payload
     * @param  bool  $unserialize
     * @return bool
     */
    private function passesEligibilityResolver(string $payload, bool $unserialize): bool
    {
        $resolver = $this->value('eligibility_resolver');
        $passes   = true;

        if ($resolver !== null) {
            $resolvedResolver = $this->eligibilityResolver($resolver);

            if ($resolvedResolver === null) {
                $passes = false;
            } else {
                try {
                    $passes = $resolvedResolver($payload, $unserialize);
                } catch (\Throwable) {
                    $passes = false;
                }
            }
        }

        return $passes;
    }

    /**
     * Resolve eligibility callback into an executable closure.
     *
     * @param  mixed  $resolver
     * @return \Closure(string, bool): bool|null
     */
    private function eligibilityResolver(mixed $resolver): ?\Closure
    {
        if (is_string($resolver) && class_exists($resolver)) {
            try {
                $resolver = app($resolver);
            } catch (\Throwable) {
                return null;
            }
        }

        if (!is_callable($resolver)) {
            return null;
        }

        return \Closure::fromCallable($resolver);
    }
}
