<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Example unit test.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[\PHPUnit\Framework\Attributes\CoversNothing]
class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     *
     * @return void
     */
    public function testThatTrueIsTrue(): void
    {
        static::assertTrue(true);
    }
}
