<?php

declare(strict_types = 1);

namespace Tests\Fixtures;

/**
 * Captures debug log entries for metric assertions.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
final class MetricLoggerSpy
{
    /** @var array<int, array{message: string, context: array<string, mixed>}> */
    public array $entries = [];

    /**
     * Record a debug log event.
     *
     * @param  string  $message
     * @param  array<string, mixed>  $context
     * @return void
     */
    public function debug(string $message, array $context = []): void
    {
        $this->entries[] = [
            'message' => $message,
            'context' => $context,
        ];
    }
}
