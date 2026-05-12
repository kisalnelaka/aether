<?php

declare(strict_types=1);

namespace Aether\Validation;

/**
 * Thrown when DTO validation fails.
 */
final class ValidationException extends \RuntimeException
{
    /** @var array<string, string> */
    public readonly array $errors;

    /**
     * @param array<string, string> $errors
     */
    public function __construct(array $errors)
    {
        $this->errors = $errors;
        $msg = 'Validation failed: ' . implode(', ', array_values($errors));
        parent::__construct($msg, 422);
    }
}
