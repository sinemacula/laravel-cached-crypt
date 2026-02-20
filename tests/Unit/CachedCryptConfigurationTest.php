<?php

declare(strict_types = 1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\CachedCrypt\CachedCryptConfiguration;
use Tests\Fixtures\AlwaysFalseEligibilityResolver;
use Tests\Fixtures\AlwaysTrueEligibilityResolver;
use Tests\Fixtures\EligibilityResolverFailure;
use Tests\Fixtures\UnresolvableEligibilityResolver;
use Tests\Support\TestCase;

/**
 * Configuration behavior tests.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(CachedCryptConfiguration::class)]
final class CachedCryptConfigurationTest extends TestCase
{
    /**
     * Ensure underscored namespace takes precedence when present.
     *
     * @return void
     */
    public function testEnabledPrefersUnderscoredNamespace(): void
    {
        config()->set('cached-crypt.enabled', false);
        config()->set('cached_crypt.enabled', '1');

        $configuration = new CachedCryptConfiguration;

        self::assertTrue($configuration->enabled());
    }

    /**
     * Ensure enabled defaults to true when config value is absent.
     *
     * @return void
     */
    public function testEnabledDefaultsToTrueWhenConfigValueIsMissing(): void
    {
        config()->set('cached_crypt', []);
        config()->set('cached-crypt', []);

        $configuration = new CachedCryptConfiguration;

        self::assertTrue($configuration->enabled());
    }

    /**
     * Ensure value resolution falls back to hyphenated namespace and defaults.
     *
     * @return void
     */
    public function testValueFallsBackToHyphenatedAndDefault(): void
    {
        config()->set('cached_crypt', []);
        config()->set('cached-crypt.epoch', 'v9');

        $configuration = new CachedCryptConfiguration;

        self::assertSame('v9', $configuration->value('epoch'));
        self::assertSame('fallback', $configuration->value('missing', 'fallback'));
    }

    /**
     * Ensure persistence gating honors enable flags and minimum payload bytes.
     *
     * @return void
     */
    public function testCanPersistPlaintextHonorsFlagsAndMinBytes(): void
    {
        $configuration = new CachedCryptConfiguration;

        $this->setCachedCryptConfig([
            'cache_plaintext' => false,
            'memo_only'       => false,
        ]);
        self::assertFalse($configuration->canPersistPlaintext('payload', true));

        $this->setCachedCryptConfig([
            'cache_plaintext' => true,
            'memo_only'       => true,
        ]);
        self::assertFalse($configuration->canPersistPlaintext('payload', true));

        $this->setCachedCryptConfig([
            'cache_plaintext'    => true,
            'memo_only'          => false,
            'min_bytes_to_cache' => 20,
        ]);
        self::assertFalse($configuration->canPersistPlaintext('small', true));

        $this->setCachedCryptConfig([
            'cache_plaintext'    => true,
            'memo_only'          => false,
            'min_bytes_to_cache' => 3,
        ]);
        self::assertTrue($configuration->canPersistPlaintext('small', true));
    }

    /**
     * Ensure resolver variants are evaluated correctly.
     *
     * @return void
     */
    public function testCanPersistPlaintextSupportsResolverVariants(): void
    {
        $configuration = new CachedCryptConfiguration;

        $this->setCachedCryptConfig([
            'cache_plaintext'      => true,
            'memo_only'            => false,
            'min_bytes_to_cache'   => null,
            'eligibility_resolver' => static fn (string $payload, bool $unserialize): bool => $payload === 'ok' && $unserialize,
        ]);
        self::assertTrue($configuration->canPersistPlaintext('ok', true));

        $this->setCachedCryptConfig([
            'cache_plaintext'      => true,
            'memo_only'            => false,
            'min_bytes_to_cache'   => null,
            'eligibility_resolver' => 'not_a_callable',
        ]);
        self::assertFalse($configuration->canPersistPlaintext('ok', true));

        $this->application()->instance(AlwaysTrueEligibilityResolver::class, new AlwaysTrueEligibilityResolver);
        $this->setCachedCryptConfig([
            'cache_plaintext'      => true,
            'memo_only'            => false,
            'min_bytes_to_cache'   => null,
            'eligibility_resolver' => AlwaysTrueEligibilityResolver::class,
        ]);
        self::assertTrue($configuration->canPersistPlaintext('ok', true));

        $this->application()->instance(AlwaysFalseEligibilityResolver::class, new AlwaysFalseEligibilityResolver);
        $this->setCachedCryptConfig([
            'cache_plaintext'      => true,
            'memo_only'            => false,
            'min_bytes_to_cache'   => null,
            'eligibility_resolver' => AlwaysFalseEligibilityResolver::class,
        ]);
        self::assertFalse($configuration->canPersistPlaintext('ok', true));

        $this->setCachedCryptConfig([
            'cache_plaintext'      => true,
            'memo_only'            => false,
            'min_bytes_to_cache'   => null,
            'eligibility_resolver' => static function (): bool {
                throw new EligibilityResolverFailure('Resolver invocation failed.');
            },
        ]);
        self::assertFalse($configuration->canPersistPlaintext('ok', true));

        $this->setCachedCryptConfig([
            'cache_plaintext'      => true,
            'memo_only'            => false,
            'min_bytes_to_cache'   => null,
            'eligibility_resolver' => UnresolvableEligibilityResolver::class,
        ]);
        self::assertFalse($configuration->canPersistPlaintext('ok', true));
    }

    /**
     * Ensure store and string-related values are normalized.
     *
     * @return void
     */
    public function testStoreAndStringConfigNormalization(): void
    {
        $configuration = new CachedCryptConfiguration;

        $this->setCachedCryptConfig([
            'store'           => null,
            'epoch'           => 123,
            'key_fingerprint' => '  ',
        ]);

        self::assertNull($configuration->storeName());
        self::assertSame('v1', $configuration->epoch());
        self::assertNull($configuration->keyFingerprint());

        config()->set('cache.stores.custom', ['driver' => 'array']);
        $this->setCachedCryptConfig([
            'store'           => 'custom',
            'epoch'           => 'v2',
            'key_fingerprint' => 'fp-1',
        ]);

        self::assertSame('custom', $configuration->storeName());
        self::assertSame('v2', $configuration->epoch());
        self::assertSame('fp-1', $configuration->keyFingerprint());

        $this->setCachedCryptConfig(['store' => 'missing']);
        self::assertNull($configuration->storeName());
    }

    /**
     * Ensure integer settings are normalized and bounded.
     *
     * @return void
     */
    public function testIntegerSettingsAreNormalized(): void
    {
        $configuration = new CachedCryptConfiguration;

        $this->setCachedCryptConfig([
            'ttl_seconds'        => 30,
            'max_bytes_to_cache' => '1024',
            'max_memo_bytes'     => '2048',
        ]);

        self::assertSame(30, $configuration->ttlSeconds());
        self::assertSame(1024, $configuration->maxBytesToCache());
        self::assertSame(2048, $configuration->maxMemoBytes());

        $this->setCachedCryptConfig([
            'ttl_seconds'        => 0,
            'max_bytes_to_cache' => '',
            'max_memo_bytes'     => 'abc',
        ]);

        self::assertSame(120, $configuration->ttlSeconds());
        self::assertNull($configuration->maxBytesToCache());
        self::assertNull($configuration->maxMemoBytes());

        $this->setCachedCryptConfig(['ttl_seconds' => 'invalid']);
        self::assertSame(120, $configuration->ttlSeconds());
    }

    /**
     * Ensure metrics and boolean toggles are normalized.
     *
     * @return void
     */
    public function testMetricConfigurationNormalization(): void
    {
        $configuration = new CachedCryptConfiguration;

        $this->setCachedCryptConfig([
            'use_tags' => '1',
            'metrics'  => [
                'enabled'     => '1',
                'sample_rate' => '0.25',
            ],
        ]);

        self::assertTrue($configuration->shouldUseTags());
        self::assertTrue($configuration->metricsEnabled());
        self::assertSame(0.25, $configuration->metricSampleRate());

        $this->setCachedCryptConfig([
            'metrics' => [
                'enabled'     => false,
                'sample_rate' => 2,
            ],
        ]);

        self::assertFalse($configuration->metricsEnabled());
        self::assertSame(1.0, $configuration->metricSampleRate());

        $this->setCachedCryptConfig([
            'metrics' => [
                'sample_rate' => -1,
            ],
        ]);
        self::assertSame(0.0, $configuration->metricSampleRate());

        $this->setCachedCryptConfig([
            'metrics' => [
                'sample_rate' => 'invalid',
            ],
        ]);
        self::assertSame(0.10, $configuration->metricSampleRate());
    }
}
