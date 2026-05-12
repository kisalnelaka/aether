<?php

declare(strict_types=1);

namespace Aether\Attributes;

/**
 * Marks a property for AOT validation.
 * The compiler reads these at build time and spits out raw PHP conditionals.
 * No regex at runtime. No validation library. Just if-statements.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::IS_REPEATABLE)]
final class Validate
{
    public function __construct(
        public readonly string $rule,
        public readonly string $message = '',
        /** @var array<string, mixed> */
        public readonly array $params = [],
    ) {}
}
