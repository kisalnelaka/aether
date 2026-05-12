<?php

declare(strict_types=1);

namespace Aether\Attributes;

/**
 * Marks a method to run as a background job.
 * When called through the dispatcher, it gets pushed to the in-memory queue
 * instead of executing inline. The worker fibers pick it up.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class Background
{
    public function __construct(
        public readonly int $maxRetries = 3,
        public readonly int $delaySeconds = 0,
        public readonly string $queue = 'default',
    ) {}
}
