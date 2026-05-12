<?php

declare(strict_types=1);

namespace Aether\Attributes;

/**
 * Marks a class as a CLI command.
 * The AOT compiler builds a radix tree for console commands,
 * same way it does for HTTP routes. Fast dispatch.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class Command
{
    public function __construct(
        public readonly string $name,
        public readonly string $description = '',
    ) {}
}
