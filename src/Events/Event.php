<?php

declare(strict_types=1);

namespace Aether\Events;

/**
 * Base event class. Your events should extend this.
 * Or don't. Any object works. But this gives you timestamps
 * and propagation control for free.
 */
class Event
{
    public readonly float $timestamp;
    private bool $stopped = false;

    public function __construct()
    {
        $this->timestamp = microtime(true);
    }

    public function stopPropagation(): void
    {
        $this->stopped = true;
    }

    public function isPropagationStopped(): bool
    {
        return $this->stopped;
    }
}
