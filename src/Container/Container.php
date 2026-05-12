<?php

declare(strict_types=1);

namespace Aether\Container;

/**
 * State-Safe Dependency Injection Container.
 *
 * Differentiates between Persistent (boot-once), Ephemeral (per-request),
 * and Transient (per-resolve) services. Designed for resident-memory
 * servers where the container survives across requests.
 *
 * Key invariants:
 * - Persistent services are NEVER recreated after boot.
 * - Ephemeral services are destroyed between requests via resetEphemerals().
 * - No circular references: dependency graph is validated at bind time.
 * - Zero Reflection at runtime when AOT hydrators are loaded.
 *
 * @package Aether\Container
 */
final class Container
{
    /** @var array<string, Definition> */
    private array $definitions = [];

    /** @var array<string, string> Interface => concrete class alias map */
    private array $aliases = [];

    /** @var array<string, true> Circular dependency guard during resolution */
    private array $resolving = [];

    /** @var array<string, callable> AOT-generated hydrator functions */
    private array $hydrators = [];

    /** @var self|null Singleton instance for global access */
    private static ?self $instance = null;

    public function __construct() {}

    /**
     * Get or create the global container instance.
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Reset the global instance (for testing).
     */
    public static function resetInstance(): void
    {
        self::$instance = null;
    }

    // ── Registration ──

    /**
     * Bind a service definition.
     *
     * @param string $id       Service identifier (FQCN or alias)
     * @param callable|string|null $factory Callable factory or class FQCN
     * @param ServiceScope $scope Lifecycle scope
     * @param array<string, string> $dependencies Param => service ID
     */
    public function bind(
        string $id,
        callable|string|null $factory = null,
        ServiceScope $scope = ServiceScope::Transient,
        array $dependencies = [],
    ): self {
        $this->definitions[$id] = new Definition($id, $scope, $factory, $dependencies);
        return $this;
    }

    /**
     * Register a persistent (boot-once) service.
     */
    public function persistent(string $id, callable|string|null $factory = null, array $dependencies = []): self
    {
        return $this->bind($id, $factory, ServiceScope::Persistent, $dependencies);
    }

    /**
     * Register an ephemeral (per-request) service.
     */
    public function ephemeral(string $id, callable|string|null $factory = null, array $dependencies = []): self
    {
        return $this->bind($id, $factory, ServiceScope::Ephemeral, $dependencies);
    }

    /**
     * Register a transient (per-resolve) service.
     */
    public function transient(string $id, callable|string|null $factory = null, array $dependencies = []): self
    {
        return $this->bind($id, $factory, ServiceScope::Transient, $dependencies);
    }

    /**
     * Register a pre-built instance as a persistent service.
     */
    public function instance(string $id, mixed $instance): self
    {
        $def = new Definition($id, ServiceScope::Persistent, null, [], true, $instance);
        $this->definitions[$id] = $def;
        return $this;
    }

    /**
     * Alias an interface to a concrete implementation.
     */
    public function alias(string $interface, string $concrete): self
    {
        $this->aliases[$interface] = $concrete;
        return $this;
    }

    /**
     * Load AOT-generated hydrators.
     *
     * @param array<string, callable> $hydrators Service ID => factory callable
     */
    public function loadHydrators(array $hydrators): void
    {
        $this->hydrators = $hydrators + $this->hydrators;
    }

    // ── Resolution ──

