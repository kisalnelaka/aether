<?php

declare(strict_types=1);

namespace Aether\Router;

/**
 * Result of a successful route match.
 *
 * @package Aether\Router
 */
final class MatchResult
{
    /**
     * @param RouteEntry $route The matched route entry
     * @param array<string, string> $params Extracted path parameters
     */
    public function __construct(
        public readonly RouteEntry $route,
        public readonly array $params = [],
    ) {}
}
