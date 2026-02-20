<?php

declare(strict_types = 1);

namespace Tests\Fixtures;

/**
 * Eligibility resolver that cannot be resolved via the container.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
final class UnresolvableEligibilityResolver
{
    /**
     * Create resolver.
     *
     * @param  string  $dependency
     */
    public function __construct(
        private readonly string $dependency,
    ) {}

    /**
     * Resolve eligibility.
     *
     * @param  string  $payload
     * @param  bool  $unserialize
     * @return bool
     */
    public function __invoke(string $payload, bool $unserialize): bool
    {
        return $this->dependency !== '' && $payload === '' && $unserialize;
    }
}
