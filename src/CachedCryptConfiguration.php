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
        return $this->boolean('enabled', false);
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
        $should_persist_plaintext = $this->boolean('cache_plaintext', false)
            && !$this->boolean('memo_only', true)
            && $this->passesEligibilityResolver($payload, $unserialize);

        if (!$should_persist_plaintext) {
            return false;
        }

        $minimum_payload_bytes = $this->nullableInteger('min_bytes_to_cache');

        if ($minimum_payload_bytes === null) {
            return true;
        }

        return strlen($payload) >= $minimum_payload_bytes;
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
        $store_name = $this->string('store');

        if ($store_name === null) {
            return null;
        }

        if (config()->has(sprintf('cache.stores.%s', $store_name))) {
            return $store_name;
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
        $ttl_seconds = $this->integer('ttl_seconds', self::DEFAULT_TTL_SECONDS);

        if ($ttl_seconds > 0) {
            return $ttl_seconds;
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
        $sample_rate = $this->value('metrics.sample_rate', self::DEFAULT_METRIC_SAMPLE_RATE);

        if (!is_numeric($sample_rate)) {
            return self::DEFAULT_METRIC_SAMPLE_RATE;
        }

        return max(0.0, min(1.0, (float) $sample_rate));
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
        $underscored_path = sprintf('cached_crypt.%s', $key);

        if (config()->has($underscored_path)) {
            return config($underscored_path);
        }

        $hyphenated_path = sprintf('cached-crypt.%s', $key);

        if (config()->has($hyphenated_path)) {
            return config($hyphenated_path);
        }

        return $default;
    }

    /**
     * Resolve a boolean config value.
     *
     * @param  string  $key
     * @param  bool  $default
     * @return bool
     */
    private function boolean(string $key, bool $default): bool
    {
        $config_value = $this->value($key, $default);

        if (is_bool($config_value)) {
            return $config_value;
        }

        return (bool) $config_value;
    }

    /**
     * Resolve a string config value.
     *
     * @param  string  $key
     * @return string|null
     */
    private function string(string $key): ?string
    {
        $config_value = $this->value($key);

        if (!is_string($config_value)) {
            return null;
        }

        $trimmed_value = trim($config_value);

        if ($trimmed_value === '') {
            return null;
        }

        return $trimmed_value;
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
        $config_value = $this->value($key, $default);

        if (!is_numeric($config_value)) {
            return $default;
        }

        return (int) $config_value;
    }

    /**
     * Resolve a nullable integer config value.
     *
     * @param  string  $key
     * @return int|null
     */
    private function nullableInteger(string $key): ?int
    {
        $config_value = $this->value($key);

        if ($config_value === null || $config_value === '') {
            return null;
        }

        if (!is_numeric($config_value)) {
            return null;
        }

        return (int) $config_value;
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

        if ($resolver === null) {
            return true;
        }

        $resolved_resolver = $this->eligibilityResolver($resolver);

        if ($resolved_resolver === null) {
            return false;
        }

        return $resolved_resolver($payload, $unserialize);
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
            $resolver = app($resolver);
        }

        if (!is_callable($resolver)) {
            return null;
        }

        return \Closure::fromCallable($resolver);
    }
}
