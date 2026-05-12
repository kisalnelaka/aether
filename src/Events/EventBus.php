<?php

declare(strict_types=1);

namespace Aether\Events;

use Aether\Container\Container;

/**
 * AOT-compiled Event Bus.
 *
 * At build time, the compiler scans #[Listener] attributes and generates
 * a static dispatch map. At runtime this is just a match() statement.
 * No looping through arrays. No resolving listeners dynamically.
 *
 * If AOT isn't loaded, falls back to manual registration. Slower, but
 * at least it works while you're developing.
 *
 * @package Aether\Events
 */
final class EventBus
{
    /** @var array<string, array<array{class: string, method: string, priority: int}>> */
    private array $listeners = [];

    /** @var array<string, callable[]> AOT-compiled dispatch map */
    private static array $compiled = [];

    private Container $container;

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    /**
     * Load AOT-compiled listener map.
     * @param array<string, callable[]> $map Event FQCN => [callable, ...]
     */
    public static function loadCompiled(array $map): void
    {
        self::$compiled = $map + self::$compiled;
    }

    /**
     * Register a listener manually.
     */
    public function listen(string $event, string $listenerClass, string $method = 'handle', int $priority = 0): self
    {
        $this->listeners[$event][] = [
            'class' => $listenerClass,
            'method' => $method,
            'priority' => $priority,
        ];

        // Sort by priority (higher = runs first)
        usort($this->listeners[$event], fn($a, $b) => $b['priority'] <=> $a['priority']);

        return $this;
    }

    /**
     * Dispatch an event. All registered listeners fire.
     * If a listener returns false, propagation stops.
     */
    public function dispatch(object $event): object
    {
        $eventClass = get_class($event);

        // Fast path: AOT-compiled listeners
        if (isset(self::$compiled[$eventClass])) {
            foreach (self::$compiled[$eventClass] as $handler) {
                $result = $handler($event, $this->container);
                if ($result === false) {
                    break;
                }
            }
            return $event;
        }

        // Slow path: dynamically registered listeners
        $listeners = $this->listeners[$eventClass] ?? [];
        foreach ($listeners as $entry) {
            $listener = $this->container->resolve($entry['class']);
            $result = $listener->{$entry['method']}($event);
            if ($result === false) {
                break;
            }
        }

        return $event;
    }

    /**
     * Check if an event has any listeners.
     */
    public function hasListeners(string $eventClass): bool
    {
        return isset(self::$compiled[$eventClass]) || isset($this->listeners[$eventClass]);
    }

    /**
     * Get listener count for diagnostics.
     * @return array<string, int>
     */
    public function stats(): array
    {
        $counts = [];
        foreach ($this->listeners as $event => $list) {
            $counts[$event] = count($list);
        }
        foreach (self::$compiled as $event => $list) {
            $counts[$event] = ($counts[$event] ?? 0) + count($list);
        }
        return $counts;
    }
}
