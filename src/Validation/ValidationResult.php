<?php

declare(strict_types=1);

namespace Aether\Validation;

/**
 * Result of a validation run. Immutable.
 */
final class ValidationResult
{
    /**
     * @param bool $valid
     * @param array<string, mixed> $validated  Clean, type-cast values
     * @param array<string, string> $errors    Field => error message
     */
    public function __construct(
        public readonly bool $valid,
        public readonly array $validated = [],
        public readonly array $errors = [],
    ) {}

    public function failed(): bool
    {
        return !$this->valid;
    }
}
