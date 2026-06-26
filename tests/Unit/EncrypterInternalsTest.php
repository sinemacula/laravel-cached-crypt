<?php

declare(strict_types = 1);

namespace Tests\Unit;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as IlluminateCacheRepository;
use Illuminate\Cache\TaggedCache;
use Illuminate\Cache\TagSet;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\CachedCrypt\Encrypter;
use Tests\Support\TestCase;

/**
 * Encrypter internal-path tests.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(Encrypter::class)]
final class EncrypterInternalsTest extends TestCase
{
    /**
     * Ensure tagged repositories are used when tag support exists.
     *
     * @return void
     */
    public function testPersistentRepositoryUsesTagsWhenSupported(): void
    {
        $baseRepository = new class (new ArrayStore) extends IlluminateCacheRepository {
            /** @var array<int, mixed> */
            public array $lastTags = [];

            /**
             * Determine if tags are supported.
             *
             * @return bool
             */
            #[\Override]
            public function supportsTags(): bool
            {
                return true;
            }

            /**
             * Capture tag arguments and return tagged repository.
             *
             * @param  mixed  $names
             * @return \Illuminate\Cache\TaggedCache
             */
            #[\Override]
            public function tags(mixed $names): TaggedCache
            {
                $this->lastTags = is_array($names) ? array_values($names) : [$names];
                $tagNames       = array_map(
                    static fn (mixed $name): string => is_scalar($name) ? (string) $name : get_debug_type($name),
                    $this->lastTags,
                );

                return new TaggedCache($this->getStore(), new TagSet($this->getStore(), $tagNames));
            }
        };

        Cache::shouldReceive('store')
            ->once()
            ->with('custom')
            ->andReturn($baseRepository);

        $encrypter = $this->newEncrypter();

        $resolvedRepository = $this->invokePrivateMethod(
            $encrypter,
            'persistentRepository',
            ['custom', true, 'v2'],
        );

        self::assertInstanceOf(TaggedCache::class, $resolvedRepository);
        self::assertSame([
            'cached-crypt',
            'cached-crypt:v2',
        ], $baseRepository->lastTags);
    }

    /**
     * Ensure non-Illuminate repositories bypass tag probing.
     *
     * @return void
     */
    public function testPersistentRepositoryBypassesTagsForNonIlluminateRepository(): void
    {
        $cacheRepository = self::createStub(CacheRepository::class);

        Cache::shouldReceive('store')
            ->once()
            ->with('custom')
            ->andReturn($cacheRepository);

        $encrypter = $this->newEncrypter();

        $resolvedRepository = $this->invokePrivateMethod(
            $encrypter,
            'persistentRepository',
            ['custom', true, 'v1'],
        );

        self::assertSame($cacheRepository, $resolvedRepository);
    }

    /**
     * Ensure size estimation handles unserializable values.
     *
     * @return void
     */
    public function testEstimatedBytesHandlesUnserializableValue(): void
    {
        $encrypter = $this->newEncrypter();
        $closure   = static fn (): bool => true;

        $estimatedBytes = $this->invokePrivateMethod(
            $encrypter,
            'estimatedBytes',
            [$closure],
        );

        self::assertSame(strlen(\Closure::class), $estimatedBytes);
    }

    /**
     * Ensure scalar values use scalar byte estimation path.
     *
     * @return void
     */
    public function testEstimatedBytesHandlesScalarValue(): void
    {
        $encrypter = $this->newEncrypter();

        $estimatedBytes = $this->invokePrivateMethod(
            $encrypter,
            'estimatedBytes',
            [123],
        );

        self::assertSame(3, $estimatedBytes);
    }

    /**
     * Ensure sampling logic handles zero and sub-threshold rates.
     *
     * @return void
     */
    public function testShouldSampleMetricHandlesBoundaryRates(): void
    {
        $encrypter = $this->newEncrypter();

        $this->setCachedCryptConfig([
            'metrics' => [
                'sample_rate' => 0,
            ],
        ]);

        self::assertFalse($this->invokePrivateMethod($encrypter, 'shouldSampleMetric'));

        $this->setCachedCryptConfig([
            'metrics' => [
                'sample_rate' => 0.00001,
            ],
        ]);

        self::assertFalse($this->invokePrivateMethod($encrypter, 'shouldSampleMetric'));

        $this->setCachedCryptConfig([
            'metrics' => [
                'sample_rate' => 1.0,
            ],
        ]);

        self::assertTrue($this->invokePrivateMethod($encrypter, 'shouldSampleMetric'));
    }

    /**
     * Ensure persistent cache resolution failures do not fail decrypt flow.
     *
     * @return void
     */
    public function testResolveCachedValueHandlesPersistentReadFailures(): void
    {
        config()->set('cache.stores', []);

        Cache::shouldReceive('store')
            ->once()
            ->with('broken')
            ->andThrow(new \RuntimeException('Cache backend unavailable.'));

        $encrypter = $this->newEncrypter();
        $context   = [
            'cache_key'             => 'decrypted:v1:test:1',
            'memo_key'              => 'memo:decrypted:v1:test:1',
            'ttl_seconds'           => 120,
            'store'                 => 'broken',
            'epoch'                 => 'v1',
            'use_tags'              => false,
            'can_persist_plaintext' => true,
        ];

        $resolvedValue = $this->invokePrivateMethod($encrypter, 'resolveCachedValue', [$context]);

        self::assertFalse($resolvedValue['hit']);
        self::assertNull($resolvedValue['value']);
        self::assertNull($resolvedValue['cache_repository']);
    }

    /**
     * Ensure persistent write failures do not fail decrypt execution.
     *
     * @return void
     */
    public function testDecryptAndCacheHandlesPersistentWriteFailures(): void
    {
        $this->setCachedCryptConfig([
            'cache_plaintext'    => true,
            'memo_only'          => false,
            'max_bytes_to_cache' => null,
        ]);

        $repository = self::createStub(CacheRepository::class);
        $repository->method('put')->willThrowException(new \RuntimeException('Cache write failed.'));

        $encrypter = $this->newEncrypter();
        $payload   = $encrypter->encrypt('fail-open');
        $context   = [
            'cache_key'             => 'decrypted:v1:test:1',
            'memo_key'              => 'memo:decrypted:v1:test:1',
            'ttl_seconds'           => 120,
            'can_persist_plaintext' => true,
        ];

        $decryptedValue = $this->invokePrivateMethod(
            $encrypter,
            'decryptAndCache',
            [$payload, true, $context, $repository],
        );

        self::assertSame('fail-open', $decryptedValue);
    }

    /**
     * Invoke a private method on the encrypter.
     *
     * @param  \SineMacula\CachedCrypt\Encrypter  $encrypter
     * @param  string  $methodName
     * @param  array<int, mixed>  $arguments
     * @return mixed
     */
    private function invokePrivateMethod(Encrypter $encrypter, string $methodName, array $arguments = []): mixed
    {
        $reflection = new \ReflectionMethod($encrypter, $methodName);

        return $reflection->invokeArgs($encrypter, $arguments);
    }
}
