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
        $base_repository = new class (new ArrayStore) extends IlluminateCacheRepository {
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
                $tag_names      = array_map(
                    static fn (mixed $name): string => is_scalar($name) ? (string) $name : get_debug_type($name),
                    $this->lastTags,
                );

                return new TaggedCache($this->getStore(), new TagSet($this->getStore(), $tag_names));
            }
        };

        Cache::shouldReceive('store')
            ->once()
            ->with('custom')
            ->andReturn($base_repository);

        $encrypter = $this->newEncrypter();

        $resolved_repository = $this->invokePrivateMethod(
            $encrypter,
            'persistentRepository',
            ['custom', true, 'v2'],
        );

        self::assertInstanceOf(TaggedCache::class, $resolved_repository);
        self::assertSame([
            'cached-crypt',
            'cached-crypt:v2',
        ], $base_repository->lastTags);
    }

    /**
     * Ensure non-Illuminate repositories bypass tag probing.
     *
     * @return void
     */
    public function testPersistentRepositoryBypassesTagsForNonIlluminateRepository(): void
    {
        $cache_repository = self::createStub(CacheRepository::class);

        Cache::shouldReceive('store')
            ->once()
            ->with('custom')
            ->andReturn($cache_repository);

        $encrypter = $this->newEncrypter();

        $resolved_repository = $this->invokePrivateMethod(
            $encrypter,
            'persistentRepository',
            ['custom', true, 'v1'],
        );

        self::assertSame($cache_repository, $resolved_repository);
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

        $estimated_bytes = $this->invokePrivateMethod(
            $encrypter,
            'estimatedBytes',
            [$closure],
        );

        self::assertSame(strlen(\Closure::class), $estimated_bytes);
    }

    /**
     * Ensure scalar values use scalar byte estimation path.
     *
     * @return void
     */
    public function testEstimatedBytesHandlesScalarValue(): void
    {
        $encrypter = $this->newEncrypter();

        $estimated_bytes = $this->invokePrivateMethod(
            $encrypter,
            'estimatedBytes',
            [123],
        );

        self::assertSame(3, $estimated_bytes);
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

        $resolved_value = $this->invokePrivateMethod($encrypter, 'resolveCachedValue', [$context]);

        self::assertFalse($resolved_value['hit']);
        self::assertNull($resolved_value['value']);
        self::assertNull($resolved_value['cache_repository']);
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

        $decrypted_value = $this->invokePrivateMethod(
            $encrypter,
            'decryptAndCache',
            [$payload, true, $context, $repository],
        );

        self::assertSame('fail-open', $decrypted_value);
    }

    /**
     * Create a new package encrypter.
     *
     * @return \SineMacula\CachedCrypt\Encrypter
     */
    private function newEncrypter(): Encrypter
    {
        $cipher = config('app.cipher');

        if (!is_string($cipher)) {
            $cipher = 'aes-256-cbc';
        }

        return new Encrypter(str_repeat('a', 32), $cipher);
    }

    /**
     * Invoke a private method on the encrypter.
     *
     * @param  \SineMacula\CachedCrypt\Encrypter  $encrypter
     * @param  string  $method_name
     * @param  array<int, mixed>  $arguments
     * @return mixed
     */
    private function invokePrivateMethod(Encrypter $encrypter, string $method_name, array $arguments = []): mixed
    {
        $reflection = new \ReflectionMethod($encrypter, $method_name);

        return $reflection->invokeArgs($encrypter, $arguments);
    }
}
