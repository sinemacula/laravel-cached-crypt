<?php

declare(strict_types = 1);

namespace Tests\Fixtures;

/**
 * Eligibility resolver that always denies persistence.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
final class AlwaysFalseEligibilityResolver
{
    /**
     * Resolve eligibility.
     *
     * @param  string  $payload
     * @param  bool  $unserialize
     * @return bool
     */
    public function __invoke(string $payload, bool $unserialize): bool
    {
        return $payload === '' && $unserialize;
    }
}
