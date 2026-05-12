<?php

declare(strict_types=1);

namespace Aether\Attributes;

/**
 * Marks a method as an event listener.
 * The AOT compiler builds a static dispatch map from these.
 * No runtime scanning. No looping through arrays of listeners.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class Listener
{
    public function __construct(
        public readonly string $event,
        public readonly int $priority = 0,
    ) {}
}
