<?php

declare(strict_types = 1);

namespace Tests\Unit;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\CachedCrypt\Encrypter;
use Tests\Fixtures\MetricLoggerSpy;
use Tests\Support\TestCase;

/**
 * Encrypter integration-style behavior tests.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(Encrypter::class)]
final class EncrypterTest extends TestCase
{
    /**
     * Ensure decrypt delegates directly when package is disabled.
     *
     * @return void
     */
    public function testDecryptDelegatesToParentWhenDisabled(): void
    {
        $this->setCachedCryptConfig([
            'enabled' => false,
        ]);

        $encrypter = $this->newEncrypter();
        $payload   = $encrypter->encrypt('secret-value');

        self::assertSame('secret-value', $encrypter->decrypt($payload));
        self::assertNull(Cache::store('array')->get($this->expectedCacheKey($payload)));
    }

    /**
     * Ensure non-string payloads pass through to framework validation.
     *
     * @return void
     */
    public function testDecryptThrowsForNonStringPayloadWhenEnabled(): void
    {
        $this->setCachedCryptConfig([
            'enabled' => true,
        ]);

        $encrypter = $this->newEncrypter();

        $this->expectException(DecryptException::class);

        $encrypter->decrypt(['invalid']);
    }

    /**
     * Ensure memo cache is used for repeated decrypt calls in one instance.
     *
     * @return void
     */
    public function testDecryptUsesMemoCacheWithinSameInstance(): void
    {
        $this->setCachedCryptConfig([
            'enabled'         => true,
            'cache_plaintext' => false,
            'memo_only'       => true,
            'metrics'         => [
                'enabled'     => true,
                'sample_rate' => 1.0,
            ],
        ]);

        $log_spy = new MetricLoggerSpy;
        Log::swap($log_spy);

        $encrypter = $this->newEncrypter();
        $payload   = $encrypter->encrypt('memo-value');

        self::assertSame('memo-value', $encrypter->decrypt($payload));
        self::assertSame('memo-value', $encrypter->decrypt($payload));

        $this->assertMetricLogged($log_spy, 'decrypt.executed', 1);
        $this->assertMetricLogged($log_spy, 'cache.miss', 1, 'memo');
        $this->assertMetricLogged($log_spy, 'cache.hit', 1, 'memo');
        self::assertNull(Cache::store('array')->get($this->expectedCacheKey($payload)));
    }

    /**
     * Ensure persistent cache supports cross-instance reuse.
     *
     * @return void
     */
    public function testDecryptUsesPersistentCacheAcrossInstances(): void
    {
        $this->setCachedCryptConfig([
            'enabled'            => true,
            'cache_plaintext'    => true,
            'memo_only'          => false,
            'min_bytes_to_cache' => 0,
            'max_memo_bytes'     => 0,
            'metrics'            => [
                'enabled'     => true,
                'sample_rate' => 1.0,
            ],
        ]);

        $log_spy = new MetricLoggerSpy;
        Log::swap($log_spy);

        $first_encrypter  = $this->newEncrypter();
        $payload          = $first_encrypter->encrypt('persistent-value');
        $expected_key     = $this->expectedCacheKey($payload);
        $first_decryption = $first_encrypter->decrypt($payload);

        self::assertSame('persistent-value', $first_decryption);
        self::assertSame(['value' => 'persistent-value'], Cache::store('array')->get($expected_key));

        $second_encrypter = $this->newEncrypter();

        self::assertSame('persistent-value', $second_encrypter->decrypt($payload));

        $this->assertMetricLogged($log_spy, 'cache.hit', 1, 'persistent');
    }

    /**
     * Ensure cache keys include configured fingerprint segments.
     *
     * @return void
     */
    public function testDecryptIncludesConfiguredKeyFingerprintInCacheKey(): void
    {
        $this->setCachedCryptConfig([
            'enabled'            => true,
            'cache_plaintext'    => true,
            'memo_only'          => false,
            'min_bytes_to_cache' => 0,
            'key_fingerprint'    => 'fp-1',
        ]);

        $encrypter = $this->newEncrypter();
        $payload   = $encrypter->encrypt('fingerprinted');
        $cache_key = $this->expectedCacheKey($payload);

        self::assertStringContainsString(':fp-1:', $cache_key);
        self::assertSame('fingerprinted', $encrypter->decrypt($payload));
        self::assertSame(['value' => 'fingerprinted'], Cache::store('array')->get($cache_key));
    }

    /**
     * Ensure persistence is skipped when plaintext exceeds configured max bytes.
     *
     * @return void
     */
    public function testDecryptSkipsPersistentCacheWhenPlaintextExceedsMaxBytes(): void
    {
        $this->setCachedCryptConfig([
            'enabled'            => true,
            'cache_plaintext'    => true,
            'memo_only'          => false,
            'min_bytes_to_cache' => 0,
            'max_bytes_to_cache' => 2,
            'metrics'            => [
                'enabled'     => true,
                'sample_rate' => 1.0,
            ],
        ]);

        $log_spy = new MetricLoggerSpy;
        Log::swap($log_spy);

        $encrypter = $this->newEncrypter();
        $payload   = $encrypter->encrypt('toolong');

        self::assertSame('toolong', $encrypter->decrypt($payload));
        self::assertNull(Cache::store('array')->get($this->expectedCacheKey($payload)));

        $this->assertMetricLogged($log_spy, 'cache.skip.max_plaintext_bytes', 1);
    }

    /**
     * Ensure payload minimum size can disable persistent caching.
     *
     * @return void
     */
    public function testDecryptSkipsPersistentCacheWhenPayloadIsBelowMinimumBytes(): void
    {
        $this->setCachedCryptConfig([
            'enabled'            => true,
            'cache_plaintext'    => true,
            'memo_only'          => false,
            'min_bytes_to_cache' => 10000,
        ]);

        $encrypter = $this->newEncrypter();
        $payload   = $encrypter->encrypt('small');

        self::assertSame('small', $encrypter->decrypt($payload));
        self::assertNull(Cache::store('array')->get($this->expectedCacheKey($payload)));
    }

    /**
     * Ensure memo guardrail prevents oversized in-process memo values.
     *
     * @return void
     */
    public function testDecryptSkipsMemoWhenValueExceedsMaxMemoBytes(): void
    {
        $this->setCachedCryptConfig([
            'enabled'         => true,
            'cache_plaintext' => false,
            'memo_only'       => true,
            'max_memo_bytes'  => 1,
            'metrics'         => [
                'enabled'     => true,
                'sample_rate' => 1.0,
            ],
        ]);

        $log_spy = new MetricLoggerSpy;
        Log::swap($log_spy);

        $encrypter = $this->newEncrypter();
        $payload   = $encrypter->encrypt('memo-guardrail');

        self::assertSame('memo-guardrail', $encrypter->decrypt($payload));
        self::assertSame('memo-guardrail', $encrypter->decrypt($payload));

        $this->assertMetricLogged($log_spy, 'decrypt.executed', 2);
        $this->assertMetricLogged($log_spy, 'cache.skip.max_memo_bytes', 2);
    }

    /**
     * Ensure null size limits do not block memoization or persistence.
     *
     * @return void
     */
    public function testDecryptAllowsNullSizeLimits(): void
    {
        $this->setCachedCryptConfig([
            'enabled'            => true,
            'cache_plaintext'    => true,
            'memo_only'          => false,
            'min_bytes_to_cache' => 0,
            'max_memo_bytes'     => null,
            'max_bytes_to_cache' => null,
        ]);

        $encrypter = $this->newEncrypter();
        $payload   = $encrypter->encrypt('null-limits');
        $cache_key = $this->expectedCacheKey($payload);

        self::assertSame('null-limits', $encrypter->decrypt($payload));
        self::assertSame(['value' => 'null-limits'], Cache::store('array')->get($cache_key));
    }

    /**
     * Ensure internal array memo fallback works when array store is unavailable.
     *
     * @return void
     */
    public function testDecryptUsesInternalMemoFallbackWhenArrayStoreIsUnavailable(): void
    {
        $file_cache_path = sprintf('%s/cached-crypt-file-cache', sys_get_temp_dir());

        if (!is_dir($file_cache_path)) {
            mkdir($file_cache_path, 0777, true);
        }

        config()->set('cache.stores', [
            'file' => [
                'driver' => 'file',
                'path'   => $file_cache_path,
            ],
        ]);
        config()->set('cache.default', 'file');

        $this->setCachedCryptConfig([
            'enabled'         => true,
            'cache_plaintext' => false,
            'memo_only'       => true,
            'metrics'         => [
                'enabled'     => true,
                'sample_rate' => 1.0,
            ],
        ]);

        $log_spy = new MetricLoggerSpy;
        Log::swap($log_spy);

        $encrypter = $this->newEncrypter();
        $payload   = $encrypter->encrypt('fallback-memo');

        self::assertSame('fallback-memo', $encrypter->decrypt($payload));
        self::assertSame('fallback-memo', $encrypter->decrypt($payload));

        $this->assertMetricLogged($log_spy, 'cache.hit', 1, 'memo');
    }

    /**
     * Ensure non-envelope cache payloads remain readable for compatibility.
     *
     * @return void
     */
    public function testDecryptReadsLegacyRawPersistentValues(): void
    {
        $this->setCachedCryptConfig([
            'enabled'            => true,
            'cache_plaintext'    => true,
            'memo_only'          => false,
            'min_bytes_to_cache' => 0,
        ]);

        $encrypter = $this->newEncrypter();
        $payload   = $encrypter->encrypt('actual-value');
        $cache_key = $this->expectedCacheKey($payload);

        Cache::store('array')->put($cache_key, 'legacy-value', 120);

        self::assertSame('legacy-value', $encrypter->decrypt($payload));
    }

    /**
     * Ensure unsupported tag stores are detected and logged.
     *
     * @return void
     */
    public function testDecryptLogsTagSupportMetricWhenTagsUnsupported(): void
    {
        $file_cache_path = sprintf('%s/cached-crypt-file-cache-tags', sys_get_temp_dir());

        if (!is_dir($file_cache_path)) {
            mkdir($file_cache_path, 0777, true);
        }

        config()->set('cache.stores.file', [
            'driver' => 'file',
            'path'   => $file_cache_path,
        ]);

        $this->setCachedCryptConfig([
            'enabled'            => true,
            'cache_plaintext'    => true,
            'memo_only'          => false,
            'min_bytes_to_cache' => 0,
            'use_tags'           => true,
            'store'              => 'file',
            'metrics'            => [
                'enabled'     => true,
                'sample_rate' => 1.0,
            ],
        ]);

        $log_spy = new MetricLoggerSpy;
        Log::swap($log_spy);

        $encrypter = $this->newEncrypter();
        $payload   = $encrypter->encrypt('tag-check');

        self::assertSame('tag-check', $encrypter->decrypt($payload));

        $this->assertMetricLogged($log_spy, 'cache.tags.unsupported', 1);
    }

    /**
     * Build expected cache key for assertions.
     *
     * @param  string  $payload
     * @param  bool  $unserialize
     * @return string
     */
    private function expectedCacheKey(string $payload, bool $unserialize = true): string
    {
        $segments = [
            'decrypted',
            'v1',
        ];

        $epoch = config('cached-crypt.epoch', 'v1');

        if (is_string($epoch) && trim($epoch) !== '') {
            $segments[1] = $epoch;
        }

        $fingerprint = config('cached-crypt.key_fingerprint');

        if (is_string($fingerprint) && trim($fingerprint) !== '') {
            $segments[] = trim($fingerprint);
        }

        $segments[] = hash('sha256', $payload);
        $segments[] = $unserialize ? '1' : '0';

        return implode(':', $segments);
    }

    /**
     * Assert metric event was logged a specific number of times.
     *
     * @param  \Tests\Fixtures\MetricLoggerSpy  $log_spy
     * @param  string  $metric
     * @param  int  $times
     * @param  string|null  $source
     * @return void
     */
    private function assertMetricLogged(MetricLoggerSpy $log_spy, string $metric, int $times, ?string $source = null): void
    {
        $matching_entries = array_filter(
            $log_spy->entries,
            static function (array $entry) use ($metric, $source): bool {
                $is_metric_event    = $entry['message']                     === 'cached-crypt.metric';
                $is_expected_metric = ($entry['context']['metric'] ?? null) === $metric;
                $is_expected_source = $source                               === null || ($entry['context']['context']['source'] ?? null) === $source;

                return $is_metric_event && $is_expected_metric && $is_expected_source;
            },
        );

        self::assertCount($times, $matching_entries);
    }
}
