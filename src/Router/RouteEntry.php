<?php

declare(strict_types=1);

namespace Aether\Router;

/**
 * Immutable route entry — holds the handler and metadata for a single route.
 *
 * @package Aether\Router
 */
final class RouteEntry
{
    /**
     * @param string $method HTTP method
     * @param string $path   Original path pattern
     * @param string $handler Controller::method or closure identifier
     * @param string $controllerClass Fully qualified controller class name
     * @param string $controllerMethod Method name on the controller
     * @param array<string> $middleware Ordered middleware class names
     * @param string $name Optional route name
     * @param array<string, string> $defaults Default parameter values
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly string $handler,
        public readonly string $controllerClass = '',
        public readonly string $controllerMethod = '',
        public readonly array $middleware = [],
        public readonly string $name = '',
        public readonly array $defaults = [],
    ) {}
}
