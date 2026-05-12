<?php

declare(strict_types=1);

namespace Aether\Attributes;

/**
 * Marks a class as a Data Transfer Object.
 * The AOT compiler generates a flat hydration + validation function for it.
 * Don't put business logic in here. It's a data bag.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class Dto
{
    public function __construct(
        public readonly bool $strict = true,
    ) {}
}
