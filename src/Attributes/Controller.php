<?php

declare(strict_types=1);

namespace Aether\Attributes;

use Attribute;

/**
 * Marks a controller class with a route prefix.
 *
 * All methods within the controller will have their paths
 * prefixed with this value.
 *
 * @package Aether\Attributes
 */
#[Attribute(Attribute::TARGET_CLASS)]
class Controller
{
    /**
     * @param string $prefix Route prefix (e.g. "/api/v1")
     * @param array<string> $middleware Middleware applied to all routes in this controller
     */
    public function __construct(
        public readonly string $prefix = '',
        public readonly array $middleware = [],
    ) {}
}
