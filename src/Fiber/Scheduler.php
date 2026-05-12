<?php

declare(strict_types=1);

namespace Aether\Fiber;

/**
 * Fiber Scheduler — cooperative event loop for async I/O.
 *
 * Manages a queue of Fibers and coordinates their execution.
 * When a Fiber suspends (e.g. waiting on a DB query), the scheduler
 * picks up the next ready Fiber, enabling concurrent request processing.
 *
 * @package Aether\Fiber
 */
final class Scheduler
{
    /** @var \SplQueue<\Fiber> Ready queue */
    private \SplQueue $readyQueue;

    /** @var array<int, array{fiber: \Fiber, socket: mixed, event: string, deferred: Deferred}> I/O watchers */
    private array $ioWatchers = [];

    /** @var array<int, array{fiber: \Fiber, time: float, deferred: Deferred}> Timer watchers */
    private array $timerWatchers = [];

    /** @var int Watcher ID counter */
    private int $watcherId = 0;

    /** @var bool Whether the loop is running */
    private bool $running = false;

    /** @var int Processed fiber count */
    private int $processed = 0;

    public function __construct()
    {
        $this->readyQueue = new \SplQueue();
    }

    /**
     * Schedule a callable to run as a Fiber.
     *
     * @param callable $task
     * @return Deferred The deferred result
     */
    public function defer(callable $task): Deferred
    {
        $deferred = new Deferred();
        $fiber = new \Fiber(function () use ($task, $deferred): void {
            try {
                $result = $task();
                $deferred->resolve($result);
            } catch (\Throwable $e) {
                $deferred->reject($e);
            }
        });

        $this->readyQueue->enqueue($fiber);
        return $deferred;
    }

    /**
     * Enqueue an existing Fiber directly into the ready queue.
     * Used by ConnectionPool to wake up fibers waiting for a connection.
     */
    public function schedule(\Fiber $fiber): void
    {
        $this->readyQueue->enqueue($fiber);
    }

    /**
     * Schedule a delay (non-blocking sleep).
     *
     * @param float $seconds Delay duration
     * @return Deferred Resolves after the delay
     */
    public function delay(float $seconds): Deferred
    {
        $deferred = new Deferred();
        $fiber = \Fiber::getCurrent();

        if ($fiber !== null) {
            $this->timerWatchers[++$this->watcherId] = [
                'fiber' => $fiber,
                'time' => microtime(true) + $seconds,
                'deferred' => $deferred,
            ];
            \Fiber::suspend();
            $deferred->resolve(null);
        } else {
            // Synchronous fallback
            usleep((int)($seconds * 1_000_000));
            $deferred->resolve(null);
        }

        return $deferred;
    }

    /**
     * Watch a socket for readability/writability.
     *
     * @param resource $socket
     * @param string $event 'read' or 'write'
     * @return Deferred Resolves when the socket is ready
     */
    public function watchSocket(mixed $socket, string $event = 'read'): Deferred
    {
        $deferred = new Deferred();
        $fiber = \Fiber::getCurrent();

        if ($fiber !== null) {
            $this->ioWatchers[++$this->watcherId] = [
                'fiber' => $fiber,
                'socket' => $socket,
                'event' => $event,
                'deferred' => $deferred,
            ];
            \Fiber::suspend();
        }

        return $deferred;
    }

    /**
     * The event loop. It runs until you stop giving it work.
     */
    public function run(): void
    {
        $this->running = true;

        while ($this->running && ($this->hasWork())) {
            // Run all ready fibers. Try not to block here.
            $queueSize = $this->readyQueue->count();
            for ($i = 0; $i < $queueSize; $i++) {
                $fiber = $this->readyQueue->dequeue();
                $this->tick($fiber);
            }

            // 2. Poll I/O watchers
            $this->pollIO();

            // Process timers. Yes, it's microsecond precision.
            $this->checkTimers();

            // Small yield to prevent CPU spin
            if ($this->hasWork() && $this->readyQueue->isEmpty()) {
                usleep(100);
            }
        }

        $this->running = false;
    }

    /**
     * Run a single tick of the event loop.
     */
    public function tick(\Fiber $fiber): void
    {
        try {
            if (!$fiber->isStarted()) {
                $fiber->start();
            } elseif ($fiber->isSuspended()) {
                $fiber->resume();
            }

            $this->processed++;

            // If fiber is suspended (waiting), it's managed by watchers
            // If fiber is terminated, it's done
        } catch (\Throwable $e) {
            // Fiber threw — log but don't crash the loop
            error_log("Fiber error: " . $e->getMessage());
        }
    }

    /**
     * Stop the event loop.
     */
    public function stop(): void
    {
        $this->running = false;
    }

    public function isRunning(): bool
    {
        return $this->running;
    }

    public function getProcessedCount(): int
    {
        return $this->processed;
    }

    public function getPendingCount(): int
    {
        return $this->readyQueue->count() + count($this->ioWatchers) + count($this->timerWatchers);
    }

    private function hasWork(): bool
    {
        return !$this->readyQueue->isEmpty()
            || count($this->ioWatchers) > 0
            || count($this->timerWatchers) > 0;
    }

    private function pollIO(): void
    {
        if (count($this->ioWatchers) === 0) {
            return;
        }

        $read = [];
        $write = [];
        $except = [];
        $watcherMap = [];

        foreach ($this->ioWatchers as $id => $watcher) {
            if ($watcher['event'] === 'read') {
                $read[] = $watcher['socket'];
            } else {
                $write[] = $watcher['socket'];
            }
            $watcherMap[(int)$watcher['socket']] = $id;
        }

        if (count($read) === 0 && count($write) === 0) {
            return;
        }

        $changed = @stream_select($read, $write, $except, 0, 1000);

        if ($changed === false || $changed === 0) {
            return;
        }

        foreach (array_merge($read, $write) as $socket) {
            $socketId = (int)$socket;
            if (isset($watcherMap[$socketId])) {
                $id = $watcherMap[$socketId];
                $watcher = $this->ioWatchers[$id];
                unset($this->ioWatchers[$id]);
                $watcher['deferred']->resolve(true);
                if ($watcher['fiber']->isSuspended()) {
                    $this->readyQueue->enqueue($watcher['fiber']);
                }
            }
        }
    }

    private function checkTimers(): void
    {
        $now = microtime(true);
        foreach ($this->timerWatchers as $id => $watcher) {
            if ($now >= $watcher['time']) {
                unset($this->timerWatchers[$id]);
                if ($watcher['fiber']->isSuspended()) {
                    $this->readyQueue->enqueue($watcher['fiber']);
                }
            }
        }
    }
}
