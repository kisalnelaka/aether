<?php

declare(strict_types=1);

namespace Aether\Container;

/**
 * Service definition — describes how to build and manage a service.
 *
 * @package Aether\Container
 */
final class Definition
{
    /**
     * @param string $id         Service identifier (usually FQCN)
     * @param ServiceScope $scope Lifecycle scope
     * @param callable|string|null $factory Factory callable or class name to instantiate
     * @param array<string, string> $dependencies Parameter name => service ID mapping
     * @param bool $isResolved   Whether a singleton instance exists
     * @param mixed $instance    Cached singleton instance
     */
    public function __construct(
        public readonly string $id,
        public readonly ServiceScope $scope = ServiceScope::Transient,
        public readonly mixed $factory = null,
        public readonly array $dependencies = [],
        public bool $isResolved = false,
        public mixed $instance = null,
    ) {}

    /**
     * Mark as resolved with the given instance.
     */
    public function resolve(mixed $instance): void
    {
        $this->instance = $instance;
        $this->isResolved = true;
    }

    /**
     * Clear the cached instance (for ephemeral reset between requests).
     */
    public function reset(): void
    {
        $this->instance = null;
        $this->isResolved = false;
    }
}
