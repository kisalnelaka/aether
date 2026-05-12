<?php

declare(strict_types=1);

namespace Aether\Attributes;

use Attribute;

/**
 * Base HTTP route attribute.
 *
 * Applied to controller methods to define endpoint routing.
 * Supports path parameters via {param} syntax.
 *
 * @package Aether\Attributes
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class Route
{
    /**
     * @param string $path    URI path (e.g. "/users/{id}")
     * @param string $method  HTTP method (GET, POST, PUT, DELETE, PATCH, OPTIONS, HEAD)
     * @param string $name    Optional route name for URL generation
     * @param array<string> $middleware Middleware class names to apply
     */
    public function __construct(
        public readonly string $path,
        public readonly string $method = 'GET',
        public readonly string $name = '',
        public readonly array $middleware = [],
    ) {}
}
