<?php

declare(strict_types = 1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\CachedCrypt\CachedCryptServiceProvider;

/**
 * Service provider unit tests.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(CachedCryptServiceProvider::class)]
final class CachedCryptServiceProviderTest extends TestCase
{
    /**
     * Ensure publishing returns early when app is not in console mode.
     *
     * @return void
     */
    public function testOfferPublishingReturnsEarlyWhenNotRunningInConsole(): void
    {
        $app = new class {
            /**
             * Determine if app is running in console.
             *
             * @return bool
             */
            public function runningInConsole(): bool
            {
                return false;
            }
        };

        $provider   = new CachedCryptServiceProvider($app);
        $reflection = new \ReflectionMethod($provider, 'offerPublishing');

        $reflection->invoke($provider);

        self::assertTrue(true);
    }
}