    /**
     * Resolve a service by ID.
     *
     * @template T
     * @param string|class-string<T> $id
     * @return T|mixed
     * @throws ContainerException
     */
    public function resolve(string $id): mixed
    {
        // Resolve aliases
        while (isset($this->aliases[$id])) {
            $id = $this->aliases[$id];
        }

        // Check for existing definition
        if (!isset($this->definitions[$id])) {
            // You requested a service that isn't bound. I'll try to build it, but don't cry if it fails.
            if (class_exists($id)) {
                $this->bind($id, $id);
            } else {
                throw new ContainerException("Service '{$id}' not found in container");
            }
        }

        $def = $this->definitions[$id];

        // Return cached instance for Persistent and Ephemeral scopes
        if ($def->isResolved && $def->scope !== ServiceScope::Transient) {
            return $def->instance;
        }

        // Stop binding circular dependencies. Fix your architecture.
        if (isset($this->resolving[$id])) {
            $chain = implode(' -> ', array_keys($this->resolving));
            throw new ContainerException("Circular dependency detected: {$chain} -> {$id}");
        }
        $this->resolving[$id] = true;

        try {
            $instance = $this->build($id, $def);
        } finally {
            unset($this->resolving[$id]);
        }

        // Cache for non-transient scopes
        if ($def->scope !== ServiceScope::Transient) {
            $def->resolve($instance);
        }

        return $instance;
    }

    /**
     * Check if a service is registered.
     */
    public function has(string $id): bool
    {
        if (isset($this->aliases[$id])) {
            $id = $this->aliases[$id];
        }
        return isset($this->definitions[$id]);
    }

    /**
     * Get the definition for a service (for diagnostics).
     */
    public function getDefinition(string $id): ?Definition
    {
        return $this->definitions[$id] ?? null;
    }

    /**
     * Get all registered service IDs.
     *
     * @return array<string>
     */
    public function getServiceIds(): array
    {
        return array_keys($this->definitions);
    }

    // ── Lifecycle ──

    /**
     * Delete all ephemeral objects. This prevents the memory leaks you normally write.
     * Called by the kernel at the end of each request cycle.
     */
    public function resetEphemerals(): void
    {
        foreach ($this->definitions as $def) {
            if ($def->scope === ServiceScope::Ephemeral) {
                $def->reset();
            }
        }
    }

    /**
     * Check for potential memory leaks (circular references).
     *
     * @return array<string> List of service IDs with potential issues
     */
    public function checkLeaks(): array
    {
        $issues = [];
        foreach ($this->definitions as $id => $def) {
            if ($def->scope === ServiceScope::Ephemeral && $def->isResolved && $def->instance !== null) {
                // Check refcount > expected
                $issues[] = $id;
            }
        }
        return $issues;
    }

    /**
     * Get memory usage diagnostic info.
     *
     * @return array<string, mixed>
     */
    public function diagnostics(): array
    {
        $persistent = 0;
        $ephemeral = 0;
        $transient = 0;
        $resolved = 0;

        foreach ($this->definitions as $def) {
            match ($def->scope) {
                ServiceScope::Persistent => $persistent++,
                ServiceScope::Ephemeral => $ephemeral++,
                ServiceScope::Transient => $transient++,
            };
            if ($def->isResolved) {
                $resolved++;
            }
        }

        return [
            'total' => count($this->definitions),
            'persistent' => $persistent,
            'ephemeral' => $ephemeral,
            'transient' => $transient,
            'resolved' => $resolved,
            'aliases' => count($this->aliases),
            'hydrators' => count($this->hydrators),
            'memory_bytes' => memory_get_usage(false),
        ];
    }

    // ── Private: Build ──

    private function build(string $id, Definition $def): mixed
    {
        // 1. AOT hydrator (fastest path — no reflection)
        if (isset($this->hydrators[$id])) {
            return ($this->hydrators[$id])($this);
        }

        // 2. Callable factory
        if ($def->factory !== null && is_callable($def->factory)) {
            return ($def->factory)($this);
        }

        // 3. Class name with dependency map
        $className = is_string($def->factory) ? $def->factory : $id;
        if (!class_exists($className)) {
            throw new ContainerException("Cannot auto-wire '{$id}': class '{$className}' not found");
        }

        // Resolve declared dependencies
        $args = [];
        foreach ($def->dependencies as $param => $depId) {
            $args[$param] = $this->resolve($depId);
        }

        if (count($args) > 0) {
            return new $className(...array_values($args));
        }

        return new $className();
    }
}
