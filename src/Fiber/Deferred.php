<?php

declare(strict_types=1);

namespace Aether\Fiber;

/**
 * Deferred/Promise — represents a value that will be available in the future.
 *
 * Works with Fibers: when awaited, it suspends the current Fiber
 * and yields control back to the scheduler.
 *
 * @template T
 * @package Aether\Fiber
 */
final class Deferred
{
    private bool $resolved = false;
    private bool $rejected = false;
    private mixed $value = null;
    private ?\Throwable $error = null;
    /** @var array<\Fiber> Fibers waiting on this deferred */
    private array $waiters = [];

    /**
     * Resolve with a value.
     *
     * @param T $value
     */
    public function resolve(mixed $value): void
    {
        if ($this->resolved || $this->rejected) {
            return;
        }
        $this->value = $value;
        $this->resolved = true;

        foreach ($this->waiters as $fiber) {
            if ($fiber->isSuspended()) {
                $fiber->resume($value);
            }
        }
        $this->waiters = [];
    }

    /**
     * Reject with an error.
     */
    public function reject(\Throwable $error): void
    {
        if ($this->resolved || $this->rejected) {
            return;
        }
        $this->error = $error;
        $this->rejected = true;

        foreach ($this->waiters as $fiber) {
            if ($fiber->isSuspended()) {
                $fiber->throw($error);
            }
        }
        $this->waiters = [];
    }

    /**
     * Await the result. Suspends the current Fiber if not yet resolved.
     *
     * @return T
     * @throws \Throwable If rejected
     */
    public function await(): mixed
    {
        if ($this->resolved) {
            return $this->value;
        }

        if ($this->rejected) {
            throw $this->error;
        }

        // Suspend current fiber and wait
        $fiber = \Fiber::getCurrent();
        if ($fiber !== null) {
            $this->waiters[] = $fiber;
            return \Fiber::suspend($this);
        }

        // Not inside a fiber — spin-wait (fallback for synchronous context)
        while (!$this->resolved && !$this->rejected) {
            usleep(100);
        }

        if ($this->rejected) {
            throw $this->error;
        }

        return $this->value;
    }

    public function isResolved(): bool { return $this->resolved; }
    public function isRejected(): bool { return $this->rejected; }
    public function isPending(): bool { return !$this->resolved && !$this->rejected; }
}
