<?php

declare(strict_types=1);

namespace Aether\Validation;

use Aether\Attributes\Validate;

/**
 * High-performance AOT-compatible Validator.
 *
 * Scans #[Validate] attributes and executes rules.
 * Uses AOT-compiled closures if available to avoid Reflection.
 *
 * @package Aether\Validation
 */
final class Validator
{
    /** @var array<string, callable> AOT-compiled validators */
    private static array $compiled = [];

    /**
     * Load AOT-compiled validators.
     * @param array<string, callable> $map
     */
    public static function loadCompiled(array $map): void
    {
        self::$compiled = $map + self::$compiled;
    }

    /**
     * Validate data against a DTO class.
     *
     * @param array<string, mixed> $data
     * @param class-string $dtoClass
     * @return ValidationResult
     */
    public static function validate(array $data, string $dtoClass): ValidationResult
    {
        // Fast path: AOT-compiled
        if (isset(self::$compiled[$dtoClass])) {
            return (self::$compiled[$dtoClass])($data);
        }

        // Fallback: Runtime reflection
        return self::validateRuntime($data, $dtoClass);
    }

    /**
     * Validate data or throw an exception.
     *
     * @param array<string, mixed> $data
     * @param class-string $dtoClass
     * @return array<string, mixed> The validated data
     * @throws ValidationException
     */
    public static function validateOrFail(array $data, string $dtoClass): array
    {
        $result = self::validate($data, $dtoClass);
        if (!$result->isValid()) {
            throw new ValidationException($result);
        }
        return $result->getValidated();
    }

    private static function validateRuntime(array $data, string $dtoClass): ValidationResult
    {
        if (!class_exists($dtoClass)) {
            throw new \InvalidArgumentException("DTO class '{$dtoClass}' not found");
        }

        $ref = new \ReflectionClass($dtoClass);
        $errors = [];
        $validated = [];

        foreach ($ref->getProperties() as $prop) {
            $name = $prop->getName();
            $val = $data[$name] ?? null;
            $attrs = $prop->getAttributes(Validate::class);

            foreach ($attrs as $attr) {
                $rule = $attr->newInstance();
                if (!self::checkRule($rule->rule, $val, $rule->params)) {
                    $errors[$name] = $rule->message !== '' ? $rule->message : "Validation failed for {$name} ({$rule->rule})";
                    break;
                }
            }

            if (!isset($errors[$name])) {
                $validated[$name] = $val;
            }
        }

        return new ValidationResult(empty($errors), $validated, $errors);
    }

    private static function checkRule(string $rule, mixed $val, array $params): bool
    {
        return match ($rule) {
            'required' => $val !== null && $val !== '',
            'email' => filter_var($val, FILTER_VALIDATE_EMAIL) !== false,
            'url' => filter_var($val, FILTER_VALIDATE_URL) !== false,
            'min' => is_string($val) && strlen($val) >= ($params['length'] ?? 0),
            'max' => is_string($val) && strlen($val) <= ($params['length'] ?? PHP_INT_MAX),
            'numeric' => is_numeric($val),
            'integer' => filter_var($val, FILTER_VALIDATE_INT) !== false,
            default => true,
        };
    }
}
